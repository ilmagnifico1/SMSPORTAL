<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/classes/SmsApp.php';
ob_start();
require $root . '/internalprovider/selftest.php';
$simulatorOutput = (string)ob_get_clean();
$source = (string)file_get_contents($root . '/classes/SmsApp.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

$assert(str_contains($simulatorOutput, 'Internal provider self-test: OK'), 'Self-test del simulatore non superato.');
$assert(str_contains($source, 'CREATE TABLE IF NOT EXISTS test_message_logs'), 'Tabella log di test separata assente.');
$assert(str_contains($source, "provider_type <> 'internal'"), 'Provider interno non escluso dagli utenti operativi.');
$internalBranch = strpos($source, "providerType(\$providerId) === 'internal'");
$billingReservation = strpos($source, '$credits->reserve(', $internalBranch === false ? 0 : $internalBranch);
$assert($internalBranch !== false && $billingReservation !== false && $internalBranch < $billingReservation, 'Il ramo test non precede la prenotazione del credito.');
$assert(str_contains($source, "'billing_applied' => false"), 'Indicatore billing disattivato assente dal flusso test.');
$assert(str_contains($source, 'CURLOPT_RESOLVE'), 'Risoluzione DNS controllata del provider interno assente.');
$assert(str_contains($source, 'SMS_INTERNAL_PROVIDER_RESOLVE_IP'), 'Override IP del provider interno assente.');

$smsApp = (new ReflectionClass(SmsApp::class))->newInstanceWithoutConstructor();
$endpointValidator = new ReflectionMethod(SmsApp::class, 'validProviderEndpoint');
$endpointValidator->setAccessible(true);
$assert($endpointValidator->invoke($smsApp, 'https://provtest.book-my.eu/api/v1/messages?scenario=success', true) === true, 'Endpoint HTTPS del provider interno rifiutato.');
$assert($endpointValidator->invoke($smsApp, 'https://provtest.book-my.eu/admin', true) === false, 'Percorso amministrativo accettato come endpoint SMS.');
$assert($endpointValidator->invoke($smsApp, 'https://example.com/api/v1/messages', true) === false, 'Host esterno accettato come provider interno.');
$assert($endpointValidator->invoke($smsApp, 'http://provtest.book-my.eu/api/v1/messages', true) === false, 'Endpoint interno non HTTPS accettato.');

if ($failures !== []) {
    fwrite(STDERR, "Internal provider integration self-test fallito:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Internal provider integration self-test: OK\n";
