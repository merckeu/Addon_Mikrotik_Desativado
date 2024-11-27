<?php
include('addons.class.php');

// Verifica se o usuário está logado
session_name('mka');
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['mka_logado']) && !isset($_SESSION['MKA_Logado'])) {
    exit('Acesso negado... <a href="/admin/login.php">Fazer Login</a>');
}

// Variáveis do Manifesto
$manifestTitle = isset($Manifest->name) ? htmlspecialchars($Manifest->name) : '';
$manifestVersion = isset($Manifest->version) ? htmlspecialchars($Manifest->version) : '';

//----------------------------------------------------------------------------------//

// Configurações do banco de dados
$host = "localhost";
$usuario = "root";
$senha = "vertrigo";
$db = "mkradius";
$conn = new mysqli($host, $usuario, $senha, $db);
if ($conn->connect_error) {
    die("<script>alert('Falha na conexão: " . $conn->connect_error . "');</script>");
}

// Estrutura exata esperada para a tabela log_mikro
$estrutura_esperada = [
    'id' => 'INT(11) NOT NULL AUTO_INCREMENT',
    'login' => 'VARCHAR(50) NOT NULL',
    'data_processamento' => 'DATE NOT NULL',
    'status' => 'TINYINT(1) NOT NULL DEFAULT 0'
];

// Inicia uma transação para garantir integridade
$conn->begin_transaction();

try {
    // Verifica a existência da tabela `log_mikro`
    $tabela_existe = $conn->query("SHOW TABLES LIKE 'log_mikro'");
    if ($tabela_existe->num_rows == 0) {
        // Cria a tabela se ela não existir
        $sql_criar_tabela = "
        CREATE TABLE log_mikro (
            id INT AUTO_INCREMENT PRIMARY KEY,
            login VARCHAR(50) NOT NULL,
            data_processamento DATE NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0
        )";
        if ($conn->query($sql_criar_tabela) === TRUE) {
            echo "<script>alert('Tabela log_mikro criada com sucesso!');</script>";
        } else {
            throw new Exception('Erro ao criar a tabela log_mikro: ' . $conn->error);
        }
    } else {
        // Verifica a estrutura da tabela e ajusta conforme necessário
        $resultado = $conn->query("SHOW COLUMNS FROM log_mikro");
        $colunas_existentes = [];
        while ($coluna = $resultado->fetch_assoc()) {
            $colunas_existentes[$coluna['Field']] = $coluna['Type'] . ($coluna['Null'] === 'NO' ? ' NOT NULL' : '');
        }

        // Adiciona colunas ausentes
        foreach ($estrutura_esperada as $coluna => $tipo) {
            if (!array_key_exists($coluna, $colunas_existentes)) {
                $sql_alter = "ALTER TABLE log_mikro ADD COLUMN $coluna $tipo";
                if ($conn->query($sql_alter) === TRUE) {
                    echo "<script>alert('Coluna $coluna adicionada com sucesso à tabela log_mikro!');</script>";
                } else {
                    throw new Exception('Erro ao adicionar a coluna ' . $coluna . ': ' . $conn->error);
                }
            }
        }

        // Remove colunas extras
        foreach ($colunas_existentes as $coluna => $tipo) {
            if (!array_key_exists($coluna, $estrutura_esperada)) {
                $sql_alter = "ALTER TABLE log_mikro DROP COLUMN $coluna";
                if ($conn->query($sql_alter) === TRUE) {
                    echo "<script>alert('Coluna extra $coluna removida da tabela log_mikro!');</script>";
                } else {
                    throw new Exception('Erro ao remover a coluna extra ' . $coluna . ': ' . $conn->error);
                }
            }
        }
    }

    // Confirma a transação
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    echo "<script>alert('Erro: " . $e->getMessage() . "');</script>";
    exit;
}

//-------------------------------------------------------------------------------------------//

// Função para exibir o agendamento atual
function obterAgendamentoAtual() {
    $comandos = [
        "/usr/bin/php -q /opt/mk-auth/admin/addons/Mikrotik_Desativado/limpeza_tabela.php >/dev/null 2>&1",
        "/usr/bin/php -q /opt/mk-auth/admin/addons/Mikrotik_Desativado/Script_desativado.php >/dev/null 2>&1"
    ];

    // Obter todos os agendamentos atuais
    $output = shell_exec("crontab -l 2>/dev/null") ?: '';

    // Filtrar os agendamentos dos comandos específicos
    $agendamentos = [];
    foreach ($comandos as $comando) {
        foreach (explode("\n", $output) as $linha) {
            if (strpos($linha, $comando) !== false) {
                $agendamentos[] = htmlspecialchars($linha);
            }
        }
    }

    return $agendamentos ? implode('<br>', $agendamentos) : "<span class='no-schedule'>Nenhum agendamento configurado para os scripts</span>";
}

// Função para excluir os agendamentos específicos
function excluirAgendamentosEspecificos() {
    $comandos = [
        "/usr/bin/php -q /opt/mk-auth/admin/addons/Mikrotik_Desativado/limpeza_tabela.php >/dev/null 2>&1",
        "/usr/bin/php -q /opt/mk-auth/admin/addons/Mikrotik_Desativado/Script_desativado.php >/dev/null 2>&1"
    ];

    // Obter a tabela de crons atual
    $cronAtual = shell_exec("crontab -l 2>/dev/null") ?: '';

    // Remove os comandos específicos do cron
    foreach ($comandos as $comando) {
        $cronAtual = preg_replace("/.*" . preg_quote($comando, '/') . ".*\n/", '', $cronAtual);
    }

    // Atualiza o cron
    if (trim($cronAtual) === '') {
        // Se nenhum cron restante, limpa o cron
        exec("crontab -r", $output, $status);
        if ($status === 0) {
            echo '<script>alert("Agendamentos excluídos com sucesso.");</script>';
        } else {
            echo '<script>alert("Erro ao excluir os agendamentos.");</script>';
        }
    } else {
        // Salva a tabela de crons atualizada
        if (file_put_contents('/tmp/cron_log_mikrotik', $cronAtual) !== false) {
            exec("crontab /tmp/cron_log_mikrotik", $output, $status);
            if ($status === 0) {
                echo '<script>alert("Agendamentos atualizados com sucesso.");</script>';
            } else {
                echo '<script>alert("Erro ao atualizar o cron.");</script>';
            }
        } else {
            echo '<script>alert("Erro ao salvar os crons no arquivo temporário. Verifique as permissões.");</script>';
        }
    }
}

// Função para atualizar os agendamentos sem sobrescrever outros
function atualizarCron($intervaloMinutos) {
    $comandos = [
        // Primeiro, o script de limpeza
        "/usr/bin/php -q /opt/mk-auth/admin/addons/Mikrotik_Desativado/limpeza_tabela.php >/dev/null 2>&1",
        // Depois, o script de desativação
        "/usr/bin/php -q /opt/mk-auth/admin/addons/Mikrotik_Desativado/Script_desativado.php >/dev/null 2>&1"
    ];

    // Obter a tabela de crons atual
    $cronAtual = shell_exec("crontab -l 2>/dev/null") ?: '';

    // Remove os comandos existentes do mesmo script
    foreach ($comandos as $comando) {
        $cronAtual = preg_replace("/.*" . preg_quote($comando, '/') . ".*\n/", '', $cronAtual);
    }

    // Adicionar os novos agendamentos
    foreach ($comandos as $index => $comando) {
        if ($index === 0) {
            $cronAtual .= "*/$intervaloMinutos * * * * $comando\n";
        } else {
            $cronAtual .= "*/$intervaloMinutos * * * * sleep 60 && $comando\n";
        }
    }

    // Atualiza o cron com os novos agendamentos
    if (file_put_contents('/tmp/cron_log_mikrotik', $cronAtual) !== false) {
        exec("crontab /tmp/cron_log_mikrotik", $output, $status);
        if ($status === 0) {
            echo '<script>alert("Agendamento atualizado com sucesso.");</script>';
        } else {
            echo '<script>alert("Erro ao atualizar o cron.");</script>';
        }
    } else {
        echo '<script>alert("Erro ao salvar o agendamento no arquivo temporário. Verifique as permissões.");</script>';
    }
}

// Verifica se o formulário foi enviado para atualizar o cron
if (isset($_POST['intervalo_minutos'])) {
    $intervaloMinutos = (int)$_POST['intervalo_minutos'];
    atualizarCron($intervaloMinutos);
    echo "<script>window.location.href = window.location.href;</script>";
    exit;
}

// Verifica se o formulário foi enviado para excluir o cron
if (isset($_POST['delete_schedule'])) {
    excluirAgendamentosEspecificos();
    echo '<script>window.location.href = window.location.href;</script>';
    exit;
}

//-------------------------------------------------------------------------//

// Caminho e permissões para o diretório de configurações
$dir_path = '/opt/mk-auth/dados/Mikrotik_Desativado';
$file_path = $dir_path . '/config.php';
if (!is_dir($dir_path)) mkdir($dir_path, 0755, true);

// Define a chave de criptografia
$chave_criptografia = '3NyBm8aa54eg8jeE';

// Funções de encriptação e desencriptação de dados
function encriptar($dados, $chave) {
    return openssl_encrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

function desencriptar($dados, $chave) {
    return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

// Verifica e cria o arquivo de configuração criptografado, se necessário
if (!file_exists($file_path)) {
    $config_content = '<?php return ' . var_export(['ip' => '', 'user' => '', 'senha' => ''], true) . ';';
    file_put_contents($file_path, $config_content);
    chmod($file_path, 0600);  // Permissões restritas
}

// Lê e desencripta as configurações do arquivo
$configuracoes = include($file_path);
$ip = isset($configuracoes['ip']) ? desencriptar($configuracoes['ip'], $chave_criptografia) : '';
$user = isset($configuracoes['user']) ? desencriptar($configuracoes['user'], $chave_criptografia) : '';
$senha = isset($configuracoes['senha']) ? desencriptar($configuracoes['senha'], $chave_criptografia) : '';

// Salva o senha, IP e user encriptados no arquivo, se o formulário foi enviado
if (isset($_POST['salvar_configuracoes'])) {
    $ip = $_POST['ip'] ?? '';
    $user = $_POST['user'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $novas_configuracoes = [
        'ip' => encriptar($ip, $chave_criptografia),
        'user' => encriptar($user, $chave_criptografia),
        'senha' => encriptar($senha, $chave_criptografia),
    ];

    $config_content = '<?php return ' . var_export($novas_configuracoes, true) . ';';
    if (file_put_contents($file_path, $config_content) !== false) {
        chmod($file_path, 0600);  // Define permissão 0600 para o arquivo
        echo "<script>alert('Configurações de senha, IP e User salvas com sucesso!');</script>";
    } else {
        echo "<script>alert('Erro ao salvar as configurações. Verifique as permissões do diretório.');</script>";
    }
}
?>


<!DOCTYPE html>
<html lang="pt-BR" class="has-navbar-fixed-top">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="iso-8859-1">
<title>MK-AUTH :: <?php echo $manifestTitle; ?></title>

<link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css" />
<link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css" />
<link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css" />

<script src="../../scripts/jquery.js"></script>
<script src="../../scripts/mk-auth.js"></script>

<style>
    .container {
        max-width: 1300px; /* Aumenta a largura máxima do contêiner */
        width: 100%; /* Define a largura para 90% da tela, adaptando-se a telas menores */
        margin: 20px auto; /* Centraliza o contêiner */
        background: #fff;
        padding: 30px; /* Ajusta o padding para um espaçamento interno confortável */
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        border-radius: 8px; /* Deixa os cantos ligeiramente arredondados */
    }
    h2 {
        text-align: center;
        color: #333;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }
    table, th, td {
        border: 1px solid #ddd;
    }
    th, td {
        padding: 5px;
        text-align: center;
    }
    th {
        background-color: #4CAF50;
        color: white;
    }
    tr:nth-child(even) {
        background-color: #f2f2f2;
    }
    tr:hover {
        background-color: #ddd;
    }
    .btn-enviar-zap, .btn-limpar-log {
        background-color: #4CAF50;
        color: white;
        padding: 12px 20px;
        font-size: 16px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }
    .btn-enviar-zap:hover, .btn-limpar-log:hover {
        background-color: #45a049;
    }
    .btn-limpar-log {
        background-color: red;
    }
    .log-container {
        background-color: #f9f9f9;
        padding: 20px;
        border: 1px solid #ddd;
        border-radius: 5px;
        max-height: 300px;
        overflow-y: scroll;
        color: blue;
    }
    /* Estilos para o Modal */
    #modalConfirmacao {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }
    #modalConfirmacao .modal-content {
        background-color: #fff;
        padding: 20px;
        border-radius: 8px;
        max-width: 300px;
        margin: 100px auto;
        text-align: center;
    }
    #modalConfirmacao button {
        background-color: #4CAF50;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }
	    .pagination {
        display: flex;
        justify-content: center;
        margin: 20px 0;
        list-style: none;
        padding: 0;
    }

        .pagination li {
        margin: 0 5px;
    }

       .pagination a,
       .pagination strong {
        display: inline-block;
        padding: 8px 12px;
        text-decoration: none;
        border-radius: 5px;
        color: #333;
        background-color: #f2f2f2;
        border: 1px solid #ddd;
        transition: background-color 0.3s ease;
    }

        .pagination a:hover {
         background-color: #ddd;
    }

        .pagination strong {
         color: white;
         background-color: #4CAF50; /* Destaque para a página atual */
         font-weight: bold;
}
</style>

</head>
<body>

<?php include('../../topo.php'); ?>

<nav class="breadcrumb has-bullet-separator is-centered" aria-label="breadcrumbs">
    <ul>
        <li><a href="#"> ADDON</a></li>
        <a href="#" aria-current="page"> <?= $manifestTitle . " - V " . $manifestVersion; ?> </a>
    </ul>
</nav>

<!-- Botão para exibir o formulário de configurações -->
<div class="container">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <h2>Configurações de senha e IP</h2>
        <div style="display: flex; gap: 10px;">
            <button id="mostrarHorario" class="toggle-button" onclick="toggleSection('agendamentoForm')" style="background: none; border: none; cursor: pointer;">
                <img src="icon_agen.png" alt="Mostrar Horário" style="width: 30px; height: 30px;">
            </button>
            <button id="mostrarConfiguracoes" onclick="toggleSection('configForm')" style="background: none; border: none; cursor: pointer;">
                <img src="icon_config.png" alt="Mostrar Configurações" style="width: 30px; height: 30px;">
            </button>
        </div>
    </div>

<!-- Formulário de Configurações (oculto inicialmente) -->
<div id="configForm" style="margin-top: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; display: none;">

    <!-- Lista de Servidores -->
    <h3 style="text-align: center; color: #31708f;">Lista de Servidores</h3>
    <div id="serverList" style="margin-bottom: 20px;">
        <!-- Lista dinâmica de servidores será gerada aqui -->
    </div>

    <!-- Botão para Adicionar Novo Servidor -->
    <div style="text-align: center; margin-bottom: 20px;">
        <button id="addServerButton" onclick="toggleAddServerForm()" 
                style="font-size: 20px; color: white; background-color: #4CAF50; border: none; padding: 10px 20px; border-radius: 50%; cursor: pointer;">
            +
        </button>
    </div>

    <!-- Formulário para Adicionar Servidores (oculto inicialmente) -->
    <div id="addServerForm" style="display: none; background-color: #f0f8ff; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-top: 20px;">
        <h4 style="text-align: center; color: #31708f;">Adicionar Novo Servidor</h4>
        <form onsubmit="addServer(event)" style="display: flex; flex-direction: column; gap: 10px;">
            <input type="text" id="newServerIp" placeholder="SERVER (Exemplo: 192.168.3.250)" required 
                   style="padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
            <input type="text" id="newServerUser" placeholder="USER (Exemplo: admin)" required 
                   style="padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
            <input type="password" id="newServerPassword" placeholder="senha (Exemplo: admin)" required 
                   style="padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
            <button type="submit" 
                    style="padding: 10px; background-color: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer;">
                Salvar Servidor
            </button>
            <button type="button" onclick="toggleAddServerForm()" 
                    style="padding: 10px; background-color: #e74c3c; color: white; border: none; border-radius: 5px; cursor: pointer;">
                Cancelar
            </button>
        </form>
    </div>
</div>

<script>
const serverList = [];

function fetchServers() {
    fetch('load_servers.php') // Endpoint que retorna os servidores desencriptados
        .then(response => response.json())
        .then(data => {
            serverList.push(...data); // Carrega os servidores na lista
            renderServerList();
        })
        .catch(error => console.error('Erro ao carregar servidores:', error));
}

function toggleAddServerForm() {
    const form = document.getElementById('addServerForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function addServer(event) {
    event.preventDefault();
    const ip = document.getElementById('newServerIp').value;
    const user = document.getElementById('newServerUser').value;
    const password = document.getElementById('newServerPassword').value;

    // Adiciona o novo servidor à lista
    serverList.push({ ip, user, password });
    renderServerList();

    // Envia os servidores para salvar no arquivo
    saveServers();

    // Esconde o formulário e limpa os campos
    document.getElementById('addServerForm').reset();
    toggleAddServerForm();
}

function renderServerList() {
    const serverListDiv = document.getElementById('serverList');
    serverListDiv.innerHTML = ''; // Limpa a lista atual

    serverList.forEach((server, index) => {
        const serverItem = document.createElement('div');
        serverItem.style.display = 'flex';
        serverItem.style.flexDirection = 'column';
        serverItem.style.padding = '10px';
        serverItem.style.border = '1px solid #ddd';
        serverItem.style.borderRadius = '5px';
        serverItem.style.marginBottom = '10px';

        serverItem.innerHTML = `
            <div><strong>IP:</strong> ${server.ip}</div>
            <div><strong>User:</strong> ${server.user}</div>
            <div>
                <strong>Senha:</strong> 
                <input type="password" id="password-${index}" value="${server.password}" readonly
                       style="border: none; background: transparent; font-size: 1em; color: #333; width: auto;">
                <button onclick="togglePassword(${index})" 
                        style="margin-left: 10px; padding: 2px 5px; font-size: 12px; background-color: #4CAF50; color: white; border: none; border-radius: 3px; cursor: pointer;">
                    Mostrar
                </button>
            </div>
            <div style="margin-top: 10px;">
                <button onclick="editServer(${index})" 
                        style="padding: 5px 10px; background-color: orange; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Editar
                </button>
                <button onclick="removeServer(${index})" 
                        style="padding: 5px 10px; background-color: red; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Remover
                </button>
            </div>
        `;
        serverListDiv.appendChild(serverItem);
    });
}

function togglePassword(index) {
    const passwordField = document.getElementById(`password-${index}`);
    const toggleButton = passwordField.nextElementSibling; // Botão ao lado do campo

    if (passwordField.type === 'password') {
        passwordField.type = 'text'; // Exibe a senha
        toggleButton.textContent = 'Ocultar'; // Altera o texto do botão
    } else {
        passwordField.type = 'password'; // Oculta a senha
        toggleButton.textContent = 'Mostrar'; // Altera o texto do botão
    }
}

function editServer(index) {
    const server = serverList[index];

    // Cria um formulário de edição diretamente no item da lista
    const serverItem = document.getElementById(`serverList`).children[index];
    serverItem.innerHTML = `
        <form onsubmit="saveEdit(${index}, event)">
            <label><strong>IP:</strong></label>
            <input type="text" id="edit-ip-${index}" value="${server.ip}" required
                   style="margin-bottom: 10px; padding: 5px; width: 100%; border: 1px solid #ddd; border-radius: 5px;">
            <label><strong>User:</strong></label>
            <input type="text" id="edit-user-${index}" value="${server.user}" required
                   style="margin-bottom: 10px; padding: 5px; width: 100%; border: 1px solid #ddd; border-radius: 5px;">
            <label><strong>Senha:</strong></label>
            <input type="text" id="edit-password-${index}" value="${server.password}" required
                   style="margin-bottom: 10px; padding: 5px; width: 100%; border: 1px solid #ddd; border-radius: 5px;">
            <div style="display: flex; gap: 10px;">
                <button type="submit" 
                        style="padding: 5px 10px; background-color: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Salvar
                </button>
                <button type="button" onclick="renderServerList()"
                        style="padding: 5px 10px; background-color: gray; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Cancelar
                </button>
            </div>
        </form>
    `;
}

function saveEdit(index, event) {
    event.preventDefault();
    const ip = document.getElementById(`edit-ip-${index}`).value;
    const user = document.getElementById(`edit-user-${index}`).value;
    const password = document.getElementById(`edit-password-${index}`).value;

    // Atualiza o servidor na lista
    serverList[index] = { ip, user, password };
    renderServerList();

    // Atualiza o arquivo no backend
    saveServers();
}

function removeServer(index) {
    serverList.splice(index, 1); // Remove o servidor da lista
    renderServerList();

    // Atualiza o arquivo após remover
    saveServers();
}

function saveServers() {
    fetch('save_servers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(serverList)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Servidores salvos com sucesso.');
        } else {
            console.error('Erro ao salvar servidores:', data.error);
        }
    })
    .catch(error => console.error('Erro na solicitação:', error));
}

// Carrega os servidores na inicialização
document.addEventListener('DOMContentLoaded', fetchServers);



</script>




    <!-- Formulário de Agendamento -->
    <div id="agendamentoForm" class="config-section" style="display: none; max-width: 1200px; margin: 20px auto; padding: 25px; border-radius: 15px; background: #ffffff; box-shadow: 0px 8px 20px rgba(0, 0, 0, 0.15);">
        
        <h3 style="text-align: center; color: #4CAF50; font-size: 1.5em; font-weight: bold; margin-bottom: 20px;">Agendamento</h3>

        <div style="display: flex; justify-content: center; gap: 15px; margin-bottom: 20px;">
            <button form="scheduleForm" type="submit" style="display: flex; align-items: center; gap: 8px; padding: 10px 18px; font-size: 1em; font-weight: bold; color: white; background-color: #4CAF50; border: none; border-radius: 50px; cursor: pointer; transition: all 0.3s ease;">
                <i class="fa fa-save"></i> Salvar
            </button>
            
            <form method="post" style="margin: 0;">
                <input type="hidden" name="delete_schedule" value="1">
                <button type="submit" style="display: flex; align-items: center; gap: 8px; padding: 10px 18px; background-color: #e74c3c; color: white; font-size: 1em; font-weight: bold; border: none; border-radius: 50px; cursor: pointer; transition: all 0.3s ease;" onclick="return confirm('Tem certeza que deseja excluir o agendamento específico?');">
                    <i class="fa fa-trash"></i> Excluir
                </button>
            </form>
        </div>

        <form id="scheduleForm" method="post" style="display: flex; flex-direction: column; gap: 15px;">
            <label for="intervalo_minutos" style="font-size: 1.1em; color: #333; font-weight: 600;">Intervalo (min):</label>
            <input type="number" id="intervalo_minutos" name="intervalo_minutos" min="1" max="60" required placeholder="Minutos" style="padding: 12px; font-size: 1.1em; border: 1px solid #ddd; border-radius: 8px; background-color: #f8f8f8;">
        </form>

        <div class="cron-display" style="margin-top: 20px; padding: 15px; text-align: center; border-radius: 8px; background-color: #f7f9fb; border: 1px solid #ddd;">
            <strong style="color: #4CAF50; font-size: 1.1em;">Agendamento Atual:</strong><br>
            <?php echo obterAgendamentoAtual(); ?>
        </div>
    </div>
</div>

<script>
    function toggleSection(sectionId) {
        const section = document.getElementById(sectionId);
        section.style.display = section.style.display === 'none' ? 'block' : 'none';
    }

    function togglePasswordVisibility() {
        const senhaInput = document.getElementById('senha');
        senhaInput.type = senhaInput.type === 'password' ? 'text' : 'password';
    }
</script>


<div class="container">
    <form id="envioForm" style="display: flex; justify-content: center; gap: 15px; margin-top: 20px;">
        <!-- Botão Executar Script -->
        <button type="button" class="btn-enviar-zap" onclick="enviarRecibo()" style="padding: 10px 18px; font-size: 1em; font-weight: bold; color: white; background-color: #4CAF50; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s ease;">
            Executar Script
        </button>
        
        <!-- Botão Limpar Log -->
        <button type="submit" formaction="limpar_log.php" method="post" onclick="return confirm('Tem certeza que deseja limpar o log?');" class="btn-limpar-log" style="padding: 10px 18px; background-color: #e74c3c; color: white; font-size: 1em; font-weight: bold; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s ease;">
            Limpar Log
        </button>
    </form>
</div>
<div class="container" style="background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 5px; max-height: 300px; overflow-y: scroll; color: green;">
    <pre><?php
        $logFile = '/opt/mk-auth/dados/Mikrotik_Desativado/log.txt';
        if (file_exists($logFile)) {
            // Lê o conteúdo do arquivo e o divide em linhas
            $logContent = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            // Inverte a ordem das linhas para que as mais recentes fiquem no topo
            $logContent = array_reverse($logContent);
            
            // Formata cada linha do log
            foreach ($logContent as &$line) {
                $formattedLine = htmlspecialchars($line); // Escapa HTML por segurança

                // Destacar somente os números da data/hora em ciano escuro com negrito
                $formattedLine = preg_replace(
                    '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/',
                    '<span style="color: darkcyan; font-weight: bold;">[$1]</span>',
                    $formattedLine
                );

                // Destacar o IP em roxo com negrito
                $formattedLine = preg_replace(
                    '/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/',
                    '<span style="color:#00008B; font-weight: bold;">$1</span>',
                    $formattedLine
                );

                // Destacar "adicionado ao Mikrotik" em vermelho e "removido" em azul
                if (strpos($line, 'adicionado ao Mikrotik') !== false) {
                    $formattedLine = preg_replace(
                        '/(\w+ adicionado)/i', 
                        '<span style="color: red; font-weight: bold;">$1</span>', 
                        $formattedLine
                    );
                } elseif (strpos($line, 'removido do Mikrotik') !== false) {
                    $formattedLine = preg_replace(
                        '/(\w+ removido)/i', 
                        '<span style="color: blue; font-weight: bold;">$1</span>', 
                        $formattedLine
                    );
                }

                // Destacar "Desconectado '<...>'" em laranja
                $formattedLine = preg_replace(
                    "/(Desconectado '.*?' do Servidor \(.*?\))/",
                    '<span style="color: blue; font-weight: bold;">$1</span>',
                    $formattedLine
                );

                // Define o restante como verde com negrito
                $line = "<span style='color: green; font-weight: bold;'>" . $formattedLine . "</span>";
            }
            
            // Exibe o conteúdo formatado
            echo implode("\n", $logContent);
        } else {
            echo "<strong>Arquivo de log não encontrado.</strong>";
        }
    ?></pre>
</div>

<!-- Modal de Confirmação --> 
<div id="modalConfirmacao" style="display: none;">
    <div class="modal-content">
        <p>Executado com sucesso!</p>
        <button onclick="fecharModal()">OK</button>
    </div>
</div>

<?php include('../../baixo.php'); ?>

<script src="../../menu.js.hhvm"></script>
<script>
    function enviarRecibo() {
        $.post("Script_desativado.php", $('#envioForm').serialize())
            .done(function(response) {
                // Exibe o modal de confirmação em caso de sucesso
                $('#modalConfirmacao').fadeIn();
            })
            .fail(function() {
                // Também exibe o modal de confirmação em caso de erro
                $('#modalConfirmacao').fadeIn();
            });
    }

    function fecharModal() {
        $('#modalConfirmacao').fadeOut(function() {
            // Recarrega a página após o modal ser fechado
            location.reload();
        });
    }
</script>

</body>
</html>
