<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/src/ProviderSimulator.php';
require_once __DIR__ . '/src/MessageStore.php';
require_once __DIR__ . '/src/AdminDashboard.php';

$config = (array)require __DIR__ . '/config.php';
$path = '/' . trim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($path === '/' || $path === '/logout' || $path === '/admin' || str_starts_with($path, '/admin/')) {
    $store = new MessageStore((string)$config['log_file'], (bool)$config['log_message'], (int)$config['max_log_bytes']);
    AdminDashboard::run($config, $store, $path, $method);
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');

$respond = static function (int $status, array $body, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"status":"encoding_error"}';
    exit;
};

if ($path === '/health') {
    $respond(200, [
        'service' => 'SMS Internal Test Provider',
        'status' => 'ok',
        'test_mode' => true,
        'configured' => strlen((string)$config['api_key']) >= 32,
        'time' => gmdate('c'),
    ]);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > (int)$config['max_request_bytes']) {
    $respond(413, ['status' => 'payload_too_large', 'test_mode' => true]);
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$payload = $_POST;
if (str_contains($contentType, 'application/json')) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    $payload = is_array($decoded) ? $decoded : [];
}

$apiKey = trim((string)$config['api_key']);
if (strlen($apiKey) < 32) {
    $respond(503, ['status' => 'not_configured', 'message' => 'API key del provider non configurata.', 'test_mode' => true]);
}
$authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$presentedKey = str_starts_with($authorization, 'Bearer ') ? trim(substr($authorization, 7)) : trim((string)($payload['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($presentedKey === '' || !hash_equals($apiKey, $presentedKey)) {
    $respond(401, ['status' => 'unauthorized', 'message' => 'Credenziali non valide.', 'test_mode' => true], ['WWW-Authenticate' => 'Bearer']);
}

$store = new MessageStore((string)$config['log_file'], (bool)$config['log_message'], (int)$config['max_log_bytes']);
if ($path === '/api/v1/messages' && $method === 'GET') {
    $respond(200, ['status' => 'ok', 'test_mode' => true, 'messages' => $store->latest((int)($_GET['limit'] ?? 50))]);
}
if ($path !== '/api/v1/messages') {
    $respond(404, ['status' => 'not_found', 'test_mode' => true]);
}
if ($method !== 'POST') {
    $respond(405, ['status' => 'method_not_allowed', 'test_mode' => true], ['Allow' => 'POST, GET']);
}

$scenario = strtolower(trim((string)($_GET['scenario'] ?? $payload['scenario'] ?? $config['default_scenario'])));
$result = (new ProviderSimulator())->simulate($payload, $scenario, (int)$config['timeout_ms']);
$body = (array)$result['body'];
$body['_http_code'] = (int)$result['http_code'];

try {
    $store->append($payload, $body, $scenario, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
} catch (Throwable $exception) {
    $respond(503, ['status' => 'storage_error', 'message' => 'Registro di test non disponibile.', 'test_mode' => true]);
}

if ($scenario === 'timeout' && !empty($body['delay_ms'])) {
    usleep((int)$body['delay_ms'] * 1000);
}
unset($body['_http_code'], $body['delay_ms']);
$headers = isset($body['retry_after']) ? ['Retry-After' => (string)$body['retry_after']] : [];
$respond((int)$result['http_code'], $body, $headers);
