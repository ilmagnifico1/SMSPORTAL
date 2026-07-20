<?php

declare(strict_types=1);

// Il server non deve mai mostrare stack trace, percorsi o configurazioni sensibili nel browser.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require dirname(__DIR__) . '/index.php';
