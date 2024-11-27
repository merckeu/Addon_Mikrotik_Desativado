<?php
$file_path = '/opt/mk-auth/dados/Mikrotik_Desativado/config.php';
$chave_criptografia = '3NyBm8aa54eg8jeE';

// Funções de criptografia
function desencriptar($dados, $chave) {
    return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

// Lê o arquivo de configuração e decodifica os servidores
if (file_exists($file_path)) {
    $serverConfig = include($file_path);
    $servers_encrypted = $serverConfig['servers'] ?? [];

    // Desencripta os servidores para exibição
    $servers = array_map(function ($server) use ($chave_criptografia) {
        return [
            'ip' => desencriptar($server['ip'], $chave_criptografia),
            'user' => desencriptar($server['user'], $chave_criptografia),
            'password' => desencriptar($server['password'], $chave_criptografia), // Desencripta a senha
        ];
    }, $servers_encrypted);

    echo json_encode($servers);
} else {
    echo json_encode([]); // Nenhum servidor salvo
}
?>
