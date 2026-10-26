<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('PMQTS_DB_HOST') ?: 'localhost';
$user = getenv('PMQTS_DB_USER') ?: 'root';
$password = getenv('PMQTS_DB_PASSWORD') ?: '';
$dbname = getenv('PMQTS_DB_NAME') ?: 'pmqts';
$port = (int) (getenv('PMQTS_DB_PORT') ?: 3306);

try {
    $conn = new mysqli($host, $user, $password, $dbname, $port);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('PMQTS database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('The database is unavailable. Import database/schema.sql and verify config/db.php settings.');
}