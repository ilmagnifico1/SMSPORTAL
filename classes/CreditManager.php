<?php

class CreditManager {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Connection::connect()->getConn();
        $this->ensureTables();
    }

    private function ensureTables(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS company_credits (
            company_id INT PRIMARY KEY,
            balance DECIMAL(14,4) NOT NULL DEFAULT 0,
            billing_enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS credit_transactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            amount DECIMAL(14,4) NOT NULL,
            transaction_type VARCHAR(30) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            created_by VARCHAR(100) NOT NULL DEFAULT '',
            reference_id BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_credit_transactions_company (company_id),
            KEY idx_credit_transactions_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS provider_credits (
            provider_id INT PRIMARY KEY,
            balance DECIMAL(14,4) NOT NULL DEFAULT 0,
            credit_control_enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS provider_credit_transactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            provider_id INT NOT NULL,
            amount DECIMAL(14,4) NOT NULL,
            transaction_type VARCHAR(30) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            created_by VARCHAR(100) NOT NULL DEFAULT '',
            reference_id BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_provider_credit_transactions_provider (provider_id),
            KEY idx_provider_credit_transactions_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sms_prefix_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prefix VARCHAR(24) NOT NULL UNIQUE,
            destination VARCHAR(150) NOT NULL DEFAULT '',
            price DECIMAL(10,4) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS provider_prefix_costs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider_id INT NOT NULL,
            prefix VARCHAR(64) NOT NULL,
            destination VARCHAR(150) NOT NULL DEFAULT '',
            operator_name VARCHAR(150) NOT NULL DEFAULT '',
            purchase_price DECIMAL(10,4) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_provider_prefix_cost (provider_id, prefix),
            KEY idx_provider_prefix_cost_provider (provider_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS company_provider_prices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            provider_id INT NOT NULL,
            prefix VARCHAR(64) NOT NULL,
            destination VARCHAR(150) NOT NULL DEFAULT '',
            operator_name VARCHAR(150) NOT NULL DEFAULT '',
            sale_price DECIMAL(10,4) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_company_provider_prefix_price (company_id, provider_id, prefix),
            KEY idx_company_provider_price_company (company_id),
            KEY idx_company_provider_price_provider (provider_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec('INSERT IGNORE INTO company_credits (company_id) SELECT id FROM companies');
        $this->pdo->exec('INSERT IGNORE INTO provider_credits (provider_id) SELECT id FROM providers');
        $this->migrateLegacyPrices();
        $this->migrateOperatorPricingSchema();
    }

    private function migrateLegacyPrices(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'billing_v2_migrated' LIMIT 1");
        $stmt->execute();
        if ((string)$stmt->fetchColumn() === '1') {
            return;
        }
        $this->pdo->exec('INSERT IGNORE INTO company_provider_prices (company_id, provider_id, prefix, sale_price, active) SELECT cp.company_id, cp.provider_id, spp.prefix, spp.price, spp.active FROM company_providers cp CROSS JOIN sms_prefix_prices spp');
        $this->pdo->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES ('billing_v2_migrated', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
    }

    private function migrateOperatorPricingSchema(): void {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'billing_operator_rules_migrated' LIMIT 1");
        $stmt->execute();
        if ((string)$stmt->fetchColumn() === '1') return;

        $this->ensureColumn('provider_prefix_costs', 'operator_name',
            "ALTER TABLE provider_prefix_costs ADD COLUMN operator_name VARCHAR(150) NOT NULL DEFAULT '' AFTER destination");
        $this->ensureColumn('company_provider_prices', 'destination',
            "ALTER TABLE company_provider_prices ADD COLUMN destination VARCHAR(150) NOT NULL DEFAULT '' AFTER prefix");
        $this->ensureColumn('company_provider_prices', 'operator_name',
            "ALTER TABLE company_provider_prices ADD COLUMN operator_name VARCHAR(150) NOT NULL DEFAULT '' AFTER destination");
        $this->pdo->exec("ALTER TABLE provider_prefix_costs MODIFY COLUMN prefix VARCHAR(64) NOT NULL");
        $this->pdo->exec("ALTER TABLE company_provider_prices MODIFY COLUMN prefix VARCHAR(64) NOT NULL");
        if ($this->tableExists('message_logs')) {
            $this->ensureColumn('message_logs', 'price_operator', "ALTER TABLE message_logs ADD COLUMN price_operator VARCHAR(150) NOT NULL DEFAULT '' AFTER price_prefix");
            if ($this->columnExists('message_logs', 'price_prefix')) $this->pdo->exec("ALTER TABLE message_logs MODIFY COLUMN price_prefix VARCHAR(64) NOT NULL DEFAULT ''");
            if ($this->columnExists('message_logs', 'purchase_prefix')) $this->pdo->exec("ALTER TABLE message_logs MODIFY COLUMN purchase_prefix VARCHAR(64) NOT NULL DEFAULT ''");
        }
        $this->pdo->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES ('billing_operator_rules_migrated', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
    }

    public function getBalances(): array {
        $query = 'SELECT c.id AS company_id, c.name AS company_name, COALESCE(cc.balance, 0) AS balance, COALESCE(cc.billing_enabled, 0) AS billing_enabled, cc.updated_at FROM companies c LEFT JOIN company_credits cc ON cc.company_id = c.id';
        $params = [];
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $query .= ' WHERE c.id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        }
        $stmt = $this->pdo->prepare($query . ' ORDER BY c.name');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTransactions(int $companyId = 0, int $limit = 200): array {
        $query = 'SELECT ct.*, c.name AS company_name FROM credit_transactions ct JOIN companies c ON c.id = ct.company_id';
        $params = [];
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $query .= ' WHERE ct.company_id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        } elseif ($companyId > 0) {
            $query .= ' WHERE ct.company_id = :company_id';
            $params[':company_id'] = $companyId;
        }
        $stmt = $this->pdo->prepare($query . ' ORDER BY ct.id DESC LIMIT ' . max(1, min($limit, 500)));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProviderBalances(): array {
        if (!(function_exists('is_super_admin') && is_super_admin())) { return []; }
        return $this->pdo->query("SELECT p.id AS provider_id, p.name AS provider_name,
            COALESCE(pc.balance, 0) AS balance,
            COALESCE(pc.credit_control_enabled, 0) AS credit_control_enabled,
            COALESCE(fin.profit_total, 0) AS profit_total,
            pc.updated_at
            FROM providers p
            LEFT JOIN provider_credits pc ON pc.provider_id = p.id
            LEFT JOIN (SELECT provider_id, SUM(profit_amount) AS profit_total FROM message_logs WHERE status = 'sent' GROUP BY provider_id) fin ON fin.provider_id = p.id
            ORDER BY p.name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProviderTransactions(int $providerId = 0, int $limit = 200): array {
        if (!(function_exists('is_super_admin') && is_super_admin())) { return []; }
        $query = 'SELECT pct.*, p.name AS provider_name FROM provider_credit_transactions pct JOIN providers p ON p.id = pct.provider_id';
        $params = [];
        if ($providerId > 0) { $query .= ' WHERE pct.provider_id = :provider_id'; $params[':provider_id'] = $providerId; }
        $stmt = $this->pdo->prepare($query . ' ORDER BY pct.id DESC LIMIT ' . max(1, min($limit, 500)));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function adjustProviderBalance(int $providerId, float $amount, string $description, string $createdBy): bool {
        if (!(function_exists('is_super_admin') && is_super_admin()) || $providerId <= 0 || abs($amount) < 0.0001) { return false; }
        if ($this->isInternalProvider($providerId)) { return false; }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO provider_credits (provider_id) VALUES (:provider_id)');
            $stmt->execute([':provider_id' => $providerId]);
            $stmt = $this->pdo->prepare('UPDATE provider_credits SET balance = balance + :amount_delta, credit_control_enabled = 1 WHERE provider_id = :provider_id AND balance + :amount_check >= 0');
            $stmt->execute([':amount_delta' => $amount, ':amount_check' => $amount, ':provider_id' => $providerId]);
            if ($stmt->rowCount() !== 1) { throw new RuntimeException('Saldo provider insufficiente.'); }
            $stmt = $this->pdo->prepare('INSERT INTO provider_credit_transactions (provider_id, amount, transaction_type, description, created_by) VALUES (:provider_id, :amount, :type, :description, :created_by)');
            $stmt->execute([':provider_id' => $providerId, ':amount' => $amount, ':type' => $amount > 0 ? 'recharge' : 'adjustment', ':description' => trim($description), ':created_by' => $createdBy]);
            if ($ownsTransaction) { $this->pdo->commit(); }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return false;
        }
    }

    public function setProviderCreditControl(int $providerId, bool $enabled): bool {
        if (!(function_exists('is_super_admin') && is_super_admin()) || $providerId <= 0) { return false; }
        if ($this->isInternalProvider($providerId)) { return false; }
        $stmt = $this->pdo->prepare('INSERT INTO provider_credits (provider_id, credit_control_enabled) VALUES (:provider_id, :enabled) ON DUPLICATE KEY UPDATE credit_control_enabled = VALUES(credit_control_enabled)');
        return $stmt->execute([':provider_id' => $providerId, ':enabled' => $enabled ? 1 : 0]);
    }

    public function getCompanyFinancials(): array {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return [];
        }
        $rows = $this->pdo->query("SELECT c.id AS company_id,
                COALESCE(cc.balance, 0) AS credit_balance,
                COALESCE(SUM(CASE WHEN ml.status = 'sent' THEN ml.profit_amount ELSE 0 END), 0) AS profit_total
            FROM companies c
            LEFT JOIN company_credits cc ON cc.company_id = c.id
            LEFT JOIN message_logs ml ON ml.company_id = c.id
            GROUP BY c.id, cc.balance")->fetchAll(PDO::FETCH_ASSOC);
        $financials = [];
        foreach ($rows as $row) {
            $financials[(int)$row['company_id']] = [
                'credit_balance' => (float)$row['credit_balance'],
                'profit_total' => (float)$row['profit_total'],
            ];
        }
        return $financials;
    }

    public function adjustBalance(int $companyId, float $amount, string $description, string $createdBy): bool {
        if (!(function_exists('is_super_admin') && is_super_admin()) || $companyId <= 0 || abs($amount) < 0.0001) {
            return false;
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO company_credits (company_id) VALUES (:company_id)');
            $stmt->execute([':company_id' => $companyId]);
            $stmt = $this->pdo->prepare('UPDATE company_credits SET balance = balance + :amount_delta WHERE company_id = :company_id AND balance + :amount_check >= 0');
            $stmt->execute([':amount_delta' => $amount, ':amount_check' => $amount, ':company_id' => $companyId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Saldo insufficiente.');
            }
            $stmt = $this->pdo->prepare('INSERT INTO credit_transactions (company_id, amount, transaction_type, description, created_by) VALUES (:company_id, :amount, :type, :description, :created_by)');
            $stmt->execute([':company_id' => $companyId, ':amount' => $amount, ':type' => $amount > 0 ? 'recharge' : 'adjustment', ':description' => trim($description), ':created_by' => $createdBy]);
            if ($ownsTransaction) { $this->pdo->commit(); }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    public function setBillingEnabled(int $companyId, bool $enabled): bool {
        if (!(function_exists('is_super_admin') && is_super_admin()) || $companyId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('INSERT INTO company_credits (company_id, billing_enabled) VALUES (:company_id, :enabled) ON DUPLICATE KEY UPDATE billing_enabled = VALUES(billing_enabled)');
        return $stmt->execute([':company_id' => $companyId, ':enabled' => $enabled ? 1 : 0]);
    }

    public function getPrices(bool $activeOnly = false): array {
        $query = 'SELECT * FROM sms_prefix_prices';
        if ($activeOnly) {
            $query .= ' WHERE active = 1';
        }
        return $this->pdo->query($query . ' ORDER BY CASE WHEN prefix = "*" THEN 1 ELSE 0 END, LENGTH(prefix) DESC, prefix')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPrice(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM sms_prefix_prices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function savePrice(array $data): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        $id = (int)($data['id'] ?? 0);
        $prefix = trim((string)($data['prefix'] ?? ''));
        $prefix = $prefix === '*' ? '*' : preg_replace('/\D+/', '', $prefix);
        $destination = trim((string)($data['destination'] ?? ''));
        $price = round((float)($data['price'] ?? 0), 4);
        $active = !empty($data['active']) ? 1 : 0;
        if ($prefix === '' || $price < 0) {
            return false;
        }
        try {
            if ($id > 0) {
                $stmt = $this->pdo->prepare('UPDATE sms_prefix_prices SET prefix = :prefix, destination = :destination, price = :price, active = :active WHERE id = :id');
                return $stmt->execute([':prefix' => $prefix, ':destination' => $destination, ':price' => $price, ':active' => $active, ':id' => $id]);
            }
            $stmt = $this->pdo->prepare('INSERT INTO sms_prefix_prices (prefix, destination, price, active) VALUES (:prefix, :destination, :price, :active)');
            return $stmt->execute([':prefix' => $prefix, ':destination' => $destination, ':price' => $price, ':active' => $active]);
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function deletePrice(int $id): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM sms_prefix_prices WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function getPurchaseCosts(): array {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return [];
        }
        return $this->pdo->query('SELECT ppc.*, p.name AS provider_name FROM provider_prefix_costs ppc JOIN providers p ON p.id = ppc.provider_id ORDER BY p.name, CASE WHEN ppc.prefix = "*" THEN 1 ELSE 0 END, LENGTH(ppc.prefix) DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPurchaseCost(int $id): ?array {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM provider_prefix_costs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function savePurchaseCost(array $data): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        $id = (int)($data['id'] ?? 0);
        $providerId = (int)($data['provider_id'] ?? 0);
        $prefix = $this->pricingRuleFromInput($data);
        $destination = trim((string)($data['destination'] ?? ''));
        $operatorName = trim((string)($data['operator_name'] ?? ''));
        $price = round((float)str_replace(',', '.', (string)($data['purchase_price'] ?? 0)), 4);
        $active = !empty($data['active']) ? 1 : 0;
        if ($providerId <= 0 || $this->isInternalProvider($providerId) || $prefix === '' || $price < 0 || ($this->isOperatorRule($prefix) && $operatorName === '')) {
            return false;
        }
        try {
            if ($id > 0) {
                $stmt = $this->pdo->prepare('UPDATE provider_prefix_costs SET provider_id = :provider_id, prefix = :prefix, destination = :destination, operator_name = :operator_name, purchase_price = :price, active = :active WHERE id = :id');
                return $stmt->execute([':provider_id' => $providerId, ':prefix' => $prefix, ':destination' => $destination, ':operator_name' => $operatorName, ':price' => $price, ':active' => $active, ':id' => $id]);
            }
            $stmt = $this->pdo->prepare('INSERT INTO provider_prefix_costs (provider_id, prefix, destination, operator_name, purchase_price, active) VALUES (:provider_id, :prefix, :destination, :operator_name, :price, :active)');
            return $stmt->execute([':provider_id' => $providerId, ':prefix' => $prefix, ':destination' => $destination, ':operator_name' => $operatorName, ':price' => $price, ':active' => $active]);
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function deletePurchaseCost(int $id): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM provider_prefix_costs WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function getSalePrices(): array {
        $query = 'SELECT cpp.*, c.name AS company_name, p.name AS provider_name
            FROM company_provider_prices cpp JOIN companies c ON c.id = cpp.company_id JOIN providers p ON p.id = cpp.provider_id';
        $params = [];
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $query .= ' WHERE cpp.company_id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        }
        $stmt = $this->pdo->prepare($query . ' ORDER BY c.name, p.name, CASE WHEN cpp.prefix = "*" THEN 1 ELSE 0 END, LENGTH(cpp.prefix) DESC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $purchaseRows = $this->pdo->query('SELECT * FROM provider_prefix_costs WHERE active = 1 ORDER BY CASE WHEN prefix = "*" THEN 1 ELSE 0 END, LENGTH(prefix) DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $representative = $this->representativeNumberForRule((string)$row['prefix']);
            $providerCosts = array_values(array_filter($purchaseRows, static fn(array $cost): bool => (int)$cost['provider_id'] === (int)$row['provider_id']));
            $purchase = $this->matchPrefix($providerCosts, $representative);
            $row['purchase_price'] = $purchase['purchase_price'] ?? null;
            $row['purchase_prefix'] = $purchase['prefix'] ?? '';
            if ((string)($row['destination'] ?? '') === '') $row['destination'] = (string)($purchase['destination'] ?? '');
            if ((string)($row['operator_name'] ?? '') === '') $row['operator_name'] = (string)($purchase['operator_name'] ?? '');
        }
        unset($row);
        return $rows;
    }

    public function getSalePrice(int $id): ?array {
        $query = 'SELECT * FROM company_provider_prices WHERE id = :id';
        $params = [':id' => $id];
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        }
        $stmt = $this->pdo->prepare($query . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveSalePrice(array $data): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        $id = (int)($data['id'] ?? 0);
        $companyId = (int)($data['company_id'] ?? 0);
        $providerId = (int)($data['provider_id'] ?? 0);
        $prefix = $this->pricingRuleFromInput($data);
        $destination = trim((string)($data['destination'] ?? ''));
        $operatorName = trim((string)($data['operator_name'] ?? ''));
        $price = round((float)str_replace(',', '.', (string)($data['sale_price'] ?? 0)), 4);
        $active = !empty($data['active']) ? 1 : 0;
        if ($companyId <= 0 || $providerId <= 0 || $this->isInternalProvider($providerId) || $prefix === '' || $price < 0 || ($this->isOperatorRule($prefix) && $operatorName === '')) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM company_providers WHERE company_id = :company_id AND provider_id = :provider_id LIMIT 1');
        $stmt->execute([':company_id' => $companyId, ':provider_id' => $providerId]);
        if (!$stmt->fetchColumn()) {
            return false;
        }
        try {
            if ($id > 0) {
                $stmt = $this->pdo->prepare('UPDATE company_provider_prices SET company_id = :company_id, provider_id = :provider_id, prefix = :prefix, destination = :destination, operator_name = :operator_name, sale_price = :price, active = :active WHERE id = :id');
                return $stmt->execute([':company_id' => $companyId, ':provider_id' => $providerId, ':prefix' => $prefix, ':destination' => $destination, ':operator_name' => $operatorName, ':price' => $price, ':active' => $active, ':id' => $id]);
            }
            $stmt = $this->pdo->prepare('INSERT INTO company_provider_prices (company_id, provider_id, prefix, destination, operator_name, sale_price, active) VALUES (:company_id, :provider_id, :prefix, :destination, :operator_name, :price, :active)');
            return $stmt->execute([':company_id' => $companyId, ':provider_id' => $providerId, ':prefix' => $prefix, ':destination' => $destination, ':operator_name' => $operatorName, ':price' => $price, ':active' => $active]);
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function deleteSalePrice(int $id): bool {
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM company_provider_prices WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function reserve(int $companyId, int $providerId, string $recipient, string $message, string $createdBy): array {
        $stmt = $this->pdo->prepare('SELECT balance, billing_enabled FROM company_credits WHERE company_id = :company_id LIMIT 1');
        $stmt->execute([':company_id' => $companyId]);
        $credit = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['balance' => 0, 'billing_enabled' => 0];
        $providerStmt = $this->pdo->prepare('SELECT balance, credit_control_enabled FROM provider_credits WHERE provider_id = :provider_id LIMIT 1');
        $providerStmt->execute([':provider_id' => $providerId]);
        $providerCredit = $providerStmt->fetch(PDO::FETCH_ASSOC) ?: ['balance' => 0, 'credit_control_enabled' => 0];
        $companyBilling = (int)$credit['billing_enabled'] === 1;
        $providerControl = true;
        $quote = $this->quote($companyId, $providerId, $recipient, $message);
        if (($quote['error_code'] ?? '') === 'sale_prefix_unauthorized') {
            return array_merge(['success' => false, 'reserved' => false, 'balance_before' => (float)$credit['balance'], 'balance_after' => (float)$credit['balance']], $quote);
        }
        if (!$companyBilling && !$providerControl) {
            return ['success' => true, 'reserved' => false, 'cost' => 0.0, 'sale_total' => 0.0, 'purchase_total' => 0.0, 'profit' => 0.0, 'sale_unit_price' => 0.0, 'purchase_unit_price' => 0.0, 'segments' => (int)($quote['segments'] ?? $this->segments($message)), 'prefix' => (string)($quote['prefix'] ?? ''), 'purchase_prefix' => '', 'balance_before' => (float)$credit['balance'], 'balance_after' => (float)$credit['balance']];
        }
        if (!empty($quote['error_code'])) {
            return array_merge(['success' => false, 'reserved' => false, 'balance_before' => (float)$credit['balance'], 'balance_after' => (float)$credit['balance']], $quote);
        }
        $companyCost = $companyBilling ? (float)$quote['sale_total'] : 0.0;
        $providerCost = $providerControl ? (float)$quote['purchase_total'] : 0.0;
        if ($providerControl && ((float)$providerCredit['balance'] <= 0 || (float)$providerCredit['balance'] + 0.0000001 < $providerCost)) {
            return [
                'success' => false,
                'reserved' => false,
                'error_code' => 'provider_credit_insufficient',
                'message' => 'Invio bloccato: credito residuo del provider insufficiente o esaurito.',
                'segments' => (int)$quote['segments'],
                'prefix' => (string)$quote['prefix'],
                'purchase_prefix' => (string)$quote['purchase_prefix'],
                'balance_before' => (float)$credit['balance'],
                'balance_after' => (float)$credit['balance'],
                'provider_balance_before' => (float)$providerCredit['balance'],
                'provider_balance_after' => (float)$providerCredit['balance'],
            ];
        }
        $quote['cost'] = $companyCost;
        if (!$companyBilling) { $quote['sale_total'] = 0.0; }
        if (!$providerControl && !$companyBilling) { $quote['purchase_total'] = 0.0; }
        $quote['profit'] = round((float)$quote['sale_total'] - (float)$quote['purchase_total'], 4);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            if ($companyBilling && $companyCost > 0) {
                $stmt = $this->pdo->prepare('UPDATE company_credits SET balance = balance - :cost_debit WHERE company_id = :company_id AND balance >= :cost_check');
                $stmt->execute([':cost_debit' => $quote['cost'], ':cost_check' => $quote['cost'], ':company_id' => $companyId]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Credito insufficiente.');
                }
            }
            $transactionId = 0;
            if ($companyBilling) {
                $stmt = $this->pdo->prepare('INSERT INTO credit_transactions (company_id, amount, transaction_type, description, created_by) VALUES (:company_id, :amount, "sms_debit", :description, :created_by)');
                $stmt->execute([':company_id' => $companyId, ':amount' => -$companyCost, ':description' => 'SMS verso ' . $recipient . ' · provider #' . $providerId . ' · regola ' . $quote['prefix'] . ((string)($quote['operator_name'] ?? '') !== '' ? ' · operatore ' . $quote['operator_name'] : '') . ' · ' . $quote['segments'] . ' segmenti', ':created_by' => $createdBy]);
                $transactionId = (int)$this->pdo->lastInsertId();
            }
            $providerTransactionId = 0;
            if ($providerControl && $providerCost > 0) {
                $stmt = $this->pdo->prepare('UPDATE provider_credits SET balance = balance - :cost_debit WHERE provider_id = :provider_id AND balance >= :cost_check');
                $stmt->execute([':cost_debit' => $providerCost, ':cost_check' => $providerCost, ':provider_id' => $providerId]);
                if ($stmt->rowCount() !== 1) { throw new RuntimeException('Credito provider insufficiente.'); }
                $stmt = $this->pdo->prepare('INSERT INTO provider_credit_transactions (provider_id, amount, transaction_type, description, created_by, reference_id) VALUES (:provider_id, :amount, "sms_debit", :description, :created_by, :reference_id)');
                $stmt->execute([':provider_id' => $providerId, ':amount' => -$providerCost, ':description' => 'Costo SMS verso ' . $recipient . ' · prefisso ' . $quote['purchase_prefix'] . ' · ' . $quote['segments'] . ' segmenti', ':created_by' => $createdBy, ':reference_id' => $transactionId]);
                $providerTransactionId = (int)$this->pdo->lastInsertId();
            }
            $balance = (float)$this->pdo->query('SELECT balance FROM company_credits WHERE company_id = ' . (int)$companyId)->fetchColumn();
            $providerBalance = (float)$this->pdo->query('SELECT balance FROM provider_credits WHERE provider_id = ' . (int)$providerId)->fetchColumn();
            if ($ownsTransaction) { $this->pdo->commit(); }
            return array_merge(['success' => true, 'reserved' => $companyBilling || $providerControl, 'company_reserved' => $companyBilling, 'provider_reserved' => $providerControl, 'provider_id' => $providerId, 'transaction_id' => $transactionId, 'provider_transaction_id' => $providerTransactionId, 'balance_before' => (float)$credit['balance'], 'balance_after' => $balance, 'provider_balance_before' => (float)$providerCredit['balance'], 'provider_balance_after' => $providerBalance], $quote);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'reserved' => false, 'message' => $exception->getMessage()];
        }
    }

    public function refund(int $companyId, array $reservation, string $createdBy): void {
        if (empty($reservation['reserved'])) {
            return;
        }
        $cost = (float)$reservation['cost'];
        $providerCost = (float)($reservation['purchase_total'] ?? 0);
        $providerId = (int)($reservation['provider_id'] ?? 0);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            if (!empty($reservation['company_reserved']) && $cost > 0) {
                $stmt = $this->pdo->prepare('UPDATE company_credits SET balance = balance + :cost WHERE company_id = :company_id');
                $stmt->execute([':cost' => $cost, ':company_id' => $companyId]);
                $stmt = $this->pdo->prepare('INSERT INTO credit_transactions (company_id, amount, transaction_type, description, created_by, reference_id) VALUES (:company_id, :amount, "sms_refund", "Rimborso invio SMS non riuscito", :created_by, :reference_id)');
                $stmt->execute([':company_id' => $companyId, ':amount' => $cost, ':created_by' => $createdBy, ':reference_id' => (int)($reservation['transaction_id'] ?? 0)]);
            }
            if (!empty($reservation['provider_reserved']) && $providerId > 0 && $providerCost > 0) {
                $stmt = $this->pdo->prepare('UPDATE provider_credits SET balance = balance + :cost WHERE provider_id = :provider_id');
                $stmt->execute([':cost' => $providerCost, ':provider_id' => $providerId]);
                $stmt = $this->pdo->prepare('INSERT INTO provider_credit_transactions (provider_id, amount, transaction_type, description, created_by, reference_id) VALUES (:provider_id, :amount, "sms_refund", "Rimborso costo provider per invio non riuscito", :created_by, :reference_id)');
                $stmt->execute([':provider_id' => $providerId, ':amount' => $providerCost, ':created_by' => $createdBy, ':reference_id' => (int)($reservation['provider_transaction_id'] ?? 0)]);
            }
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    public function estimateCampaign(int $companyId, int $providerId, array $recipients, string $message): array {
        $creditStmt = $this->pdo->prepare('SELECT balance, billing_enabled FROM company_credits WHERE company_id = :company_id LIMIT 1');
        $creditStmt->execute([':company_id' => $companyId]);
        $credit = $creditStmt->fetch(PDO::FETCH_ASSOC) ?: ['balance' => 0, 'billing_enabled' => 0];
        $providerCreditStmt = $this->pdo->prepare('SELECT balance, credit_control_enabled FROM provider_credits WHERE provider_id = :provider_id LIMIT 1');
        $providerCreditStmt->execute([':provider_id' => $providerId]);
        $providerCredit = $providerCreditStmt->fetch(PDO::FETCH_ASSOC) ?: ['balance' => 0, 'credit_control_enabled' => 0];

        [$salePrices, $purchasePrices] = $this->pricingRows($companyId, $providerId);
        $saleTotal = 0.0;
        $purchaseTotal = 0.0;
        $segments = 0;
        $quoted = 0;
        $errors = [];
        $breakdown = [];

        foreach ($recipients as $recipientData) {
            $recipient = is_array($recipientData) ? (string)($recipientData['phone'] ?? '') : (string)$recipientData;
            $quote = $this->quoteFromPrices($salePrices, $purchasePrices, $recipient, $message);
            if (!empty($quote['error_code'])) {
                $errorCode = (string)$quote['error_code'];
                if (!isset($errors[$errorCode])) {
                    $errors[$errorCode] = [
                        'code' => $errorCode,
                        'message' => (string)($quote['public_message'] ?? $quote['message'] ?? 'Tariffa non disponibile.'),
                        'count' => 0,
                        'samples' => [],
                    ];
                }
                $errors[$errorCode]['count']++;
                if (count($errors[$errorCode]['samples']) < 3) $errors[$errorCode]['samples'][] = $recipient;
                continue;
            }

            $quoted++;
            $segments += (int)$quote['segments'];
            $saleTotal += (float)$quote['sale_total'];
            $purchaseTotal += (float)$quote['purchase_total'];
            $key = implode('|', [(string)$quote['prefix'], (string)$quote['operator_name'], (string)$quote['sale_unit_price'], (string)$quote['segments']]);
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'destination' => (string)$quote['destination'],
                    'operator_name' => (string)$quote['operator_name'],
                    'prefix' => (string)$quote['prefix'],
                    'recipients' => 0,
                    'segments' => 0,
                    'unit_price' => (float)$quote['sale_unit_price'],
                    'total' => 0.0,
                ];
            }
            $breakdown[$key]['recipients']++;
            $breakdown[$key]['segments'] += (int)$quote['segments'];
            $breakdown[$key]['total'] = round((float)$breakdown[$key]['total'] + (float)$quote['sale_total'], 4);
        }

        $saleTotal = round($saleTotal, 4);
        $purchaseTotal = round($purchaseTotal, 4);
        $companyBalance = (float)$credit['balance'];
        $providerBalance = (float)$providerCredit['balance'];
        $billingEnabled = (int)$credit['billing_enabled'] === 1;
        $providerControlEnabled = (int)$providerCredit['credit_control_enabled'] === 1;
        $companySufficient = !$billingEnabled || $companyBalance + 0.0000001 >= $saleTotal;
        $providerSufficient = !$providerControlEnabled || $providerBalance + 0.0000001 >= $purchaseTotal;
        $canStart = $recipients !== [] && $errors === [] && $companySufficient && $providerSufficient;

        $messageText = 'Spesa prevista: € ' . number_format($saleTotal, 4, ',', '.') . '.';
        if ($errors !== []) $messageText = 'Campagna bloccata: uno o più destinatari non hanno una tariffa valida.';
        elseif (!$companySufficient) $messageText = 'Campagna bloccata: credito aziendale insufficiente per completare tutti gli invii.';
        elseif (!$providerSufficient) $messageText = 'Campagna bloccata: credito provider insufficiente per completare tutti gli invii.';
        elseif ($recipients === []) $messageText = 'Campagna bloccata: la lista non contiene destinatari.';

        return [
            'success' => $errors === [],
            'can_start' => $canStart,
            'message' => $messageText,
            'recipient_count' => count($recipients),
            'quoted_count' => $quoted,
            'segments' => $segments,
            'expected_cost' => $saleTotal,
            'expected_purchase_cost' => $purchaseTotal,
            'balance' => $companyBalance,
            'balance_after' => round($companyBalance - ($billingEnabled ? $saleTotal : 0.0), 4),
            'billing_enabled' => $billingEnabled,
            'company_credit_sufficient' => $companySufficient,
            'provider_balance' => $providerBalance,
            'provider_balance_after' => round($providerBalance - ($providerControlEnabled ? $purchaseTotal : 0.0), 4),
            'provider_credit_control_enabled' => $providerControlEnabled,
            'provider_credit_sufficient' => $providerSufficient,
            'errors' => array_values($errors),
            'breakdown' => array_values($breakdown),
        ];
    }

    private function quote(int $companyId, int $providerId, string $recipient, string $message): array {
        [$salePrices, $purchasePrices] = $this->pricingRows($companyId, $providerId);
        return $this->quoteFromPrices($salePrices, $purchasePrices, $recipient, $message);
    }

    private function pricingRows(int $companyId, int $providerId): array {
        $saleStmt = $this->pdo->prepare('SELECT prefix, destination, operator_name, sale_price FROM company_provider_prices WHERE company_id = :company_id AND provider_id = :provider_id AND active = 1 ORDER BY CASE WHEN prefix = "*" THEN 1 ELSE 0 END, LENGTH(prefix) DESC');
        $saleStmt->execute([':company_id' => $companyId, ':provider_id' => $providerId]);
        $purchaseStmt = $this->pdo->prepare('SELECT prefix, destination, operator_name, purchase_price FROM provider_prefix_costs WHERE provider_id = :provider_id AND active = 1 ORDER BY CASE WHEN prefix = "*" THEN 1 ELSE 0 END, LENGTH(prefix) DESC');
        $purchaseStmt->execute([':provider_id' => $providerId]);
        return [$saleStmt->fetchAll(PDO::FETCH_ASSOC), $purchaseStmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function quoteFromPrices(array $salePrices, array $purchasePrices, string $recipient, string $message): array {
        $number = preg_replace('/\D+/', '', $recipient) ?: '';
        $sale = $this->matchPrefix($salePrices, $number);
        $purchase = $this->matchPrefix($purchasePrices, $number);
        $destination = $this->destinationForNumber($number);
        if (!$sale) {
            $prefixLabel = (string)($destination['prefix'] ?? '');
            $prefixDisplay = $prefixLabel !== '' ? '+' . $prefixLabel : 'prefisso sconosciuto';
            $countryLabel = (string)($destination['country'] ?? 'Paese sconosciuto');
            return [
                'error_code' => 'sale_prefix_unauthorized',
                'message' => 'Invio bloccato: Paese e/o prefisso non autorizzato (' . $countryLabel . ', ' . $prefixDisplay . '). Nessun prezzo di vendita attivo per questa azienda e provider.',
                'public_message' => 'Invio bloccato: Paese e/o prefisso non autorizzato (' . $countryLabel . ', ' . $prefixDisplay . ').',
                'prefix' => $prefixLabel,
                'destination' => $countryLabel,
                'segments' => $this->segments($message),
            ];
        }
        if (!$purchase) {
            return [
                'error_code' => 'purchase_price_missing',
                'message' => 'Invio bloccato: costo di acquisto non configurato per il provider e il prefisso +' . (string)($destination['prefix'] ?? $sale['prefix']) . '.',
                'prefix' => (string)$sale['prefix'],
                'destination' => (string)($destination['country'] ?? ''),
                'segments' => $this->segments($message),
            ];
        }
        $segments = $this->segments($message);
        $saleTotal = round((float)$sale['sale_price'] * $segments, 4);
        $purchaseTotal = round((float)$purchase['purchase_price'] * $segments, 4);
        return [
            'cost' => $saleTotal,
            'sale_total' => $saleTotal,
            'purchase_total' => $purchaseTotal,
            'profit' => round($saleTotal - $purchaseTotal, 4),
            'sale_unit_price' => (float)$sale['sale_price'],
            'purchase_unit_price' => (float)$purchase['purchase_price'],
            'segments' => $segments,
            'prefix' => (string)$sale['prefix'],
            'purchase_prefix' => (string)$purchase['prefix'],
            'operator_name' => (string)($sale['operator_name'] ?: ($purchase['operator_name'] ?? '')),
            'destination' => (string)($sale['destination'] ?: ($purchase['destination'] ?? ($destination['country'] ?? ''))),
        ];
    }

    private function matchPrefix(array $prices, string $number): ?array {
        $best = null;
        $bestScore = -1;
        foreach ($prices as $price) {
            $score = $this->ruleMatchScore((string)($price['prefix'] ?? ''), $number);
            if ($score !== null && $score > $bestScore) {
                $best = $price;
                $bestScore = $score;
            }
        }
        return $best;
    }

    private function ruleMatchScore(string $rule, string $number): ?int {
        if ($rule === '*') return 0;
        if (preg_match('/^(\d{1,8}):(\d{1,24})-(\d{1,24})$/', $rule, $matches) === 1) {
            $countryPrefix = $matches[1];
            $start = $matches[2];
            $end = $matches[3];
            if (strlen($start) !== strlen($end) || strcmp($start, $end) > 0 || !str_starts_with($number, $countryPrefix)) return null;
            $nationalSlice = substr($number, strlen($countryPrefix), strlen($start));
            if (strlen($nationalSlice) !== strlen($start) || strcmp($nationalSlice, $start) < 0 || strcmp($nationalSlice, $end) > 0) return null;
            return (strlen($countryPrefix) + strlen($start)) * 10 + 1;
        }
        if ($rule !== '' && ctype_digit($rule) && str_starts_with($number, $rule)) {
            return strlen($rule) * 10 + 2;
        }
        return null;
    }

    public function describePricingRule(string $rule): array {
        $rule = trim($rule);
        if ($rule === '*') return ['type' => 'global', 'country_prefix' => '*', 'national_prefix' => '', 'range_start' => '', 'range_end' => '', 'label' => 'Tutti i Paesi'];
        if (preg_match('/^(\d{1,8}):(\d{1,24})-(\d{1,24})$/', $rule, $matches) === 1) {
            return ['type' => 'range', 'country_prefix' => $matches[1], 'national_prefix' => '', 'range_start' => $matches[2], 'range_end' => $matches[3], 'label' => '+' . $matches[1] . ' · ' . $matches[2] . '–' . $matches[3]];
        }
        $normalized = $this->normalizePrefix($rule);
        $destination = $this->destinationForNumber($normalized);
        $countryPrefix = (string)($destination['prefix'] ?? '');
        $nationalPrefix = $countryPrefix !== '' && str_starts_with($normalized, $countryPrefix) ? substr($normalized, strlen($countryPrefix)) : '';
        $type = $nationalPrefix !== '' ? 'subprefix' : 'country';
        return ['type' => $type, 'country_prefix' => $countryPrefix !== '' ? $countryPrefix : $normalized, 'national_prefix' => $nationalPrefix, 'range_start' => '', 'range_end' => '', 'label' => '+' . $normalized];
    }

    private function pricingRuleFromInput(array $data): string {
        $matchType = trim((string)($data['match_type'] ?? ''));
        if ($matchType === '') return $this->normalizePrefix((string)($data['prefix'] ?? ''));
        if ($matchType === 'global') return '*';
        $countryPrefix = preg_replace('/\D+/', '', (string)($data['country_prefix'] ?? $data['prefix'] ?? '')) ?: '';
        if ($countryPrefix === '' || strlen($countryPrefix) > 8) return '';
        if ($matchType === 'country') return $countryPrefix;
        if ($matchType === 'subprefix') {
            $nationalPrefix = preg_replace('/\D+/', '', (string)($data['national_prefix'] ?? '')) ?: '';
            return $nationalPrefix !== '' && strlen($countryPrefix . $nationalPrefix) <= 64 ? $countryPrefix . $nationalPrefix : '';
        }
        if ($matchType === 'range') {
            $start = preg_replace('/\D+/', '', (string)($data['range_start'] ?? '')) ?: '';
            $end = preg_replace('/\D+/', '', (string)($data['range_end'] ?? '')) ?: '';
            if ($start === '' || strlen($start) !== strlen($end) || strlen($start) > 24 || strcmp($start, $end) > 0) return '';
            return $countryPrefix . ':' . $start . '-' . $end;
        }
        return '';
    }

    private function isOperatorRule(string $rule): bool {
        $description = $this->describePricingRule($rule);
        return in_array((string)$description['type'], ['subprefix', 'range'], true);
    }

    private function isInternalProvider(int $providerId): bool {
        if ($providerId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT provider_type FROM providers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $providerId]);
        return strtolower((string)$stmt->fetchColumn()) === 'internal';
    }

    private function representativeNumberForRule(string $rule): string {
        if (preg_match('/^(\d{1,8}):(\d{1,24})-\d{1,24}$/', $rule, $matches) === 1) return $matches[1] . $matches[2];
        return $rule === '*' ? '' : preg_replace('/\D+/', '', $rule);
    }

    private function destinationForNumber(string $number): array {
        $countryFile = dirname(__DIR__) . '/inc/country_codes.php';
        if (!function_exists('sms_country_codes') && is_file($countryFile)) {
            require_once $countryFile;
        }
        if (!function_exists('sms_country_codes')) {
            return ['prefix' => '', 'country' => 'Paese sconosciuto'];
        }
        $matches = [];
        foreach (sms_country_codes() as $country) {
            $prefix = preg_replace('/\D+/', '', (string)($country['code'] ?? '')) ?: '';
            if ($prefix !== '' && str_starts_with($number, $prefix)) {
                $matches[] = ['prefix' => $prefix, 'country' => trim((string)preg_replace('/\s*\(\+.*/', '', (string)($country['label'] ?? ''))), 'length' => strlen($prefix)];
            }
        }
        usort($matches, static fn(array $a, array $b): int => $b['length'] <=> $a['length']);
        return $matches[0] ?? ['prefix' => '', 'country' => 'Paese sconosciuto'];
    }

    private function normalizePrefix(string $prefix): string {
        $prefix = trim($prefix);
        return $prefix === '*' ? '*' : (preg_replace('/\D+/', '', $prefix) ?: '');
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void {
        $stmt = $this->pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name LIMIT 1');
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        if ($stmt->fetchColumn() === false) $this->pdo->exec($alterSql);
    }

    private function tableExists(string $table): bool {
        $stmt = $this->pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name LIMIT 1');
        $stmt->execute([':table_name' => $table]);
        return $stmt->fetchColumn() !== false;
    }

    private function columnExists(string $table, string $column): bool {
        $stmt = $this->pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name LIMIT 1');
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return $stmt->fetchColumn() !== false;
    }

    private function segments(string $message): int {
        $unicode = preg_match('/[^\x00-\x7F]/u', $message) === 1;
        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
        $singleLimit = $unicode ? 70 : 160;
        $multiLimit = $unicode ? 67 : 153;
        return max(1, (int)ceil($length / ($length <= $singleLimit ? $singleLimit : $multiLimit)));
    }
}
