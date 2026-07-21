<?php

declare(strict_types=1);

$installationFiles = [
    __DIR__ . '/storage/install.lock',
    __DIR__ . '/storage/config.local.php',
    __DIR__ . '/classes/config.local.php',
];
$isInstalled = count(array_filter($installationFiles, 'is_file')) > 0;
if (PHP_SAPI !== 'cli' && !$isInstalled) {
    header('Location: install/', true, 302);
    exit;
}

require_once __DIR__ . '/inc/routing.php';
require_once __DIR__ . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(__DIR__);
App\Core\Router::dispatch((string)($_GET['route'] ?? 'login'));
