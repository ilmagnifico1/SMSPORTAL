<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/routing.php';
require_once __DIR__ . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(__DIR__);
App\Core\Router::dispatch((string)($_GET['route'] ?? 'login'));
