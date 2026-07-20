<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private const ROUTES = [
        'login' => ['Pages/login/index.php', 'index.php'],
        'access-denied' => ['Pages/access-denied/index.php', 'access_denied.php'],
        'dashboard' => ['Pages/dashboard/index.php', 'dashboard.php'],
        'send-single' => ['Pages/send-single/index.php', 'succesLogged.php'],
        'campaigns' => ['Pages/campaigns/index.php', 'campaigns.php'],
        'lists' => ['Pages/lists/index.php', 'lists.php'],
        'teams' => ['Pages/teams/index.php', 'teams.php'],
        'users' => ['Pages/users/index.php', 'users.php'],
        'settings' => ['Pages/settings/index.php', 'settings.php'],
        'extension-install' => ['Pages/extension-install/index.php', 'extension-install.php'],
        'companies' => ['Pages/companies/index.php', 'companies.php'],
        'devices' => ['Pages/devices/index.php', 'devices.php'],
        'providers' => ['Pages/providers/index.php', 'providers.php'],
        'credits' => ['Pages/credits/index.php', 'credits.php'],
        'firewall' => ['Pages/firewall/index.php', 'firewall.php'],
        'logs' => ['Pages/logs/index.php', 'logs.php'],
        'system-logs' => ['Pages/system-logs/index.php', 'system_logs.php'],
        'device-api' => ['Pages/device-api/index.php', 'device_api.php'],
        'logout' => ['Pages/logout/index.php', 'logout.php'],
    ];

    public static function dispatch(string $route): void
    {
        $route = strtolower(trim($route));
        if (!isset(self::ROUTES[$route])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Pagina non trovata.';
            return;
        }

        [$relativePath, $legacyScriptName] = self::ROUTES[$route];
        $path = dirname(__DIR__) . '/' . $relativePath;
        if (!is_file($path)) {
            throw new \RuntimeException('Handler della route non trovato: ' . $route);
        }

        $_SERVER['APP_ROUTE'] = $route;
        $_SERVER['SCRIPT_NAME'] = '/' . $legacyScriptName;
        require $path;
    }
}
