<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/install/Installer.php';

$root = dirname(__DIR__);
$installer = new Installer($root);
$valid = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'sms_portal',
    'db_user' => 'sms_app',
    'db_password' => 'db-password-example',
    'admin_username' => 'superadmin',
    'admin_password' => 'A-secure-example-2026!',
    'admin_password_confirm' => 'A-secure-example-2026!',
    'company_name' => 'Azienda principale',
    'public_host' => 'sms.example.com',
    'trusted_proxies' => '127.0.0.1/32, ::1/128',
];

if ($installer->validationErrors($valid) !== []) {
    fwrite(STDERR, "Installer self-test: validazione valida rifiutata.\n");
    exit(1);
}

$invalid = $valid;
$invalid['db_name'] = 'sms;DROP';
$invalid['admin_password'] = 'breve';
$invalid['admin_password_confirm'] = 'diversa';
$invalid['trusted_proxies'] = '10.0.0.999/32';
if (count($installer->validationErrors($invalid)) < 4) {
    fwrite(STDERR, "Installer self-test: validazione insufficiente.\n");
    exit(1);
}

$schema = file_get_contents($root . '/database/schema.sql');
if (!is_string($schema) || $schema === '') {
    fwrite(STDERR, "Installer self-test: schema assente.\n");
    exit(1);
}
$statements = Installer::splitSql($schema);
if (count($statements) < 50) {
    fwrite(STDERR, "Installer self-test: parsing schema incompleto.\n");
    exit(1);
}
foreach ($statements as $statement) {
    if (!str_ends_with(rtrim($statement), ';')) {
        fwrite(STDERR, "Installer self-test: statement senza terminatore.\n");
        exit(1);
    }
}
if (!str_contains($schema, 'UNIQUE KEY `unique_utenti_username` (`username`)')
    || !str_contains($schema, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')) {
    fwrite(STDERR, "Installer self-test: schema utenti non aggiornato.\n");
    exit(1);
}

echo "Installer self-test: OK\n";
