<?php

declare(strict_types=1);

require_once __DIR__ . '/Installer.php';
require_once dirname(__DIR__) . '/classes/ClientIpResolver.php';

$installer = new Installer(dirname(__DIR__));
if ($installer->isInstalled()) {
    header('Location: ../index.php', true, 302);
    exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
$secureRequest = ClientIpResolver::isSecureRequest();
$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$localRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
session_name('sms_portal_installer');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureRequest,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}

$value = static function (string $name, string $default = ''): string {
    $submitted = $_POST[$name] ?? $default;
    return htmlspecialchars(is_scalar($submitted) ? trim((string)$submitted) : $default, ENT_QUOTES, 'UTF-8');
};

$errors = [];
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    $errors[] = 'Richiesta troppo grande.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['installer_csrf'], $csrf)) {
        $errors[] = 'Sessione di installazione scaduta. Ricarica la pagina.';
    } elseif (!$secureRequest && !$localRequest) {
        $errors[] = 'Per proteggere le credenziali, l’installazione è consentita soltanto tramite HTTPS.';
    } else {
        $errors = $installer->validationErrors($_POST);
        if ($errors === []) {
            try {
                $installer->install($_POST);
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $parameters = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], $parameters['secure'], $parameters['httponly']);
                }
                session_destroy();
                header('Location: ../index.php?installed=1', true, 303);
                exit;
            } catch (InstallerException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    }
}

$defaultHost = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: '';
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Installazione · SMS Portal</title>
    <style>
        :root { color-scheme: light; font-family: Inter, system-ui, sans-serif; background:#eef4fc; color:#13213a; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; padding:36px 18px; background:radial-gradient(circle at top left,#dcecff,transparent 45%),#f4f7fb; }
        main { width:min(920px,100%); margin:auto; background:#fff; border:1px solid #dbe4f0; border-radius:24px; overflow:hidden; box-shadow:0 24px 70px rgba(25,55,95,.13); }
        header { padding:30px 34px; color:#fff; background:linear-gradient(135deg,#0c203e,#174b91); }
        header p { margin:8px 0 0; color:#cfe0fa; }
        h1 { margin:0; font-size:clamp(1.7rem,4vw,2.35rem); }
        form { padding:30px 34px 36px; }
        fieldset { border:0; padding:0; margin:0 0 30px; }
        legend { width:100%; padding:0 0 12px; margin-bottom:18px; font-size:1.15rem; font-weight:800; border-bottom:1px solid #e2e9f2; }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; }
        .wide { grid-column:1/-1; }
        label { display:grid; gap:7px; font-weight:700; font-size:.92rem; }
        input, textarea { width:100%; border:1px solid #bdcbe0; border-radius:10px; padding:12px 13px; background:#fbfdff; color:#13213a; font:inherit; }
        textarea { min-height:80px; resize:vertical; }
        input:focus, textarea:focus { outline:3px solid rgba(35,105,225,.17); border-color:#2369e1; }
        small { color:#64748b; font-weight:400; line-height:1.45; }
        .notice, .errors { margin:0 34px 24px; padding:15px 17px; border-radius:12px; line-height:1.5; }
        .notice { background:#fff8db; border:1px solid #eed77b; color:#684f00; }
        .errors { background:#fff0f1; border:1px solid #f1aeb5; color:#8b1d2c; }
        .errors ul { margin:0; padding-left:20px; }
        button { border:0; border-radius:11px; padding:13px 22px; background:linear-gradient(135deg,#1167e8,#594cf0); color:#fff; font:700 1rem inherit; cursor:pointer; box-shadow:0 9px 24px rgba(32,92,220,.25); }
        .footer { display:flex; align-items:center; justify-content:space-between; gap:18px; }
        .footer span { color:#64748b; font-size:.88rem; }
        @media (max-width:680px) { .grid{grid-template-columns:1fr}.wide{grid-column:auto} header,form{padding:24px}.notice,.errors{margin:0 24px 20px}.footer{align-items:stretch;flex-direction:column}button{width:100%} }
    </style>
</head>
<body>
<main>
    <header>
        <h1>Prima installazione</h1>
        <p>Configura il database e crea il primo account Super Admin.</p>
    </header>

    <?php if (!$secureRequest && !$localRequest): ?>
        <div class="notice"><strong>HTTPS richiesto.</strong> Puoi compilare il modulo, ma l’installazione sarà bloccata finché la connessione non risulterà sicura. Se usi un reverse proxy, configura prima <code>SMS_TRUSTED_PROXIES</code> nel servizio PHP.</div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="errors" role="alert"><strong>Correggi i seguenti problemi:</strong><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['installer_csrf'], ENT_QUOTES, 'UTF-8'); ?>">

        <fieldset>
            <legend>Database vuoto</legend>
            <div class="grid">
                <label>Host database<input name="db_host" required maxlength="253" value="<?php echo $value('db_host', 'localhost'); ?>" placeholder="localhost"></label>
                <label>Porta<input name="db_port" required type="number" min="1" max="65535" value="<?php echo $value('db_port', '3306'); ?>"></label>
                <label>Nome database<input name="db_name" required maxlength="64" value="<?php echo $value('db_name', 'sms'); ?>" placeholder="sms"></label>
                <label>Utente database<input name="db_user" required maxlength="128" value="<?php echo $value('db_user'); ?>" autocomplete="username"></label>
                <label class="wide">Password database<input name="db_password" required type="password" maxlength="1024" autocomplete="new-password"><small>La password sarà salvata in <code>storage/config.local.php</code>, escluso da Git e non accessibile dal web.</small></label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Primo Super Admin</legend>
            <div class="grid">
                <label class="wide">Nome azienda<input name="company_name" required maxlength="150" value="<?php echo $value('company_name', 'Azienda principale'); ?>"></label>
                <label class="wide">Username<input name="admin_username" required minlength="3" maxlength="64" value="<?php echo $value('admin_username'); ?>" autocomplete="username"></label>
                <label>Password<input name="admin_password" required type="password" minlength="12" maxlength="255" autocomplete="new-password"><small>Almeno 12 caratteri; usa una password unica.</small></label>
                <label>Conferma password<input name="admin_password_confirm" required type="password" minlength="12" maxlength="255" autocomplete="new-password"></label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Rete e pubblicazione</legend>
            <div class="grid">
                <label>Host pubblico<input name="public_host" maxlength="253" value="<?php echo $value('public_host', $defaultHost); ?>" placeholder="sms.example.com"></label>
                <label>Reverse proxy affidabili<textarea name="trusted_proxies" placeholder="127.0.0.1/32, ::1/128"><?php echo $value('trusted_proxies', '127.0.0.1/32, ::1/128'); ?></textarea><small>Inserisci soltanto IP o CIDR dei proxy sotto il tuo controllo.</small></label>
            </div>
        </fieldset>

        <div class="footer">
            <span>Il database deve essere vuoto. L’installer si disattiva automaticamente dopo il completamento.</span>
            <button type="submit">Installa SMS Portal</button>
        </div>
    </form>
</main>
</body>
</html>
