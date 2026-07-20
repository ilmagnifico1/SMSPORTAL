<?php
require_once 'inc/option.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}
require_permission('manage_users');

$manager = new DeviceAuthManager();
$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $feedback = 'Token di sicurezza non valido.';
    } else {
        $status = (string)($_POST['status'] ?? '');
        $deviceId = (int)($_POST['id'] ?? 0);
        $updated = $manager->setDeviceStatus($deviceId, $status);
        $feedback = $updated ? ($status === 'approved' ? 'Dispositivo approvato.' : 'Dispositivo revocato.') : 'Dispositivo non trovato o stato invariato.';
        system_log($updated ? 'info' : 'warning', 'security', $updated ? 'device.status_changed' : 'device.status_change_failed', $feedback, [
            'device_id' => $deviceId,
            'status' => $status,
        ]);
    }
}
$devices = $manager->listDevices();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispositivi autorizzati</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-devices settings-area">
<div id="wrapper" class="dashboard-shell">
    <div id="header">
        <div><p class="eyebrow">Sicurezza invii</p><h2>Dispositivi Chrome</h2></div>
        <div class="header-actions"><span class="user-badge"><?php echo htmlspecialchars((string)$_SESSION['logged'], ENT_QUOTES, 'UTF-8'); ?></span><a href="<?php echo app_url('logout'); ?>" class="logout-link">Logout</a></div>
    </div>
    <?php require 'inc/top_nav.php'; ?>
    <?php require 'inc/settings_sidebar.php'; ?>

    <?php if ($feedback !== '') : ?><div class="alert"><?php echo htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <section class="hero-panel compact-hero">
        <div>
            <p class="eyebrow">Registrazione manuale</p>
            <h3>Approva solo i dispositivi riconosciuti</h3>
            <p class="muted-text">Un dispositivo in attesa non può autorizzare alcun SMS. La revoca ha effetto immediato anche sulle richieste già aperte.</p>
        </div>
    </section>

    <section class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Utente</th><?php if (is_super_admin()) : ?><th>Azienda</th><?php endif; ?><th>Dispositivo</th><th>Identificativo</th><th>Impronta chiave</th><th>Stato</th><th>Ultimo accesso</th><th>Azioni</th></tr></thead>
                <tbody>
                <?php if (!$devices) : ?>
                    <tr><td colspan="<?php echo is_super_admin() ? 8 : 7; ?>">Nessun dispositivo registrato.</td></tr>
                <?php else : foreach ($devices as $device) : ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$device['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <?php if (is_super_admin()) : ?><td><?php echo htmlspecialchars((string)$device['company_name'], ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                        <td class="wrap-text"><?php echo htmlspecialchars((string)($device['device_name'] ?: 'Chrome'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><code><?php echo htmlspecialchars((string)$device['device_uuid'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                        <td><code title="<?php echo htmlspecialchars((string)$device['public_key_fingerprint'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(substr((string)$device['public_key_fingerprint'], 0, 16), ENT_QUOTES, 'UTF-8'); ?>…</code></td>
                        <td><span class="status-pill <?php echo (string)$device['status'] === 'approved' ? 'sent' : 'failed'; ?>"><?php echo htmlspecialchars((string)$device['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?php echo htmlspecialchars((string)($device['last_seen_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="actions-cell">
                            <?php if ((string)$device['status'] !== 'approved') : ?>
                                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>"><input type="hidden" name="status" value="approved"><button class="action-btn" type="submit">Approva</button></form>
                            <?php endif; ?>
                            <?php if ((string)$device['status'] !== 'revoked') : ?>
                                <form method="post" onsubmit="return confirm('Revocare questo dispositivo?');"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>"><input type="hidden" name="status" value="revoked"><button class="action-btn danger-btn" type="submit">Revoca</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>
