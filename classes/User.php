<?php

class User {
    private array $errors = [];
    private array $vals = [];
    protected ?PDO $pdo = null;
    private ?array $lastSendResult = null;
    private ?array $authenticatedUser = null;

    public function __construct(array $vals = []) {
        $this->vals = $vals;
    }

    public function logIn(): bool {
        $this->cleanInputs();
        $user = $this->getValue('name', '') ?: $this->getValue('username', '');
        $psw = $this->getValue('psw', '') ?: $this->getValue('password', '');

        if ($user === '' && $psw === '') {
            $this->addError('Inserisci utente e password!');
        } elseif ($user === '') {
            $this->addError('Inserisci utente!');
        } elseif ($psw === '') {
            $this->addError('Inserisci password!');
        } else {
            $query = 'SELECT u.*, c.name AS company_name, c.active AS company_active, t.name AS team_name, t.active AS team_active
                FROM utenti u
                LEFT JOIN companies c ON c.id = u.company_id
                LEFT JOIN teams t ON t.id = u.team_id
                WHERE u.username = :user LIMIT 1';
            $result = $this->getPdo()->prepare($query);
            $result->execute([':user' => $user]);

            $row = $result->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $this->authenticatedUser = $row;
                $isExplicitlyDisabled = array_key_exists('active', $row) && $row['active'] !== null && (int)$row['active'] === 0;
                if ($isExplicitlyDisabled) {
                    $this->addError('Utente disattivato.');
                    return false;
                }
                if ((int)($row['company_active'] ?? 0) !== 1 || (int)($row['team_active'] ?? 0) !== 1) {
                    $this->addError('Azienda o team disattivato.');
                    return false;
                }

                if ($this->isValidPassword($psw, (string)($row['password'] ?? ''))) {
                    if (password_needs_rehash((string)$row['password'], PASSWORD_DEFAULT)) {
                        $rehash = $this->getPdo()->prepare('UPDATE utenti SET password = :password WHERE id = :id');
                        $rehash->execute([':password' => password_hash($psw, PASSWORD_DEFAULT), ':id' => (int)$row['id']]);
                    }
                    return true;
                }
            } else {
                password_verify($psw, '$2y$10$wH9fih3jF3YQwQb7j1fL3eL1uPKgFVJzQhULQjz8WgnXJ9Yp5QmQq');
            }

            $this->addError('Utente o password sbagliati!');
        }

        return false;
    }

    public function sendSms(): void {
        $this->cleanInputs();
        $app = new SmsApp($this->getPdo());
        $providerId = (int)$this->getValue('provider_id', '0');
        $from = $this->getValue('from', '');
        $to = $this->getValue('to', '');
        $prefix = $this->resolvePrefix();
        $msg = $this->getValue('sms', '');
        $userName = (string)($_SESSION['logged'] ?? $from);

        if ($to === '') {
            $this->addError('Inserisci il numero dove mandare il messaggio!');
            return;
        }

        if ($msg === '') {
            $this->addError('Non puoi inviare un messaggio vuoto!');
            return;
        }

        if ($providerId <= 0) {
            $defaultProvider = $app->getDefaultProvider();
            if (!$defaultProvider) {
                $this->addError('Nessun provider attivo configurato.');
                return;
            }
            $providerId = (int)$defaultProvider['id'];
        }

        $recipient = $app->composeRecipient($to, $prefix);
        $this->lastSendResult = $app->sendAndLog($providerId, $recipient !== '' ? $recipient : $to, $msg, $from, $userName);

        $status = (string)($this->lastSendResult['status'] ?? 'failed');
        $response = (string)($this->lastSendResult['response'] ?? '');
        $providerName = (string)($this->lastSendResult['provider_name'] ?? '');
        $finalRecipient = (string)($this->lastSendResult['recipient'] ?? ($recipient !== '' ? $recipient : $to));

        if ($status === 'sent') {
            $this->addError('Messaggio inviato con successo tramite ' . $providerName . '.');
        } else {
            $this->addError('Invio fallito' . ($providerName !== '' ? ' tramite ' . $providerName : '') . ': ' . $response);
        }

        $log = 'Provider: ' . $providerName . PHP_EOL .
            'From: ' . $from . PHP_EOL .
            'To: ' . $finalRecipient . PHP_EOL .
            'Message: ' . $msg . PHP_EOL .
            'Status: ' . $status . PHP_EOL .
            'Response: ' . $response;
        $this->saveLog($log);
    }

    public function addError(string $message): void {
        $this->errors[] = $message;
    }

    public function getValue(string $key, string $default = ''): string {
        if (!is_array($this->vals)) {
            return $default;
        }

        $value = $this->vals[$key] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    public function getErrors(): array {
        return $this->errors;
    }

    public function getLastSendResult(): ?array {
        return $this->lastSendResult;
    }

    public function getAuthenticatedUser(): ?array {
        return $this->authenticatedUser;
    }

    private function getPdo(): PDO {
        if ($this->pdo === null) {
            $this->pdo = Connection::connect()->getConn();
        }

        return $this->pdo;
    }

    private function isValidPassword(string $providedPassword, string $storedHash): bool {
        if ($storedHash === '') {
            return false;
        }

        if (str_starts_with($storedHash, '$2')) {
            return password_verify($providedPassword, $storedHash);
        }

        return hash_equals(sha1($providedPassword), $storedHash);
    }

    private function saveLog(string $text): bool {
        $text = date('d/m/Y H:i:s') . PHP_EOL . $text . PHP_EOL . str_repeat('-', 60) . PHP_EOL;
        $directory = __DIR__ . '/../logs';
        $logFile = $directory . '/log_messaggi.txt';

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            error_log('SMS app: impossibile creare la cartella log "' . $directory . '".');
            return false;
        }

        if (file_exists($logFile) && !is_writable($logFile)) {
            error_log('SMS app: il file di log non e scrivibile "' . $logFile . '".');
            return false;
        }

        $fh = @fopen($logFile, 'a');
        if ($fh === false) {
            error_log('SMS app: impossibile aprire il file di log "' . $logFile . '" in append.');
            return false;
        }

        $written = @fwrite($fh, $text);
        fclose($fh);

        if ($written === false) {
            error_log('SMS app: impossibile scrivere nel file di log "' . $logFile . '".');
            return false;
        }

        return true;
    }

    private function resolvePrefix(): string {
        $countryCode = $this->getValue('country_code', '');
        if ($countryCode !== '') {
            return $countryCode;
        }

        $legacyPrefix = strtoupper($this->getValue('stateNo', ''));
        return match ($legacyPrefix) {
            'RO' => '40',
            'IT' => '39',
            default => $legacyPrefix,
        };
    }

    public function cleanInputs(): array {
        $result = [];
        foreach ($this->vals as $key => $val) {
            if (is_array($val)) {
                continue;
            }
            $result[$key] = strip_tags(trim((string)$val));
        }

        $this->vals = $result;
        return $this->vals;
    }

    public function showErr(): void {
        if (!empty($this->errors)) {
            foreach ($this->errors as $err) {
                echo '<p><b>' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</b></p>';
            }
        }
    }
}
?>
