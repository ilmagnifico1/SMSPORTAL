<?php

class Connection {
    public static $instance = null;
    private $connection;

    private function __construct(){
        try {
            $config = $this->databaseConfig();
            $this->connection = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]
            );


		} catch(PDOException $e) {
            throw new RuntimeException('Impossibile connettersi al database SMS.', 0, $e);
		}
    }

    private function databaseConfig(): array {
        $configuredFile = trim((string)getenv('SMS_CONFIG_FILE'));
        $localFile = $configuredFile !== ''
            ? $configuredFile
            : dirname(__DIR__) . '/storage/config.local.php';
        if (!is_file($localFile)) {
            $localFile = __DIR__ . '/config.local.php';
        }
        $local = is_file($localFile) ? (array) require $localFile : [];
        $read = static function (string $environmentName, string $localKey, string $default = '') use ($local): string {
            $environmentValue = getenv($environmentName);
            if (is_string($environmentValue) && trim($environmentValue) !== '') {
                return trim($environmentValue);
            }
            return trim((string)($local[$localKey] ?? $default));
        };

        $config = [
            'host' => $read('SMS_DB_HOST', 'host', 'localhost'),
            'port' => (int)$read('SMS_DB_PORT', 'port', '3306'),
            'database' => $read('SMS_DB_NAME', 'database', 'sms'),
            'username' => $read('SMS_DB_USER', 'username'),
            'password' => $read('SMS_DB_PASSWORD', 'password'),
        ];
        if ($config['username'] === '' || $config['password'] === '') {
            throw new RuntimeException('Configurazione database mancante. Imposta SMS_DB_USER e SMS_DB_PASSWORD.');
        }
        if ($config['port'] < 1 || $config['port'] > 65535) {
            throw new RuntimeException('Porta database non valida.');
        }
        return $config;
    }

    public static function connect(){
        if(!isset(self::$instance))
            self::$instance = new Connection();

        return self::$instance;
    }

    public function getConn(){
        return $this->connection;
    }

}

?>
