<?php

declare(strict_types=1);

final class InstallerException extends RuntimeException
{
}

final class Installer
{
    private string $root;
    private string $storageDirectory;
    private string $configFile;
    private string $lockFile;

    public function __construct(string $root)
    {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) {
            throw new InstallerException('Radice del progetto non valida.');
        }

        $this->root = $resolvedRoot;
        $this->storageDirectory = $this->root . '/storage';
        $this->configFile = $this->storageDirectory . '/config.local.php';
        $this->lockFile = $this->storageDirectory . '/install.lock';
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockFile)
            || is_file($this->configFile)
            || is_file($this->root . '/classes/config.local.php');
    }

    /** @return list<string> */
    public function validationErrors(array $input): array
    {
        $errors = [];
        $host = trim((string)($input['db_host'] ?? ''));
        $port = filter_var($input['db_port'] ?? null, FILTER_VALIDATE_INT);
        $database = trim((string)($input['db_name'] ?? ''));
        $dbUser = trim((string)($input['db_user'] ?? ''));
        $dbPassword = (string)($input['db_password'] ?? '');
        $adminUser = trim((string)($input['admin_username'] ?? ''));
        $adminPassword = (string)($input['admin_password'] ?? '');
        $adminConfirmation = (string)($input['admin_password_confirm'] ?? '');
        $companyName = trim((string)($input['company_name'] ?? ''));
        $publicHost = trim((string)($input['public_host'] ?? ''));

        if ($host === '' || strlen($host) > 253 || preg_match('/^[A-Za-z0-9.:-]+$/', $host) !== 1) {
            $errors[] = 'Host database non valido.';
        }
        if ($port === false || $port < 1 || $port > 65535) {
            $errors[] = 'Porta database non valida.';
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            $errors[] = 'Il nome del database può contenere soltanto lettere, numeri e underscore.';
        }
        if ($dbUser === '' || strlen($dbUser) > 128 || str_contains($dbUser, "\0")) {
            $errors[] = 'Utente database non valido.';
        }
        if ($dbPassword === '' || strlen($dbPassword) > 1024 || str_contains($dbPassword, "\0")) {
            $errors[] = 'Password database mancante o non valida.';
        }
        if (preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $adminUser) !== 1) {
            $errors[] = 'Lo username Super Admin deve avere 3-64 caratteri e può contenere lettere, numeri, punto, trattino, underscore e @.';
        }
        if (strlen($adminPassword) < 12 || strlen($adminPassword) > 255) {
            $errors[] = 'La password Super Admin deve contenere almeno 12 caratteri.';
        }
        if ($adminPassword !== $adminConfirmation) {
            $errors[] = 'Le password del Super Admin non coincidono.';
        }
        if ($companyName === '' || mb_strlen($companyName, 'UTF-8') > 150) {
            $errors[] = 'Nome azienda non valido.';
        }
        if ($publicHost !== '' && (strlen($publicHost) > 253 || preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $publicHost) !== 1)) {
            $errors[] = 'Host pubblico non valido.';
        }

        foreach ($this->proxyRanges((string)($input['trusted_proxies'] ?? '')) as $range) {
            if (!$this->validIpRange($range)) {
                $errors[] = 'Proxy affidabile non valido: ' . $range;
            }
        }

        return array_values(array_unique($errors));
    }

    public function install(array $input): void
    {
        $errors = $this->validationErrors($input);
        if ($errors !== []) {
            throw new InstallerException(implode(' ', $errors));
        }
        if ($this->isInstalled()) {
            throw new InstallerException('SMS Portal risulta già installato.');
        }
        if (!is_dir($this->storageDirectory) || !is_writable($this->storageDirectory)) {
            throw new InstallerException('La cartella storage non è scrivibile dal processo PHP.');
        }

        $mutexPath = $this->storageDirectory . '/.installing.lock';
        $mutex = @fopen($mutexPath, 'c');
        if ($mutex === false || !flock($mutex, LOCK_EX | LOCK_NB)) {
            if (is_resource($mutex)) {
                fclose($mutex);
            }
            throw new InstallerException('Un’altra installazione è già in corso.');
        }

        $pdo = null;
        $schemaStarted = false;
        $configCreated = false;
        $lockCreated = false;

        try {
            if ($this->isInstalled()) {
                throw new InstallerException('SMS Portal risulta già installato.');
            }

            $pdo = $this->connect($input);
            if ($this->databaseObjectCount($pdo) !== 0) {
                throw new InstallerException('Il database selezionato non è vuoto. Usa un database vuoto dedicato a SMS Portal.');
            }

            $schemaStarted = true;
            $this->importSchema($pdo);
            $this->createAdministrator($pdo, $input);

            $config = [
                'host' => trim((string)$input['db_host']),
                'port' => (int)$input['db_port'],
                'database' => trim((string)$input['db_name']),
                'username' => trim((string)$input['db_user']),
                'password' => (string)$input['db_password'],
                'trusted_proxies' => $this->proxyRanges((string)($input['trusted_proxies'] ?? '')),
                'public_host' => trim((string)($input['public_host'] ?? '')),
            ];
            $configContent = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
            $this->createExclusiveFile($this->configFile, $configContent, 0600);
            $configCreated = true;

            $lockContent = json_encode([
                'installed_at' => gmdate('c'),
                'schema' => 'database/schema.sql',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->createExclusiveFile($this->lockFile, ($lockContent ?: '{}') . "\n", 0600);
            $lockCreated = true;
        } catch (InstallerException $exception) {
            if ($configCreated) {
                @unlink($this->configFile);
            }
            if ($lockCreated) {
                @unlink($this->lockFile);
            }
            if ($schemaStarted && $pdo instanceof PDO) {
                $this->cleanupDatabase($pdo);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($configCreated) {
                @unlink($this->configFile);
            }
            if ($lockCreated) {
                @unlink($this->lockFile);
            }
            if ($schemaStarted && $pdo instanceof PDO) {
                $this->cleanupDatabase($pdo);
            }
            error_log('SMS Portal installer: ' . $exception->getMessage());
            throw new InstallerException('Installazione non riuscita. Controlla le credenziali e il log PHP.');
        } finally {
            flock($mutex, LOCK_UN);
            fclose($mutex);
        }
    }

    /** @return list<string> */
    public static function splitSql(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $statements = [];
        $buffer = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            throw new InstallerException('Schema SQL incompleto: manca il terminatore finale.');
        }
        return $statements;
    }

    private function connect(array $input): PDO
    {
        try {
            return new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    trim((string)$input['db_host']),
                    (int)$input['db_port'],
                    trim((string)$input['db_name'])
                ),
                trim((string)$input['db_user']),
                (string)$input['db_password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                ]
            );
        } catch (Throwable $exception) {
            error_log('SMS Portal installer database connection: ' . $exception->getMessage());
            throw new InstallerException('Connessione al database non riuscita. Verifica host, porta, nome database, utente e password.');
        }
    }

    private function databaseObjectCount(PDO $pdo): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $statement->execute();
        return (int)$statement->fetchColumn();
    }

    private function importSchema(PDO $pdo): void
    {
        $schemaFile = $this->root . '/database/schema.sql';
        $sql = @file_get_contents($schemaFile);
        if (!is_string($sql) || trim($sql) === '') {
            throw new InstallerException('File database/schema.sql non disponibile.');
        }
        foreach (self::splitSql($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function createAdministrator(PDO $pdo, array $input): void
    {
        $pdo->beginTransaction();
        try {
            $company = $pdo->prepare('INSERT INTO companies (name, active, provider_access_configured) VALUES (:name, 1, 0)');
            $company->execute([':name' => trim((string)$input['company_name'])]);
            $companyId = (int)$pdo->lastInsertId();

            $team = $pdo->prepare("INSERT INTO teams (company_id, name, active) VALUES (:company_id, 'Team principale', 1)");
            $team->execute([':company_id' => $companyId]);
            $teamId = (int)$pdo->lastInsertId();

            $administrator = $pdo->prepare("INSERT INTO utenti (company_id, team_id, username, password, role, preferred_language, active, provider_access_configured) VALUES (:company_id, :team_id, :username, :password, 'super_admin', 'it', 1, 0)");
            $administrator->execute([
                ':company_id' => $companyId,
                ':team_id' => $teamId,
                ':username' => trim((string)$input['admin_username']),
                ':password' => password_hash((string)$input['admin_password'], PASSWORD_DEFAULT),
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function cleanupDatabase(PDO $pdo): void
    {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as $table) {
                $name = str_replace('`', '``', (string)($table[0] ?? ''));
                if ($name !== '') {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $name . '`');
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $exception) {
            error_log('SMS Portal installer cleanup: ' . $exception->getMessage());
        }
    }

    private function createExclusiveFile(string $path, string $content, int $mode): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new InstallerException('Impossibile creare in sicurezza il file ' . basename($path) . '.');
        }
        try {
            $remaining = $content;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if ($written === false || $written === 0) {
                    throw new InstallerException('Scrittura della configurazione non riuscita.');
                }
                $remaining = substr($remaining, $written);
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }
        @chmod($path, $mode);
    }

    /** @return list<string> */
    private function proxyRanges(string $value): array
    {
        $ranges = preg_split('/[\s,]+/', trim($value)) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $ranges), static fn(string $item): bool => $item !== '')));
    }

    private function validIpRange(string $range): bool
    {
        if (!str_contains($range, '/')) {
            return filter_var($range, FILTER_VALIDATE_IP) !== false;
        }
        [$network, $prefix] = explode('/', $range, 2);
        if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($prefix)) {
            return false;
        }
        $maximum = str_contains($network, ':') ? 128 : 32;
        return (int)$prefix >= 0 && (int)$prefix <= $maximum;
    }
}
