<?php
// Caminho do arquivo de log
$logFile = '/opt/mk-auth/dados/Mikrotik_Desativado/log.txt';

// Garante que o arquivo de log exista com permissões 777
if (!file_exists($logFile)) {
    if (file_put_contents($logFile, '') === false) {
        die("Erro ao criar o arquivo de log. Verifique as permissões do diretório.");
    }
    chmod($logFile, 0777); // Define permissões 777
}

// Função para registrar log
function registrarLog($mensagem) {
    global $logFile;
    $dataHora = date('Y-m-d H:i:s');
    $logMensagem = "[$dataHora] $mensagem" . PHP_EOL;
    file_put_contents($logFile, $logMensagem, FILE_APPEND);
}

// Função para desencriptar os dados
function desencriptar($dados, $chave) {
    return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

// Carregar configurações criptografadas
$configFile = '/opt/mk-auth/dados/Mikrotik_Desativado/config.php';
$chave_criptografia = '3NyBm8aa54eg8jeE';

if (file_exists($configFile)) {
    $config = include($configFile);
    if (!isset($config['servers']) || !is_array($config['servers'])) {
        registrarLog("Nenhum servidor configurado.");
        die("Erro: Nenhum servidor Mikrotik configurado.");
    }

    // Desencriptar dados dos servidores
    $mikrotik_servers = array_map(function ($server) use ($chave_criptografia) {
        return [
            'ip' => desencriptar($server['ip'], $chave_criptografia),
            'user' => desencriptar($server['user'], $chave_criptografia),
            'password' => desencriptar($server['password'], $chave_criptografia)
        ];
    }, $config['servers']);
} else {
    registrarLog("Arquivo de configuração não encontrado.");
    die("Erro: Arquivo de configuração não encontrado.");
}

// Dados de conexão com o banco de dados MK-AUTH
$host = "localhost";
$user = "root";
$pass = "vertrigo";
$db = "mkradius";

// Conectar ao banco de dados
$mysqli = new mysqli($host, $user, $pass, $db);

// Verifica a conexão com o banco de dados
if ($mysqli->connect_error) {
    registrarLog("Erro de conexão com o banco de dados: " . $mysqli->connect_error);
    die("Erro de conexão: " . $mysqli->connect_error);
}

// Obter a data de hoje no formato AAAA-MM-DD
$dataHoje = date('Y-m-d');


// Query para verificar se há clientes desativados, unindo condições de sis_cliente e radpostauth
$queryDesativados = "
    (
        SELECT c.login, c.senha
        FROM sis_cliente AS c
        WHERE c.cli_ativado = 'n' 
          AND DATE(c.data_desativacao) = '$dataHoje'
          AND c.login NOT IN (
              SELECT l.login 
              FROM log_mikro AS l
              WHERE l.status = 0
          )
    )
    UNION
    (
        SELECT r.username AS login, c.senha
        FROM radpostauth AS r
        INNER JOIN sis_cliente AS c ON r.username = c.login
        WHERE r.reply = 'Access-Reject'
          AND c.cli_ativado = 'n'
          AND r.authdate >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) -- Verifica apenas últimos 10 minutos
          AND r.username NOT IN (
              SELECT l.login 
              FROM log_mikro AS l
              WHERE l.status = 0
          )
        GROUP BY r.username, c.senha
    )
";


$resultDesativados = $mysqli->query($queryDesativados);

// Query para verificar se há clientes reativados não processados
$queryAtivados = "
    SELECT c.login 
    FROM sis_ativ AS a
    INNER JOIN sis_cliente AS c ON a.registro LIKE CONCAT('%ativou o cliente de login ', c.login, '%')
    WHERE c.cli_ativado = 's' 
      AND a.registro LIKE 'ativou o cliente de login%' 
      AND DATE(a.data) = '$dataHoje'
      AND c.login NOT IN (
          SELECT login FROM log_mikro 
          WHERE status = 1
      )
";
$resultAtivados = $mysqli->query($queryAtivados);

// Somente conectar ao Mikrotik se houver clientes a serem processados
if ($resultDesativados->num_rows > 0 || $resultAtivados->num_rows > 0) {
    require('RouterOS-API/routeros_api.class.php');

    // Iterar sobre cada servidor configurado
    foreach ($mikrotik_servers as $server) {
        $API = new RouterosAPI();
        if ($API->connect($server['ip'], $server['user'], $server['password'])) {
           // registrarLog("Conectado ao servidor Mikrotik: {$server['ip']}");

// Processar clientes desativados
$resultDesativados->data_seek(0); // Resetar o ponteiro do resultado
while ($row = $resultDesativados->fetch_assoc()) {
    $nomeCliente = $row['login'];
    $senhaCliente = $row['senha']; // Captura a senha

    // Adicionar cliente ao Mikrotik com nome e senha
    $API->comm('/ppp/secret/add', [
        'name' => $nomeCliente,
        'password' => $senhaCliente, // Usa a senha capturada
        'service' => 'pppoe',
        'profile' => 'PG-Corte',
        'disabled' => 'false',
        'comment' => 'Mk-bot'
    ]);

    registrarLog("Cliente $nomeCliente adicionado ao Mikrotik ({$server['ip']}).");

    // Verificar se já existe log do cliente
    $checkLog = "SELECT * FROM log_mikro WHERE login = '$nomeCliente'";
    $checkResult = $mysqli->query($checkLog);

    if ($checkResult->num_rows > 0) {
        $updateLog = "UPDATE log_mikro SET data_processamento = '$dataHoje', status = 0 WHERE login = '$nomeCliente'";
        $mysqli->query($updateLog);
    } else {
        $insertLog = "INSERT INTO log_mikro (login, data_processamento, status) VALUES ('$nomeCliente', '$dataHoje', 0)";
        $mysqli->query($insertLog);
    }
}
            // Processar clientes reativados
            $resultAtivados->data_seek(0); // Resetar o ponteiro do resultado
            while ($row = $resultAtivados->fetch_assoc()) {
                $nomeCliente = $row['login'];

            $existingSecrets = $API->comm('/ppp/secret/print', ["?name" => $nomeCliente]);
                if (!empty($existingSecrets)) {
                // Remover o cliente de /ppp/secret
                $API->comm('/ppp/secret/remove', [".id" => $existingSecrets[0]['.id']]);
                registrarLog("Cliente $nomeCliente removido do Mikrotik ({$server['ip']}).");

                // Formatar o nome da interface PPPoE no padrão <pppoe-NomeCliente>
                $interfaceName = "<pppoe-" . $nomeCliente . ">";

                // Remover a interface PPPoE associada ao cliente, se existir
                $existingPPPoE = $API->comm('/interface/pppoe-server/print', ["?name" => $interfaceName]);
                if (!empty($existingPPPoE)) {
                   $API->comm('/interface/pppoe-server/remove', [".id" => $existingPPPoE[0]['.id']]);
                   registrarLog("Cliente Desconectado '$interfaceName' do Servidor ({$server['ip']}).");
                } else {
                // registrarLog("Interface PPPoE '$interfaceName' do cliente $nomeCliente não encontrada no Mikrotik ({$server['ip']}).");
                }
            }

                $checkLog = "SELECT * FROM log_mikro WHERE login = '$nomeCliente'";
                $checkResult = $mysqli->query($checkLog);

                if ($checkResult->num_rows > 0) {
                    $updateLog = "UPDATE log_mikro SET data_processamento = '$dataHoje', status = 1 WHERE login = '$nomeCliente'";
                    $mysqli->query($updateLog);
                } else {
                    $insertLog = "INSERT INTO log_mikro (login, data_processamento, status) VALUES ('$nomeCliente', '$dataHoje', 1)";
                    $mysqli->query($insertLog);
                }
            }

            $API->disconnect();
          //  registrarLog("Desconectado do servidor Mikrotik: {$server['ip']}");
        } else {
          //  registrarLog("Erro ao conectar com o servidor Mikrotik: {$server['ip']}");
        }
    }
} else {
  //  registrarLog("Nenhum cliente a ser processado; conexão com Mikrotik não foi estabelecida.");
}

// Fechar a conexão com o banco de dados
$mysqli->close();
//registrarLog("Conexão com o banco de dados fechada.");
?>
