<?php
require_once 'inc/option.php';
$extensionConfig = require 'inc/extension_config.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_language') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $feedback = 'Token di sicurezza non valido.';
    } else {
        $language = app_normalize_locale($_POST['preferred_language'] ?? APP_DEFAULT_LOCALE);
        try {
            $app = new SmsApp();
            if ($app->updateUserLanguage(current_user_id(), $language)) {
                $_SESSION['preferred_language'] = $language;
                $feedback = $language === 'en' ? 'Language preference saved.' : 'Preferenza lingua salvata.';
            } else {
                $feedback = 'Impossibile aggiornare la lingua.';
            }
        } catch (Throwable $exception) {
            $feedback = 'Impossibile aggiornare la lingua.';
            system_log('error', 'user', 'user.language_update_failed', $feedback, ['error' => $exception->getMessage()]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impostazioni</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-settings settings-area">
<div id="wrapper" class="dashboard-shell">
    <div id="header">
        <div><p class="eyebrow">Control Room</p><h2>Impostazioni</h2></div>
        <div class="header-actions"><span class="user-badge"><?php echo htmlspecialchars((string)$_SESSION['logged'], ENT_QUOTES, 'UTF-8'); ?></span><a href="<?php echo app_url('logout'); ?>" class="logout-link">Logout</a></div>
    </div>
    <?php require 'inc/top_nav.php'; ?>
    <?php require 'inc/settings_sidebar.php'; ?>

    <?php if ($feedback !== '') : ?><div class="alert"><?php echo htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <section class="hero-panel settings-hero">
        <div>
            <p class="eyebrow">Control Room</p>
            <h3>Configura e proteggi il portale</h3>
            <p class="muted-text">Organizzazione, sicurezza, servizi e monitoraggio raccolti in un unico spazio amministrativo.</p>
        </div>
    </section>

    <section class="card settings-language-card">
        <div>
            <p class="eyebrow">Preferenze personali</p>
            <h3>Lingua interfaccia</h3>
            <p class="muted-text">Scegli la lingua usata nelle pagine, nei menu, nei messaggi e nei popup del portale.</p>
        </div>
        <form method="post" class="settings-language-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_language">
            <label for="settingsLanguage">Lingua</label>
            <select name="preferred_language" id="settingsLanguage">
                <?php foreach (app_locale_names() as $localeCode => $localeName) : ?>
                    <option value="<?php echo $localeCode; ?>" <?php echo app_locale() === $localeCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($localeName, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Salva preferenza</button>
        </form>
    </section>

    <section class="card settings-extension-card" data-extension-manager data-expected-version="<?php echo htmlspecialchars((string)$extensionConfig['version'], ENT_QUOTES, 'UTF-8'); ?>" data-install-url="<?php echo htmlspecialchars(app_url('extension-install'), ENT_QUOTES, 'UTF-8'); ?>">
        <div>
            <p class="eyebrow">Browser Extension</p>
            <h3>Estensione di autorizzazione</h3>
            <p class="muted-text">Controlla la versione installata e ricarica automaticamente i file dell’estensione Chrome o Firefox.</p>
            <p class="extension-version-line" data-extension-status role="status">Verifica dell’estensione in corso…</p>
        </div>
        <div class="settings-extension-actions">
            <button type="button" class="primary-action-button" data-extension-update>Aggiorna estensione</button>
            <small>Versione disponibile: <?php echo htmlspecialchars((string)$extensionConfig['version'], ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
    </section>

    <section class="settings-overview-grid">
        <?php if (is_super_admin()) : ?><a class="settings-overview-card" href="<?php echo app_url('companies'); ?>"><span>&#127970;</span><div><strong>Organizzazione</strong><small>Gestisci aziende e accessi ai servizi.</small></div></a><?php endif; ?>
        <?php if (user_can('manage_users') || user_can('manage_firewall')) : ?><a class="settings-overview-card" href="<?php echo app_url(user_can('manage_users') ? 'devices' : 'firewall'); ?>"><span>&#128737;</span><div><strong>Sicurezza</strong><small>Controlla dispositivi autorizzati e firewall.</small></div></a><?php endif; ?>
        <?php if (user_can('manage_providers') || user_can('view_credits')) : ?><a class="settings-overview-card" href="<?php echo app_url(user_can('manage_providers') ? 'providers' : 'credits'); ?>"><span>&#128421;</span><div><strong>Servizi e costi</strong><small>Configura provider, tariffe e crediti.</small></div></a><?php endif; ?>
        <?php if (user_can('manage_users')) : ?><a class="settings-overview-card" href="<?php echo app_url('logs'); ?>"><span>&#8984;</span><div><strong>Monitoraggio</strong><small>Analizza messaggi ed eventi di sistema.</small></div></a><?php endif; ?>
    </section>
</div>
<script src="js/extension-management.js"></script>
</body>
</html>
