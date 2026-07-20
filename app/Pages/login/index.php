<?php
require_once 'inc/option.php';

$app = new SmsApp();
$user = new User($_POST);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $loginName = $user->getValue('name');
    $clientIp = SystemLogger::clientIp();
    $rateLimiter = new AuthRateLimiter();
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $user->addError('Token di sicurezza non valido.');
        system_log('warning', 'auth', 'login.csrf_failed', 'Tentativo di accesso con token di sicurezza non valido.', [], $user->getValue('name'));
    } elseif ($rateLimiter->isBlocked($clientIp, $loginName)) {
        $user->addError('Troppi tentativi di accesso. Riprova più tardi.');
        system_log('warning', 'auth', 'login.rate_limited', 'Accesso temporaneamente bloccato per troppi tentativi.', ['ip' => $clientIp], $loginName);
    } elseif ($user->logIn()) {
        $authenticatedUser = $user->getAuthenticatedUser() ?? [];
        $firewall = new AppFirewall();
        if (!$firewall->isAllowed((int)($authenticatedUser['id'] ?? 0), (int)($authenticatedUser['company_id'] ?? 0), $clientIp)) {
            $user->addError('Accesso negato: il tuo indirizzo IP non è autorizzato.');
            system_log('warning', 'security', 'firewall.login_denied', 'Login negato dal firewall applicativo.', [
                'company_id' => (int)($authenticatedUser['company_id'] ?? 0),
                'team_id' => (int)($authenticatedUser['team_id'] ?? 0),
                'user_id' => (int)($authenticatedUser['id'] ?? 0),
                'ip' => $clientIp,
            ], (string)($authenticatedUser['username'] ?? $user->getValue('name')));
        } else {
            session_regenerate_id(true);
            $_SESSION['logged'] = (string)($authenticatedUser['username'] ?? $user->getValue('name'));
            $_SESSION['authenticated_at'] = time();
            $_SESSION['last_activity_at'] = time();
            $_SESSION['last_regenerated_at'] = time();
            set_user_session_context($authenticatedUser);
            $rateLimiter->clear($clientIp, $loginName);
            system_log('info', 'auth', 'login.success', 'Accesso eseguito con successo.', [
                'role' => (string)($authenticatedUser['role'] ?? 'user'),
            ], (string)$_SESSION['logged']);
            header('Location: ' . default_authorized_page());
            exit;
        }
    } else {
        $rateLimiter->recordFailure($clientIp, $loginName);
        $attemptedUser = $user->getAuthenticatedUser() ?? [];
        system_log('warning', 'auth', 'login.failed', 'Tentativo di accesso non riuscito.', [
            'company_id' => (int)($attemptedUser['company_id'] ?? 0),
            'team_id' => (int)($attemptedUser['team_id'] ?? 0),
            'reason' => implode(' ', $user->getErrors()),
        ], $user->getValue('name'));
    }
}

if (!empty($_SESSION['logged'])) {
    header('Location: ' . default_authorized_page());
    exit;
}
$firewallFlash = flash_message();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no">
    <title>SMS - Accesso</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css" type="text/css">
</head>
<body>
    <div class="login-scene" aria-hidden="true">
        <span class="scene-glow glow-one"></span>
        <span class="scene-glow glow-two"></span>
        <span class="scene-orb orb-one"></span>
        <span class="scene-orb orb-two"></span>
        <span class="scene-orb orb-three"></span>
        <span class="scene-ring ring-one"></span>
        <span class="scene-ring ring-two"></span>
        <span class="scene-tile tile-message">✉</span>
        <span class="scene-tile tile-sparkle">✦</span>
        <span class="scene-capsule capsule-one"></span>
        <span class="scene-capsule capsule-two"></span>
    </div>
    <div id="wrapper">
        <div id="logOn">
            <img src="imgs/sms-logo-3d.png?v=2" alt="Logo SMS Portal">
            <h2>Accedi</h2>

            <form action="<?php echo app_url('login'); ?>" method="post" autocomplete="off">
                <?php echo csrf_field(); ?>
                <fieldset>
                    <legend><b>Logati</b></legend>
                    <label for="name">Nome utente</label>
                    <input type="text" id="name" name="name" placeholder="Utente" value="<?php echo htmlspecialchars($user->getValue('name'), ENT_QUOTES, 'UTF-8'); ?>">
                    <label for="psw">Password</label>
                    <input type="password" id="psw" name="psw" placeholder="Password">
                </fieldset>
                <input type="submit" value="Accedi" name="login">
                <p class="helper-text">Accesso riservato all’area SMS</p>
            </form>
        </div>

        <div id="err">
            <?php if ($firewallFlash !== '') : ?><p><b><?php echo htmlspecialchars($firewallFlash, ENT_QUOTES, 'UTF-8'); ?></b></p><?php endif; ?>
            <?php $user->showErr(); ?>
        </div>
    </div>
</body>
</html>
