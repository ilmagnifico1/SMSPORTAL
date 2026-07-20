<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root);

$expectedClasses = [
    App\Core\View::class,
    App\Core\Router::class,
    App\Core\ControllerFactory::class,
    App\Controllers\CompanyController::class,
    App\Models\Company::class,
    App\Repositories\CompanyRepository::class,
    App\Services\CompanyService::class,
    SmsApp::class,
];

foreach ($expectedClasses as $class) {
    if (!class_exists($class)) {
        throw new RuntimeException('Autoload fallito per ' . $class);
    }
}

$company = App\Models\Company::fromArray([
    'id' => '12',
    'name' => 'Azienda test',
    'active' => '1',
    'created_at' => '2026-07-17 12:00:00',
]);
if ($company->id !== 12 || $company->name !== 'Azienda test' || !$company->active) {
    throw new RuntimeException('Mapping del Model Company non valido.');
}

$unsafeViewRejected = false;
try {
    App\Core\View::render('../classes/config.local');
} catch (RuntimeException $exception) {
    $unsafeViewRejected = true;
}
if (!$unsafeViewRejected) {
    throw new RuntimeException('Il renderer ha accettato un percorso non sicuro.');
}

$rootPhpFiles = array_map('basename', glob($root . '/*.php') ?: []);
sort($rootPhpFiles);
if ($rootPhpFiles !== ['index.php']) {
    throw new RuntimeException('La radice deve contenere un solo endpoint PHP: index.php.');
}

$routerReflection = new ReflectionClass(App\Core\Router::class);
$routeMap = $routerReflection->getConstant('ROUTES');
if (!is_array($routeMap) || count($routeMap) < 18) {
    throw new RuntimeException('Mappa delle route incompleta.');
}
foreach ($routeMap as $route => $handler) {
    $handlerPath = $root . '/app/' . (string)($handler[0] ?? '');
    if (basename($handlerPath) !== 'index.php' || !is_file($handlerPath)) {
        throw new RuntimeException('Handler non valido per la route ' . $route);
    }
}

ob_start();
App\Core\Router::dispatch('route-inesistente');
$notFoundBody = (string)ob_get_clean();
if (http_response_code() !== 404 || !str_contains($notFoundBody, 'Pagina non trovata')) {
    throw new RuntimeException('Gestione 404 del front controller non valida.');
}
http_response_code(200);

echo "MVC self-test: OK\n";
