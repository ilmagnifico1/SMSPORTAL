<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/SmsApp.php';

$source = (string)file_get_contents(dirname(__DIR__) . '/classes/SmsApp.php');
$page = (string)file_get_contents(dirname(__DIR__) . '/app/Pages/campaigns/index.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$assert(str_contains($source, 'processCampaignBatch'), 'Lavoratore a batch assente.');
$assert(str_contains($source, 'campaign_run_token'), 'Token anti-duplicazione assente.');
$assert(str_contains($source, 'job_lock_until'), 'Lock concorrente della campagna assente.');
$assert(str_contains($source, 'cancelCampaign'), 'Arresto persistente della campagna assente.');
$assert(str_contains($page, 'session_write_close()'), 'La sessione non viene liberata prima del batch.');
$assert(str_contains($page, 'data-campaign-progress'), 'Indicatore di avanzamento assente.');
$assert(str_contains($page, 'data-cancel-campaign'), 'Pulsante di arresto assente.');

$app = (new ReflectionClass(SmsApp::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(SmsApp::class, 'campaignProgress');
$method->setAccessible(true);
$progress = $method->invoke($app, [
    'id' => 9,
    'last_status' => 'sending',
    'last_result' => 'Invio in corso',
    'job_token' => str_repeat('a', 64),
    'job_total' => 20,
    'job_processed' => 7,
    'job_sent' => 6,
    'job_failed' => 1,
]);
$assert($progress['active'] === true, 'Campagna attiva non riconosciuta.');
$assert($progress['percent'] === 35, 'Percentuale errata.');
$assert($progress['remaining'] === 13, 'Conteggio rimanenti errato.');

if ($failures !== []) {
    fwrite(STDERR, "Campaign queue self-test fallito:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Campaign queue self-test: OK\n";
