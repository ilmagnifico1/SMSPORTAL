<?php

declare(strict_types=1);

require_once __DIR__ . '/src/ProviderSimulator.php';
require_once __DIR__ . '/src/MessageStore.php';

$simulator = new ProviderSimulator();
$payload = ['to' => '+393471234567', 'from' => 'TEST', 'text' => 'Messaggio simulato'];
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

foreach (['success' => 202, 'reject' => 422, 'provider_error' => 503, 'rate_limit' => 429, 'timeout' => 504] as $scenario => $code) {
    $result = $simulator->simulate($payload, $scenario, 1);
    $expect((int)$result['http_code'] === $code, $scenario . ': codice HTTP inatteso');
    $expect(!empty($result['body']['test_mode']), $scenario . ': test_mode assente');
}
$invalid = $simulator->simulate(['to' => '123', 'text' => 'x'], 'success', 1);
$expect((int)$invalid['http_code'] === 422, 'numero non valido accettato');
$mixedA = $simulator->simulate($payload, 'mixed', 1);
$mixedB = $simulator->simulate($payload, 'mixed', 1);
$expect($mixedA['body']['status'] === $mixedB['body']['status'], 'scenario mixed non deterministico');

$temporaryLog = tempnam(sys_get_temp_dir(), 'sms-provider-test-');
if (is_string($temporaryLog)) {
    $store = new MessageStore($temporaryLog, false, 1048576);
    $storedResponse = $simulator->simulate($payload, 'success', 1);
    $body = $storedResponse['body'] + ['_http_code' => $storedResponse['http_code']];
    $store->append($payload, $body, 'success', '127.0.0.1');
    $latest = $store->latest(1)[0] ?? [];
    $expect((string)($latest['to_masked'] ?? '') === '********4567', 'destinatario non mascherato nel registro');
    $expect((string)($latest['message'] ?? '') === '', 'testo registrato nonostante log_message=false');
    $statistics = $store->statistics();
    $expect((int)($statistics['total'] ?? 0) === 1, 'statistiche: totale errato');
    $expect((int)($statistics['success'] ?? 0) === 1, 'statistiche: successo non conteggiato');
    $expect(count($store->search(['outcome' => 'failed'], 10)) === 0, 'filtro esito non applicato');
    $expect(count($store->search(['query' => '4567'], 10)) === 1, 'ricerca registro non funzionante');
    unlink($temporaryLog);
} else {
    $failures[] = 'impossibile creare il registro temporaneo';
}

if ($failures !== []) {
    fwrite(STDERR, "Internal provider self-test fallito:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Internal provider self-test: OK\n";
