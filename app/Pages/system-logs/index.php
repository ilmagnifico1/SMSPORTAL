<?php
require_once 'inc/option.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

require_permission('manage_users');

$app = new SmsApp();
$logger = new SystemLogger();
$filters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'level' => (string)($_GET['level'] ?? 'all'),
    'category' => (string)($_GET['category'] ?? 'all'),
    'company_id' => (int)($_GET['company_id'] ?? 0),
];
$systemLogs = $logger->getLogs($filters, 500);
$stats = $logger->getStats($filters);
$filterOptions = $logger->getFilterOptions($filters);
$companies = is_super_admin() ? $app->getCompanies() : [];
$flashMessage = flash_message();
function visible_billing_detail(string $message): string {
    if (is_super_admin()) { return $message; }
    return preg_replace('/\)\.\s*Nessun prezzo di vendita attivo per questa azienda e provider\.?/u', ').', $message) ?? $message;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Log</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-system-logs settings-area">
    <div id="wrapper" class="dashboard-shell">
        <div id="header">
            <div>
                <p class="eyebrow">Diagnostica e sicurezza</p>
                <h2>System Log</h2>
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

        <section class="hero-panel compact-hero">
            <div>
                <p class="eyebrow">Registro centralizzato</p>
                <h3>Eventi applicativi, accessi e provider</h3>
                <p class="muted-text">Raccoglie errori PHP, accessi, operazioni amministrative, invii SMS e risposte dei provider. Sono mostrati al massimo gli ultimi 500 eventi filtrati.</p>
            </div>
        </section>

        <section class="stats-grid system-log-stats">
            <article class="stat-card"><span>Eventi totali</span><strong><?php echo (int)$stats['total']; ?></strong></article>
            <article class="stat-card"><span>Errori e criticità</span><strong><?php echo (int)$stats['errors']; ?></strong></article>
            <article class="stat-card"><span>Eventi accesso</span><strong><?php echo (int)$stats['access_events']; ?></strong></article>
            <article class="stat-card"><span>Invii e provider</span><strong><?php echo (int)$stats['delivery_events']; ?></strong></article>
        </section>

        <section class="card system-log-card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Ricerca eventi</p>
                    <h3>Registro di sistema</h3>
                </div>
            </div>

            <form method="get" class="filter-bar system-log-filters"><input type="hidden" name="route" value="system-logs">
                <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Messaggio, evento, utente, IP o paese">
                <select name="level">
                    <option value="all">Tutti i livelli</option>
                    <?php foreach ($filterOptions['levels'] as $level) : ?>
                        <option value="<?php echo htmlspecialchars((string)$level, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['level'] === (string)$level ? 'selected' : ''; ?>><?php echo htmlspecialchars(strtoupper((string)$level), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="category">
                    <option value="all">Tutte le categorie</option>
                    <?php foreach ($filterOptions['categories'] as $category) : ?>
                        <option value="<?php echo htmlspecialchars((string)$category, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['category'] === (string)$category ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst((string)$category), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (is_super_admin()) : ?>
                <select name="company_id"><option value="0">Tutte le aziende</option><?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>" <?php echo $filters['company_id'] === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                <?php endif; ?>
                <input type="submit" value="Filtra">
                <a href="<?php echo app_url('system-logs'); ?>" class="secondary-action">Azzera filtri</a>
            </form>

            <div class="table-wrap">
                <table class="data-table system-log-table">
                    <thead>
                        <tr><th>Data</th><?php if (is_super_admin()) : ?><th>Azienda</th><?php endif; ?><th>Livello</th><th>Categoria / Evento</th><th>Utente</th><th>IP / Paese</th><th>Messaggio</th><th>Richiesta</th><th>Dettagli</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($systemLogs)) : ?>
                            <tr><td colspan="<?php echo is_super_admin() ? 9 : 8; ?>">Nessun evento trovato.</td></tr>
                        <?php else : ?>
                            <?php foreach ($systemLogs as $log) : ?>
                                <?php
                                $level = strtolower((string)($log['level'] ?? 'info'));
                                $countryCode = strtolower(trim((string)($log['country_code'] ?? '')));
                                $hasCountryFlag = preg_match('/^[a-z]{2}$/', $countryCode) === 1;
                                $context = json_decode((string)($log['context_json'] ?? '{}'), true);
                                $context = is_array($context) ? $context : [];
                                if (!is_super_admin()) {
                                    foreach (['response', 'raw_response', 'message'] as $contextKey) {
                                        if (isset($context[$contextKey]) && is_string($context[$contextKey])) { $context[$contextKey] = visible_billing_detail($context[$contextKey]); }
                                    }
                                }
                                if (!empty($log['proxy_chain'])) {
                                    $context['proxy_chain'] = (string)$log['proxy_chain'];
                                }
                                $contextText = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                                ?>
                                <tr>
                                    <td class="log-date"><?php echo htmlspecialchars((string)$log['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if (is_super_admin()) : ?><td><?php $companyName = '-'; foreach ($companies as $company) { if ((int)$company['id'] === (int)$log['company_id']) { $companyName = (string)$company['name']; break; } } echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                                    <td><span class="log-level log-level-<?php echo htmlspecialchars($level, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(strtoupper($level), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars((string)$log['category'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars((string)$log['event_name'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                                    <td><?php echo htmlspecialchars((string)($log['user_name'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <strong class="log-country-line">
                                            <?php if ($hasCountryFlag) : ?>
                                                <img class="country-flag-image" src="https://flagcdn.com/24x18/<?php echo htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8'); ?>.png" srcset="https://flagcdn.com/48x36/<?php echo htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8'); ?>.png 2x" width="24" height="18" loading="lazy" alt="Bandiera <?php echo htmlspecialchars((string)($log['country_name'] ?: strtoupper($countryCode)), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php else : ?>
                                                <span class="country-flag-fallback"><?php echo htmlspecialchars((string)($log['flag'] ?: '🌐'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars((string)$log['ip_address'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </strong>
                                        <small><?php echo htmlspecialchars((string)($log['country_name'] ?: 'Sconosciuto'), ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                    <td class="wrap-text"><?php echo htmlspecialchars(visible_billing_detail((string)$log['message']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><strong><?php echo htmlspecialchars((string)($log['request_method'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></strong><small class="log-request-uri"><?php echo htmlspecialchars((string)($log['request_uri'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></small></td>
                                    <td>
                                        <details class="log-details">
                                            <summary>Apri</summary>
                                            <pre><?php echo htmlspecialchars($contextText, ENT_QUOTES, 'UTF-8'); ?></pre>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>
</html>
