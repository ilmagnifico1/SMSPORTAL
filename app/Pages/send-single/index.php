<?php
require_once 'inc/option.php';
require_once 'inc/country_codes.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

require_permission('send_single');

$app = new SmsApp();
$deviceAuth = new DeviceAuthManager();
$user = new User($_POST);
$providers = $app->getProviders(['active' => '1']);
$companies = is_super_admin() ? $app->getCompanies() : [];
$feedback = '';
$result = null;
$flashMessage = flash_message();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $feedback = 'Token di sicurezza non valido.';
    } elseif (!$deviceAuth->consumeAuthorization(
        (string)($_POST['authorization_id'] ?? ''),
        current_user_id(),
        current_company_id(),
        'single_sms',
        $deviceAuth->singlePayload($_POST)
    )) {
        $feedback = 'Invio bloccato: autorizzazione dell’estensione assente, non valida o scaduta.';
        $result = ['status' => 'failed'];
        system_log('warning', 'security', 'device.authorization_missing', $feedback, ['action_type' => 'single_sms']);
    } else {
        $user->sendSms();
        $result = $user->getLastSendResult();
        $errors = $user->getErrors();
        $feedback = !empty($errors) ? implode(' ', $errors) : '';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no">
    <title>Invio SMS</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css" type="text/css">
    <link rel="stylesheet" href="css/loggedstyle.css" type="text/css">
    <script src="js/counter.js" type="text/javascript"></script>
</head>
<body class="page-single">
    <div id="wrapper" class="dashboard-shell">
        <div id="header">
            <div>
                <p class="eyebrow">Invio immediato</p>
                <h2>SMS singolo internazionale</h2>
            </div>
            <div class="header-actions">
                <span class="user-badge"><?php echo htmlspecialchars($_SESSION['logged'], ENT_QUOTES, 'UTF-8'); ?></span>
                <a href="<?php echo app_url('logout'); ?>" class="logout-link">Logout</a>
            </div>
        </div>

        <?php require 'inc/top_nav.php'; ?>

        <?php if ($flashMessage !== '') : ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($feedback !== '') : ?>
            <div class="alert <?php echo ($result['status'] ?? '') === 'failed' ? 'alert-danger' : ''; ?>">
                <?php echo htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="hero-panel compact-hero">
            <div>
                <p class="eyebrow">Comunicazione immediata</p>
                <h3>Invio SMS singolo</h3>
                <p class="muted-text">Seleziona il provider, inserisci destinatario, mittente e testo. L’esito viene registrato automaticamente.</p>
            </div>
        </section>

        <div class="single-layout">
            <section id="content" class="card">
            <form action="<?php echo app_url('send-single'); ?>" method="POST" class="single-message-form modern-form" data-device-authorized="single_sms">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="send" value="1">
                <input type="hidden" name="authorization_id" value="">
                <div>
                    <label for="provider_id">Provider</label>
                    <select name="provider_id" id="provider_id" required>
                        <option value="">Seleziona provider</option>
                        <?php foreach ($providers as $provider) : ?>
                            <option value="<?php echo (int)$provider['id']; ?>" <?php echo (string)($_POST['provider_id'] ?? '') === (string)$provider['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(((string)($provider['provider_type'] ?? '') === 'internal' ? '[TEST] ' : '') . $provider['name'] . (is_super_admin() ? ' · Azienda #' . (int)$provider['company_id'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>
                <div>
                    <label for="from">Mittente</label>
                    <input type="text" id="from" name="from" placeholder="Alias mittente" value="">
                </div>
                <div>
                    <label for="country_code">Prefisso internazionale</label>
                    <select name="country_code" id="country_code" title="Seleziona prefisso">
                        <option value="">Numero già completo con +</option>
                        <?php foreach (sms_country_codes() as $country) : ?>
                            <?php $selectedCode = (string)($_POST['country_code'] ?? '40'); ?>
                            <option value="<?php echo htmlspecialchars((string)$country['code'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedCode === (string)$country['code'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$country['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>
                <div>
                    <label for="to">Destinatario</label>
                    <input type="text" id="to" name="to" placeholder="Esempio: 712345678 oppure +447..." value="<?php echo htmlspecialchars($_POST['to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="full-span">
                    <label for="sms">Messaggio</label>
                    <textarea name="sms" placeholder="Scrivi messaggio..." id="sms"><?php echo isset($_POST['sms']) ? htmlspecialchars($_POST['sms'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
                    <div class="actions">
                        <input type="submit" value="Invia SMS">
                        <span>Caratteri: <span id="ctr">0</span></span>
                    </div>
                </div>
            </form>
            </section>

            <aside class="card send-guide-card">
                <p class="eyebrow">Prima dell’invio</p>
                <h3>Controlla questi passaggi</h3>
                <div class="guide-step"><span>1</span><div><strong>Scegli il provider</strong><small>Usa uno dei gateway attivi.</small></div></div>
                <div class="guide-step"><span>2</span><div><strong>Controlla il numero</strong><small>Utilizza il formato internazionale E.164.</small></div></div>
                <div class="guide-step"><span>3</span><div><strong>Verifica il testo</strong><small>Il risultato sarà registrato nei log.</small></div></div>
            </aside>
        </div>
    </div>
    <script src="js/device-auth.js" defer></script>
</body>
</html>
