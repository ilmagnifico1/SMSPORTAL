<?php
require_once 'inc/option.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

require_permission('manage_users');

$app = new SmsApp();
$filters = [
    'status' => (string)($_GET['status'] ?? 'all'),
    'provider_id' => (string)($_GET['provider_id'] ?? ''),
    'search' => trim((string)($_GET['search'] ?? '')),
    'date_from' => (string)($_GET['date_from'] ?? ''),
    'date_to' => (string)($_GET['date_to'] ?? ''),
    'company_id' => (int)($_GET['company_id'] ?? 0),
];
$logs = $app->getLogs($filters);
$providers = $app->getProviders(['active' => 'all']);
$companies = is_super_admin() ? $app->getCompanies() : [];
$stats = $app->getLogStats();
$flashMessage = flash_message();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log messaggi</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-logs settings-area">
    <div id="wrapper" class="dashboard-shell">
        <div id="header">
            <div>
                <p class="eyebrow">Storico operativo</p>
                <h2>Log messaggi</h2>
            </div>
            <div class="header-actions">
                <span class="user-badge"><?php echo htmlspecialchars($_SESSION['logged'], ENT_QUOTES, 'UTF-8'); ?></span>
                <a href="<?php echo app_url('logout'); ?>" class="logout-link">Logout</a>
            </div>
        </div>
        <?php require 'inc/top_nav.php'; ?>
        <?php require 'inc/settings_sidebar.php'; ?>

        <?php if ($flashMessage !== '') : ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="page-heading">
            <div>
                <p class="eyebrow">Controllo completo</p>
                <h1>Log dei messaggi</h1>
                <p class="muted-text">Ogni tentativo è tracciato per una verifica rapida.</p>
            </div>
            <span class="heading-pill">Filtra per provider, stato o numero</span>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Filtri</p>
                    <h3>Ricerca avanzata</h3>
                </div>
            </div>

            <form method="get" class="filter-bar filter-grid"><input type="hidden" name="route" value="logs">
                <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cerca destinatario, utente, provider o testo">
                <select name="status">
                    <option value="all" <?php echo $filters['status'] === 'all' ? 'selected' : ''; ?>>Tutti gli stati</option>
                    <option value="sent" <?php echo $filters['status'] === 'sent' ? 'selected' : ''; ?>>Inviati</option>
                    <option value="failed" <?php echo $filters['status'] === 'failed' ? 'selected' : ''; ?>>Non inviati</option>
                </select>
                <select name="provider_id">
                    <option value="">Tutti i provider</option>
                    <?php foreach ($providers as $provider) : ?>
                        <option value="<?php echo (int)$provider['id']; ?>" <?php echo $filters['provider_id'] === (string)$provider['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($provider['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php if (is_super_admin()) : ?><select name="company_id"><option value="0">Tutte le aziende</option><?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>" <?php echo $filters['company_id'] === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><?php endif; ?>
                <input type="submit" value="Filtra">
            </form>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Data</th><?php if (is_super_admin()) : ?><th>Azienda</th><?php endif; ?><th>Utente</th><th>Provider</th><th>Destinatario</th><th>Tariffa / Operatore</th><th>Stato</th><th>Messaggio</th><th>Risposta</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)) : ?>
                            <tr><td colspan="<?php echo is_super_admin() ? 9 : 8; ?>">Nessun messaggio trovato con i filtri correnti.</td></tr>
                        <?php else : ?>
                            <?php foreach ($logs as $log) : ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if (is_super_admin()) : ?><td><?php $companyName = '-'; foreach ($companies as $company) { if ((int)$company['id'] === (int)$log['company_id']) { $companyName = (string)$company['name']; break; } } echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                                    <td><?php echo htmlspecialchars($log['user_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="provider-tag <?php echo strtolower((string)$log['provider_name']) === 'twilio' ? 'twilio' : 'generic'; ?>"><?php echo htmlspecialchars($log['provider_name'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars($log['recipient'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><strong><?php echo htmlspecialchars((string)($log['price_operator'] ?: $log['price_prefix']), ENT_QUOTES, 'UTF-8'); ?></strong><?php if ((string)($log['price_operator'] ?? '') !== '') : ?><small><?php echo htmlspecialchars((string)$log['price_prefix'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                                    <td><span class="status-pill <?php echo ($log['status'] ?? '') === 'sent' ? 'sent' : 'failed'; ?>"><?php echo ($log['status'] ?? '') === 'sent' ? 'Inviato' : 'Non inviato'; ?></span></td>
                                    <td class="wrap-text" title="<?php echo htmlspecialchars($log['message'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($log['message'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="wrap-text" title="<?php echo htmlspecialchars($log['response'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($log['response'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="stats-grid stats-footer">
            <article class="stat-card">
                <strong><?php echo $stats['total']; ?></strong>
                <span>Invii registrati</span>
            </article>
            <article class="stat-card">
                <strong><?php echo $stats['sent_count']; ?></strong>
                <span>Invii riusciti</span>
            </article>
            <article class="stat-card">
                <strong><?php echo $stats['failed_count']; ?></strong>
                <span>Da verificare</span>
            </article>
        </section>
    </div>
</body>
</html>
