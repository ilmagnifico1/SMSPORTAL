<?php

$root = dirname(__DIR__);
$configFile = $root . '/storage/config.local.php';
if (!is_file($configFile)) {
    $configFile = $root . '/classes/config.local.php';
}
if (!is_file($configFile)) {
    fwrite(STDERR, "config.local.php non trovato; usare le variabili SMS_DB_* nel servizio.\n");
    exit(2);
}

$config = (array)require $configFile;
$host = trim((string)(getenv('SMS_DB_AUDIT_HOST') ?: ($config['host'] ?? 'localhost')));
$port = (int)($config['port'] ?? 3306);
$database = trim((string)($config['database'] ?? 'sms'));
$username = trim((string)($config['username'] ?? ''));
$password = (string)($config['password'] ?? '');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $account = (string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    $grants = array_map('strtoupper', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
    $grantText = implode("\n", $grants);
    $findings = [];
    if (str_starts_with(strtolower($account), 'root@')) $findings[] = 'L’applicazione usa un account root.';
    if (str_contains($grantText, 'ALL PRIVILEGES ON *.*')) $findings[] = 'L’account dispone di privilegi globali.';
    if (str_contains($grantText, 'GRANT OPTION')) $findings[] = 'L’account dispone di GRANT OPTION.';
    if ($host !== 'localhost' && $host !== '127.0.0.1' && $host !== '::1') $findings[] = 'Il controllo usa MySQL tramite rete; limitare la porta 3306 agli host necessari.';

    echo json_encode([
        'connected' => true,
        'database' => $database,
        'account_is_dedicated' => !str_starts_with(strtolower($account), 'root@') && !str_contains($grantText, 'ALL PRIVILEGES ON *.*'),
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($findings === [] ? 0 : 1);
} catch (Throwable $exception) {
    echo json_encode(['connected' => false, 'error' => get_class($exception)], JSON_PRETTY_PRINT), PHP_EOL;
    exit(2);
}
