<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function render(string $view, array $data = []): void
    {
        if (preg_match('/^[A-Za-z0-9_\/-]+$/', $view) !== 1) {
            throw new RuntimeException('Nome della vista non valido.');
        }

        $path = dirname(__DIR__) . '/Views/' . $view . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('Vista non trovata: ' . $view);
        }

        extract($data, EXTR_SKIP);
        require $path;
    }
}
