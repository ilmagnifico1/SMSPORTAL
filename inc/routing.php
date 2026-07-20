<?php

declare(strict_types=1);

function app_url(string $route = 'login', array $parameters = []): string
{
    $query = ['route' => $route] + $parameters;
    return 'index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function current_route(): string
{
    return (string)($_SERVER['APP_ROUTE'] ?? 'login');
}
