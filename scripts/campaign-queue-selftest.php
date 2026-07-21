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
$assert(str_contains($source, 'resumeCampaign'), 'Ripresa persistente della campagna assente.');
$assert(str_contains($source, 'job_started_processed'), 'Campione ETA della ripresa assente.');
$assert(str_contains($page, 'session_write_close()'), 'La sessione non viene liberata prima del batch.');
$assert(str_contains($page, 'data-campaign-progress'), 'Indicatore di avanzamento assente.');
$assert(str_contains($page, 'data-cancel-campaign'), 'Pulsante di arresto assente.');
$assert(str_contains($page, 'data-progress-eta'), 'Tempo stimato della campagna assente.');
$assert(str_contains($page, "formatCount(progress.sent) + ' inviati'"), 'Conteggio degli SMS inviati assente dal progresso.');
$assert(str_contains($page, 'data-sending-animation'), 'Animazione delle buste SMS assente.');
$assert(str_contains($page, 'sendingAnimation.hidden = !progress.active'), 'L animazione non segue lo stato della campagna.');
$assert(str_contains($page, 'data-resume-campaign'), 'Pulsante Riprendi assente.');
$assert(str_contains($page, 'data-restart-campaign'), 'Pulsante Ricomincia assente.');
$assert(str_contains($page, "['run_campaign', 'resume_campaign', 'restart_campaign']"), 'Azioni protette di ripresa non gestite.');

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
    'job_started_at' => date('Y-m-d H:i:s', time() - 70),
]);
$assert($progress['active'] === true, 'Campagna attiva non riconosciuta.');
$assert($progress['percent'] === 35, 'Percentuale errata.');
$assert($progress['remaining'] === 13, 'Conteggio rimanenti errato.');
$assert($progress['can_resume'] === false, 'Una campagna attiva non deve risultare riprendibile.');
$assert(is_int($progress['eta_seconds']) && $progress['eta_seconds'] >= 100 && $progress['eta_seconds'] <= 200, 'Stima del tempo residuo errata.');
$assert(str_starts_with($progress['eta_label'], 'Tempo stimato:'), 'Etichetta del tempo stimato assente.');

$formatEta = new ReflectionMethod(SmsApp::class, 'formatCampaignEta');
$formatEta->setAccessible(true);
$assert($formatEta->invoke($app, 45 * 60, true, 100) === 'Tempo stimato: circa 45 minuti', 'Formato ETA in minuti errato.');
$assert($formatEta->invoke($app, 75 * 60, true, 100) === 'Tempo stimato: circa 1 ora e 15 min', 'Formato ETA in ore errato.');
$assert($formatEta->invoke($app, null, true, 100) === 'Tempo stimato: calcolo in corso...', 'Stato iniziale ETA errato.');
$assert($formatEta->invoke($app, 300, false, 0) === '', 'ETA mostrato per campagna non attiva.');

$cancelledProgress = $method->invoke($app, [
    'id' => 9,
    'last_status' => 'cancelled',
    'last_result' => 'Invio fermato.',
    'job_token' => str_repeat('a', 64),
    'job_total' => 20,
    'job_processed' => 7,
]);
$assert($cancelledProgress['active'] === false, 'Campagna fermata ancora attiva.');
$assert($cancelledProgress['can_resume'] === true, 'Campagna fermata non riprendibile.');

$resumedProgress = $method->invoke($app, [
    'id' => 9,
    'last_status' => 'sending',
    'last_result' => 'Invio ripreso',
    'job_token' => str_repeat('b', 64),
    'job_total' => 20,
    'job_processed' => 10,
    'job_started_processed' => 7,
    'job_started_at' => date('Y-m-d H:i:s', time() - 30),
]);
$assert(is_int($resumedProgress['eta_seconds']) && $resumedProgress['eta_seconds'] >= 90 && $resumedProgress['eta_seconds'] <= 120, 'ETA della campagna ripresa errato.');

if ($failures !== []) {
    fwrite(STDERR, "Campaign queue self-test fallito:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Campaign queue self-test: OK\n";
