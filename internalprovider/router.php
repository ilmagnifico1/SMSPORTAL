<?php

declare(strict_types=1);

$publicDirectory = realpath(__DIR__ . '/public');
$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$requestedFile = $publicDirectory !== false ? realpath($publicDirectory . DIRECTORY_SEPARATOR . ltrim($requestPath, '/')) : false;
if ($publicDirectory !== false && $requestedFile !== false && is_file($requestedFile)
    && str_starts_with($requestedFile, $publicDirectory . DIRECTORY_SEPARATOR)) {
    $extension = strtolower((string)pathinfo($requestedFile, PATHINFO_EXTENSION));
    $contentTypes = ['css' => 'text/css; charset=UTF-8', 'ico' => 'image/x-icon', 'png' => 'image/png', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile($requestedFile);
    exit;
}

require __DIR__ . '/public/index.php';
