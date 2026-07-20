<?php
require_once 'inc/option.php';
$extensionConfig = require 'inc/extension_config.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

$requestedBrowser = strtolower(trim((string)($_GET['browser'] ?? 'auto')));
$userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$browser = in_array($requestedBrowser, ['chrome', 'firefox'], true)
    ? $requestedBrowser
    : (str_contains($userAgent, 'firefox') ? 'firefox' : 'chrome');
$version = (string)$extensionConfig['version'];
$packageUrl = $browser === 'firefox'
    ? 'dist/firefox-extension/sms-portal-firefox-' . rawurlencode($version) . '.zip'
    : 'dist/chrome-extension/sms-portal-chrome-' . rawurlencode($version) . '.zip';
$english = app_locale() === 'en';
?>
<!DOCTYPE html>
<html lang="<?php echo $english ? 'en' : 'it'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $english ? 'Install browser extension' : 'Installa estensione browser'; ?></title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-extension-install">
<main id="wrapper" class="extension-install-shell">
    <section class="card extension-install-card" data-extension-installer data-package-url="<?php echo htmlspecialchars($packageUrl, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="extension-install-icon" aria-hidden="true">&#8681;</div>
        <p class="eyebrow">SMS Portal · <?php echo ucfirst($browser); ?></p>
        <h1><?php echo $english ? 'Extension package ready' : 'Pacchetto estensione pronto'; ?></h1>
        <p class="muted-text"><?php echo $english ? 'The correct package was selected automatically for your browser.' : 'Il pacchetto corretto è stato selezionato automaticamente per il tuo browser.'; ?></p>
        <p class="extension-install-status" data-install-status role="status"><?php echo $english ? 'Starting the automatic download…' : 'Avvio del download automatico…'; ?></p>
        <div class="extension-install-actions">
            <a class="primary-action-button" data-package-download download href="<?php echo htmlspecialchars($packageUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $english ? 'Download again' : 'Scarica nuovamente'; ?></a>
            <a class="secondary-action" href="<?php echo htmlspecialchars(app_url('settings'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $english ? 'Back to settings' : 'Torna alle impostazioni'; ?></a>
        </div>
        <p class="extension-install-help">
            <?php if ($browser === 'firefox') : ?>
                <?php echo $english ? 'Firefox requires a signed add-on for permanent installation. For a temporary installation, extract the package and select manifest.json from about:debugging.' : 'Firefox richiede un componente firmato per l’installazione permanente. Per l’installazione temporanea, estrai il pacchetto e seleziona manifest.json da about:debugging.'; ?>
            <?php else : ?>
                <?php echo $english ? 'Extract the package, open chrome://extensions, enable Developer mode and choose Load unpacked.' : 'Estrai il pacchetto, apri chrome://extensions, attiva Modalità sviluppatore e scegli Carica estensione non pacchettizzata.'; ?>
            <?php endif; ?>
        </p>
    </section>
</main>
<script src="js/extension-installer.js"></script>
</body>
</html>
