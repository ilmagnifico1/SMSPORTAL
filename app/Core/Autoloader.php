<?php

declare(strict_types=1);

namespace App\Core;

final class Autoloader
{
    public static function register(string $root): void
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        spl_autoload_register(static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\')) {
                $relativeClass = substr($class, 4);
                if (!self::validClassName($relativeClass)) {
                    return;
                }
                $path = $root . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
                return;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) !== 1) {
                return;
            }
            $path = $root . '/classes/' . $class . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    private static function validClassName(string $class): bool
    {
        $segments = explode('\\', $class);
        if ($segments === []) {
            return false;
        }
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                return false;
            }
        }
        return true;
    }
}
