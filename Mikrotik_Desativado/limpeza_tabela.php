<?php
// Configuração do banco de dados
$host = 'localhost';
$user = 'root';
$password = 'vertrigo';
$dbname = 'mkradius';

// Caminho do arquivo de log
$logFile = '/opt/mk-auth/dados/Mikrotik_Desativado/log.txt';

try {
    // Conexão com o banco de dados
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Comando SQL para selecionar registros antigos
    $sqlSelect = "SELECT * FROM log_mikro WHERE data_processamento < CURDATE() - INTERVAL 1 DAY";
    $stmtSelect = $pdo->query($sqlSelect);
    $oldRecords = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($oldRecords)) {
        // Comando SQL para deletar registros antigos
        $sqlDelete = "DELETE FROM log_mikro WHERE data_processamento < CURDATE() - INTERVAL 1 DAY";
        $stmtDelete = $pdo->prepare($sqlDelete);
        $stmtDelete->execute();

        // Registro de log para cada registro deletado
        foreach ($oldRecords as $record) {
            //$logMessage = "[" . date('Y-m-d H:i:s') . "] Registro deletado: Login=" . $record['login'] . ", Data=" . $record['data_processamento'] . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }

        echo "Registros mais antigos que 1 dia foram removidos com sucesso.";
    } else {
        // Log quando não há registros para remover
        //$logMessage = "[" . date('Y-m-d H:i:s') . "] Nenhum registro antigo encontrado para remoção.\n";
        //file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
} catch (PDOException $e) {
    // Registro de erro no log
    $errorMessage = "[" . date('Y-m-d H:i:s') . "] Erro ao remover registros: " . $e->getMessage() . "\n";
    file_put_contents($logFile, $errorMessage, FILE_APPEND);

    echo "Erro ao remover registros: " . $e->getMessage();
}
?>
