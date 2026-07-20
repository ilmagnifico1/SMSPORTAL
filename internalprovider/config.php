<?php

declare(strict_types=1);

$local = [];
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = (array)require $localFile;
}

$read = static function (string $environment, string $localKey, mixed $default = '') use ($local): mixed {
    $value = getenv($environment);
    return $value !== false && $value !== '' ? $value : ($local[$localKey] ?? $default);
};

return [
    'api_key' => trim((string)$read('INTERNAL_PROVIDER_API_KEY', 'api_key')),
    'admin_password_hash' => trim((string)$read('INTERNAL_PROVIDER_ADMIN_PASSWORD_HASH', 'admin_password_hash')),
    'default_scenario' => strtolower(trim((string)$read('INTERNAL_PROVIDER_DEFAULT_SCENARIO', 'default_scenario', 'success'))),
    'timeout_ms' => max(0, min(30000, (int)$read('INTERNAL_PROVIDER_TIMEOUT_MS', 'timeout_ms', 15000))),
    'max_request_bytes' => max(1024, min(1048576, (int)$read('INTERNAL_PROVIDER_MAX_REQUEST_BYTES', 'max_request_bytes', 65536))),
    'log_file' => (string)$read('INTERNAL_PROVIDER_LOG_FILE', 'log_file', __DIR__ . '/storage/messages.ndjson'),
    'log_message' => strtolower((string)$read('INTERNAL_PROVIDER_LOG_MESSAGE', 'log_message', 'false')) === 'true',
    'max_log_bytes' => max(1048576, min(104857600, (int)$read('INTERNAL_PROVIDER_MAX_LOG_BYTES', 'max_log_bytes', 10485760))),
];
