<?php

class AuthRateLimiter {
    private PDO $pdo;
    private int $maxAttempts;
    private int $windowSeconds;
    private int $lockSeconds;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Connection::connect()->getConn();
        $this->maxAttempts = max(3, (int)(getenv('SMS_LOGIN_MAX_ATTEMPTS') ?: 5));
        $this->windowSeconds = max(60, (int)(getenv('SMS_LOGIN_WINDOW_SECONDS') ?: 900));
        $this->lockSeconds = max(60, (int)(getenv('SMS_LOGIN_LOCK_SECONDS') ?: 900));
        $this->ensureTable();
    }

    public function isBlocked(string $ip, string $username): bool {
        $this->purgeExpired();
        $stmt = $this->pdo->prepare('SELECT COUNT(*), MAX(attempted_at) FROM auth_login_attempts WHERE ip_address = :ip AND username_hash = :username_hash AND attempted_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)');
        $stmt->bindValue(':ip', $this->normalizeIp($ip));
        $stmt->bindValue(':username_hash', $this->usernameHash($username));
        $stmt->bindValue(':window', $this->windowSeconds, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_NUM) ?: [0, null];
        if ((int)$row[0] < $this->maxAttempts || empty($row[1])) {
            return false;
        }
        return strtotime((string)$row[1]) + $this->lockSeconds > time();
    }

    public function recordFailure(string $ip, string $username): void {
        $stmt = $this->pdo->prepare('INSERT INTO auth_login_attempts (ip_address, username_hash) VALUES (:ip, :username_hash)');
        $stmt->execute([
            ':ip' => $this->normalizeIp($ip),
            ':username_hash' => $this->usernameHash($username),
        ]);
    }

    public function clear(string $ip, string $username): void {
        $stmt = $this->pdo->prepare('DELETE FROM auth_login_attempts WHERE ip_address = :ip AND username_hash = :username_hash');
        $stmt->execute([
            ':ip' => $this->normalizeIp($ip),
            ':username_hash' => $this->usernameHash($username),
        ]);
    }

    private function ensureTable(): void {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS auth_login_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            username_hash CHAR(64) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_auth_attempt_lookup (ip_address, username_hash, attempted_at),
            KEY idx_auth_attempt_time (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function purgeExpired(): void {
        if (random_int(1, 100) !== 1) {
            return;
        }
        $retention = max($this->windowSeconds, $this->lockSeconds) * 2;
        $stmt = $this->pdo->prepare('DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL :retention SECOND)');
        $stmt->bindValue(':retention', $retention, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function usernameHash(string $username): string {
        return hash('sha256', mb_strtolower(trim($username), 'UTF-8'));
    }

    private function normalizeIp(string $ip): string {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
