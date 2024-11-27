<?php
$file_path = '/opt/mk-auth/dados/Mikrotik_Desativado/config.php';
$chave_criptografia = '3NyBm8aa54eg8jeE';

// Funções de criptografia
function encriptar($dados, $chave) {
    return openssl_encrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

function desencriptar($dados, $chave) {
    return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

// Recebe os servidores via POST
$input = file_get_contents('php://input');
$servers = json_decode($input, true);

if (!is_array($servers)) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

// Encripta os servidores antes de salvar
$servers_encrypted = array_map(function ($server) use ($chave_criptografia) {
    return [
        'ip' => encriptar($server['ip'], $chave_criptografia),
        'user' => encriptar($server['user'], $chave_criptografia),
        'password' => encriptar($server['password'], $chave_criptografia),
    ];
}, $servers);

// Salva no arquivo de configuração
$config_content = "<?php return " . var_export(['servers' => $servers_encrypted], true) . ";";
if (file_put_contents($file_path, $config_content)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar o arquivo']);
}
?>
