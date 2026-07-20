<?php

class SmsApp {
    private ?PDO $pdo = null;
    private ?CreditManager $creditManager = null;
    private bool $testLogRetentionApplied = false;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Connection::connect()->getConn();
        $this->ensureTables();
        $this->refreshSessionContext();
    }

    public function ensureTables(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_company_team (company_id, name),
            KEY idx_teams_company_id (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_providers (
            user_id INT NOT NULL,
            provider_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, provider_id),
            KEY idx_user_providers_provider (provider_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS company_providers (
            company_id INT NOT NULL,
            provider_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (company_id, provider_id),
            KEY idx_company_providers_provider (provider_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $queries = [
            "CREATE TABLE IF NOT EXISTS utenti (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(50) DEFAULT 'user',
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS providers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                endpoint VARCHAR(500) NOT NULL,
                provider_type VARCHAR(20) DEFAULT 'generic',
                username VARCHAR(100) DEFAULT '',
                password VARCHAR(255) DEFAULT '',
                api_key VARCHAR(255) DEFAULT '',
                request_type VARCHAR(20) DEFAULT 'GET',
                default_from VARCHAR(100) DEFAULT '',
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS message_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_name VARCHAR(100) DEFAULT '',
                provider_id INT DEFAULT 0,
                provider_name VARCHAR(100) DEFAULT '',
                lead_id INT DEFAULT 0,
                list_id INT DEFAULT 0,
                campaign_id INT DEFAULT 0,
                campaign_run_token CHAR(64) DEFAULT NULL,
                recipient VARCHAR(100) NOT NULL,
                message TEXT NOT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                response TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_message_logs_lead_id (lead_id),
                KEY idx_message_logs_list_id (list_id),
                KEY idx_message_logs_campaign_id (campaign_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS test_message_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                company_id INT DEFAULT 0,
                team_id INT DEFAULT 0,
                user_name VARCHAR(100) DEFAULT '',
                provider_id INT DEFAULT 0,
                provider_name VARCHAR(100) DEFAULT '',
                lead_id INT DEFAULT 0,
                list_id INT DEFAULT 0,
                campaign_id INT DEFAULT 0,
                campaign_run_token CHAR(64) DEFAULT NULL,
                recipient VARCHAR(100) NOT NULL,
                message TEXT NOT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                response TEXT DEFAULT '',
                http_code INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_test_message_logs_provider (provider_id),
                KEY idx_test_message_logs_campaign (campaign_id),
                KEY idx_test_message_logs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS campaigns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                provider_id INT DEFAULT 0,
                list_id INT DEFAULT 0,
                sender VARCHAR(100) DEFAULT '',
                message TEXT NOT NULL,
                csv_path VARCHAR(500) DEFAULT '',
                csv_name VARCHAR(255) DEFAULT '',
                created_by VARCHAR(100) DEFAULT '',
                last_status VARCHAR(50) DEFAULT 'draft',
                last_result TEXT DEFAULT '',
                last_sent_at DATETIME NULL,
                job_total INT NOT NULL DEFAULT 0,
                job_processed INT NOT NULL DEFAULT 0,
                job_sent INT NOT NULL DEFAULT 0,
                job_failed INT NOT NULL DEFAULT 0,
                job_cursor INT NOT NULL DEFAULT 0,
                job_user VARCHAR(100) DEFAULT '',
                job_token CHAR(64) DEFAULT NULL,
                job_lock_token CHAR(32) DEFAULT NULL,
                job_lock_until DATETIME NULL,
                job_updated_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS sms_lists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                csv_path VARCHAR(500) DEFAULT '',
                csv_name VARCHAR(255) DEFAULT '',
                total_contacts INT DEFAULT 0,
                invalid_contacts INT DEFAULT 0,
                created_by VARCHAR(100) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS leads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                list_id INT NOT NULL,
                phone VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_list_phone (list_id, phone),
                KEY idx_leads_list_id (list_id),
                CONSTRAINT fk_leads_list FOREIGN KEY (list_id) REFERENCES sms_lists(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        foreach ($queries as $query) {
            $this->pdo->exec($query);
        }

        $this->ensureColumn('utenti', 'role', "ALTER TABLE utenti ADD COLUMN role VARCHAR(50) DEFAULT 'user' AFTER password");
        $this->ensureColumn('utenti', 'preferred_language', "ALTER TABLE utenti ADD COLUMN preferred_language VARCHAR(5) NOT NULL DEFAULT 'it' AFTER role");
        $this->ensureColumn('companies', 'provider_access_configured', "ALTER TABLE companies ADD COLUMN provider_access_configured TINYINT(1) DEFAULT 0 AFTER active");
        $this->ensureColumn('utenti', 'company_id', "ALTER TABLE utenti ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        $this->ensureColumn('utenti', 'team_id', "ALTER TABLE utenti ADD COLUMN team_id INT DEFAULT 1 AFTER company_id");
        $this->ensureColumn('utenti', 'active', "ALTER TABLE utenti ADD COLUMN active TINYINT(1) DEFAULT 1 AFTER role");
        $this->ensureColumn('utenti', 'can_send_single', "ALTER TABLE utenti ADD COLUMN can_send_single TINYINT(1) DEFAULT 1 AFTER active");
        $this->ensureColumn('utenti', 'can_send_bulk', "ALTER TABLE utenti ADD COLUMN can_send_bulk TINYINT(1) DEFAULT 1 AFTER can_send_single");
        $this->ensureColumn('utenti', 'can_manage_providers', "ALTER TABLE utenti ADD COLUMN can_manage_providers TINYINT(1) DEFAULT 1 AFTER can_send_bulk");
        $this->ensureColumn('utenti', 'can_manage_users', "ALTER TABLE utenti ADD COLUMN can_manage_users TINYINT(1) DEFAULT 1 AFTER can_manage_providers");
        $this->ensureColumn('utenti', 'can_view_dashboard', "ALTER TABLE utenti ADD COLUMN can_view_dashboard TINYINT(1) DEFAULT 1 AFTER can_manage_users");
        $this->ensureColumn('utenti', 'can_view_campaigns', "ALTER TABLE utenti ADD COLUMN can_view_campaigns TINYINT(1) DEFAULT 1 AFTER can_view_dashboard");
        $this->ensureColumn('utenti', 'can_view_lists', "ALTER TABLE utenti ADD COLUMN can_view_lists TINYINT(1) DEFAULT 1 AFTER can_view_campaigns");
        $this->ensureColumn('utenti', 'can_view_team_messages', "ALTER TABLE utenti ADD COLUMN can_view_team_messages TINYINT(1) DEFAULT 1 AFTER can_view_lists");
        $this->ensureColumn('utenti', 'can_create_campaigns', "ALTER TABLE utenti ADD COLUMN can_create_campaigns TINYINT(1) DEFAULT 1 AFTER can_view_team_messages");
        $this->ensureColumn('utenti', 'can_edit_campaigns', "ALTER TABLE utenti ADD COLUMN can_edit_campaigns TINYINT(1) DEFAULT 1 AFTER can_create_campaigns");
        $this->ensureColumn('utenti', 'can_delete_campaigns', "ALTER TABLE utenti ADD COLUMN can_delete_campaigns TINYINT(1) DEFAULT 1 AFTER can_edit_campaigns");
        $this->ensureColumn('utenti', 'can_create_lists', "ALTER TABLE utenti ADD COLUMN can_create_lists TINYINT(1) DEFAULT 1 AFTER can_delete_campaigns");
        $this->ensureColumn('utenti', 'can_edit_lists', "ALTER TABLE utenti ADD COLUMN can_edit_lists TINYINT(1) DEFAULT 1 AFTER can_create_lists");
        $this->ensureColumn('utenti', 'can_delete_lists', "ALTER TABLE utenti ADD COLUMN can_delete_lists TINYINT(1) DEFAULT 1 AFTER can_edit_lists");
        $this->ensureColumn('utenti', 'provider_access_configured', "ALTER TABLE utenti ADD COLUMN provider_access_configured TINYINT(1) DEFAULT 0 AFTER can_delete_lists");
        $this->ensureColumn('utenti', 'created_at', "ALTER TABLE utenti ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

        $this->ensureColumn('providers', 'provider_type', "ALTER TABLE providers ADD COLUMN provider_type VARCHAR(20) DEFAULT 'generic' AFTER endpoint");
        $this->ensureColumn('providers', 'company_id', "ALTER TABLE providers ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        $this->ensureColumn('providers', 'username', "ALTER TABLE providers ADD COLUMN username VARCHAR(100) DEFAULT '' AFTER provider_type");
        $this->ensureColumn('providers', 'password', "ALTER TABLE providers ADD COLUMN password VARCHAR(255) DEFAULT '' AFTER username");
        $this->ensureColumn('providers', 'api_key', "ALTER TABLE providers ADD COLUMN api_key VARCHAR(255) DEFAULT '' AFTER password");
        $this->ensureColumn('providers', 'request_type', "ALTER TABLE providers ADD COLUMN request_type VARCHAR(20) DEFAULT 'GET' AFTER api_key");
        $this->ensureColumn('providers', 'default_from', "ALTER TABLE providers ADD COLUMN default_from VARCHAR(100) DEFAULT '' AFTER request_type");
        $this->ensureColumn('providers', 'active', "ALTER TABLE providers ADD COLUMN active TINYINT(1) DEFAULT 1 AFTER default_from");
        $this->ensureColumn('providers', 'created_at', "ALTER TABLE providers ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

        $this->ensureColumn('message_logs', 'user_name', "ALTER TABLE message_logs ADD COLUMN user_name VARCHAR(100) DEFAULT '' AFTER id");
        $this->ensureColumn('message_logs', 'company_id', "ALTER TABLE message_logs ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        $this->ensureColumn('message_logs', 'team_id', "ALTER TABLE message_logs ADD COLUMN team_id INT DEFAULT 1 AFTER company_id");
        $this->ensureColumn('message_logs', 'provider_id', "ALTER TABLE message_logs ADD COLUMN provider_id INT DEFAULT 0 AFTER user_name");
        $this->ensureColumn('message_logs', 'provider_name', "ALTER TABLE message_logs ADD COLUMN provider_name VARCHAR(100) DEFAULT '' AFTER provider_id");
        $this->ensureColumn('message_logs', 'status', "ALTER TABLE message_logs ADD COLUMN status VARCHAR(50) DEFAULT 'pending' AFTER message");
        $this->ensureColumn('message_logs', 'response', "ALTER TABLE message_logs ADD COLUMN response TEXT DEFAULT NULL AFTER status");
        $this->ensureColumn('message_logs', 'lead_id', "ALTER TABLE message_logs ADD COLUMN lead_id INT DEFAULT 0 AFTER provider_name");
        $this->ensureColumn('message_logs', 'list_id', "ALTER TABLE message_logs ADD COLUMN list_id INT DEFAULT 0 AFTER lead_id");
        $this->ensureColumn('message_logs', 'campaign_id', "ALTER TABLE message_logs ADD COLUMN campaign_id INT DEFAULT 0 AFTER list_id");
        $this->ensureColumn('message_logs', 'campaign_run_token', "ALTER TABLE message_logs ADD COLUMN campaign_run_token CHAR(64) DEFAULT NULL AFTER campaign_id");
        $this->ensureColumn('test_message_logs', 'campaign_run_token', "ALTER TABLE test_message_logs ADD COLUMN campaign_run_token CHAR(64) DEFAULT NULL AFTER campaign_id");
        $this->ensureColumn('message_logs', 'created_at', "ALTER TABLE message_logs ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $this->ensureColumn('message_logs', 'credit_cost', "ALTER TABLE message_logs ADD COLUMN credit_cost DECIMAL(10,4) DEFAULT 0 AFTER response");
        $this->ensureColumn('message_logs', 'credit_balance_before', "ALTER TABLE message_logs ADD COLUMN credit_balance_before DECIMAL(14,4) DEFAULT 0 AFTER credit_cost");
        $this->ensureColumn('message_logs', 'credit_balance_after', "ALTER TABLE message_logs ADD COLUMN credit_balance_after DECIMAL(14,4) DEFAULT 0 AFTER credit_balance_before");
        $this->ensureColumn('message_logs', 'sms_segments', "ALTER TABLE message_logs ADD COLUMN sms_segments INT DEFAULT 1 AFTER credit_balance_after");
        $this->ensureColumn('message_logs', 'price_prefix', "ALTER TABLE message_logs ADD COLUMN price_prefix VARCHAR(64) DEFAULT '' AFTER sms_segments");
        $this->ensureColumn('message_logs', 'price_operator', "ALTER TABLE message_logs ADD COLUMN price_operator VARCHAR(150) DEFAULT '' AFTER price_prefix");
        $this->ensureColumn('message_logs', 'purchase_cost', "ALTER TABLE message_logs ADD COLUMN purchase_cost DECIMAL(10,4) DEFAULT 0 AFTER credit_cost");
        $this->ensureColumn('message_logs', 'sale_amount', "ALTER TABLE message_logs ADD COLUMN sale_amount DECIMAL(10,4) DEFAULT 0 AFTER purchase_cost");
        $this->ensureColumn('message_logs', 'profit_amount', "ALTER TABLE message_logs ADD COLUMN profit_amount DECIMAL(10,4) DEFAULT 0 AFTER sale_amount");
        $this->ensureColumn('message_logs', 'purchase_unit_price', "ALTER TABLE message_logs ADD COLUMN purchase_unit_price DECIMAL(10,4) DEFAULT 0 AFTER profit_amount");
        $this->ensureColumn('message_logs', 'sale_unit_price', "ALTER TABLE message_logs ADD COLUMN sale_unit_price DECIMAL(10,4) DEFAULT 0 AFTER purchase_unit_price");
        $this->ensureColumn('message_logs', 'purchase_prefix', "ALTER TABLE message_logs ADD COLUMN purchase_prefix VARCHAR(64) DEFAULT '' AFTER price_operator");
        $this->ensureIndex('message_logs', 'idx_message_logs_lead_id', 'ALTER TABLE message_logs ADD INDEX idx_message_logs_lead_id (lead_id)');
        $this->ensureIndex('message_logs', 'idx_message_logs_list_id', 'ALTER TABLE message_logs ADD INDEX idx_message_logs_list_id (list_id)');
        $this->ensureIndex('message_logs', 'idx_message_logs_campaign_id', 'ALTER TABLE message_logs ADD INDEX idx_message_logs_campaign_id (campaign_id)');
        $this->ensureIndex('message_logs', 'unique_message_campaign_run_lead', 'ALTER TABLE message_logs ADD UNIQUE INDEX unique_message_campaign_run_lead (campaign_run_token, lead_id)');
        $this->ensureIndex('test_message_logs', 'unique_test_campaign_run_lead', 'ALTER TABLE test_message_logs ADD UNIQUE INDEX unique_test_campaign_run_lead (campaign_run_token, lead_id)');
        $this->ensureColumn('campaigns', 'provider_id', "ALTER TABLE campaigns ADD COLUMN provider_id INT DEFAULT 0 AFTER name");
        $this->ensureColumn('campaigns', 'company_id', "ALTER TABLE campaigns ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        $this->ensureColumn('campaigns', 'team_id', "ALTER TABLE campaigns ADD COLUMN team_id INT DEFAULT 1 AFTER company_id");
        $this->ensureColumn('campaigns', 'list_id', "ALTER TABLE campaigns ADD COLUMN list_id INT DEFAULT 0 AFTER provider_id");
        $this->ensureColumn('campaigns', 'sender', "ALTER TABLE campaigns ADD COLUMN sender VARCHAR(100) DEFAULT '' AFTER provider_id");
        $this->ensureColumn('campaigns', 'message', "ALTER TABLE campaigns ADD COLUMN message TEXT AFTER sender");
        $this->ensureColumn('campaigns', 'csv_path', "ALTER TABLE campaigns ADD COLUMN csv_path VARCHAR(500) DEFAULT '' AFTER message");
        $this->ensureColumn('campaigns', 'csv_name', "ALTER TABLE campaigns ADD COLUMN csv_name VARCHAR(255) DEFAULT '' AFTER csv_path");
        $this->ensureColumn('campaigns', 'created_by', "ALTER TABLE campaigns ADD COLUMN created_by VARCHAR(100) DEFAULT '' AFTER csv_name");
        $this->ensureColumn('campaigns', 'last_status', "ALTER TABLE campaigns ADD COLUMN last_status VARCHAR(50) DEFAULT 'draft' AFTER created_by");
        $this->ensureColumn('campaigns', 'last_result', "ALTER TABLE campaigns ADD COLUMN last_result TEXT DEFAULT NULL AFTER last_status");
        $this->ensureColumn('campaigns', 'last_sent_at', "ALTER TABLE campaigns ADD COLUMN last_sent_at DATETIME NULL AFTER last_result");
        $this->ensureColumn('campaigns', 'job_total', "ALTER TABLE campaigns ADD COLUMN job_total INT NOT NULL DEFAULT 0 AFTER last_sent_at");
        $this->ensureColumn('campaigns', 'job_processed', "ALTER TABLE campaigns ADD COLUMN job_processed INT NOT NULL DEFAULT 0 AFTER job_total");
        $this->ensureColumn('campaigns', 'job_sent', "ALTER TABLE campaigns ADD COLUMN job_sent INT NOT NULL DEFAULT 0 AFTER job_processed");
        $this->ensureColumn('campaigns', 'job_failed', "ALTER TABLE campaigns ADD COLUMN job_failed INT NOT NULL DEFAULT 0 AFTER job_sent");
        $this->ensureColumn('campaigns', 'job_cursor', "ALTER TABLE campaigns ADD COLUMN job_cursor INT NOT NULL DEFAULT 0 AFTER job_failed");
        $this->ensureColumn('campaigns', 'job_user', "ALTER TABLE campaigns ADD COLUMN job_user VARCHAR(100) DEFAULT '' AFTER job_cursor");
        $this->ensureColumn('campaigns', 'job_token', "ALTER TABLE campaigns ADD COLUMN job_token CHAR(64) DEFAULT NULL AFTER job_user");
        $this->ensureColumn('campaigns', 'job_lock_token', "ALTER TABLE campaigns ADD COLUMN job_lock_token CHAR(32) DEFAULT NULL AFTER job_token");
        $this->ensureColumn('campaigns', 'job_lock_until', "ALTER TABLE campaigns ADD COLUMN job_lock_until DATETIME NULL AFTER job_lock_token");
        $this->ensureColumn('campaigns', 'job_updated_at', "ALTER TABLE campaigns ADD COLUMN job_updated_at DATETIME NULL AFTER job_lock_until");
        $this->ensureColumn('campaigns', 'created_at', "ALTER TABLE campaigns ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $this->ensureColumn('sms_lists', 'csv_path', "ALTER TABLE sms_lists ADD COLUMN csv_path VARCHAR(500) DEFAULT '' AFTER name");
        $this->ensureColumn('sms_lists', 'company_id', "ALTER TABLE sms_lists ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        $this->ensureColumn('sms_lists', 'team_id', "ALTER TABLE sms_lists ADD COLUMN team_id INT DEFAULT 1 AFTER company_id");
        $this->ensureColumn('sms_lists', 'csv_name', "ALTER TABLE sms_lists ADD COLUMN csv_name VARCHAR(255) DEFAULT '' AFTER csv_path");
        $this->ensureColumn('sms_lists', 'total_contacts', "ALTER TABLE sms_lists ADD COLUMN total_contacts INT DEFAULT 0 AFTER csv_name");
        $this->ensureColumn('sms_lists', 'invalid_contacts', "ALTER TABLE sms_lists ADD COLUMN invalid_contacts INT DEFAULT 0 AFTER total_contacts");
        $this->ensureColumn('sms_lists', 'created_by', "ALTER TABLE sms_lists ADD COLUMN created_by VARCHAR(100) DEFAULT '' AFTER invalid_contacts");
        $this->ensureColumn('sms_lists', 'created_at', "ALTER TABLE sms_lists ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

        $this->pdo->exec("INSERT IGNORE INTO companies (id, name, active) VALUES (1, 'Azienda principale', 1)");
        $this->pdo->exec("INSERT IGNORE INTO teams (id, company_id, name, active) VALUES (1, 1, 'Team principale', 1)");
        $this->pdo->exec("UPDATE utenti SET company_id = 1 WHERE company_id IS NULL OR company_id = 0");
        $this->pdo->exec("UPDATE utenti SET team_id = 1 WHERE team_id IS NULL OR team_id = 0");
        $this->pdo->exec("UPDATE providers SET company_id = 1 WHERE company_id IS NULL OR company_id = 0");
        $this->pdo->exec("INSERT IGNORE INTO company_providers (company_id, provider_id) SELECT p.company_id, p.id FROM providers p JOIN companies c ON c.id = p.company_id WHERE c.provider_access_configured = 0");
        $this->pdo->exec("UPDATE companies SET provider_access_configured = 1 WHERE provider_access_configured IS NULL OR provider_access_configured = 0");
        $this->pdo->exec("UPDATE message_logs SET company_id = 1 WHERE company_id IS NULL OR company_id = 0");
        $this->pdo->exec("UPDATE message_logs SET team_id = 1 WHERE team_id IS NULL OR team_id = 0");
        $this->pdo->exec("UPDATE campaigns SET company_id = 1 WHERE company_id IS NULL OR company_id = 0");
        $this->pdo->exec("UPDATE campaigns SET team_id = 1 WHERE team_id IS NULL OR team_id = 0");
        $this->pdo->exec("UPDATE sms_lists SET company_id = 1 WHERE company_id IS NULL OR company_id = 0");
        $this->pdo->exec("UPDATE sms_lists SET team_id = 1 WHERE team_id IS NULL OR team_id = 0");
        $this->pdo->exec("UPDATE utenti SET role = 'user' WHERE role IS NULL OR role = ''");
        $this->pdo->exec("UPDATE utenti SET active = 1 WHERE active IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_send_single = 1 WHERE can_send_single IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_send_bulk = 1 WHERE can_send_bulk IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_manage_providers = 1 WHERE can_manage_providers IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_manage_users = 1 WHERE can_manage_users IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_view_dashboard = 1 WHERE can_view_dashboard IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_view_campaigns = 1 WHERE can_view_campaigns IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_view_lists = 1 WHERE can_view_lists IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_view_team_messages = 1 WHERE can_view_team_messages IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_create_campaigns = 1 WHERE can_create_campaigns IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_edit_campaigns = 1 WHERE can_edit_campaigns IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_delete_campaigns = 1 WHERE can_delete_campaigns IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_create_lists = 1 WHERE can_create_lists IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_edit_lists = 1 WHERE can_edit_lists IS NULL");
        $this->pdo->exec("UPDATE utenti SET can_delete_lists = 1 WHERE can_delete_lists IS NULL");
        $this->pdo->exec("UPDATE utenti SET provider_access_configured = 0 WHERE provider_access_configured IS NULL");
        if ((int)$this->pdo->query("SELECT COUNT(*) FROM utenti WHERE role = 'super_admin'")->fetchColumn() === 0) {
            $legacyAdminId = (int)$this->pdo->query("SELECT id FROM utenti WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($legacyAdminId <= 0) {
                $legacyAdminId = (int)$this->pdo->query("SELECT id FROM utenti WHERE can_manage_users = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
            }
            if ($legacyAdminId > 0) {
                $stmt = $this->pdo->prepare("UPDATE utenti SET role = 'super_admin', can_manage_users = 1, can_manage_providers = 1 WHERE id = :id");
                $stmt->execute([':id' => $legacyAdminId]);
            }
        }
        $this->pdo->exec("UPDATE utenti SET role = 'admin' WHERE role = 'user' AND can_manage_users = 1");
        $this->ensureIndex('utenti', 'idx_utenti_company_id', 'ALTER TABLE utenti ADD INDEX idx_utenti_company_id (company_id)');
        $this->ensureIndex('utenti', 'idx_utenti_team_id', 'ALTER TABLE utenti ADD INDEX idx_utenti_team_id (team_id)');
        $this->ensureIndex('providers', 'idx_providers_company_id', 'ALTER TABLE providers ADD INDEX idx_providers_company_id (company_id)');
        $this->ensureIndex('message_logs', 'idx_message_logs_company_id', 'ALTER TABLE message_logs ADD INDEX idx_message_logs_company_id (company_id)');
        $this->ensureIndex('campaigns', 'idx_campaigns_company_id', 'ALTER TABLE campaigns ADD INDEX idx_campaigns_company_id (company_id)');
        $this->ensureIndex('sms_lists', 'idx_sms_lists_company_id', 'ALTER TABLE sms_lists ADD INDEX idx_sms_lists_company_id (company_id)');
        $this->migrateStoredListCsvFiles();
        $this->normalizeStoredProviderResponses();
        $this->ensureLegacyProviders();
    }

    public function getCompanies(bool $activeOnly = false): array {
        $query = 'SELECT * FROM companies WHERE 1=1';
        $params = [];
        if (!$this->isSuperAdmin()) {
            $query .= ' AND id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
        }
        if ($activeOnly) {
            $query .= ' AND active = 1';
        }
        $query .= ' ORDER BY name ASC';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCompanyById(int $id): ?array {
        if (!$this->isSuperAdmin() && $id !== $this->tenantCompanyId()) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveCompany(array $data): bool {
        if (!$this->isSuperAdmin()) {
            return false;
        }
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return false;
        }
        $active = !empty($data['active']) ? 1 : 0;
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            $stmt = $this->pdo->prepare('UPDATE companies SET name = :name, active = :active, provider_access_configured = 1 WHERE id = :id');
            $saved = $stmt->execute([':name' => $name, ':active' => $active, ':id' => $id]);
            if ($saved) {
                $this->syncCompanyProviders($id, (array)($data['provider_ids'] ?? []));
            }
            return $saved;
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO companies (name, active) VALUES (:name, :active)');
            $stmt->execute([':name' => $name, ':active' => $active]);
            $companyId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare("INSERT INTO teams (company_id, name, active) VALUES (:company_id, 'Team principale', 1)");
            $stmt->execute([':company_id' => $companyId]);
            $this->syncCompanyProviders($companyId, (array)($data['provider_ids'] ?? []));
            $stmt = $this->pdo->prepare('UPDATE companies SET provider_access_configured = 1 WHERE id = :id');
            $stmt->execute([':id' => $companyId]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    public function getCompanyProviderIds(int $companyId): array {
        if (!$this->getCompanyById($companyId)) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT provider_id FROM company_providers WHERE company_id = :company_id ORDER BY provider_id');
        $stmt->execute([':company_id' => $companyId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function syncCompanyProviders(int $companyId, array $providerIds): void {
        $providerIds = array_values(array_unique(array_filter(array_map('intval', $providerIds))));
        $validIds = [];
        if ($providerIds) {
            $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
            $stmt = $this->pdo->prepare("SELECT id FROM providers WHERE id IN ($placeholders)");
            $stmt->execute($providerIds);
            $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $stmt = $this->pdo->prepare('DELETE FROM company_providers WHERE company_id = :company_id');
        $stmt->execute([':company_id' => $companyId]);
        $stmt = $this->pdo->prepare('INSERT INTO company_providers (company_id, provider_id) VALUES (:company_id, :provider_id)');
        foreach ($validIds as $providerId) {
            $stmt->execute([':company_id' => $companyId, ':provider_id' => $providerId]);
        }
        $stmt = $this->pdo->prepare('DELETE up FROM user_providers up JOIN utenti u ON u.id = up.user_id LEFT JOIN company_providers cp ON cp.company_id = u.company_id AND cp.provider_id = up.provider_id WHERE u.company_id = :company_id AND cp.provider_id IS NULL');
        $stmt->execute([':company_id' => $companyId]);
    }

    private function companyHasProvider(int $companyId, int $providerId): bool {
        $stmt = $this->pdo->prepare('SELECT 1 FROM company_providers WHERE company_id = :company_id AND provider_id = :provider_id LIMIT 1');
        $stmt->execute([':company_id' => $companyId, ':provider_id' => $providerId]);
        return (bool)$stmt->fetchColumn();
    }

    public function getTeams(array $filters = []): array {
        $query = 'SELECT t.*, c.name AS company_name, COUNT(u.id) AS user_count
            FROM teams t
            JOIN companies c ON c.id = t.company_id
            LEFT JOIN utenti u ON u.team_id = t.id';
        $params = [];
        $conditions = [];
        if (!$this->isSuperAdmin()) {
            $conditions[] = 't.company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
        } elseif (!empty($filters['company_id'])) {
            $conditions[] = 't.company_id = :company_id';
            $params[':company_id'] = (int)$filters['company_id'];
        }
        if (($filters['active'] ?? 'all') !== 'all') {
            $conditions[] = 't.active = :active';
            $params[':active'] = (int)$filters['active'];
        }
        if (!empty($filters['search'])) {
            $conditions[] = '(t.name LIKE :search OR c.name LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }
        if ($conditions) {
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $query .= ' GROUP BY t.id, c.name ORDER BY c.name, t.name';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTeamById(int $id): ?array {
        $query = 'SELECT t.*, c.name AS company_name FROM teams t JOIN companies c ON c.id = t.company_id WHERE t.id = :id';
        $params = [':id' => $id];
        if (!$this->isSuperAdmin()) {
            $query .= ' AND t.company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
        }
        $stmt = $this->pdo->prepare($query . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveTeam(array $data): bool {
        $companyId = $this->isSuperAdmin() ? (int)($data['company_id'] ?? 0) : $this->tenantCompanyId();
        $name = trim((string)($data['name'] ?? ''));
        if ($companyId <= 0 || $name === '' || !$this->getCompanyById($companyId)) {
            return false;
        }
        $active = !empty($data['active']) ? 1 : 0;
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            if (!$this->getTeamById($id)) {
                return false;
            }
            $stmt = $this->pdo->prepare('UPDATE teams SET company_id = :company_id, name = :name, active = :active WHERE id = :id');
            return $stmt->execute([':company_id' => $companyId, ':name' => $name, ':active' => $active, ':id' => $id]);
        }
        $stmt = $this->pdo->prepare('INSERT INTO teams (company_id, name, active) VALUES (:company_id, :name, :active)');
        return $stmt->execute([':company_id' => $companyId, ':name' => $name, ':active' => $active]);
    }

    public function deleteTeam(int $id): bool {
        $team = $this->getTeamById($id);
        if (!$team) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM utenti WHERE team_id = :id');
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM teams WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    private function refreshSessionContext(): void {
        if (empty($_SESSION['logged']) || !function_exists('set_user_session_context')) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT u.*, c.name AS company_name, c.active AS company_active, t.name AS team_name, t.active AS team_active
            FROM utenti u
            LEFT JOIN companies c ON c.id = u.company_id
            LEFT JOIN teams t ON t.id = u.team_id
            WHERE u.username = :username LIMIT 1');
        $stmt->execute([':username' => (string)$_SESSION['logged']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $isActive = $user
            && (int)($user['active'] ?? 0) === 1
            && (int)($user['company_active'] ?? 0) === 1
            && (int)($user['team_active'] ?? 0) === 1;
        if (!$isActive) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            return;
        }
        set_user_session_context($user);
    }

    private function isSuperAdmin(): bool {
        return function_exists('is_super_admin') && is_super_admin();
    }

    private function credits(): CreditManager {
        if ($this->creditManager === null) {
            $this->creditManager = new CreditManager($this->pdo);
        }
        return $this->creditManager;
    }

    private function tenantCompanyId(): int {
        return function_exists('current_company_id') ? current_company_id() : 0;
    }

    private function tenantTeamId(): int {
        return function_exists('current_team_id') ? current_team_id() : 0;
    }

    private function scopedCompanyId(array $data = []): int {
        return $this->isSuperAdmin() ? (int)($data['company_id'] ?? $this->tenantCompanyId()) : $this->tenantCompanyId();
    }

    private function validTeamId(int $teamId, int $companyId): int {
        if ($teamId <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM teams WHERE id = :id AND company_id = :company_id LIMIT 1');
        $stmt->execute([':id' => $teamId, ':company_id' => $companyId]);
        return $stmt->fetchColumn() ? $teamId : 0;
    }

    public function getUsers(array $filters = []): array {
        $query = 'SELECT u.*, c.name AS company_name, t.name AS team_name
            FROM utenti u
            LEFT JOIN companies c ON c.id = u.company_id
            LEFT JOIN teams t ON t.id = u.team_id
            WHERE 1=1';
        $params = [];

        if (!$this->isSuperAdmin()) {
            $query .= ' AND u.company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
        } elseif (!empty($filters['company_id'])) {
            $query .= ' AND u.company_id = :company_id';
            $params[':company_id'] = (int)$filters['company_id'];
        }

        if (!empty($filters['team_id'])) {
            $query .= ' AND u.team_id = :team_id';
            $params[':team_id'] = (int)$filters['team_id'];
        }

        if (($filters['active'] ?? '') !== '' && ($filters['active'] ?? '') !== 'all') {
            $query .= ' AND u.active = :active';
            $params[':active'] = (int)$filters['active'];
        }

        if (!empty($filters['search'])) {
            $query .= ' AND (u.username LIKE :search OR u.role LIKE :search OR c.name LIKE :search OR t.name LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }

        $query .= ' ORDER BY u.id DESC';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserById(int $id): ?array {
        $query = 'SELECT u.*, c.name AS company_name, t.name AS team_name
            FROM utenti u LEFT JOIN companies c ON c.id = u.company_id LEFT JOIN teams t ON t.id = u.team_id
            WHERE u.id = :id';
        $params = [':id' => $id];
        if (!$this->isSuperAdmin()) {
            $query .= ' AND u.company_id = :company_id AND u.role <> :super_role';
            $params[':company_id'] = $this->tenantCompanyId();
            $params[':super_role'] = 'super_admin';
        }
        $stmt = $this->pdo->prepare($query . ' LIMIT 1');
        $stmt->execute($params);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function saveUser(array $data): bool {
        $username = trim((string)($data['username'] ?? ''));
        $password = trim((string)($data['password'] ?? ''));
        $role = trim((string)($data['role'] ?? 'user'));
        $preferredLanguage = strtolower(trim((string)($data['preferred_language'] ?? '')));
        $preferredLanguage = in_array($preferredLanguage, ['it', 'en'], true) ? $preferredLanguage : '';
        $allowedRoles = $this->isSuperAdmin() ? ['user', 'admin', 'super_admin'] : ['user', 'admin'];
        $role = in_array($role, $allowedRoles, true) ? $role : 'user';
        $companyId = $this->scopedCompanyId($data);
        $teamId = $this->validTeamId((int)($data['team_id'] ?? 0), $companyId);
        $active = !empty($data['active']) ? 1 : 0;
        $canSendSingle = !empty($data['can_send_single']) ? 1 : 0;
        $canSendBulk = !empty($data['can_send_bulk']) ? 1 : 0;
        $canManageProviders = !empty($data['can_manage_providers']) ? 1 : 0;
        $canManageUsers = !empty($data['can_manage_users']) ? 1 : 0;
        $canViewDashboard = !empty($data['can_view_dashboard']) ? 1 : 0;
        $canViewCampaigns = !empty($data['can_view_campaigns']) ? 1 : 0;
        $canViewLists = !empty($data['can_view_lists']) ? 1 : 0;
        $canViewTeamMessages = !empty($data['can_view_team_messages']) ? 1 : 0;
        $canCreateCampaigns = !empty($data['can_create_campaigns']) ? 1 : 0;
        $canEditCampaigns = !empty($data['can_edit_campaigns']) ? 1 : 0;
        $canDeleteCampaigns = !empty($data['can_delete_campaigns']) ? 1 : 0;
        $canCreateLists = !empty($data['can_create_lists']) ? 1 : 0;
        $canEditLists = !empty($data['can_edit_lists']) ? 1 : 0;
        $canDeleteLists = !empty($data['can_delete_lists']) ? 1 : 0;

        if (!preg_match('/^[A-Za-z0-9._@-]{3,100}$/', $username) || $companyId <= 0 || $teamId <= 0) {
            return false;
        }
        if ($password !== '' && strlen($password) < 12) {
            return false;
        }

        if (!empty($data['id'])) {
            $id = (int)$data['id'];
            $existingUser = $this->getUserById($id);
            if (!$existingUser || (!$this->isSuperAdmin() && (string)$existingUser['role'] === 'super_admin')) {
                return false;
            }
            if ($preferredLanguage === '') $preferredLanguage = (string)($existingUser['preferred_language'] ?? 'it');
            $stmt = $this->pdo->prepare('SELECT id FROM utenti WHERE username = :username AND id <> :id LIMIT 1');
            $stmt->execute([':username' => $username, ':id' => $id]);
            if ($stmt->fetch()) {
                return false;
            }

            $stmt = $this->pdo->prepare('UPDATE utenti SET company_id = :company_id, team_id = :team_id, username = :username, role = :role, preferred_language = :preferred_language, active = :active, can_send_single = :can_send_single, can_send_bulk = :can_send_bulk, can_manage_providers = :can_manage_providers, can_manage_users = :can_manage_users, can_view_dashboard = :can_view_dashboard, can_view_campaigns = :can_view_campaigns, can_view_lists = :can_view_lists, can_view_team_messages = :can_view_team_messages, can_create_campaigns = :can_create_campaigns, can_edit_campaigns = :can_edit_campaigns, can_delete_campaigns = :can_delete_campaigns, can_create_lists = :can_create_lists, can_edit_lists = :can_edit_lists, can_delete_lists = :can_delete_lists, provider_access_configured = 1 WHERE id = :id');
            $stmt->execute([
                ':company_id' => $companyId,
                ':team_id' => $teamId,
                ':username' => $username,
                ':role' => $role,
                ':preferred_language' => $preferredLanguage,
                ':active' => $active,
                ':can_send_single' => $canSendSingle,
                ':can_send_bulk' => $canSendBulk,
                ':can_manage_providers' => $canManageProviders,
                ':can_manage_users' => $canManageUsers,
                ':can_view_dashboard' => $canViewDashboard,
                ':can_view_campaigns' => $canViewCampaigns,
                ':can_view_lists' => $canViewLists,
                ':can_view_team_messages' => $canViewTeamMessages,
                ':can_create_campaigns' => $canCreateCampaigns,
                ':can_edit_campaigns' => $canEditCampaigns,
                ':can_delete_campaigns' => $canDeleteCampaigns,
                ':can_create_lists' => $canCreateLists,
                ':can_edit_lists' => $canEditLists,
                ':can_delete_lists' => $canDeleteLists,
                ':id' => $id
            ]);
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare('UPDATE utenti SET password = :password WHERE id = :id');
                $stmt->execute([':password' => $hash, ':id' => $id]);
            }
            $this->syncUserProviders($id, (array)($data['provider_ids'] ?? []));
            return true;
        }

        if ($password === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM utenti WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            return false;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($preferredLanguage === '') $preferredLanguage = 'it';
        $stmt = $this->pdo->prepare('INSERT INTO utenti (company_id, team_id, username, password, role, preferred_language, active, can_send_single, can_send_bulk, can_manage_providers, can_manage_users, can_view_dashboard, can_view_campaigns, can_view_lists, can_view_team_messages, can_create_campaigns, can_edit_campaigns, can_delete_campaigns, can_create_lists, can_edit_lists, can_delete_lists, provider_access_configured) VALUES (:company_id, :team_id, :username, :password, :role, :preferred_language, :active, :can_send_single, :can_send_bulk, :can_manage_providers, :can_manage_users, :can_view_dashboard, :can_view_campaigns, :can_view_lists, :can_view_team_messages, :can_create_campaigns, :can_edit_campaigns, :can_delete_campaigns, :can_create_lists, :can_edit_lists, :can_delete_lists, 1)');
        $stmt->execute([
            ':company_id' => $companyId,
            ':team_id' => $teamId,
            ':username' => $username,
            ':password' => $hash,
            ':role' => $role,
            ':preferred_language' => $preferredLanguage,
            ':active' => $active,
            ':can_send_single' => $canSendSingle,
            ':can_send_bulk' => $canSendBulk,
            ':can_manage_providers' => $canManageProviders,
            ':can_manage_users' => $canManageUsers,
            ':can_view_dashboard' => $canViewDashboard,
            ':can_view_campaigns' => $canViewCampaigns,
            ':can_view_lists' => $canViewLists,
            ':can_view_team_messages' => $canViewTeamMessages,
            ':can_create_campaigns' => $canCreateCampaigns,
            ':can_edit_campaigns' => $canEditCampaigns,
            ':can_delete_campaigns' => $canDeleteCampaigns,
            ':can_create_lists' => $canCreateLists,
            ':can_edit_lists' => $canEditLists,
            ':can_delete_lists' => $canDeleteLists
        ]);
        $this->syncUserProviders((int)$this->pdo->lastInsertId(), (array)($data['provider_ids'] ?? []));
        return true;
    }

    public function deleteUser(int $id): bool {
        if ($id === (function_exists('current_user_id') ? current_user_id() : 0) || !$this->getUserById($id)) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM user_providers WHERE user_id = :id');
        $stmt->execute([':id' => $id]);
        $stmt = $this->pdo->prepare('DELETE FROM utenti WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function updateUserLanguage(int $userId, string $language): bool {
        $language = strtolower(trim($language));
        if ($userId <= 0 || !in_array($language, ['it', 'en'], true)) return false;
        if (function_exists('current_user_id') && current_user_id() > 0 && current_user_id() !== $userId) return false;
        $stmt = $this->pdo->prepare('UPDATE utenti SET preferred_language = :preferred_language WHERE id = :id');
        return $stmt->execute([':preferred_language' => $language, ':id' => $userId]);
    }

    public function getUserProviderIds(int $userId): array {
        $user = $this->getUserById($userId);
        if (!$user) {
            return [];
        }
        if ((int)($user['provider_access_configured'] ?? 0) !== 1) {
            $stmt = $this->pdo->prepare("SELECT p.id FROM providers p JOIN company_providers cp ON cp.provider_id = p.id WHERE cp.company_id = :company_id AND p.active = 1 AND p.provider_type <> 'internal'");
            $stmt->execute([':company_id' => (int)$user['company_id']]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $stmt = $this->pdo->prepare("SELECT up.provider_id FROM user_providers up JOIN company_providers cp ON cp.provider_id = up.provider_id JOIN providers p ON p.id = up.provider_id WHERE up.user_id = :user_id AND cp.company_id = :company_id AND p.provider_type <> 'internal'");
        $stmt->execute([':user_id' => $userId, ':company_id' => (int)$user['company_id']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function syncUserProviders(int $userId, array $providerIds): void {
        $user = $this->getUserById($userId);
        if (!$user) {
            return;
        }
        $providerIds = array_values(array_unique(array_filter(array_map('intval', $providerIds))));
        $allowedIds = [];
        if ($providerIds) {
            $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
            $stmt = $this->pdo->prepare("SELECT p.id FROM providers p JOIN company_providers cp ON cp.provider_id = p.id WHERE cp.company_id = ? AND p.provider_type <> 'internal' AND p.id IN ($placeholders)");
            $stmt->execute(array_merge([(int)$user['company_id']], $providerIds));
            $allowedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $stmt = $this->pdo->prepare('DELETE FROM user_providers WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $stmt = $this->pdo->prepare('INSERT INTO user_providers (user_id, provider_id) VALUES (:user_id, :provider_id)');
        foreach ($allowedIds as $providerId) {
            $stmt->execute([':user_id' => $userId, ':provider_id' => $providerId]);
        }
    }

    public function getProviders(array $filters = []): array {
        $query = 'SELECT * FROM providers WHERE 1=1';
        $params = [];

        if (!$this->isSuperAdmin()) {
            $query .= " AND provider_type <> 'internal'";
            $query .= ' AND EXISTS (SELECT 1 FROM company_providers cp WHERE cp.company_id = :company_id AND cp.provider_id = providers.id)';
            $params[':company_id'] = $this->tenantCompanyId();
            if (function_exists('current_user_role') && current_user_role() === 'user') {
                $query .= ' AND (EXISTS (SELECT 1 FROM utenti u WHERE u.id = :access_user_id_config AND u.provider_access_configured = 0) OR EXISTS (SELECT 1 FROM user_providers up WHERE up.user_id = :access_user_id_map AND up.provider_id = providers.id))';
                $params[':access_user_id_config'] = function_exists('current_user_id') ? current_user_id() : 0;
                $params[':access_user_id_map'] = function_exists('current_user_id') ? current_user_id() : 0;
            }
        } elseif (!empty($filters['company_id'])) {
            $query .= ' AND EXISTS (SELECT 1 FROM company_providers cp WHERE cp.company_id = :company_id AND cp.provider_id = providers.id)';
            $params[':company_id'] = (int)$filters['company_id'];
        }

        if (($filters['active'] ?? '') !== '' && ($filters['active'] ?? '') !== 'all') {
            $query .= ' AND active = :active';
            $params[':active'] = (int)$filters['active'];
        }

        if (!empty($filters['search'])) {
            $query .= ' AND (name LIKE :search OR endpoint LIKE :search OR default_from LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }

        $query .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($logs as &$log) {
            $log['response'] = $this->simplifyProviderResponse((string)($log['response'] ?? ''));
        }

        unset($log);

        return $logs;
    }

    public function getDefaultProvider(): ?array {
        $providers = $this->getProviders(['active' => '1']);
        return $providers[0] ?? null;
    }

    public function saveProvider(array $data): bool {
        if (!$this->isSuperAdmin()) {
            return false;
        }
        $name = trim((string)($data['name'] ?? ''));
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        $providerType = strtolower(trim((string)($data['provider_type'] ?? 'generic')));
        $providerType = in_array($providerType, ['generic', 'twilio', 'internal'], true) ? $providerType : 'generic';
        if ($name === '' || !$this->validProviderEndpoint($endpoint, $providerType === 'internal')) {
            return false;
        }

        $requestType = strtoupper(trim((string)($data['request_type'] ?? 'GET')));
        if ($providerType === 'twilio' || $providerType === 'internal') {
            $requestType = 'POST';
        }
        $defaultFrom = trim((string)($data['default_from'] ?? ''));
        $active = !empty($data['active']) ? 1 : 0;
        $companyId = $this->scopedCompanyId($data);
        if ($companyId <= 0 || !$this->getCompanyById($companyId)) {
            return false;
        }

        if (!empty($data['id'])) {
            $existingProvider = $this->getProviderById((int)$data['id']);
            if (!$existingProvider) {
                return false;
            }
            $apiKey = trim((string)($data['api_key'] ?? '')) !== '' ? trim((string)$data['api_key']) : (string)$existingProvider['api_key'];
            if ($providerType === 'internal' && strlen($apiKey) < 32) {
                return false;
            }
            $stmt = $this->pdo->prepare('UPDATE providers SET company_id = :company_id, name = :name, endpoint = :endpoint, provider_type = :provider_type, username = :username, password = :password, api_key = :api_key, request_type = :request_type, default_from = :default_from, active = :active WHERE id = :id');
            $stmt->execute([
                ':company_id' => $companyId,
                ':name' => $name,
                ':endpoint' => $endpoint,
                ':provider_type' => $providerType,
                ':username' => trim((string)($data['username'] ?? '')),
                ':password' => trim((string)($data['password'] ?? '')) !== '' ? trim((string)$data['password']) : (string)$existingProvider['password'],
                ':api_key' => $apiKey,
                ':request_type' => $requestType,
                ':default_from' => $defaultFrom,
                ':active' => $active,
                ':id' => (int)$data['id']
            ]);
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO company_providers (company_id, provider_id) VALUES (:company_id, :provider_id)');
            $stmt->execute([':company_id' => $companyId, ':provider_id' => (int)$data['id']]);
            return true;
        }

        $apiKey = trim((string)($data['api_key'] ?? ''));
        if ($providerType === 'internal' && strlen($apiKey) < 32) {
            return false;
        }
        $stmt = $this->pdo->prepare('INSERT INTO providers (company_id, name, endpoint, provider_type, username, password, api_key, request_type, default_from, active) VALUES (:company_id, :name, :endpoint, :provider_type, :username, :password, :api_key, :request_type, :default_from, :active)');
        $stmt->execute([
            ':company_id' => $companyId,
            ':name' => $name,
            ':endpoint' => $endpoint,
            ':provider_type' => $providerType,
            ':username' => trim((string)($data['username'] ?? '')),
            ':password' => trim((string)($data['password'] ?? '')),
            ':api_key' => $apiKey,
            ':request_type' => $requestType,
            ':default_from' => $defaultFrom,
            ':active' => $active
        ]);
        $providerId = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO company_providers (company_id, provider_id) VALUES (:company_id, :provider_id)');
        $stmt->execute([':company_id' => $companyId, ':provider_id' => $providerId]);
        return true;
    }

    public function deleteProvider(int $id): bool {
        if (!$this->isSuperAdmin()) {
            return false;
        }
        if (!$this->getProviderById($id)) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM user_providers WHERE provider_id = :id');
        $stmt->execute([':id' => $id]);
        $stmt = $this->pdo->prepare('DELETE FROM company_providers WHERE provider_id = :id');
        $stmt->execute([':id' => $id]);
        $stmt = $this->pdo->prepare('DELETE FROM providers WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function getProviderById(int $id): ?array {
        $query = 'SELECT * FROM providers WHERE id = :id';
        $params = [':id' => $id];
        if (!$this->isSuperAdmin()) {
            $query .= " AND provider_type <> 'internal'";
            $query .= ' AND EXISTS (SELECT 1 FROM company_providers cp WHERE cp.company_id = :company_id AND cp.provider_id = providers.id)';
            $params[':company_id'] = $this->tenantCompanyId();
            if (function_exists('current_user_role') && current_user_role() === 'user') {
                $query .= ' AND (EXISTS (SELECT 1 FROM utenti u WHERE u.id = :access_user_id_config AND u.provider_access_configured = 0) OR EXISTS (SELECT 1 FROM user_providers up WHERE up.user_id = :access_user_id_map AND up.provider_id = providers.id))';
                $params[':access_user_id_config'] = function_exists('current_user_id') ? current_user_id() : 0;
                $params[':access_user_id_map'] = function_exists('current_user_id') ? current_user_id() : 0;
            }
        }
        $stmt = $this->pdo->prepare($query . ' LIMIT 1');
        $stmt->execute($params);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        return $provider ?: null;
    }

    public function getLogs(array $filters = []): array {
        $query = 'SELECT * FROM message_logs WHERE 1=1';
        $params = [];

        if (!$this->isSuperAdmin()) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
        } elseif (!empty($filters['company_id'])) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = (int)$filters['company_id'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query .= ' AND status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['provider_id'])) {
            $query .= ' AND provider_id = :provider_id';
            $params[':provider_id'] = (int)$filters['provider_id'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim((string)$filters['search']) . '%';
            $query .= ' AND (recipient LIKE :search OR message LIKE :search OR response LIKE :search OR user_name LIKE :search OR provider_name LIKE :search)';
            $params[':search'] = $search;
        }

        if (!empty($filters['team_id'])) {
            $query .= ' AND team_id = :team_id';
            $params[':team_id'] = (int)$filters['team_id'];
        }

        if (!empty($filters['user_name'])) {
            $query .= ' AND user_name = :user_name';
            $params[':user_name'] = trim((string)$filters['user_name']);
        }

        if (!empty($filters['date_from'])) {
            $query .= ' AND created_at >= :date_from';
            $params[':date_from'] = trim((string)$filters['date_from']) . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $query .= ' AND created_at <= :date_to';
            $params[':date_to'] = trim((string)$filters['date_to']) . ' 23:59:59';
        }

        $query .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$this->isSuperAdmin()) {
            foreach ($logs as &$log) {
                $log['response'] = $this->publicBillingMessage((string)($log['response'] ?? ''));
            }
            unset($log);
        }
        return $logs;
    }

    public function getInternalTestLogs(int $limit = 100): array {
        if (!$this->isSuperAdmin()) {
            return [];
        }
        $limit = max(1, min($limit, 500));
        $stmt = $this->pdo->query('SELECT * FROM test_message_logs ORDER BY id DESC LIMIT ' . $limit);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInternalTestStats(): array {
        if (!$this->isSuperAdmin()) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }
        $row = $this->pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN status <> 'sent' THEN 1 ELSE 0 END) AS failed FROM test_message_logs")->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'sent' => (int)($row['sent'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
        ];
    }

    public function getLogStats(array $filters = []): array {
        $query = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
            FROM message_logs";
        $params = [];
        $conditions = [];
        if (!$this->isSuperAdmin()) {
            $conditions[] = 'company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
        }
        if (!empty($filters['team_id'])) {
            $conditions[] = 'team_id = :team_id';
            $params[':team_id'] = (int)$filters['team_id'];
        }
        if (!empty($filters['user_name'])) {
            $conditions[] = 'user_name = :user_name';
            $params[':user_name'] = trim((string)$filters['user_name']);
        }
        if ($conditions) {
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($stats['total'] ?? 0),
            'sent_count' => (int)($stats['sent_count'] ?? 0),
            'failed_count' => (int)($stats['failed_count'] ?? 0),
        ];
    }

    public function getMessageTrend(array $filters = [], int $days = 14): array {
        $days = max(7, min(31, $days));
        $query = "SELECT DATE(created_at) AS log_date,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
            SUM(CASE WHEN status <> 'sent' THEN 1 ELSE 0 END) AS not_sent_count
            FROM message_logs";
        $conditions = ['created_at >= DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY)'];
        $params = [];
        if (!$this->isSuperAdmin()) {
            $conditions[] = 'company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
        }
        if (!empty($filters['team_id'])) {
            $conditions[] = 'team_id = :team_id';
            $params[':team_id'] = (int)$filters['team_id'];
        }
        if (!empty($filters['user_name'])) {
            $conditions[] = 'user_name = :user_name';
            $params[':user_name'] = trim((string)$filters['user_name']);
        }
        $query .= ' WHERE ' . implode(' AND ', $conditions) . ' GROUP BY DATE(created_at) ORDER BY log_date ASC';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $rowsByDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowsByDate[(string)$row['log_date']] = $row;
        }

        $trend = [];
        $start = new DateTimeImmutable('-' . ($days - 1) . ' days');
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->modify('+' . $offset . ' days');
            $key = $date->format('Y-m-d');
            $row = $rowsByDate[$key] ?? [];
            $trend[] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'sent' => (int)($row['sent_count'] ?? 0),
                'not_sent' => (int)($row['not_sent_count'] ?? 0),
            ];
        }
        return $trend;
    }

    public function getBillingStats(): array {
        if (!$this->isSuperAdmin()) {
            return ['purchases' => 0.0, 'sales' => 0.0, 'profit' => 0.0];
        }
        $row = $this->pdo->query("SELECT
            COALESCE(SUM(CASE WHEN status = 'sent' THEN purchase_cost ELSE 0 END), 0) AS purchases,
            COALESCE(SUM(CASE WHEN status = 'sent' THEN sale_amount ELSE 0 END), 0) AS sales,
            COALESCE(SUM(CASE WHEN status = 'sent' THEN profit_amount ELSE 0 END), 0) AS profit
            FROM message_logs")->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'purchases' => (float)($row['purchases'] ?? 0),
            'sales' => (float)($row['sales'] ?? 0),
            'profit' => (float)($row['profit'] ?? 0),
        ];
    }

    public function addLog(array $data): void {
        $stmt = $this->pdo->prepare('INSERT INTO message_logs (company_id, team_id, user_name, provider_id, provider_name, lead_id, list_id, campaign_id, campaign_run_token, recipient, message, status, response, credit_cost, purchase_cost, sale_amount, profit_amount, purchase_unit_price, sale_unit_price, credit_balance_before, credit_balance_after, sms_segments, price_prefix, price_operator, purchase_prefix) VALUES (:company_id, :team_id, :user_name, :provider_id, :provider_name, :lead_id, :list_id, :campaign_id, :campaign_run_token, :recipient, :message, :status, :response, :credit_cost, :purchase_cost, :sale_amount, :profit_amount, :purchase_unit_price, :sale_unit_price, :credit_balance_before, :credit_balance_after, :sms_segments, :price_prefix, :price_operator, :purchase_prefix)');
        $stmt->execute([
            ':company_id' => (int)($data['company_id'] ?? $this->tenantCompanyId()),
            ':team_id' => (int)($data['team_id'] ?? $this->tenantTeamId()),
            ':user_name' => trim((string)($data['user_name'] ?? '')),
            ':provider_id' => (int)($data['provider_id'] ?? 0),
            ':provider_name' => trim((string)($data['provider_name'] ?? '')),
            ':lead_id' => (int)($data['lead_id'] ?? 0),
            ':list_id' => (int)($data['list_id'] ?? 0),
            ':campaign_id' => (int)($data['campaign_id'] ?? 0),
            ':campaign_run_token' => ($data['campaign_run_token'] ?? '') !== '' ? (string)$data['campaign_run_token'] : null,
            ':recipient' => trim((string)($data['recipient'] ?? '')),
            ':message' => trim((string)($data['message'] ?? '')),
            ':status' => trim((string)($data['status'] ?? 'pending')),
            ':response' => trim((string)($data['response'] ?? '')),
            ':credit_cost' => (float)($data['credit_cost'] ?? 0),
            ':purchase_cost' => (float)($data['purchase_cost'] ?? 0),
            ':sale_amount' => (float)($data['sale_amount'] ?? 0),
            ':profit_amount' => (float)($data['profit_amount'] ?? 0),
            ':purchase_unit_price' => (float)($data['purchase_unit_price'] ?? 0),
            ':sale_unit_price' => (float)($data['sale_unit_price'] ?? 0),
            ':credit_balance_before' => (float)($data['credit_balance_before'] ?? 0),
            ':credit_balance_after' => (float)($data['credit_balance_after'] ?? 0),
            ':sms_segments' => max(1, (int)($data['sms_segments'] ?? 1)),
            ':price_prefix' => trim((string)($data['price_prefix'] ?? '')),
            ':price_operator' => trim((string)($data['price_operator'] ?? '')),
            ':purchase_prefix' => trim((string)($data['purchase_prefix'] ?? ''))
        ]);
    }

    public function getCampaigns(): array {
        $query = 'SELECT * FROM campaigns';
        $params = [];
        if (!$this->isSuperAdmin()) {
            $query .= ' WHERE company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
            if (function_exists('user_can') && user_can('view_campaigns')) {
                $query .= ' AND team_id = :team_id';
                $params[':team_id'] = $this->tenantTeamId();
            } else {
                $query .= ' AND created_by = :created_by';
                $params[':created_by'] = (string)($_SESSION['logged'] ?? '');
            }
        }
        $stmt = $this->pdo->prepare($query . ' ORDER BY id DESC');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCampaignById(int $id): ?array {
        $query = 'SELECT * FROM campaigns WHERE id = :id';
        $params = [':id' => $id];
        if (!$this->isSuperAdmin()) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
            if (function_exists('user_can') && user_can('view_campaigns')) {
                $query .= ' AND team_id = :team_id';
                $params[':team_id'] = $this->tenantTeamId();
            } else {
                $query .= ' AND created_by = :created_by';
                $params[':created_by'] = (string)($_SESSION['logged'] ?? '');
            }
        }
        $stmt = $this->pdo->prepare($query . ' LIMIT 1');
        $stmt->execute($params);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        return $campaign ?: null;
    }

    public function saveCampaign(array $data, string $userName): array {
        $name = trim((string)($data['name'] ?? ''));
        $providerId = (int)($data['provider_id'] ?? 0);
        $listId = (int)($data['list_id'] ?? 0);
        $sender = trim((string)($data['from'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));
        $id = (int)($data['id'] ?? 0);
        $requiredPermission = $id > 0 ? 'edit_campaigns' : 'create_campaigns';
        if (function_exists('user_can') && !user_can($requiredPermission)) {
            return ['success' => false, 'message' => $id > 0 ? 'Non hai il permesso di modificare campagne.' : 'Non hai il permesso di creare campagne.'];
        }
        $existing = $id > 0 ? $this->getCampaignById($id) : null;

        if ($name === '') {
            return ['success' => false, 'message' => 'Inserisci il nome della campagna.'];
        }

        if ($providerId <= 0) {
            return ['success' => false, 'message' => 'Seleziona un provider.'];
        }

        if ($listId <= 0) {
            return ['success' => false, 'message' => 'Seleziona una lista.'];
        }

        if ($message === '') {
            return ['success' => false, 'message' => 'Scrivi il messaggio della campagna.'];
        }

        $list = $this->getListById($listId);
        if (!$list) {
            return ['success' => false, 'message' => 'Lista non trovata.'];
        }
        $provider = $this->getProviderById($providerId);
        if (!$provider || !$this->companyHasProvider((int)$list['company_id'], $providerId)) {
            return ['success' => false, 'message' => 'Il provider non è autorizzato per questa azienda.'];
        }
        $companyId = (int)$list['company_id'];
        $teamId = (int)($list['team_id'] ?? $this->tenantTeamId());

        if ($existing) {
            $stmt = $this->pdo->prepare('UPDATE campaigns SET company_id = :company_id, team_id = :team_id, name = :name, provider_id = :provider_id, list_id = :list_id, sender = :sender, message = :message WHERE id = :id');
            $stmt->execute([
                ':company_id' => $companyId,
                ':team_id' => $teamId,
                ':name' => $name,
                ':provider_id' => $providerId,
                ':list_id' => $listId,
                ':sender' => $sender,
                ':message' => $message,
                ':id' => $id,
            ]);

            return ['success' => true, 'message' => 'Campagna aggiornata.'];
        }

        $stmt = $this->pdo->prepare('INSERT INTO campaigns (company_id, team_id, name, provider_id, list_id, sender, message, created_by, last_status, last_result) VALUES (:company_id, :team_id, :name, :provider_id, :list_id, :sender, :message, :created_by, :last_status, :last_result)');
        $stmt->execute([
            ':company_id' => $companyId,
            ':team_id' => $teamId,
            ':name' => $name,
            ':provider_id' => $providerId,
            ':list_id' => $listId,
            ':sender' => $sender,
            ':message' => $message,
            ':created_by' => $userName,
            ':last_status' => 'draft',
            ':last_result' => 'Campagna creata',
        ]);

        return ['success' => true, 'message' => 'Campagna creata.'];
    }

    public function deleteCampaign(int $id): bool {
        if (function_exists('user_can') && !user_can('delete_campaigns')) {
            return false;
        }
        if (!$this->getCampaignById($id)) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM campaigns WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function estimateCampaignCost(int $providerId, int $listId, string $message): array {
        $list = $this->getListById($listId);
        if (!$list) {
            return ['success' => false, 'can_start' => false, 'message' => 'Lista della campagna non trovata.', 'expected_cost' => 0.0];
        }
        if ($providerId <= 0 || !$this->companyHasProvider((int)$list['company_id'], $providerId)) {
            return ['success' => false, 'can_start' => false, 'message' => 'Il provider non è autorizzato per questa azienda.', 'expected_cost' => 0.0];
        }
        if (trim($message) === '') {
            return ['success' => false, 'can_start' => false, 'message' => 'Scrivi il messaggio per calcolare la spesa prevista.', 'expected_cost' => 0.0];
        }

        if ($this->providerType($providerId) === 'internal') {
            if (!$this->isSuperAdmin()) {
                return ['success' => false, 'can_start' => false, 'message' => 'Il provider di test è riservato al Super Admin.', 'expected_cost' => 0.0];
            }
            return [
                'success' => true,
                'can_start' => true,
                'message' => 'Modalità test: nessun credito reale verrà utilizzato.',
                'expected_cost' => 0.0,
                'test_mode' => true,
                'recipient_count' => count($this->getLeadsByListId($listId)),
            ];
        }

        $leads = $this->getLeadsByListId($listId);
        return $this->credits()->estimateCampaign((int)$list['company_id'], $providerId, $leads, $message);
    }

    public function estimateSavedCampaign(int $id): array {
        $campaign = $this->getCampaignById($id);
        if (!$campaign) {
            return ['success' => false, 'can_start' => false, 'message' => 'Campagna non trovata.', 'expected_cost' => 0.0];
        }
        return $this->estimateCampaignCost(
            (int)($campaign['provider_id'] ?? 0),
            (int)($campaign['list_id'] ?? 0),
            (string)($campaign['message'] ?? '')
        );
    }

    public function queueCampaign(int $id, string $userName): array {
        if (function_exists('user_can') && !user_can('send_bulk')) {
            return ['success' => false, 'message' => 'Non hai il permesso di inviare campagne.'];
        }
        $campaign = $this->getCampaignById($id);
        if (!$campaign) {
            return ['success' => false, 'message' => 'Campagna non trovata.'];
        }
        $estimate = $this->estimateSavedCampaign($id);
        if (empty($estimate['can_start'])) {
            return ['success' => false, 'message' => (string)($estimate['message'] ?? 'Campagna bloccata.')];
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM leads WHERE list_id = :list_id');
        $countStmt->execute([':list_id' => (int)$campaign['list_id']]);
        $total = (int)$countStmt->fetchColumn();
        if ($total <= 0) {
            return ['success' => false, 'message' => 'La lista non contiene lead.'];
        }

        try {
            $this->pdo->beginTransaction();
            $lock = $this->pdo->prepare('SELECT last_status, job_token FROM campaigns WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $id]);
            $current = $lock->fetch(PDO::FETCH_ASSOC) ?: [];
            if (in_array((string)($current['last_status'] ?? ''), ['queued', 'sending'], true)
                && trim((string)($current['job_token'] ?? '')) !== '') {
                $this->pdo->rollBack();
                return ['success' => true, 'message' => 'Campagna gia in esecuzione.', 'progress' => $this->getCampaignProgress($id)];
            }
            $runToken = bin2hex(random_bytes(32));
            $stmt = $this->pdo->prepare("UPDATE campaigns SET last_status = 'queued', last_result = 'Invio accodato', last_sent_at = NOW(), job_total = :total, job_processed = 0, job_sent = 0, job_failed = 0, job_cursor = 0, job_user = :job_user, job_token = :job_token, job_lock_token = NULL, job_lock_until = NULL, job_updated_at = NOW() WHERE id = :id");
            $stmt->execute([':total' => $total, ':job_user' => $userName, ':job_token' => $runToken, ':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['success' => false, 'message' => 'Impossibile accodare la campagna in sicurezza.'];
        }
        return ['success' => true, 'message' => 'Campagna accodata.', 'progress' => $this->getCampaignProgress($id)];
    }

    public function getCampaignProgress(int $id): array {
        $campaign = $this->getCampaignById($id);
        if (!$campaign) return ['success' => false, 'message' => 'Campagna non trovata.'];
        return ['success' => true] + $this->campaignProgress($campaign);
    }

    public function cancelCampaign(int $id): array {
        if (function_exists('user_can') && !user_can('send_bulk')) {
            return ['success' => false, 'message' => 'Non hai il permesso di fermare campagne.'];
        }
        $campaign = $this->getCampaignById($id);
        if (!$campaign) return ['success' => false, 'message' => 'Campagna non trovata.'];
        if (!in_array((string)($campaign['last_status'] ?? ''), ['queued', 'sending'], true)
            || trim((string)($campaign['job_token'] ?? '')) === '') {
            return ['success' => false, 'message' => 'La campagna non e in esecuzione.'];
        }
        $processed = (int)($campaign['job_processed'] ?? 0);
        $total = (int)($campaign['job_total'] ?? 0);
        $message = 'Invio fermato. Elaborati: ' . $processed . ' su ' . $total . '.';
        $stmt = $this->pdo->prepare("UPDATE campaigns SET last_status = 'cancelled', last_result = :result, job_lock_until = NULL, job_updated_at = NOW() WHERE id = :id AND last_status IN ('queued', 'sending') AND job_token = :run_token");
        $stmt->execute([':result' => $message, ':id' => $id, ':run_token' => (string)$campaign['job_token']]);
        return ['success' => $stmt->rowCount() === 1, 'message' => $message] + $this->getCampaignProgress($id);
    }

    public function processCampaignBatch(int $id, int $batchSize = 3): array {
        if (function_exists('user_can') && !user_can('send_bulk')) {
            return ['success' => false, 'message' => 'Non hai il permesso di elaborare campagne.'];
        }
        $campaign = $this->getCampaignById($id);
        if (!$campaign) return ['success' => false, 'message' => 'Campagna non trovata.'];
        $batchSize = max(1, min($batchSize, 5));
        $lockToken = bin2hex(random_bytes(16));

        try {
            $this->pdo->beginTransaction();
            $lock = $this->pdo->prepare('SELECT * FROM campaigns WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $id]);
            $campaign = $lock->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!in_array((string)($campaign['last_status'] ?? ''), ['queued', 'sending'], true)
                || trim((string)($campaign['job_token'] ?? '')) === '') {
                $this->pdo->rollBack();
                return ['success' => true] + $this->campaignProgress($campaign);
            }
            $lockUntil = !empty($campaign['job_lock_until']) ? strtotime((string)$campaign['job_lock_until']) : 0;
            if ($lockUntil > time()) {
                $this->pdo->rollBack();
                return ['success' => true, 'busy' => true] + $this->campaignProgress($campaign);
            }
            $claim = $this->pdo->prepare("UPDATE campaigns SET last_status = 'sending', last_result = 'Invio in corso', job_lock_token = :token, job_lock_until = DATE_ADD(NOW(), INTERVAL 50 SECOND), job_updated_at = NOW() WHERE id = :id");
            $claim->execute([':token' => $lockToken, ':id' => $id]);
            $this->pdo->commit();

            $leadStmt = $this->pdo->prepare('SELECT id, phone FROM leads WHERE list_id = :list_id AND id > :cursor ORDER BY id ASC LIMIT ' . $batchSize);
            $leadStmt->bindValue(':list_id', (int)$campaign['list_id'], PDO::PARAM_INT);
            $leadStmt->bindValue(':cursor', (int)$campaign['job_cursor'], PDO::PARAM_INT);
            $leadStmt->execute();
            $leads = $leadStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($leads === []) {
                $normalizeTotal = $this->pdo->prepare('UPDATE campaigns SET job_total = job_processed, job_updated_at = NOW() WHERE id = :id AND job_token = :run_token');
                $normalizeTotal->execute([':id' => $id, ':run_token' => (string)$campaign['job_token']]);
            }
            $logTable = $this->providerType((int)$campaign['provider_id']) === 'internal' ? 'test_message_logs' : 'message_logs';
            $jobUser = trim((string)($campaign['job_user'] ?? '')) !== '' ? (string)$campaign['job_user'] : (string)$campaign['created_by'];

            foreach ($leads as $lead) {
                $stateStmt = $this->pdo->prepare('SELECT last_status FROM campaigns WHERE id = :id AND job_token = :run_token LIMIT 1');
                $stateStmt->execute([':id' => $id, ':run_token' => (string)$campaign['job_token']]);
                if (!in_array((string)$stateStmt->fetchColumn(), ['queued', 'sending'], true)) {
                    break;
                }
                $existing = $this->pdo->prepare("SELECT status FROM {$logTable} WHERE campaign_run_token = :run_token AND lead_id = :lead_id LIMIT 1");
                $existing->execute([':run_token' => (string)$campaign['job_token'], ':lead_id' => (int)$lead['id']]);
                $existingStatus = $existing->fetchColumn();
                if ($existingStatus !== false) {
                    $sent = (string)$existingStatus === 'sent';
                } else {
                    $sendResult = $this->sendAndLog(
                        (int)$campaign['provider_id'],
                        (string)$lead['phone'],
                        (string)$campaign['message'],
                        (string)$campaign['sender'],
                        $jobUser,
                        [
                            'company_id' => (int)$campaign['company_id'],
                            'team_id' => (int)$campaign['team_id'],
                            'lead_id' => (int)$lead['id'],
                            'list_id' => (int)$campaign['list_id'],
                            'campaign_id' => $id,
                            'campaign_run_token' => (string)$campaign['job_token'],
                        ]
                    );
                    $sent = (string)($sendResult['status'] ?? 'failed') === 'sent';
                }
                $progress = $this->pdo->prepare('UPDATE campaigns SET job_cursor = :cursor, job_processed = LEAST(job_total, job_processed + 1), job_sent = job_sent + :sent, job_failed = job_failed + :failed, job_updated_at = NOW() WHERE id = :id AND job_token = :run_token');
                $progress->execute([':cursor' => (int)$lead['id'], ':sent' => $sent ? 1 : 0, ':failed' => $sent ? 0 : 1, ':id' => $id, ':run_token' => (string)$campaign['job_token']]);
            }
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $release = $this->pdo->prepare("UPDATE campaigns SET job_lock_token = NULL, job_lock_until = NULL, job_updated_at = NOW(), last_result = 'Errore temporaneo nel batch; nuovo tentativo automatico' WHERE id = :id AND job_lock_token = :token");
            $release->execute([':id' => $id, ':token' => $lockToken]);
            return ['success' => false, 'message' => 'Errore temporaneo durante il batch.'];
        }

        $currentStmt = $this->pdo->prepare('SELECT * FROM campaigns WHERE id = :id LIMIT 1');
        $currentStmt->execute([':id' => $id]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: $campaign;
        $cancelled = (string)($current['last_status'] ?? '') === 'cancelled';
        $completed = !$cancelled && (int)$current['job_processed'] >= (int)$current['job_total'];
        if ($completed) {
            $status = (int)$current['job_failed'] > 0 ? 'processed_with_errors' : 'sent';
            $summary = 'Campagna elaborata. Totale: ' . (int)$current['job_processed'] . ', falliti: ' . (int)$current['job_failed'] . '.';
            $finish = $this->pdo->prepare('UPDATE campaigns SET last_status = :status, last_result = :result, job_lock_token = NULL, job_lock_until = NULL, job_updated_at = NOW() WHERE id = :id AND job_token = :run_token');
            $finish->execute([':status' => $status, ':result' => $summary, ':id' => $id, ':run_token' => (string)$campaign['job_token']]);
        } else {
            $release = $this->pdo->prepare('UPDATE campaigns SET job_lock_token = NULL, job_lock_until = NULL, job_updated_at = NOW() WHERE id = :id AND job_lock_token = :token');
            $release->execute([':id' => $id, ':token' => $lockToken]);
        }
        return ['success' => true, 'completed_now' => $completed] + $this->getCampaignProgress($id);
    }

    private function campaignProgress(array $campaign): array {
        $total = max(0, (int)($campaign['job_total'] ?? 0));
        $processed = max(0, min($total, (int)($campaign['job_processed'] ?? 0)));
        return [
            'campaign_id' => (int)($campaign['id'] ?? 0),
            'status' => (string)($campaign['last_status'] ?? 'draft'),
            'active' => trim((string)($campaign['job_token'] ?? '')) !== '' && in_array((string)($campaign['last_status'] ?? ''), ['queued', 'sending'], true),
            'total' => $total,
            'processed' => $processed,
            'sent' => (int)($campaign['job_sent'] ?? 0),
            'failed' => (int)($campaign['job_failed'] ?? 0),
            'remaining' => max(0, $total - $processed),
            'percent' => $total > 0 ? (int)floor(($processed / $total) * 100) : 0,
            'message' => (string)($campaign['last_result'] ?? ''),
        ];
    }

    public function sendCampaign(int $id, string $userName): array {
        if (function_exists('user_can') && !user_can('send_bulk')) {
            return ['success' => false, 'message' => 'Non hai il permesso di inviare campagne.'];
        }
        $campaign = $this->getCampaignById($id);
        if (!$campaign) {
            return ['success' => false, 'message' => 'Campagna non trovata.'];
        }

        $list = $this->getListById((int)($campaign['list_id'] ?? 0));
        if (!$list) {
            return ['success' => false, 'message' => 'Lista della campagna non trovata.'];
        }

        $leads = $this->getLeadsByListId((int)$list['id']);
        if (empty($leads)) {
            return ['success' => false, 'message' => 'La lista non contiene lead.'];
        }

        $estimate = $this->estimateCampaignCost(
            (int)($campaign['provider_id'] ?? 0),
            (int)$list['id'],
            (string)($campaign['message'] ?? '')
        );
        if (empty($estimate['can_start'])) {
            return [
                'success' => false,
                'message' => (string)($estimate['message'] ?? 'Campagna bloccata: credito insufficiente o tariffa non disponibile.'),
                'estimate' => $estimate,
            ];
        }

        $this->pdo->beginTransaction();
        try {
            $lockStmt = $this->pdo->prepare('SELECT last_status, last_sent_at FROM campaigns WHERE id = :id FOR UPDATE');
            $lockStmt->execute([':id' => $id]);
            $lockedCampaign = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $startedAt = !empty($lockedCampaign['last_sent_at']) ? strtotime((string)$lockedCampaign['last_sent_at']) : 0;
            if ((string)($lockedCampaign['last_status'] ?? '') === 'sending' && $startedAt > time() - 3600) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Questa campagna è già in esecuzione.'];
            }
            $markStmt = $this->pdo->prepare("UPDATE campaigns SET last_status = 'sending', last_result = 'Invio in corso', last_sent_at = NOW() WHERE id = :id");
            $markStmt->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Impossibile avviare la campagna in sicurezza.'];
        }

        $result = ['processed' => 0, 'results' => []];
        try {
            foreach ($leads as $lead) {
                $sendResult = $this->sendAndLog(
                (int)($campaign['provider_id'] ?? 0),
                (string)$lead['phone'],
                (string)($campaign['message'] ?? ''),
                (string)($campaign['sender'] ?? ''),
                $userName,
                [
                    'company_id' => (int)$campaign['company_id'],
                    'team_id' => (int)$campaign['team_id'],
                    'lead_id' => (int)$lead['id'],
                    'list_id' => (int)$list['id'],
                    'campaign_id' => $id,
                ]
            );
                $result['results'][] = [
                    'recipient' => (string)$lead['phone'],
                    'status' => $sendResult['status'] ?? 'failed',
                    'response' => $sendResult['response'] ?? 'Nessuna risposta',
                ];
                $result['processed']++;
            }
        } catch (Throwable $exception) {
            $result['results'][] = ['recipient' => '', 'status' => 'failed', 'response' => 'Campagna interrotta per errore interno.'];
        }

        $failedRows = count(array_filter($result['results'] ?? [], fn($row) => ($row['status'] ?? '') === 'failed'));
        $processed = (int)($result['processed'] ?? 0);
        $status = $failedRows > 0 ? 'processed_with_errors' : 'sent';
        $summary = 'Campagna elaborata. Totale: ' . $processed . ', falliti: ' . $failedRows . '.';

        $stmt = $this->pdo->prepare('UPDATE campaigns SET last_status = :last_status, last_result = :last_result, last_sent_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':last_status' => $status,
            ':last_result' => $summary,
            ':id' => $id,
        ]);

        return [
            'success' => true,
            'message' => $summary,
            'results' => $result['results'] ?? [],
        ];
    }

    public function getLists(): array {
        $query = 'SELECT * FROM sms_lists';
        $params = [];
        if (!$this->isSuperAdmin()) {
            $query .= ' WHERE company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
            if (function_exists('user_can') && user_can('view_lists')) {
                $query .= ' AND team_id = :team_id';
                $params[':team_id'] = $this->tenantTeamId();
            } else {
                $query .= ' AND created_by = :created_by';
                $params[':created_by'] = (string)($_SESSION['logged'] ?? '');
            }
        }
        $stmt = $this->pdo->prepare($query . ' ORDER BY id DESC');
        $stmt->execute($params);
        $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lists as &$list) {
            $stats = $this->getListStats((int)$list['id']);
            $list['sent_count'] = $stats['sent_count'];
            $list['failed_count'] = $stats['failed_count'];
            $list['last_log'] = $stats['last_log'];
        }

        unset($list);

        return $lists;
    }

    public function getListById(int $id): ?array {
        $query = 'SELECT * FROM sms_lists WHERE id = :id';
        $params = [':id' => $id];
        if (!$this->isSuperAdmin()) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = $this->tenantCompanyId();
            if (function_exists('user_can') && user_can('view_lists')) {
                $query .= ' AND team_id = :team_id';
                $params[':team_id'] = $this->tenantTeamId();
            } else {
                $query .= ' AND created_by = :created_by';
                $params[':created_by'] = (string)($_SESSION['logged'] ?? '');
            }
        }
        $stmt = $this->pdo->prepare($query . ' LIMIT 1');
        $stmt->execute($params);
        $list = $stmt->fetch(PDO::FETCH_ASSOC);
        return $list ?: null;
    }

    public function getLeadsByListId(int $listId): array {
        if (!$this->getListById($listId)) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id, list_id, phone, created_at FROM leads WHERE list_id = :list_id ORDER BY id ASC');
        $stmt->execute([':list_id' => $listId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveList(array $data, ?array $uploadedFile, string $userName): array {
        $name = trim((string)($data['name'] ?? ''));
        $id = (int)($data['id'] ?? 0);
        $requiredPermission = $id > 0 ? 'edit_lists' : 'create_lists';
        if (function_exists('user_can') && !user_can($requiredPermission)) {
            return ['success' => false, 'message' => $id > 0 ? 'Non hai il permesso di modificare liste.' : 'Non hai il permesso di creare liste.'];
        }
        $existing = $id > 0 ? $this->getListById($id) : null;
        if ($id > 0 && !$existing) {
            return ['success' => false, 'message' => 'Lista non trovata o non accessibile.'];
        }
        $companyId = $existing ? (int)$existing['company_id'] : $this->scopedCompanyId($data);
        $teamId = $existing ? (int)$existing['team_id'] : $this->validTeamId((int)($data['team_id'] ?? $this->tenantTeamId()), $companyId);

        if ($name === '' || $companyId <= 0 || $teamId <= 0) {
            return ['success' => false, 'message' => 'Inserisci il nome della lista.'];
        }

        $csvData = $this->readUploadedListCsv($uploadedFile);
        if (!$csvData['success']) {
            return ['success' => false, 'message' => $csvData['message']];
        }

        try {
            $this->pdo->beginTransaction();

            if ($existing) {
                $stmt = $this->pdo->prepare('UPDATE sms_lists SET name = :name, csv_path = :csv_path WHERE id = :id');
                $stmt->execute([
                    ':name' => $name,
                    ':csv_path' => '',
                    ':id' => $id,
                ]);
                $listId = $id;
            } else {
                $stmt = $this->pdo->prepare('INSERT INTO sms_lists (company_id, team_id, name, csv_path, csv_name, total_contacts, invalid_contacts, created_by) VALUES (:company_id, :team_id, :name, :csv_path, :csv_name, 0, 0, :created_by)');
                $stmt->execute([
                    ':company_id' => $companyId,
                    ':team_id' => $teamId,
                    ':name' => $name,
                    ':csv_path' => '',
                    ':csv_name' => '',
                    ':created_by' => $userName,
                ]);
                $listId = (int)$this->pdo->lastInsertId();
            }

            if ($csvData['has_file']) {
                $this->replaceListLeads($listId, $csvData['recipients']);
                $stmt = $this->pdo->prepare('UPDATE sms_lists SET csv_name = :csv_name, total_contacts = :total_contacts, invalid_contacts = :invalid_contacts WHERE id = :id');
                $stmt->execute([
                    ':csv_name' => $csvData['original_name'],
                    ':total_contacts' => count($csvData['recipients']),
                    ':invalid_contacts' => $csvData['invalid_count'],
                    ':id' => $listId,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Impossibile salvare i lead nel database.'];
        }

        $oldCsvPath = (string)($existing['csv_path'] ?? '');
        if ($oldCsvPath !== '' && is_file($oldCsvPath)) {
            @unlink($oldCsvPath);
        }

        return ['success' => true, 'message' => $existing ? 'Lista aggiornata.' : 'Lista creata.'];
    }

    public function deleteList(int $id): bool {
        if (function_exists('user_can') && !user_can('delete_lists')) {
            return false;
        }
        $list = $this->getListById($id);
        if (!$list) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM leads WHERE list_id = :list_id');
            $stmt->execute([':list_id' => $id]);
            $stmt = $this->pdo->prepare('DELETE FROM sms_lists WHERE id = :id');
            $deleted = $stmt->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }

        if ($deleted) {
            $csvPath = (string)($list['csv_path'] ?? '');
            if ($csvPath !== '' && file_exists($csvPath)) {
                @unlink($csvPath);
            }
        }

        return $deleted;
    }

    public function sendMessage(int $providerId, string $to, string $message, string $from = ''): array {
        $provider = $this->getProviderById($providerId);
        if (!$provider) {
            return ['status' => 'failed', 'response' => 'Provider non trovato'];
        }

        if ((int)$provider['active'] !== 1) {
            return ['status' => 'failed', 'response' => 'Provider disattivato', 'provider_name' => $provider['name'], 'company_id' => (int)$provider['company_id']];
        }

        $to = $this->normalizePhone($to);
        if ($to === '') {
            return ['status' => 'failed', 'response' => 'Numero non valido', 'provider_name' => $provider['name'], 'company_id' => (int)$provider['company_id']];
        }

        if (trim($message) === '') {
            return ['status' => 'failed', 'response' => 'Messaggio vuoto', 'provider_name' => $provider['name'], 'company_id' => (int)$provider['company_id']];
        }

        $providerType = strtolower((string)($provider['provider_type'] ?? 'generic'));
        if ($providerType === 'internal' && !$this->isSuperAdmin()) {
            return ['status' => 'failed', 'response' => 'Provider di test riservato al Super Admin', 'provider_name' => $provider['name'], 'company_id' => (int)$provider['company_id']];
        }
        $sender = $from !== '' ? $from : (string)$provider['default_from'];

        if ($providerType === 'twilio') {
            if (trim((string)$provider['username']) === '' || trim((string)$provider['password']) === '') {
                return ['status' => 'failed', 'response' => 'Credenziali Twilio mancanti', 'provider_name' => $provider['name'], 'company_id' => (int)$provider['company_id']];
            }
            if ($sender === '') {
                return ['status' => 'failed', 'response' => 'Mittente Twilio o Messaging Service SID mancante', 'provider_name' => $provider['name'], 'company_id' => (int)$provider['company_id']];
            }

            $params = [
                'To' => $to,
                'Body' => $message,
            ];
            if (str_starts_with(strtoupper($sender), 'MG')) {
                $params['MessagingServiceSid'] = $sender;
            } else {
                $params['From'] = $sender;
            }
        } else {
            $params = [
                'username' => $provider['username'],
                'password' => $provider['password'],
                'api_key' => $provider['api_key'],
                'from' => $sender,
                'to' => $to,
                'text' => $message
            ];
        }

        $url = $provider['endpoint'];
        $responseBody = '';
        $success = false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

        if ($providerType === 'internal') {
            $internalHost = strtolower((string)(parse_url((string)$url, PHP_URL_HOST) ?: ''));
            $configuredIp = trim((string)getenv('SMS_INTERNAL_PROVIDER_RESOLVE_IP'));
            $resolveIp = $configuredIp !== '' ? $configuredIp : '82.77.19.48';
            $publicIpFlags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if ($internalHost !== 'provtest.book-my.eu'
                || filter_var($resolveIp, FILTER_VALIDATE_IP, $publicIpFlags) === false) {
                curl_close($ch);
                return [
                    'status' => 'failed',
                    'response' => 'Configurazione rete del provider interno non valida',
                    'raw_response' => '',
                    'http_code' => 0,
                    'provider_name' => $provider['name'],
                    'company_id' => (int)$provider['company_id'],
                ];
            }
            // Il NAS non dispone sempre della risoluzione DNS pubblica: il pin mantiene SNI,
            // hostname e verifica TLS, evitando di disabilitare i controlli del certificato.
            curl_setopt($ch, CURLOPT_RESOLVE, ['provtest.book-my.eu:443:' . $resolveIp]);
        }

        if ($providerType === 'twilio') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, (string)$provider['username'] . ':' . (string)$provider['password']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        if ($providerType === 'twilio' || strtoupper($provider['request_type']) === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            $separator = strpos($url, '?') === false ? '?' : '&';
            curl_setopt($ch, CURLOPT_URL, $url . $separator . http_build_query($params));
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($providerType === 'twilio' && is_string($responseBody)) {
            $twilioResponse = json_decode($responseBody, true);
            $success = ($httpCode >= 200 && $httpCode < 300)
                && is_array($twilioResponse)
                && !empty($twilioResponse['sid']);
        } elseif (is_string($responseBody)) {
            $body = strtolower($responseBody);
            $success = ($httpCode >= 200 && $httpCode < 300) && (str_contains($body, 'success') || str_contains($body, 'ok') || str_contains($body, 'sent'));
        }

        $rawResponse = is_string($responseBody) ? $responseBody : ($curlError !== '' ? $curlError : 'Nessuna risposta');
        $displayResponse = $providerType === 'twilio'
            ? $this->simplifyTwilioResponse($rawResponse, $httpCode)
            : ($providerType === 'internal' ? $this->simplifyInternalProviderResponse($rawResponse, $httpCode) : $this->simplifyProviderResponse($rawResponse));

        return [
            'status' => $success ? 'sent' : 'failed',
            'response' => $displayResponse,
            'raw_response' => $rawResponse,
            'http_code' => $httpCode,
            'provider_name' => $provider['name'],
            'company_id' => (int)$provider['company_id']
        ];
    }

    private function simplifyTwilioResponse(string $response, int $httpCode): string {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return $httpCode > 0
                ? 'Twilio HTTP ' . $httpCode . ': ' . $this->simplifyProviderResponse($response)
                : $this->simplifyProviderResponse($response);
        }

        if (!empty($data['message'])) {
            $code = !empty($data['code']) ? ' (' . $data['code'] . ')' : '';
            return 'Twilio' . $code . ': ' . trim((string)$data['message']);
        }

        if (!empty($data['sid'])) {
            $status = trim((string)($data['status'] ?? 'accettato'));
            return 'Twilio: messaggio ' . $status . ' - SID ' . (string)$data['sid'];
        }

        return 'Risposta Twilio non riconosciuta';
    }

    private function simplifyInternalProviderResponse(string $response, int $httpCode): string {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return 'TEST HTTP ' . $httpCode . ': ' . $this->simplifyProviderResponse($response);
        }
        $status = strtoupper(trim((string)($data['status'] ?? 'unknown')));
        $message = trim((string)($data['message'] ?? 'Risposta senza descrizione'));
        $id = trim((string)($data['id'] ?? ''));
        return 'TEST ' . $status . ': ' . $message . ($id !== '' ? ' · ' . $id : '');
    }

    public function sendAndLog(int $providerId, string $to, string $message, string $from, string $userName, array $context = []): array {
        if ($this->providerType($providerId) === 'internal') {
            return $this->sendInternalTestAndLog($providerId, $to, $message, $from, $userName, $context);
        }
        $recipient = $this->normalizePhone($to);
        $companyId = (int)($context['company_id'] ?? $this->tenantCompanyId());
        $providerContacted = false;
        $reservation = ['success' => true, 'reserved' => false, 'cost' => 0, 'sale_total' => 0, 'purchase_total' => 0, 'profit' => 0, 'sale_unit_price' => 0, 'purchase_unit_price' => 0, 'segments' => 1, 'prefix' => '', 'purchase_prefix' => '', 'balance_before' => 0, 'balance_after' => 0];
        if ($recipient === '') {
            $result = ['status' => 'failed', 'response' => 'Numero non valido', 'provider_name' => $this->getProviderName($providerId)];
        } else {
            $credits = $this->credits();
            $reservation = $credits->reserve($companyId, $providerId, $recipient, $message, $userName);
            if (empty($reservation['success'])) {
                $result = ['status' => 'failed', 'response' => (string)($reservation['message'] ?? 'Credito insufficiente.'), 'provider_name' => $this->getProviderName($providerId), 'billing_error_code' => (string)($reservation['error_code'] ?? '')];
            } else {
                $providerContacted = true;
                $result = $this->sendMessage($providerId, $recipient, $message, $from);
                if (($result['status'] ?? 'failed') !== 'sent') {
                    $credits->refund($companyId, $reservation, $userName);
                }
            }
        }

        $sent = ($result['status'] ?? 'failed') === 'sent';

        $this->addLog([
            'company_id' => (int)($context['company_id'] ?? ($this->tenantCompanyId() > 0 ? $this->tenantCompanyId() : ($result['company_id'] ?? 0))),
            'team_id' => (int)($context['team_id'] ?? $this->tenantTeamId()),
            'user_name' => $userName,
            'provider_id' => $providerId,
            'provider_name' => $result['provider_name'] ?? $this->getProviderName($providerId),
            'lead_id' => (int)($context['lead_id'] ?? 0),
            'list_id' => (int)($context['list_id'] ?? 0),
            'campaign_id' => (int)($context['campaign_id'] ?? 0),
            'campaign_run_token' => (string)($context['campaign_run_token'] ?? ''),
            'recipient' => $recipient !== '' ? $recipient : $to,
            'message' => $message,
            'status' => $result['status'] ?? 'failed',
            'response' => $result['response'] ?? 'Nessuna risposta',
            'credit_cost' => $sent ? (float)($reservation['cost'] ?? 0) : 0,
            'purchase_cost' => $sent ? (float)($reservation['purchase_total'] ?? 0) : 0,
            'sale_amount' => $sent ? (float)($reservation['sale_total'] ?? 0) : 0,
            'profit_amount' => $sent ? (float)($reservation['profit'] ?? 0) : 0,
            'purchase_unit_price' => (float)($reservation['purchase_unit_price'] ?? 0),
            'sale_unit_price' => (float)($reservation['sale_unit_price'] ?? 0),
            'credit_balance_before' => (float)($reservation['balance_before'] ?? 0),
            'credit_balance_after' => $sent ? (float)($reservation['balance_after'] ?? 0) : (float)($reservation['balance_before'] ?? 0),
            'sms_segments' => (int)($reservation['segments'] ?? 1),
            'price_prefix' => (string)($reservation['prefix'] ?? ''),
            'price_operator' => (string)($reservation['operator_name'] ?? ''),
            'purchase_prefix' => (string)($reservation['purchase_prefix'] ?? '')
        ]);

        $providerName = (string)($result['provider_name'] ?? $this->getProviderName($providerId));
        $logContext = [
            'company_id' => (int)($context['company_id'] ?? ($this->tenantCompanyId() > 0 ? $this->tenantCompanyId() : ($result['company_id'] ?? 0))),
            'team_id' => (int)($context['team_id'] ?? $this->tenantTeamId()),
            'provider_id' => $providerId,
            'provider_name' => $providerName,
            'recipient' => $recipient !== '' ? $recipient : $to,
            'sender' => $from,
            'status' => (string)($result['status'] ?? 'failed'),
            'http_code' => (int)($result['http_code'] ?? 0),
            'response' => (string)($result['response'] ?? 'Nessuna risposta'),
            'raw_response' => (string)($result['raw_response'] ?? ''),
            'credit_cost' => $sent ? (float)($reservation['cost'] ?? 0) : 0,
            'purchase_cost' => $sent ? (float)($reservation['purchase_total'] ?? 0) : 0,
            'sale_amount' => $sent ? (float)($reservation['sale_total'] ?? 0) : 0,
            'profit_amount' => $sent ? (float)($reservation['profit'] ?? 0) : 0,
            'credit_balance_before' => (float)($reservation['balance_before'] ?? 0),
            'credit_balance_after' => $sent ? (float)($reservation['balance_after'] ?? 0) : (float)($reservation['balance_before'] ?? 0),
            'sms_segments' => (int)($reservation['segments'] ?? 1),
            'price_prefix' => (string)($reservation['prefix'] ?? ''),
            'price_operator' => (string)($reservation['operator_name'] ?? ''),
            'billing_error_code' => (string)($reservation['error_code'] ?? ''),
            'billing_destination' => (string)($reservation['destination'] ?? ''),
            'provider_contacted' => $providerContacted,
            'lead_id' => (int)($context['lead_id'] ?? 0),
            'list_id' => (int)($context['list_id'] ?? 0),
            'campaign_id' => (int)($context['campaign_id'] ?? 0),
        ];
        $billingRejected = !empty($reservation['error_code']);
        SystemLogger::record(
            $sent ? 'info' : ($billingRejected ? 'warning' : 'error'),
            $billingRejected ? 'billing' : 'sms',
            $sent ? 'message.sent' : ($billingRejected ? 'billing.message_blocked' : 'message.failed'),
            $sent ? 'Messaggio inviato con successo.' : ($billingRejected ? (string)($reservation['message'] ?? 'Invio bloccato dal billing.') : 'Errore durante l’invio del messaggio.'),
            $logContext,
            $userName
        );
        if ($providerContacted) {
            SystemLogger::record(
                $sent ? 'info' : 'error',
                'provider',
                $sent ? 'provider.response' : 'provider.error',
                ($sent ? 'Risposta ricevuta dal provider ' : 'Errore restituito dal provider ') . ($providerName !== '' ? $providerName : '#' . $providerId) . '.',
                $logContext,
                $userName
            );
        }

        $visibleResult = $result;
        if (!$this->isSuperAdmin() && ($reservation['error_code'] ?? '') === 'sale_prefix_unauthorized') {
            $visibleResult['response'] = (string)($reservation['public_message'] ?? $this->publicBillingMessage((string)($result['response'] ?? '')));
        }
        return $visibleResult + ['recipient' => $recipient];
    }

    private function sendInternalTestAndLog(int $providerId, string $to, string $message, string $from, string $userName, array $context): array {
        if (!$this->isSuperAdmin()) {
            SystemLogger::record('warning', 'security', 'test_provider.denied', 'Tentativo di utilizzo del provider fittizio da un utente operativo.', [
                'provider_id' => $providerId,
                'user_id' => function_exists('current_user_id') ? current_user_id() : 0,
            ], $userName);
            return ['status' => 'failed', 'response' => 'Provider di test riservato al Super Admin', 'recipient' => $this->normalizePhone($to), 'test_mode' => true];
        }

        $provider = $this->getProviderById($providerId);
        $recipient = $this->normalizePhone($to);
        if (!$provider || (string)($provider['provider_type'] ?? '') !== 'internal') {
            return ['status' => 'failed', 'response' => 'Provider di test non trovato', 'recipient' => $recipient, 'test_mode' => true];
        }
        $result = $recipient === ''
            ? ['status' => 'failed', 'response' => 'Numero non valido', 'provider_name' => (string)$provider['name'], 'http_code' => 0]
            : $this->sendMessage($providerId, $recipient, $message, $from);

        $stmt = $this->pdo->prepare('INSERT INTO test_message_logs (company_id, team_id, user_name, provider_id, provider_name, lead_id, list_id, campaign_id, campaign_run_token, recipient, message, status, response, http_code) VALUES (:company_id, :team_id, :user_name, :provider_id, :provider_name, :lead_id, :list_id, :campaign_id, :campaign_run_token, :recipient, :message, :status, :response, :http_code)');
        $stmt->execute([
            ':company_id' => (int)($context['company_id'] ?? $this->tenantCompanyId()),
            ':team_id' => (int)($context['team_id'] ?? $this->tenantTeamId()),
            ':user_name' => $userName,
            ':provider_id' => $providerId,
            ':provider_name' => (string)($result['provider_name'] ?? $provider['name']),
            ':lead_id' => (int)($context['lead_id'] ?? 0),
            ':list_id' => (int)($context['list_id'] ?? 0),
            ':campaign_id' => (int)($context['campaign_id'] ?? 0),
            ':campaign_run_token' => ($context['campaign_run_token'] ?? '') !== '' ? (string)$context['campaign_run_token'] : null,
            ':recipient' => $recipient !== '' ? $recipient : $to,
            ':message' => $message,
            ':status' => (string)($result['status'] ?? 'failed'),
            ':response' => (string)($result['response'] ?? 'Nessuna risposta'),
            ':http_code' => (int)($result['http_code'] ?? 0),
        ]);
        $this->applyTestLogRetention();

        $sent = (string)($result['status'] ?? 'failed') === 'sent';
        SystemLogger::record($sent ? 'info' : 'warning', 'test_provider', $sent ? 'message.simulated' : 'message.simulation_failed', $sent ? 'Messaggio simulato con successo.' : 'Simulazione messaggio fallita.', [
            'provider_id' => $providerId,
            'provider_name' => (string)$provider['name'],
            'recipient' => $recipient !== '' ? $recipient : $to,
            'status' => (string)($result['status'] ?? 'failed'),
            'http_code' => (int)($result['http_code'] ?? 0),
            'response' => (string)($result['response'] ?? ''),
            'campaign_id' => (int)($context['campaign_id'] ?? 0),
            'billing_applied' => false,
        ], $userName);

        return $result + ['recipient' => $recipient, 'test_mode' => true, 'billing_applied' => false];
    }

    private function providerType(int $providerId): string {
        if ($providerId <= 0) {
            return '';
        }
        $stmt = $this->pdo->prepare('SELECT provider_type FROM providers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $providerId]);
        return strtolower((string)$stmt->fetchColumn());
    }

    private function applyTestLogRetention(): void {
        if ($this->testLogRetentionApplied) {
            return;
        }
        $this->testLogRetentionApplied = true;
        $days = max(1, min(3650, (int)(getenv('SMS_TEST_LOG_RETENTION_DAYS') ?: 30)));
        $this->pdo->exec('DELETE FROM test_message_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
    }

    private function publicBillingMessage(string $message): string {
        return preg_replace('/\)\.\s*Nessun prezzo di vendita attivo per questa azienda e provider\.?/u', ').', $message) ?? $message;
    }

    private function simplifyProviderResponse(string $response): string {
        $response = trim($response);
        if ($response === '') {
            return 'Nessuna risposta dal provider';
        }

        if (preg_match('/<description>(.*?)<\/description>/is', $response, $matches) === 1) {
            $description = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($description !== '') {
                return $this->mapProviderErrorMessage($description);
            }
        }

        $plainText = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($response, ENT_QUOTES | ENT_XML1, 'UTF-8'))));
        if ($plainText !== '') {
            return $this->mapProviderErrorMessage(mb_substr($plainText, 0, 220));
        }

        return 'Risposta non riconosciuta dal provider';
    }

    private function mapProviderErrorMessage(string $message): string {
        $normalizedMessage = strtolower(trim($message));

        if (str_contains($normalizedMessage, 'do not have enough credit')
            || str_contains($normalizedMessage, 'not enough credit')
            || str_contains($normalizedMessage, 'buy credit')) {
            return 'Credito finito, ricaricare il provider.';
        }

        return $message;
    }

    private function normalizeStoredProviderResponses(): void {
        $stmt = $this->pdo->prepare(
            "UPDATE message_logs
            SET response = :normalized
            WHERE LOWER(response) LIKE :enough
               OR LOWER(response) LIKE :not_enough
               OR LOWER(response) LIKE :buy_credit"
        );

        $stmt->execute([
            ':normalized' => 'Credito finito, ricaricare il provider.',
            ':enough' => '%do not have enough credit%',
            ':not_enough' => '%not enough credit%',
            ':buy_credit' => '%buy credit%',
        ]);
    }

    private function storeCampaignCsv(?array $uploadedFile, string $existingPath = '', string $existingName = ''): array {
        $tmpName = trim((string)($uploadedFile['tmp_name'] ?? ''));
        $originalName = trim((string)($uploadedFile['name'] ?? ''));
        $hasNewFile = $tmpName !== '' && (is_uploaded_file($tmpName) || is_file($tmpName));

        if (!$hasNewFile) {
            if ($existingPath !== '' && is_file($existingPath)) {
                return [
                    'success' => true,
                    'csv_path' => $existingPath,
                    'csv_name' => $existingName,
                ];
            }

            return [
                'success' => true,
                'csv_path' => '',
                'csv_name' => '',
            ];
        }

        $directory = dirname(__DIR__) . '/uploads/campaigns';
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return ['success' => false, 'message' => 'Impossibile creare la cartella delle campagne.'];
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName !== '' ? $originalName : 'campaign.csv'));
        $targetPath = $directory . '/' . uniqid('campaign_', true) . '_' . $safeName;

        if (!@move_uploaded_file($tmpName, $targetPath) && !@copy($tmpName, $targetPath)) {
            return ['success' => false, 'message' => 'Impossibile salvare il file CSV della campagna.'];
        }

        if ($existingPath !== '' && $existingPath !== $targetPath && file_exists($existingPath)) {
            @unlink($existingPath);
        }

        return [
            'success' => true,
            'csv_path' => $targetPath,
            'csv_name' => $originalName !== '' ? $originalName : basename($targetPath),
        ];
    }

    private function readUploadedListCsv(?array $uploadedFile): array {
        $error = (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'has_file' => false, 'recipients' => [], 'invalid_count' => 0, 'original_name' => ''];
        }

        if ($error !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Errore durante il caricamento del CSV.'];
        }

        $maxBytes = max(1024, (int)(getenv('SMS_MAX_CSV_BYTES') ?: 5242880));
        $originalName = basename(trim((string)($uploadedFile['name'] ?? '')));
        if ((int)($uploadedFile['size'] ?? 0) <= 0 || (int)$uploadedFile['size'] > $maxBytes) {
            return ['success' => false, 'message' => 'Il file CSV è vuoto o supera la dimensione massima consentita.'];
        }
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
            return ['success' => false, 'message' => 'Sono ammessi esclusivamente file con estensione .csv.'];
        }

        $tmpName = trim((string)($uploadedFile['tmp_name'] ?? ''));
        if ($tmpName === '' || (!is_uploaded_file($tmpName) && !is_file($tmpName))) {
            return ['success' => false, 'message' => 'File CSV temporaneo non trovato.'];
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName) ?: '';
        $allowedMimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
        if (!in_array($mime, $allowedMimes, true)) {
            return ['success' => false, 'message' => 'Il contenuto del file caricato non è un CSV valido.'];
        }

        $recipientsData = $this->readRecipientsFromCsv($tmpName);
        if (!empty($recipientsData['limit_exceeded'])) {
            return ['success' => false, 'message' => 'Il CSV contiene più contatti del limite consentito.'];
        }
        return [
            'success' => true,
            'has_file' => true,
            'recipients' => $recipientsData['recipients'],
            'invalid_count' => $recipientsData['invalid_count'],
            'original_name' => $originalName !== '' ? $originalName : 'lista.csv',
        ];
    }

    private function replaceListLeads(int $listId, array $recipients): void {
        $deleteStmt = $this->pdo->prepare('DELETE FROM leads WHERE list_id = :list_id');
        $deleteStmt->execute([':list_id' => $listId]);

        if (empty($recipients)) {
            return;
        }

        $insertStmt = $this->pdo->prepare('INSERT INTO leads (list_id, phone) VALUES (:list_id, :phone)');
        foreach ($recipients as $recipient) {
            $insertStmt->execute([
                ':list_id' => $listId,
                ':phone' => (string)$recipient,
            ]);
        }
    }

    private function migrateStoredListCsvFiles(): void {
        $stmt = $this->pdo->query("SELECT id, csv_path FROM sms_lists WHERE csv_path IS NOT NULL AND csv_path <> ''");
        $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lists as $list) {
            $listId = (int)$list['id'];
            $csvPath = (string)$list['csv_path'];
            $recipientsData = $this->readRecipientsFromCsv($csvPath);

            try {
                $this->pdo->beginTransaction();
                $this->replaceListLeads($listId, $recipientsData['recipients']);
                $updateStmt = $this->pdo->prepare('UPDATE sms_lists SET total_contacts = :total_contacts, invalid_contacts = :invalid_contacts WHERE id = :id');
                $updateStmt->execute([
                    ':total_contacts' => count($recipientsData['recipients']),
                    ':invalid_contacts' => $recipientsData['invalid_count'],
                    ':id' => $listId,
                ]);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                continue;
            }

            $fileRemoved = $csvPath === '' || !is_file($csvPath) || @unlink($csvPath);
            if ($fileRemoved) {
                $clearPathStmt = $this->pdo->prepare('UPDATE sms_lists SET csv_path = :csv_path WHERE id = :id');
                $clearPathStmt->execute([
                    ':csv_path' => '',
                    ':id' => $listId,
                ]);
            }
        }
    }

    private function getListStats(int $listId): array {
        $countStmt = $this->pdo->prepare("SELECT
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
            FROM message_logs
            WHERE list_id = :list_id");
        $countStmt->execute([':list_id' => $listId]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $latestStmt = $this->pdo->prepare('SELECT created_at, response FROM message_logs WHERE list_id = :list_id ORDER BY id DESC LIMIT 1');
        $latestStmt->execute([':list_id' => $listId]);
        $latest = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $lastLog = 'Nessun invio registrato';
        if ($latest) {
            $lastLog = (string)$latest['created_at'] . ' - ' . $this->simplifyProviderResponse((string)($latest['response'] ?? ''));
        }

        return [
            'sent_count' => (int)($counts['sent_count'] ?? 0),
            'failed_count' => (int)($counts['failed_count'] ?? 0),
            'last_log' => $lastLog,
        ];
    }

    private function readRecipientsFromCsv(string $csvPath): array {
        if ($csvPath === '' || !is_file($csvPath)) {
            return ['recipients' => [], 'invalid_count' => 0];
        }

        $delimiter = $this->detectCsvDelimiter($csvPath);
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return ['recipients' => [], 'invalid_count' => 0];
        }

        $header = fgetcsv($handle, 0, $delimiter);
        $numberIndex = null;
        $prefixIndex = null;
        if (is_array($header)) {
            $normalizedHeaders = array_map(fn($item) => strtolower(trim((string)$item)), $header);
            $numberIndex = $this->findColumnIndex($normalizedHeaders, ['number', 'phone', 'mobile', 'recipient', 'to', 'destination']);
            $prefixIndex = $this->findColumnIndex($normalizedHeaders, ['prefix', 'country', 'country_code', 'cc']);
        }

        $recipients = [];
        $invalidCount = 0;
        $maxRecipients = max(1, (int)(getenv('SMS_MAX_CSV_RECIPIENTS') ?: 100000));
        $limitExceeded = false;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (!is_array($row) || count(array_filter($row, fn($value) => trim((string)$value) !== '')) === 0) {
                continue;
            }

            $number = $numberIndex !== null && isset($row[$numberIndex]) ? trim((string)$row[$numberIndex]) : (isset($row[0]) ? trim((string)$row[0]) : '');
            $prefix = $prefixIndex !== null && isset($row[$prefixIndex]) ? trim((string)$row[$prefixIndex]) : '';
            $recipient = $this->normalizePhone($number, $prefix);
            if ($recipient === '') {
                $invalidCount++;
                continue;
            }

            $recipients[$recipient] = $recipient;
            if (count($recipients) > $maxRecipients) {
                $limitExceeded = true;
                break;
            }
        }

        fclose($handle);

        return [
            'recipients' => array_values($recipients),
            'invalid_count' => $invalidCount,
            'limit_exceeded' => $limitExceeded,
        ];
    }

    private function validProviderEndpoint(string $endpoint, bool $internalTestProvider = false): bool {
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || strlen($endpoint) > 500) {
            return false;
        }
        $parts = parse_url($endpoint);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || !empty($parts['user']) || !empty($parts['pass'])) {
            return false;
        }
        if ($scheme !== 'https' && !($scheme === 'http' && strtolower((string)getenv('SMS_PROVIDER_ALLOW_HTTP')) === 'true')) {
            return false;
        }
        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.localhost')) {
            return false;
        }
        if ($internalTestProvider) {
            $path = rtrim((string)($parts['path'] ?? ''), '/');
            $port = isset($parts['port']) ? (int)$parts['port'] : 443;
            return $scheme === 'https'
                && $host === 'provtest.book-my.eu'
                && $port === 443
                && $path === '/api/v1/messages';
        }
        $allowPrivate = strtolower((string)getenv('SMS_PROVIDER_ALLOW_PRIVATE')) === 'true';
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : array_values(array_unique(array_filter([
            gethostbyname($host),
            ...array_map(static fn(array $record): string => (string)($record['ip'] ?? $record['ipv6'] ?? ''), (array)@dns_get_record($host, DNS_A | DNS_AAAA)),
        ])));
        if (!$addresses) {
            return false;
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            if (!$allowPrivate && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    public function importCsv(string $tmpFile, int $providerId, string $message, string $from, string $userName): array {
        $results = [];

        if (!is_file($tmpFile)) {
            return ['processed' => 0, 'errors' => ['File non trovato']];
        }

        $delimiter = $this->detectCsvDelimiter($tmpFile);
        $handle = fopen($tmpFile, 'r');
        if ($handle === false) {
            return ['processed' => 0, 'errors' => ['Impossibile leggere il file']];
        }

        $header = fgetcsv($handle, 0, $delimiter);
        $numberIndex = null;
        $prefixIndex = null;
        if (is_array($header)) {
            $normalizedHeaders = array_map(fn($item) => strtolower(trim((string)$item)), $header);
            $numberIndex = $this->findColumnIndex($normalizedHeaders, ['number', 'phone', 'mobile', 'recipient', 'to', 'destination']);
            $prefixIndex = $this->findColumnIndex($normalizedHeaders, ['prefix', 'country', 'country_code', 'cc']);
        }

        $processed = 0;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (!is_array($row) || count(array_filter($row, fn($value) => trim((string)$value) !== '')) === 0) {
                continue;
            }

            $number = $numberIndex !== null && isset($row[$numberIndex]) ? trim((string)$row[$numberIndex]) : (isset($row[0]) ? trim((string)$row[0]) : '');
            $prefix = $prefixIndex !== null && isset($row[$prefixIndex]) ? trim((string)$row[$prefixIndex]) : '';
            $normalizedMessage = $message;
            $recipient = $this->normalizePhone($number, $prefix);
            if ($recipient === '') {
                $this->addLog([
                    'user_name' => $userName,
                    'provider_id' => $providerId,
                    'provider_name' => $this->getProviderName($providerId),
                    'recipient' => $number,
                    'message' => $normalizedMessage,
                    'status' => 'failed',
                    'response' => 'Numero non valido'
                ]);
                $results[] = ['recipient' => $number, 'status' => 'failed', 'response' => 'Numero non valido'];
                $processed++;
                continue;
            }

            $result = $this->sendAndLog($providerId, $recipient, $normalizedMessage, $from, $userName);
            $results[] = ['recipient' => $recipient, 'status' => $result['status'], 'response' => $result['response']];
            $processed++;
        }

        fclose($handle);
        return ['processed' => $processed, 'results' => $results];
    }

    private function findColumnIndex(array $headers, array $keys): ?int {
        foreach ($headers as $index => $header) {
            if (in_array($header, $keys, true)) {
                return $index;
            }
        }
        return null;
    }

    private function getProviderName(int $providerId): string {
        $provider = $this->getProviderById($providerId);
        return $provider['name'] ?? 'Provider';
    }

    public function composeRecipient(string $number, string $prefix = ''): string {
        return $this->normalizePhone($number, $prefix);
    }

    private function normalizePhone(string $number, string $prefix = ''): string {
        $number = trim($number);
        $prefix = trim($prefix);

        if ($number === '') {
            return '';
        }

        if (str_starts_with($number, '+')) {
            $normalized = '+' . preg_replace('/\D/', '', substr($number, 1));
            return strlen($normalized) > 4 ? $normalized : '';
        }

        if (str_starts_with($number, '00')) {
            $normalized = '+' . preg_replace('/\D/', '', substr($number, 2));
            return strlen($normalized) > 4 ? $normalized : '';
        }

        $cleanNumber = preg_replace('/\D/', '', $number);
        $cleanPrefix = preg_replace('/\D/', '', $prefix);

        if ($cleanNumber === '') {
            return '';
        }

        if ($cleanPrefix !== '') {
            $cleanNumber = ltrim($cleanNumber, '0');
            return '+' . $cleanPrefix . $cleanNumber;
        }

        if (strlen($cleanNumber) >= 8) {
            return '+' . $cleanNumber;
        }

        return '';
    }

    private function detectCsvDelimiter(string $tmpFile): string {
        $sample = file_get_contents($tmpFile, false, null, 0, 2048);
        if (!is_string($sample) || $sample === '') {
            return ',';
        }

        $scores = [
            ',' => substr_count($sample, ','),
            ';' => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
        ];

        arsort($scores);
        $delimiter = array_key_first($scores);
        return is_string($delimiter) ? $delimiter : ',';
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);

        if ((int)$stmt->fetchColumn() === 0) {
            $this->pdo->exec($alterSql);
        }
    }

    private function ensureIndex(string $table, string $index, string $alterSql): void {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index_name');
        $stmt->execute([
            ':table' => $table,
            ':index_name' => $index,
        ]);

        if ((int)$stmt->fetchColumn() === 0) {
            $this->pdo->exec($alterSql);
        }
    }

    private function ensureLegacyProviders(): void {
        $legacyProviders = [
            [
                'name' => 'Freevoipdeal',
                'endpoint' => 'https://www.freevoipdeal.com/myaccount/sendsms.php',
                'username' => 'AriEricris',
                'password' => 'CI0127sco',
                'api_key' => '',
                'request_type' => 'GET',
                'default_from' => '',
                'active' => 1,
            ],
        ];

        $findStmt = $this->pdo->prepare('SELECT id FROM providers WHERE name = :name OR endpoint = :endpoint LIMIT 1');
        $insertStmt = $this->pdo->prepare('INSERT INTO providers (name, endpoint, username, password, api_key, request_type, default_from, active) VALUES (:name, :endpoint, :username, :password, :api_key, :request_type, :default_from, :active)');

        foreach ($legacyProviders as $provider) {
            $findStmt->execute([
                ':name' => $provider['name'],
                ':endpoint' => $provider['endpoint'],
            ]);

            if ($findStmt->fetch()) {
                continue;
            }

            $insertStmt->execute([
                ':name' => $provider['name'],
                ':endpoint' => $provider['endpoint'],
                ':username' => $provider['username'],
                ':password' => $provider['password'],
                ':api_key' => $provider['api_key'],
                ':request_type' => $provider['request_type'],
                ':default_from' => $provider['default_from'],
                ':active' => $provider['active'],
            ]);
        }
    }
}
?>
