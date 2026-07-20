<?php

class DeviceAuthManager {
    private PDO $pdo;
    private const AUTH_TTL_SECONDS = 90;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Connection::connect()->getConn();
        $this->ensureTables();
    }

    public function registerDevice(int $userId, int $companyId, string $uuid, array $publicJwk, string $name = ''): array {
        $uuid = strtolower(trim($uuid));
        $normalizedJwk = $this->normalizePublicJwk($publicJwk);
        if ($userId <= 0 || $companyId <= 0 || !$this->validUuid($uuid) || $normalizedJwk === null) {
            return ['success' => false, 'message' => 'Identità del dispositivo non valida.'];
        }
        $publicKeyJson = json_encode($normalizedJwk, JSON_UNESCAPED_SLASHES);
        if (!is_string($publicKeyJson)) {
            return ['success' => false, 'message' => 'Chiave pubblica non valida.'];
        }
        $fingerprint = hash('sha256', $publicKeyJson);
        $stmt = $this->pdo->prepare('SELECT * FROM registered_devices WHERE device_uuid = :device_uuid LIMIT 1');
        $stmt->execute([':device_uuid' => $uuid]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['user_id'] !== $userId || (int)$existing['company_id'] !== $companyId || !hash_equals((string)$existing['public_key_fingerprint'], $fingerprint)) {
                return ['success' => false, 'message' => 'Il dispositivo risulta associato a un’altra identità.'];
            }
            $update = $this->pdo->prepare('UPDATE registered_devices SET device_name = :device_name, last_seen_at = NOW() WHERE id = :id');
            $update->execute([':device_name' => substr(trim($name), 0, 150), ':id' => (int)$existing['id']]);
            return ['success' => true, 'status' => (string)$existing['status'], 'message' => $this->deviceStatusMessage((string)$existing['status'])];
        }

        $insert = $this->pdo->prepare('INSERT INTO registered_devices
            (user_id, company_id, device_uuid, device_name, public_key_jwk, public_key_fingerprint, status, last_seen_at)
            VALUES (:user_id, :company_id, :device_uuid, :device_name, :public_key_jwk, :fingerprint, "pending", NOW())');
        $insert->execute([
            ':user_id' => $userId,
            ':company_id' => $companyId,
            ':device_uuid' => $uuid,
            ':device_name' => substr(trim($name), 0, 150),
            ':public_key_jwk' => $publicKeyJson,
            ':fingerprint' => $fingerprint,
        ]);
        return ['success' => true, 'status' => 'pending', 'message' => 'Dispositivo registrato: attendi l’approvazione di un amministratore.'];
    }

    public function prepareAuthorization(int $userId, int $companyId, string $deviceUuid, string $actionType, array $payload, array $summary): array {
        $device = $this->approvedDevice($deviceUuid, $userId, $companyId);
        if (!$device) {
            return ['success' => false, 'message' => 'Estensione assente o dispositivo non approvato.'];
        }
        if (!in_array($actionType, ['single_sms', 'campaign'], true)) {
            return ['success' => false, 'message' => 'Tipo di autorizzazione non valido.'];
        }
        $this->purgeExpiredAuthorizations();
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM send_authorizations WHERE user_id = :user_id AND used_at IS NULL AND expires_at > NOW()');
        $countStmt->execute([':user_id' => $userId]);
        if ((int)$countStmt->fetchColumn() >= 5) {
            return ['success' => false, 'message' => 'Hai troppe autorizzazioni pendenti. Attendi la scadenza o completa quella aperta.'];
        }

        $authorizationId = bin2hex(random_bytes(24));
        $challenge = $this->base64UrlEncode(random_bytes(32));
        $payloadHash = $this->payloadHash($payload);
        $expiresAt = time() + self::AUTH_TTL_SECONDS;
        $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare('INSERT INTO send_authorizations
            (authorization_id, device_id, user_id, company_id, action_type, challenge, payload_hash, summary_json, expires_at)
            VALUES (:authorization_id, :device_id, :user_id, :company_id, :action_type, :challenge, :payload_hash, :summary_json, FROM_UNIXTIME(:expires_at))');
        $stmt->execute([
            ':authorization_id' => $authorizationId,
            ':device_id' => (int)$device['id'],
            ':user_id' => $userId,
            ':company_id' => $companyId,
            ':action_type' => $actionType,
            ':challenge' => $challenge,
            ':payload_hash' => $payloadHash,
            ':summary_json' => is_string($summaryJson) ? $summaryJson : '{}',
            ':expires_at' => $expiresAt,
        ]);
        return [
            'success' => true,
            'authorization_id' => $authorizationId,
            'challenge' => $challenge,
            'expires_at' => $expiresAt,
        ];
    }

    public function getAuthorizationDetails(string $authorizationId, string $challenge): ?array {
        $stmt = $this->pdo->prepare('SELECT sa.authorization_id, sa.action_type, sa.challenge, sa.payload_hash, sa.summary_json,
                UNIX_TIMESTAMP(sa.expires_at) AS expires_at, rd.device_uuid
            FROM send_authorizations sa
            JOIN registered_devices rd ON rd.id = sa.device_id
            WHERE sa.authorization_id = :authorization_id AND sa.challenge = :challenge
              AND sa.used_at IS NULL AND sa.approved_at IS NULL AND sa.expires_at > NOW() AND rd.status = "approved"
            LIMIT 1');
        $stmt->execute([':authorization_id' => trim($authorizationId), ':challenge' => trim($challenge)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $summary = json_decode((string)$row['summary_json'], true);
        return [
            'authorization_id' => (string)$row['authorization_id'],
            'action_type' => (string)$row['action_type'],
            'challenge' => (string)$row['challenge'],
            'payload_hash' => (string)$row['payload_hash'],
            'expires_at' => (int)$row['expires_at'],
            'device_uuid' => (string)$row['device_uuid'],
            'summary' => is_array($summary) ? $summary : [],
        ];
    }

    public function approveAuthorization(string $authorizationId, string $deviceUuid, string $signature): bool {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT sa.*, UNIX_TIMESTAMP(sa.expires_at) AS expires_epoch, rd.device_uuid, rd.public_key_jwk, rd.status AS device_status
                FROM send_authorizations sa JOIN registered_devices rd ON rd.id = sa.device_id
                WHERE sa.authorization_id = :authorization_id FOR UPDATE');
            $stmt->execute([':authorization_id' => trim($authorizationId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !hash_equals((string)$row['device_uuid'], strtolower(trim($deviceUuid)))
                || (string)$row['device_status'] !== 'approved' || !empty($row['used_at'])
                || (int)$row['expires_epoch'] < time()) {
                $this->pdo->rollBack();
                return false;
            }
            if (!empty($row['approved_at'])) {
                $this->pdo->commit();
                return true;
            }
            $signedText = $this->signatureText(
                (string)$row['authorization_id'],
                (string)$row['challenge'],
                (string)$row['payload_hash'],
                (int)$row['expires_epoch'],
                (string)$row['device_uuid']
            );
            $jwk = json_decode((string)$row['public_key_jwk'], true);
            if (!is_array($jwk) || !$this->verifySignature($jwk, $signedText, $signature)) {
                $this->pdo->rollBack();
                return false;
            }
            $update = $this->pdo->prepare('UPDATE send_authorizations SET approved_at = NOW(), signature = :signature WHERE id = :id AND approved_at IS NULL');
            $update->execute([':signature' => substr($signature, 0, 500), ':id' => (int)$row['id']]);
            $this->pdo->commit();
            return $update->rowCount() === 1;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    public function authorizationStatus(string $authorizationId, int $userId): string {
        $stmt = $this->pdo->prepare('SELECT approved_at, used_at, expires_at FROM send_authorizations WHERE authorization_id = :authorization_id AND user_id = :user_id LIMIT 1');
        $stmt->execute([':authorization_id' => trim($authorizationId), ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'missing';
        if (!empty($row['used_at'])) return 'used';
        if (strtotime((string)$row['expires_at']) < time()) return 'expired';
        return !empty($row['approved_at']) ? 'approved' : 'pending';
    }

    public function claimExpiredAuthorizationLog(string $authorizationId, int $userId): ?array {
        $authorizationId = trim($authorizationId);
        if ($authorizationId === '' || $userId <= 0) return null;

        $claim = $this->pdo->prepare('UPDATE send_authorizations
            SET expiration_logged_at = NOW()
            WHERE authorization_id = :authorization_id AND user_id = :user_id
              AND used_at IS NULL AND approved_at IS NULL AND expires_at < NOW()
              AND expiration_logged_at IS NULL');
        $claim->execute([':authorization_id' => $authorizationId, ':user_id' => $userId]);
        if ($claim->rowCount() !== 1) return null;

        $details = $this->pdo->prepare('SELECT sa.authorization_id, sa.action_type, rd.device_uuid
            FROM send_authorizations sa
            JOIN registered_devices rd ON rd.id = sa.device_id
            WHERE sa.authorization_id = :authorization_id AND sa.user_id = :user_id
            LIMIT 1');
        $details->execute([':authorization_id' => $authorizationId, ':user_id' => $userId]);
        $row = $details->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function consumeAuthorization(string $authorizationId, int $userId, int $companyId, string $actionType, array $payload): bool {
        if ($authorizationId === '') return false;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT sa.*, rd.status AS device_status FROM send_authorizations sa
                JOIN registered_devices rd ON rd.id = sa.device_id
                WHERE sa.authorization_id = :authorization_id FOR UPDATE');
            $stmt->execute([':authorization_id' => trim($authorizationId)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $valid = $row
                && (int)$row['user_id'] === $userId
                && (int)$row['company_id'] === $companyId
                && hash_equals((string)$row['action_type'], $actionType)
                && hash_equals((string)$row['payload_hash'], $this->payloadHash($payload))
                && (string)$row['device_status'] === 'approved'
                && !empty($row['approved_at']) && empty($row['used_at'])
                && strtotime((string)$row['expires_at']) >= time();
            if (!$valid) {
                $this->pdo->rollBack();
                return false;
            }
            $update = $this->pdo->prepare('UPDATE send_authorizations SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
            $update->execute([':id' => (int)$row['id']]);
            $this->pdo->commit();
            return $update->rowCount() === 1;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return false;
        }
    }

    public function singlePayload(array $data): array {
        return [
            'provider_id' => (int)($data['provider_id'] ?? 0),
            'country_code' => preg_replace('/\D+/', '', (string)($data['country_code'] ?? '')) ?: '',
            'to' => strip_tags(trim((string)($data['to'] ?? ''))),
            'from' => strip_tags(trim((string)($data['from'] ?? ''))),
            'sms' => strip_tags(trim((string)($data['sms'] ?? ''))),
        ];
    }

    public function campaignPayload(SmsApp $app, int $campaignId): ?array {
        $campaign = $app->getCampaignById($campaignId);
        if (!$campaign) return null;
        $leads = $app->getLeadsByListId((int)$campaign['list_id']);
        $recipients = array_map(static fn(array $lead): string => (string)$lead['phone'], $leads);
        return [
            'campaign_id' => (int)$campaign['id'],
            'company_id' => (int)$campaign['company_id'],
            'team_id' => (int)$campaign['team_id'],
            'provider_id' => (int)$campaign['provider_id'],
            'list_id' => (int)$campaign['list_id'],
            'sender' => (string)$campaign['sender'],
            'message' => (string)$campaign['message'],
            'recipients' => $recipients,
        ];
    }

    public function listDevices(): array {
        $query = 'SELECT rd.*, u.username, c.name AS company_name FROM registered_devices rd
            JOIN utenti u ON u.id = rd.user_id JOIN companies c ON c.id = rd.company_id';
        $params = [];
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $query .= ' WHERE rd.company_id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        }
        $stmt = $this->pdo->prepare($query . ' ORDER BY rd.created_at DESC');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setDeviceStatus(int $id, string $status): bool {
        if (!in_array($status, ['approved', 'revoked'], true)) return false;
        $query = 'UPDATE registered_devices SET status = :status, approved_at = IF(:approved_status = "approved", NOW(), approved_at), revoked_at = IF(:revoked_status = "revoked", NOW(), NULL) WHERE id = :id';
        $params = [':status' => $status, ':approved_status' => $status, ':revoked_status' => $status, ':id' => $id];
        if (!(function_exists('is_super_admin') && is_super_admin())) {
            $query .= ' AND company_id = :company_id';
            $params[':company_id'] = function_exists('current_company_id') ? current_company_id() : 0;
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    public function payloadHash(array $payload): string {
        $normalized = $this->sortRecursive($payload);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function approvedDevice(string $uuid, int $userId, int $companyId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM registered_devices WHERE device_uuid = :uuid AND user_id = :user_id AND company_id = :company_id AND status = "approved" LIMIT 1');
        $stmt->execute([':uuid' => strtolower(trim($uuid)), ':user_id' => $userId, ':company_id' => $companyId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        return $device ?: null;
    }

    private function normalizePublicJwk(array $jwk): ?array {
        if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') return null;
        $x = $this->base64UrlDecode((string)($jwk['x'] ?? ''));
        $y = $this->base64UrlDecode((string)($jwk['y'] ?? ''));
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) return null;
        return ['kty' => 'EC', 'crv' => 'P-256', 'x' => $this->base64UrlEncode($x), 'y' => $this->base64UrlEncode($y), 'ext' => true];
    }

    private function verifySignature(array $jwk, string $message, string $signature): bool {
        $normalized = $this->normalizePublicJwk($jwk);
        $rawSignature = $this->base64UrlDecode($signature);
        if ($normalized === null || $rawSignature === null) return false;
        $x = $this->base64UrlDecode($normalized['x']);
        $y = $this->base64UrlDecode($normalized['y']);
        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004');
        if ($prefix === false || $x === null || $y === null) return false;
        $pem = "-----BEGIN PUBLIC KEY-----\r\n" . chunk_split(base64_encode($prefix . $x . $y), 64, "\r\n") . "-----END PUBLIC KEY-----\r\n";
        $derSignature = strlen($rawSignature) === 64 ? $this->rawEcdsaToDer($rawSignature) : $rawSignature;
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) return false;
        return openssl_verify($message, $derSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function rawEcdsaToDer(string $signature): string {
        $encodeInteger = static function (string $value): string {
            $value = ltrim($value, "\x00");
            if ($value === '') $value = "\x00";
            if ((ord($value[0]) & 0x80) !== 0) $value = "\x00" . $value;
            return "\x02" . chr(strlen($value)) . $value;
        };
        $sequence = $encodeInteger(substr($signature, 0, 32)) . $encodeInteger(substr($signature, 32, 32));
        return "\x30" . chr(strlen($sequence)) . $sequence;
    }

    private function signatureText(string $id, string $challenge, string $payloadHash, int $expiresAt, string $deviceUuid): string {
        return implode("\n", ['SMS-AUTH-V1', $id, $challenge, $payloadHash, (string)$expiresAt, strtolower($deviceUuid)]);
    }

    private function validUuid(string $uuid): bool {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid) === 1;
    }

    private function sortRecursive(array $value): array {
        foreach ($value as &$item) if (is_array($item)) $item = $this->sortRecursive($item);
        unset($item);
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return $value;
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) return null;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return is_string($decoded) ? $decoded : null;
    }

    private function purgeExpiredAuthorizations(): void {
        $this->pdo->exec('DELETE FROM send_authorizations WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }

    private function deviceStatusMessage(string $status): string {
        return match ($status) {
            'approved' => 'Dispositivo approvato.',
            'revoked' => 'Dispositivo revocato. Contatta un amministratore.',
            default => 'Dispositivo in attesa di approvazione.',
        };
    }

    private function ensureTables(): void {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS registered_devices (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            company_id INT NOT NULL,
            device_uuid CHAR(36) NOT NULL UNIQUE,
            device_name VARCHAR(150) NOT NULL DEFAULT "",
            public_key_jwk TEXT NOT NULL,
            public_key_fingerprint CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            approved_at DATETIME NULL,
            revoked_at DATETIME NULL,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_registered_devices_user (user_id, status),
            KEY idx_registered_devices_company (company_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS send_authorizations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            authorization_id CHAR(48) NOT NULL UNIQUE,
            device_id BIGINT NOT NULL,
            user_id INT NOT NULL,
            company_id INT NOT NULL,
            action_type VARCHAR(30) NOT NULL,
            challenge VARCHAR(100) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            summary_json TEXT NOT NULL,
            signature VARCHAR(500) NULL,
            approved_at DATETIME NULL,
            used_at DATETIME NULL,
            expiration_logged_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_send_auth_user (user_id, expires_at),
            KEY idx_send_auth_device (device_id, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->ensureColumn('send_authorizations', 'expiration_logged_at',
            'ALTER TABLE send_authorizations ADD COLUMN expiration_logged_at DATETIME NULL AFTER used_at');
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void {
        $stmt = $this->pdo->prepare('SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name
            LIMIT 1');
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        if ($stmt->fetchColumn() === false) {
            $this->pdo->exec($alterSql);
        }
    }
}
