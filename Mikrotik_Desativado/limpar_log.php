<?php
$logFile = '/opt/mk-auth/dados/Mikrotik_Desativado/log.txt';

if (file_exists($logFile)) {
    // Tenta limpar o conteúdo do arquivo de log
    if (is_writable($logFile)) {
        file_put_contents($logFile, ''); // Limpa o conteúdo do arquivo de log
        echo "<script>alert('Log limpo com sucesso!');</script>";
    } else {
        echo "<script>alert('Erro: Não foi possível limpar o log. Verifique as permissões do arquivo.');</script>";
    }
} else {
    echo "<script>alert('Erro: Arquivo de log não encontrado.');</script>";
}

// Redireciona para a página anterior de forma segura
$redirectUrl = isset($_SERVER['HTTP_REFERER']) ? htmlspecialchars($_SERVER['HTTP_REFERER'], ENT_QUOTES, 'UTF-8') : '/';
echo "<script>window.location.href = '$redirectUrl';</script>";
exit;
