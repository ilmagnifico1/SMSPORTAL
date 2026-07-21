<?php

class SystemLogger {
    private PDO $pdo;
    private array $config;
    private static bool $booted = false;
    private static bool $writing = false;
    private static bool $tablesReady = false;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Connection::connect()->getConn();
        $configFile = __DIR__ . '/../inc/system_log_config.php';
        $this->config = file_exists($configFile) ? (array)require $configFile : [];
        $this->ensureTables();
    }

    public static function boot(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            self::record('error', 'system', 'php.error', $message, [
                'severity' => $severity,
                'file' => $file,
                'line' => $line,
            ]);
            return false;
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!$error || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            self::record('critical', 'system', 'php.fatal', (string)$error['message'], [
                'severity' => (int)$error['type'],
                'file' => (string)$error['file'],
                'line' => (int)$error['line'],
            ]);
        });
    }

    public static function record(string $level, string $category, string $event, string $message, array $context = [], string $userName = ''): void {
        if (self::$writing) {
            return;
        }

        self::$writing = true;
        try {
            $logger = new self();
            $logger->write($level, $category, $event, $message, $context, $userName);
        } catch (Throwable $exception) {
            self::writeFallback($level, $category, $event, $message, $context, $userName, $exception->getMessage());
        } finally {
            self::$writing = false;
        }
    }

    public static function clientIp(): string {
        require_once __DIR__ . '/ClientIpResolver.php';
        return ClientIpResolver::resolve();
    }

    public static function serverIp(): string {
        foreach (['SERVER_ADDR', 'LOCAL_ADDR'] as $serverKey) {
            $ip = trim((string)($_SERVER[$serverKey] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }
        $hostName = gethostname();
        if (is_string($hostName) && $hostName !== '') {
            $resolved = gethostbyname($hostName);
            if (filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                return $resolved;
            }
        }
        return 'Non disponibile';
    }

    public static function proxyIp(): string {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'Non disponibile';
    }

    public static function portalHost(): string {
        $configuredHost = trim((string)getenv('SMS_PUBLIC_HOST'));
        if ($configuredHost === '') {
            $configuredFile = trim((string)getenv('SMS_CONFIG_FILE'));
            $localConfigFile = $configuredFile !== ''
                ? $configuredFile
                : dirname(__DIR__) . '/storage/config.local.php';
            if (!is_file($localConfigFile)) {
                $localConfigFile = __DIR__ . '/config.local.php';
            }
            if (is_file($localConfigFile)) {
                $local = (array)require $localConfigFile;
                $configuredHost = trim((string)($local['public_host'] ?? ''));
            }
        }
        $rawHost = $configuredHost !== '' ? $configuredHost : trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $host = parse_url('http://' . $rawHost, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || strlen($host) > 253) {
            return 'Non disponibile';
        }
        return strtolower($host);
    }

    public static function publicPortalIp(): string {
        $configuredIp = trim((string)getenv('SMS_PUBLIC_IP'));
        if (filter_var($configuredIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return $configuredIp;
        }
        $host = self::portalHost();
        if ($host === 'Non disponibile') {
            return 'Non disponibile';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return $host;
        }
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return 'Non disponibile';
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
        try {
            $pdo = Connection::connect()->getConn();
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'portal_public_ip_cache' LIMIT 1");
            $stmt->execute();
            $cached = json_decode((string)$stmt->fetchColumn(), true);
            if (is_array($cached)
                && (int)($cached['expires_at'] ?? 0) > time()
                && filter_var((string)($cached['ip'] ?? ''), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return (string)$cached['ip'];
            }

            $detectedIp = self::fetchPublicIpWithoutDns();
            if ($detectedIp !== '') {
                $payload = json_encode(['ip' => $detectedIp, 'expires_at' => time() + 21600], JSON_UNESCAPED_SLASHES) ?: '{}';
                $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('portal_public_ip_cache', :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([':value' => $payload]);
                return $detectedIp;
            }
        } catch (Throwable $exception) {
            return 'Non disponibile';
        }
        return 'Non disponibile';
    }

    private static function fetchPublicIpWithoutDns(): string {
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init('https://1.1.1.1/cdn-cgi/trace');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SMS-Portal-Public-IP/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300 || !preg_match('/^ip=([^\r\n]+)$/m', $body, $matches)) {
            return '';
        }
        $ip = trim((string)$matches[1]);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ? $ip : '';
    }

    public function write(string $level, string $category, string $event, string $message, array $context = [], string $userName = ''): void {
        $client = $this->getClientDetails();
        $geo = $this->resolveGeo((string)$client['ip']);
        $userName = $userName !== '' ? $userName : (string)($_SESSION['logged'] ?? '');
        $sessionCompanyId = function_exists('current_company_id') ? current_company_id() : 0;
        $sessionTeamId = function_exists('current_team_id') ? current_team_id() : 0;
        $canSelectTenant = empty($_SESSION['logged']) || (function_exists('is_super_admin') && is_super_admin());
        $companyId = $canSelectTenant ? (int)($context['company_id'] ?? $sessionCompanyId) : $sessionCompanyId;
        $teamId = $canSelectTenant ? (int)($context['team_id'] ?? $sessionTeamId) : $sessionTeamId;
        $context['_request'] = [
            'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
            'forwarded_proto' => (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'remote_addr' => (string)$client['remote_addr'],
            'via_trusted_proxy' => (bool)$client['via_proxy'],
        ];

        $stmt = $this->pdo->prepare('INSERT INTO system_logs
            (company_id, team_id, level, category, event_name, message, user_name, ip_address, country_code, country_name, flag, request_method, request_uri, proxy_chain, context_json)
            VALUES (:company_id, :team_id, :level, :category, :event_name, :message, :user_name, :ip_address, :country_code, :country_name, :flag, :request_method, :request_uri, :proxy_chain, :context_json)');
        $stmt->execute([
            ':company_id' => $companyId,
            ':team_id' => $teamId,
            ':level' => $this->normalizeToken($level, 'info'),
            ':category' => $this->normalizeToken($category, 'system'),
            ':event_name' => substr(trim($event), 0, 120),
            ':message' => trim($message),
            ':user_name' => substr(trim($userName), 0, 100),
            ':ip_address' => substr((string)$client['ip'], 0, 45),
            ':country_code' => substr((string)$geo['country_code'], 0, 8),
            ':country_name' => substr((string)$geo['country_name'], 0, 100),
            ':flag' => substr((string)$geo['flag'], 0, 16),
            ':request_method' => substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10),
            ':request_uri' => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 1000),
            ':proxy_chain' => substr((string)$client['proxy_chain'], 0, 1000),
            ':context_json' => json_encode(self::sanitizeContext($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);
    }

    public function getLogs(array $filters = [], int $limit = 500): array {
        $query = 'SELECT * FROM system_logs WHERE 1=1';
        $params = [];

        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        } elseif (!empty($filters['company_id'])) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = (int)$filters['company_id'];
        }

        foreach (['level', 'category'] as $field) {
            if (!empty($filters[$field]) && $filters[$field] !== 'all') {
                $query .= ' AND ' . $field . ' = :' . $field;
                $params[':' . $field] = (string)$filters[$field];
            }
        }
        if (!empty($filters['search'])) {
            $query .= ' AND (message LIKE :search OR event_name LIKE :search OR user_name LIKE :search OR ip_address LIKE :search OR country_name LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }

        $query .= ' ORDER BY id DESC LIMIT ' . max(1, min($limit, 1000));
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats(array $filters = []): array {
        $query = "SELECT COUNT(*) AS total,
            SUM(level IN ('error', 'critical')) AS errors,
            SUM(category = 'auth') AS access_events,
            SUM(category IN ('sms', 'provider')) AS delivery_events
            FROM system_logs";
        $params = [];
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $query .= ' WHERE company_id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        } elseif (!empty($filters['company_id'])) {
            $query .= ' WHERE company_id = :company_id';
            $params[':company_id'] = (int)$filters['company_id'];
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'errors' => (int)($row['errors'] ?? 0),
            'access_events' => (int)($row['access_events'] ?? 0),
            'delivery_events' => (int)($row['delivery_events'] ?? 0),
        ];
    }

    public function getFilterOptions(array $filters = []): array {
        $where = '';
        $params = [];
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $where = ' WHERE company_id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        } elseif (!empty($filters['company_id'])) {
            $where = ' WHERE company_id = :company_id';
            $params[':company_id'] = (int)$filters['company_id'];
        }
        $levelStmt = $this->pdo->prepare('SELECT DISTINCT level FROM system_logs' . $where . ' ORDER BY level');
        $levelStmt->execute($params);
        $categoryStmt = $this->pdo->prepare('SELECT DISTINCT category FROM system_logs' . $where . ' ORDER BY category');
        $categoryStmt->execute($params);
        return [
            'levels' => $levelStmt->fetchAll(PDO::FETCH_COLUMN),
            'categories' => $categoryStmt->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    private function ensureTables(): void {
        if (self::$tablesReady) {
            return;
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL DEFAULT 1,
            team_id INT NOT NULL DEFAULT 1,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            category VARCHAR(50) NOT NULL DEFAULT 'system',
            event_name VARCHAR(120) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            user_name VARCHAR(100) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            country_code VARCHAR(8) NOT NULL DEFAULT '',
            country_name VARCHAR(100) NOT NULL DEFAULT '',
            flag VARCHAR(16) NOT NULL DEFAULT '',
            request_method VARCHAR(10) NOT NULL DEFAULT '',
            request_uri VARCHAR(1000) NOT NULL DEFAULT '',
            proxy_chain VARCHAR(1000) NOT NULL DEFAULT '',
            context_json MEDIUMTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_system_logs_level (level),
            KEY idx_system_logs_category (category),
            KEY idx_system_logs_created_at (created_at),
            KEY idx_system_logs_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (
            ip_address VARCHAR(45) PRIMARY KEY,
            country_code VARCHAR(8) NOT NULL DEFAULT '',
            country_name VARCHAR(100) NOT NULL DEFAULT '',
            flag VARCHAR(16) NOT NULL DEFAULT '',
            expires_at DATETIME NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->ensureColumn('system_logs', 'company_id', "ALTER TABLE system_logs ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id");
        $this->ensureColumn('system_logs', 'team_id', "ALTER TABLE system_logs ADD COLUMN team_id INT NOT NULL DEFAULT 1 AFTER company_id");
        self::$tablesReady = true;
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $this->pdo->exec($alterSql);
        }
    }

    private function getClientDetails(): array {
        require_once __DIR__ . '/ClientIpResolver.php';
        return ClientIpResolver::details($_SERVER, (array)($this->config['trusted_proxies'] ?? []));
    }

    private function resolveGeo(string $ip): array {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['country_code' => 'LOCAL', 'country_name' => 'Rete locale', 'flag' => '🏠'];
        }

        $stmt = $this->pdo->prepare('SELECT country_code, country_name, flag FROM ip_geo_cache WHERE ip_address = :ip AND expires_at > NOW() LIMIT 1');
        $stmt->execute([':ip' => $ip]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            return $cached;
        }

        $geo = ['country_code' => '', 'country_name' => 'Sconosciuto', 'flag' => '🌐'];
        if (!empty($this->config['geo_enabled'])) {
            $endpoint = sprintf((string)($this->config['geo_endpoint'] ?? ''), rawurlencode($ip));
            $payload = $this->fetchJson($endpoint);
            if (is_array($payload) && !empty($payload['success'])) {
                $code = strtoupper(substr(trim((string)($payload['country_code'] ?? '')), 0, 2));
                $geo = [
                    'country_code' => $code,
                    'country_name' => trim((string)($payload['country'] ?? 'Sconosciuto')),
                    'flag' => $this->countryCodeToFlag($code),
                ];
            }
        }

        $days = max(1, (int)($this->config['geo_cache_days'] ?? 30));
        $cache = $this->pdo->prepare('INSERT INTO ip_geo_cache (ip_address, country_code, country_name, flag, expires_at)
            VALUES (:ip, :country_code, :country_name, :flag, DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY))
            ON DUPLICATE KEY UPDATE country_code = VALUES(country_code), country_name = VALUES(country_name), flag = VALUES(flag), expires_at = VALUES(expires_at)');
        $cache->execute([
            ':ip' => $ip,
            ':country_code' => $geo['country_code'],
            ':country_name' => $geo['country_name'],
            ':flag' => $geo['flag'],
        ]);
        return $geo;
    }

    private function fetchJson(string $url): ?array {
        if ($url === '') {
            return null;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'SMS-System-Logger/1.0',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $status < 200 || $status >= 300) {
                return null;
            }
        } else {
            $context = stream_context_create(['http' => ['timeout' => 2, 'user_agent' => 'SMS-System-Logger/1.0']]);
            $body = @file_get_contents($url, false, $context);
            if (!is_string($body)) {
                return null;
            }
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function countryCodeToFlag(string $code): string {
        if (strlen($code) !== 2 || !ctype_alpha($code)) {
            return '🌐';
        }
        $flag = '';
        foreach (str_split(strtoupper($code)) as $letter) {
            $flag .= html_entity_decode('&#' . (127397 + ord($letter)) . ';', ENT_NOQUOTES, 'UTF-8');
        }
        return $flag;
    }

    private static function sanitizeContext(array $context): array {
        $sensitive = ['password', 'psw', 'token', 'csrf_token', 'api_key', 'authorization', 'cookie'];
        $result = [];
        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string)$key);
            if (in_array($normalizedKey, $sensitive, true)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = self::sanitizeContext($value);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = is_string($value) && strlen($value) > 8000 ? substr($value, 0, 8000) . '…' : $value;
            }
        }
        return $result;
    }

    private function normalizeToken(string $value, string $fallback): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]/', '', $value) ?: '';
        return substr($value !== '' ? $value : $fallback, 0, 50);
    }

    private static function writeFallback(string $level, string $category, string $event, string $message, array $context, string $userName, string $loggerError): void {
        $directory = __DIR__ . '/../logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $row = [
            'created_at' => date('c'),
            'level' => $level,
            'category' => $category,
            'event' => $event,
            'message' => $message,
            'user_name' => $userName !== '' ? $userName : (string)($_SESSION['logged'] ?? ''),
            'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'context' => self::sanitizeContext($context),
            'logger_error' => $loggerError,
        ];
        @file_put_contents($directory . '/system_fallback.log', (json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
