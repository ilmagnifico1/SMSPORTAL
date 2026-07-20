<?php

$_SESSION = [];
require_once dirname(__DIR__) . '/inc/i18n.php';

function assertI18n(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

assertI18n(app_locale() === 'it', 'La lingua predefinita deve essere italiano.');
assertI18n(app_normalize_locale('EN-us') === 'en', 'La normalizzazione della lingua inglese non funziona.');
assertI18n(app_normalize_locale('de') === 'it', 'Una lingua non supportata deve usare il fallback italiano.');

$_SESSION['preferred_language'] = 'en';
assertI18n(t('language') === 'Language', 'La traduzione semantica inglese non funziona.');
$html = '<html lang="it"><body><h1>Gestione campagne</h1><button>Salva</button><span>Credito disponibile</span></body></html>';
$localized = app_localize_output($html);
assertI18n(str_contains($localized, 'lang="en"'), 'L’attributo lang non viene localizzato.');
assertI18n(str_contains($localized, 'Campaign management'), 'Il titolo della pagina non viene tradotto.');
assertI18n(str_contains($localized, '>Save<'), 'L’azione Salva non viene tradotta.');
assertI18n(str_contains($localized, 'Available credit'), 'Il riepilogo credito non viene tradotto.');

$representativeTexts = [
    'Gestione aziende' => 'Company management',
    'Gestione team' => 'Team management',
    'Rubriche destinatari' => 'Recipient address books',
    'Gestione provider' => 'Provider management',
    'Dispositivi Chrome' => 'Chrome devices',
    'Firewall applicativo' => 'Application firewall',
    'Log dei messaggi' => 'Message log',
    'Registro di sistema' => 'System log',
    'Lingua interfaccia' => 'Interface language',
];
foreach ($representativeTexts as $italian => $english) {
    assertI18n(app_localize_output($italian) === $english, 'Traduzione mancante: ' . $italian);
}

$root = dirname(__DIR__);
assertI18n(!str_contains((string)file_get_contents($root . '/app/Pages/login/index.php'), 'preferred_language'), 'Il selettore lingua non deve comparire nel login.');
assertI18n(!str_contains((string)file_get_contents($root . '/inc/top_nav.php'), 'preferred_language'), 'Il selettore lingua non deve comparire nel menu principale.');
assertI18n(str_contains((string)file_get_contents($root . '/app/Pages/settings/index.php'), 'name="preferred_language"'), 'Il selettore lingua deve comparire nelle Impostazioni.');
assertI18n(str_contains((string)file_get_contents($root . '/chrome-extension/service-worker.js'), 'locale: locale'), 'La lingua non viene inoltrata al popup dell’estensione.');

echo "I18n self-test: OK\n";
