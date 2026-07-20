<?php

class AppFirewall {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Connection::connect()->getConn();
        $this->ensureTables();
    }

    private function ensureTables(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS firewall_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            ip_ranges TEXT NOT NULL,
            super_admin_only TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_firewall_rules_company (company_id),
            KEY idx_firewall_rules_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->ensureColumn('firewall_rules', 'super_admin_only', 'ALTER TABLE firewall_rules ADD COLUMN super_admin_only TINYINT(1) NOT NULL DEFAULT 0 AFTER ip_ranges');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS firewall_rule_users (
            rule_id INT NOT NULL,
            user_id INT NOT NULL,
            PRIMARY KEY (rule_id, user_id),
            KEY idx_firewall_rule_users_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS firewall_blocked_ips (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            country_code VARCHAR(8) NOT NULL DEFAULT '',
            country_name VARCHAR(100) NOT NULL DEFAULT 'Sconosciuto',
            flag VARCHAR(16) NOT NULL DEFAULT '🌐',
            user_id INT NOT NULL DEFAULT 0,
            user_name VARCHAR(100) NOT NULL DEFAULT '',
            company_id INT NOT NULL DEFAULT 0,
            request_uri VARCHAR(1000) NOT NULL DEFAULT '',
            user_agent VARCHAR(500) NOT NULL DEFAULT '',
            attempt_count INT NOT NULL DEFAULT 1,
            first_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_firewall_blocked_ip (ip_address),
            KEY idx_firewall_blocked_last_attempt (last_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS firewall_blocked_ip_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            blocked_ip_id BIGINT NOT NULL,
            request_uri VARCHAR(1000) NOT NULL DEFAULT '',
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_firewall_attempts_blocked_ip (blocked_ip_id),
            KEY idx_firewall_attempts_date (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS firewall_access_requests (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            blocked_ip_id BIGINT NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL,
            user_agent VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            company_id INT NOT NULL DEFAULT 0,
            rule_id BIGINT NOT NULL DEFAULT 0,
            reviewed_by INT NOT NULL DEFAULT 0,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_firewall_requests_status (status),
            KEY idx_firewall_requests_ip (ip_address),
            KEY idx_firewall_requests_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->ensureColumn('firewall_access_requests', 'rule_id', 'ALTER TABLE firewall_access_requests ADD COLUMN rule_id BIGINT NOT NULL DEFAULT 0 AFTER company_id');
        $this->pdo->exec("UPDATE firewall_access_requests far
            SET far.rule_id = COALESCE((SELECT MAX(fr.id) FROM firewall_rules fr WHERE fr.company_id = far.company_id AND fr.ip_ranges = far.ip_address AND fr.name LIKE 'Richiesta accesso · %'), 0)
            WHERE far.status = 'approved' AND far.rule_id = 0");
        $this->pdo->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('firewall_enabled', '0')");
    }

    public function isEnabled(): bool {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'firewall_enabled' LIMIT 1");
        $stmt->execute();
        return (string)$stmt->fetchColumn() === '1';
    }

    public function setEnabled(bool $enabled): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        if ($enabled) {
            $ip = class_exists('SystemLogger') ? SystemLogger::clientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
            if (!$this->matchesRule(function_exists('current_user_id') ? current_user_id() : 0, function_exists('current_company_id') ? current_company_id() : 0, $ip)) {
                return false;
            }
        }
        $stmt = $this->pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('firewall_enabled', :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        return $stmt->execute([':value' => $enabled ? '1' : '0']);
    }

    public function isAllowed(int $userId, int $companyId, string $ip): bool {
        if (!$this->isEnabled()) {
            return true;
        }
        return $this->matchesRule($userId, $companyId, $ip);
    }

    public function isIpAuthorizedByAnyRule(string $ip): bool {
        if (!$this->isEnabled()) { return true; }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) { return false; }
        $stmt = $this->pdo->query('SELECT ip_ranges FROM firewall_rules WHERE active = 1');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ranges) {
            foreach ($this->parseRanges((string)$ranges) as $range) {
                if ($this->ipInCidr($ip, $range)) { return true; }
            }
        }
        return false;
    }

    public function isBlockedIp(string $ip): bool {
        if ($this->isBypassedIp($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) { return false; }
        $stmt = $this->pdo->prepare('SELECT 1 FROM firewall_blocked_ips WHERE ip_address = :ip LIMIT 1');
        $stmt->execute([':ip' => $ip]);
        return (bool)$stmt->fetchColumn();
    }

    public function recordDeniedAttempt(int $userId, int $companyId, string $userName, string $ip, string $requestUri = '', string $userAgent = ''): bool {
        if ($this->isBypassedIp($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) { return false; }
        $geoStmt = $this->pdo->prepare('SELECT country_code, country_name, flag FROM ip_geo_cache WHERE ip_address = :ip ORDER BY expires_at DESC LIMIT 1');
        $geoStmt->execute([':ip' => $ip]);
        $geo = $geoStmt->fetch(PDO::FETCH_ASSOC) ?: ['country_code' => '', 'country_name' => 'Sconosciuto', 'flag' => '🌐'];
        $stmt = $this->pdo->prepare('INSERT INTO firewall_blocked_ips
            (ip_address, country_code, country_name, flag, user_id, user_name, company_id, request_uri, user_agent)
            VALUES (:ip, :country_code, :country_name, :flag, :user_id, :user_name, :company_id, :request_uri, :user_agent)
            ON DUPLICATE KEY UPDATE country_code = VALUES(country_code), country_name = VALUES(country_name), flag = VALUES(flag), user_id = IF(VALUES(user_id) > 0, VALUES(user_id), user_id), user_name = IF(VALUES(user_name) <> "", VALUES(user_name), user_name), company_id = IF(VALUES(company_id) > 0, VALUES(company_id), company_id), request_uri = VALUES(request_uri), user_agent = VALUES(user_agent), attempt_count = attempt_count + 1, last_attempt_at = CURRENT_TIMESTAMP');
        $stored = $stmt->execute([
            ':ip' => $ip,
            ':country_code' => substr((string)$geo['country_code'], 0, 8),
            ':country_name' => substr((string)$geo['country_name'], 0, 100),
            ':flag' => substr((string)$geo['flag'], 0, 16),
            ':user_id' => $userId,
            ':user_name' => substr(trim($userName), 0, 100),
            ':company_id' => $companyId,
            ':request_uri' => substr($requestUri, 0, 1000),
            ':user_agent' => substr($userAgent, 0, 500),
        ]);
        if ($stored) {
            try {
                $idStmt = $this->pdo->prepare('SELECT id FROM firewall_blocked_ips WHERE ip_address = :ip LIMIT 1');
                $idStmt->execute([':ip' => $ip]);
                $blockedIpId = (int)$idStmt->fetchColumn();
                if ($blockedIpId > 0) {
                    $attemptStmt = $this->pdo->prepare('INSERT INTO firewall_blocked_ip_attempts (blocked_ip_id, request_uri) VALUES (:blocked_ip_id, :request_uri)');
                    $attemptStmt->execute([
                        ':blocked_ip_id' => $blockedIpId,
                        ':request_uri' => substr($requestUri, 0, 1000),
                    ]);
                }
            } catch (Throwable $exception) {
                // Il blocco deve restare efficace anche se lo storico dettagliato non è scrivibile.
            }
        }
        return $stored;
    }

    public function getBlockedIps(): array {
        if (!(function_exists('is_super_admin') && is_super_admin())) { return []; }
        $blockedIps = $this->pdo->query('SELECT fbi.*, c.name AS company_name FROM firewall_blocked_ips fbi LEFT JOIN companies c ON c.id = fbi.company_id ORDER BY fbi.last_attempt_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        if (!$blockedIps) { return []; }
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $blockedIps);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $attemptStmt = $this->pdo->prepare("SELECT blocked_ip_id, request_uri FROM firewall_blocked_ip_attempts WHERE blocked_ip_id IN ($placeholders) ORDER BY attempted_at DESC, id DESC");
        $attemptStmt->execute($ids);
        $pagesByIp = [];
        foreach ($attemptStmt->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
            $blockedIpId = (int)$attempt['blocked_ip_id'];
            $page = trim((string)$attempt['request_uri']);
            if ($page !== '' && !in_array($page, $pagesByIp[$blockedIpId] ?? [], true)) {
                $pagesByIp[$blockedIpId][] = $page;
            }
        }
        foreach ($blockedIps as &$blockedIp) {
            $id = (int)$blockedIp['id'];
            $fallback = trim((string)$blockedIp['request_uri']);
            $blockedIp['requested_pages'] = $pagesByIp[$id] ?? ($fallback !== '' ? [$fallback] : []);
            // Compatibilità con la tabella esistente: mostra tutte le pagine nella stessa cella.
            $blockedIp['request_uri'] = $blockedIp['requested_pages']
                ? implode(' · ', $blockedIp['requested_pages'])
                : '';
        }
        unset($blockedIp);
        return $blockedIps;
    }

    public function unblockIp(int $id): bool {
        if (!(function_exists('is_super_admin') && is_super_admin()) || $id <= 0) { return false; }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $attemptStmt = $this->pdo->prepare('DELETE FROM firewall_blocked_ip_attempts WHERE blocked_ip_id = :id');
            $attemptStmt->execute([':id' => $id]);
            $stmt = $this->pdo->prepare('DELETE FROM firewall_blocked_ips WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $deleted = $stmt->rowCount() === 1;
            if ($ownsTransaction) { $this->pdo->commit(); }
            return $deleted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return false;
        }
    }

    public function submitAccessRequest(string $ip, string $firstName, string $lastName, string $email, string $userAgent = ''): bool {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $email = strtolower(trim($email));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $firstName === '' || $lastName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $blockedStmt = $this->pdo->prepare('SELECT id FROM firewall_blocked_ips WHERE ip_address = :ip LIMIT 1');
        $blockedStmt->execute([':ip' => $ip]);
        $blockedIpId = (int)$blockedStmt->fetchColumn();
        if ($blockedIpId <= 0) {
            // La pagina di diniego può essere rimasta aperta dopo una pulizia manuale
            // dei blocchi. Se l'IP è ancora fuori whitelist, ricrea il blocco e
            // collega comunque la richiesta senza obbligare l'utente a ripassare dal login.
            if ($this->isIpAuthorizedByAnyRule($ip)) { return false; }
            $this->recordDeniedAttempt(0, 0, '', $ip, (string)($_SERVER['REQUEST_URI'] ?? '/index.php?route=access-denied'), $userAgent);
            $blockedStmt->execute([':ip' => $ip]);
            $blockedIpId = (int)$blockedStmt->fetchColumn();
            if ($blockedIpId <= 0) { return false; }
        }
        $pendingStmt = $this->pdo->prepare("SELECT id FROM firewall_access_requests WHERE ip_address = :ip AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $pendingStmt->execute([':ip' => $ip]);
        $pendingId = (int)$pendingStmt->fetchColumn();
        $params = [
            ':blocked_ip_id' => $blockedIpId,
            ':ip' => $ip,
            ':first_name' => substr($firstName, 0, 100),
            ':last_name' => substr($lastName, 0, 100),
            ':email' => substr($email, 0, 190),
            ':user_agent' => substr($userAgent, 0, 500),
        ];
        if ($pendingId > 0) {
            $params[':id'] = $pendingId;
            $stmt = $this->pdo->prepare("UPDATE firewall_access_requests SET blocked_ip_id = :blocked_ip_id, first_name = :first_name, last_name = :last_name, email = :email, user_agent = :user_agent, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND ip_address = :ip AND status = 'pending'");
            return $stmt->execute($params);
        }
        $stmt = $this->pdo->prepare("INSERT INTO firewall_access_requests (blocked_ip_id, ip_address, first_name, last_name, email, user_agent) VALUES (:blocked_ip_id, :ip, :first_name, :last_name, :email, :user_agent)");
        return $stmt->execute($params);
    }

    public function getAccessRequests(): array {
        if (!(function_exists('is_super_admin') && is_super_admin())) { return []; }
        $requests = $this->pdo->query("SELECT far.*, c.name AS company_name, u.username AS reviewer_name, fbi.country_code, fbi.country_name, fbi.flag
            FROM firewall_access_requests far
            LEFT JOIN companies c ON c.id = far.company_id
            LEFT JOIN utenti u ON u.id = far.reviewed_by
            LEFT JOIN firewall_blocked_ips fbi ON fbi.id = far.blocked_ip_id
            ORDER BY CASE far.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, far.created_at DESC
            LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $ruleIds = array_values(array_unique(array_filter(array_map(static fn(array $request): int => (int)$request['rule_id'], $requests))));
        $usersByRule = [];
        if ($ruleIds) {
            $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
            $stmt = $this->pdo->prepare("SELECT rule_id, user_id FROM firewall_rule_users WHERE rule_id IN ($placeholders)");
            $stmt->execute($ruleIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usersByRule[(int)$row['rule_id']][] = (int)$row['user_id'];
            }
        }
        foreach ($requests as &$request) {
            $request['authorized_user_ids'] = $usersByRule[(int)$request['rule_id']] ?? [];
        }
        unset($request);
        return $requests;
    }

    public function reviewAccessRequest(int $requestId, string $decision, int $companyId = 0, array $userIds = []): bool {
        if (!(function_exists('is_super_admin') && is_super_admin()) || $requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM firewall_access_requests WHERE id = :id AND status = 'pending' LIMIT 1");
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) { return false; }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $ruleId = 0;
            if ($decision === 'approve') {
                $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
                if ($companyId <= 0 || !$userIds) { throw new RuntimeException('Azienda e utenti sono obbligatori.'); }
                $name = substr('Richiesta accesso · ' . trim((string)$request['first_name'] . ' ' . (string)$request['last_name']), 0, 150);
                if (!$this->saveRule([
                    'company_id' => $companyId,
                    'name' => $name,
                    'ip_ranges' => (string)$request['ip_address'],
                    'user_ids' => $userIds,
                    'active' => 1,
                ])) {
                    throw new RuntimeException('Impossibile creare la regola firewall.');
                }
                $ruleStmt = $this->pdo->prepare('SELECT id FROM firewall_rules WHERE company_id = :company_id AND name = :name AND ip_ranges = :ip ORDER BY id DESC LIMIT 1');
                $ruleStmt->execute([':company_id' => $companyId, ':name' => $name, ':ip' => (string)$request['ip_address']]);
                $ruleId = (int)$ruleStmt->fetchColumn();
                if ($ruleId <= 0) { throw new RuntimeException('Regola firewall non trovata dopo la creazione.'); }
                $blockedIpId = (int)$request['blocked_ip_id'];
                if ($blockedIpId > 0 && $this->isBlockedIp((string)$request['ip_address']) && !$this->unblockIp($blockedIpId)) {
                    throw new RuntimeException('Impossibile rimuovere il blocco IP.');
                }
            }
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $update = $this->pdo->prepare('UPDATE firewall_access_requests SET status = :status, company_id = :company_id, rule_id = :rule_id, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id');
            $update->execute([
                ':status' => $status,
                ':company_id' => $decision === 'approve' ? $companyId : 0,
                ':rule_id' => $decision === 'approve' ? $ruleId : 0,
                ':reviewed_by' => function_exists('current_user_id') ? current_user_id() : 0,
                ':id' => $requestId,
            ]);
            if ($ownsTransaction) { $this->pdo->commit(); }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return false;
        }
    }

    public function updateApprovedAccessRequest(int $requestId, int $companyId, array $userIds): bool {
        if (!(function_exists('is_super_admin') && is_super_admin()) || $requestId <= 0 || $companyId <= 0) { return false; }
        $stmt = $this->pdo->prepare("SELECT * FROM firewall_access_requests WHERE id = :id AND status = 'approved' LIMIT 1");
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        $ruleId = (int)($request['rule_id'] ?? 0);
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$request || $ruleId <= 0 || !$userIds) { return false; }
        $name = substr('Richiesta accesso · ' . trim((string)$request['first_name'] . ' ' . (string)$request['last_name']), 0, 150);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            if (!$this->saveRule([
                'id' => $ruleId,
                'company_id' => $companyId,
                'name' => $name,
                'ip_ranges' => (string)$request['ip_address'],
                'user_ids' => $userIds,
                'active' => 1,
            ])) { throw new RuntimeException('Aggiornamento regola fallito.'); }
            $update = $this->pdo->prepare('UPDATE firewall_access_requests SET company_id = :company_id, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id');
            $update->execute([':company_id' => $companyId, ':reviewed_by' => function_exists('current_user_id') ? current_user_id() : 0, ':id' => $requestId]);
            if ($ownsTransaction) { $this->pdo->commit(); }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return false;
        }
    }

    public function deleteApprovedAccessRequest(int $requestId): bool {
        if (!(function_exists('is_super_admin') && is_super_admin()) || $requestId <= 0) { return false; }
        $stmt = $this->pdo->prepare("SELECT * FROM firewall_access_requests WHERE id = :id AND status = 'approved' LIMIT 1");
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) { return false; }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $ruleId = (int)$request['rule_id'];
            if ($ruleId > 0 && !$this->deleteRule($ruleId)) { throw new RuntimeException('Eliminazione regola fallita.'); }
            $delete = $this->pdo->prepare('DELETE FROM firewall_access_requests WHERE id = :id');
            $delete->execute([':id' => $requestId]);
            if ($delete->rowCount() !== 1) { throw new RuntimeException('Eliminazione richiesta fallita.'); }
            $ip = (string)$request['ip_address'];
            if (!$this->isIpAuthorizedByAnyRule($ip)) {
                $this->recordDeniedAttempt(0, 0, '', $ip, '/access-revoked', 'Accesso revocato dal Super Admin');
            }
            if ($ownsTransaction) { $this->pdo->commit(); }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return false;
        }
    }

    private function matchesRule(int $userId, int $companyId, string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $userId <= 0) {
            return false;
        }
        $roleStmt = $this->pdo->prepare('SELECT role FROM utenti WHERE id = :user_id LIMIT 1');
        $roleStmt->execute([':user_id' => $userId]);
        if ((string)$roleStmt->fetchColumn() === 'super_admin') {
            $superStmt = $this->pdo->query('SELECT ip_ranges FROM firewall_rules WHERE active = 1 AND super_admin_only = 1');
            foreach ($superStmt->fetchAll(PDO::FETCH_COLUMN) as $ranges) {
                foreach ($this->parseRanges((string)$ranges) as $range) {
                    if ($this->ipInCidr($ip, $range)) { return true; }
                }
            }
        }
        if ($companyId <= 0) { return false; }
        $stmt = $this->pdo->prepare('SELECT fr.ip_ranges FROM firewall_rules fr JOIN firewall_rule_users fru ON fru.rule_id = fr.id WHERE fr.active = 1 AND fr.super_admin_only = 0 AND fr.company_id = :company_id AND fru.user_id = :user_id');
        $stmt->execute([':company_id' => $companyId, ':user_id' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ranges) {
            foreach ($this->parseRanges((string)$ranges) as $range) {
                if ($this->ipInCidr($ip, $range)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getRules(): array {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return [];
        }
        $stmt = $this->pdo->query('SELECT fr.*, CASE WHEN fr.super_admin_only = 1 THEN "Solo Super Admin" ELSE COALESCE(c.name, "-") END AS company_name, GROUP_CONCAT(u.username ORDER BY u.username SEPARATOR ", ") AS user_names, COUNT(fru.user_id) AS user_count FROM firewall_rules fr LEFT JOIN companies c ON c.id = fr.company_id LEFT JOIN firewall_rule_users fru ON fru.rule_id = fr.id LEFT JOIN utenti u ON u.id = fru.user_id GROUP BY fr.id, c.name ORDER BY fr.id DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRule(int $id): ?array {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM firewall_rules WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT user_id FROM firewall_rule_users WHERE rule_id = :id');
        $stmt->execute([':id' => $id]);
        $rule['user_ids'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return $rule;
    }

    public function saveRule(array $data): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        $id = (int)($data['id'] ?? 0);
        $companyId = (int)($data['company_id'] ?? 0);
        $superAdminOnly = !empty($data['super_admin_only']);
        $name = trim((string)($data['name'] ?? ''));
        $ranges = $this->parseRanges((string)($data['ip_ranges'] ?? ''));
        $userIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['user_ids'] ?? [])))));
        if ($name === '' || !$ranges || (!$superAdminOnly && ($companyId <= 0 || !$userIds))) {
            return false;
        }
        $validUserIds = [];
        if (!$superAdminOnly) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $this->pdo->prepare("SELECT id FROM utenti WHERE company_id = ? AND id IN ($placeholders)");
            $stmt->execute(array_merge([$companyId], $userIds));
            $validUserIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            sort($validUserIds);
            $expectedUserIds = $userIds;
            sort($expectedUserIds);
            if ($validUserIds !== $expectedUserIds) { return false; }
        } else {
            $companyId = 0;
        }
        $active = !empty($data['active']) ? 1 : 0;
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            if ($id > 0) {
                $stmt = $this->pdo->prepare('UPDATE firewall_rules SET company_id = :company_id, name = :name, ip_ranges = :ip_ranges, super_admin_only = :super_admin_only, active = :active WHERE id = :id');
                $stmt->execute([':company_id' => $companyId, ':name' => $name, ':ip_ranges' => implode("\n", $ranges), ':super_admin_only' => $superAdminOnly ? 1 : 0, ':active' => $active, ':id' => $id]);
            } else {
                $stmt = $this->pdo->prepare('INSERT INTO firewall_rules (company_id, name, ip_ranges, super_admin_only, active) VALUES (:company_id, :name, :ip_ranges, :super_admin_only, :active)');
                $stmt->execute([':company_id' => $companyId, ':name' => $name, ':ip_ranges' => implode("\n", $ranges), ':super_admin_only' => $superAdminOnly ? 1 : 0, ':active' => $active]);
                $id = (int)$this->pdo->lastInsertId();
            }
            $stmt = $this->pdo->prepare('DELETE FROM firewall_rule_users WHERE rule_id = :id');
            $stmt->execute([':id' => $id]);
            $stmt = $this->pdo->prepare('INSERT INTO firewall_rule_users (rule_id, user_id) VALUES (:rule_id, :user_id)');
            foreach ($validUserIds as $userId) {
                $stmt->execute([':rule_id' => $id, ':user_id' => $userId]);
            }
            if ($ownsTransaction) { $this->pdo->commit(); }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    public function deleteRule(int $id): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM firewall_rule_users WHERE rule_id = :id');
            $stmt->execute([':id' => $id]);
            $stmt = $this->pdo->prepare('DELETE FROM firewall_rules WHERE id = :id');
            $deleted = $stmt->execute([':id' => $id]);
            if ($ownsTransaction) { $this->pdo->commit(); }
            return $deleted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        if ((int)$stmt->fetchColumn() === 0) { $this->pdo->exec($alterSql); }
    }

    private function parseRanges(string $value): array {
        $ranges = [];
        foreach (preg_split('/[\s,;]+/', trim($value)) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (!$this->validRange($candidate)) {
                return [];
            }
            $ranges[] = $candidate;
        }
        return array_values(array_unique($ranges));
    }

    private function validRange(string $range): bool {
        if (!str_contains($range, '/')) {
            return filter_var($range, FILTER_VALIDATE_IP) !== false;
        }
        [$network, $prefix] = explode('/', $range, 2);
        if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($prefix)) {
            return false;
        }
        $max = str_contains($network, ':') ? 128 : 32;
        return (int)$prefix >= 0 && (int)$prefix <= $max;
    }

    private function ipInCidr(string $ip, string $cidr): bool {
        if (!str_contains($cidr, '/')) {
            return hash_equals($cidr, $ip);
        }
        [$network, $prefix] = explode('/', $cidr, 2);
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }
        $bits = (int)$prefix;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($ipBinary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }

    private function isBypassedIp(string $ip): bool {
        $configured = trim((string)getenv('SMS_FIREWALL_BYPASS_CIDRS'));
        if ($configured === '') {
            return false;
        }
        foreach (array_filter(array_map('trim', explode(',', $configured))) as $range) {
            if ($this->ipInCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }
}
