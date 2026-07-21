<?php
require_once 'inc/option.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

$app = new SmsApp();
$userFilters = ['search' => '', 'active' => 'all'];
$providerFilters = ['search' => '', 'active' => 'all'];
$users = $app->getUsers($userFilters);
$providers = $app->getProviders($providerFilters);
$dashboardFilters = is_super_admin() ? [] : (user_can('view_dashboard')
    ? ['team_id' => current_team_id()]
    : ['user_name' => (string)$_SESSION['logged']]);
$recentLogFilters = is_super_admin() ? [] : (user_can('view_team_messages')
    ? ['team_id' => current_team_id()]
    : ['user_name' => (string)$_SESSION['logged']]);
$logStats = $app->getLogStats($dashboardFilters);
$billingStats = is_super_admin() ? $app->getBillingStats() : ['purchases' => 0, 'sales' => 0, 'profit' => 0];
$messageTrend = $app->getMessageTrend($dashboardFilters, 14);
$logs = $app->getLogs(array_merge(['status' => 'all'], $recentLogFilters));
$recentLogs = array_slice($logs, 0, 3);
$totalMessages = (int)$logStats['sent_count'] + (int)$logStats['failed_count'];
$successRate = $totalMessages > 0 ? round(((int)$logStats['sent_count'] / $totalMessages) * 100, 1) : 0;
$chartWidth = 800;
$chartHeight = 260;
$chartLeft = 48;
$chartRight = 18;
$chartTop = 20;
$chartBottom = 42;
$chartPlotWidth = $chartWidth - $chartLeft - $chartRight;
$chartPlotHeight = $chartHeight - $chartTop - $chartBottom;
$chartMax = 1;
foreach ($messageTrend as $trendPoint) {
    $chartMax = max($chartMax, (int)$trendPoint['sent'], (int)$trendPoint['not_sent']);
}
$chartMax = (int)(ceil($chartMax / 5) * 5);
$sentPoints = [];
$notSentPoints = [];
$trendCount = count($messageTrend);
foreach ($messageTrend as $index => $trendPoint) {
    $x = $chartLeft + ($trendCount > 1 ? ($index / ($trendCount - 1)) * $chartPlotWidth : 0);
    $sentY = $chartTop + $chartPlotHeight - (((int)$trendPoint['sent'] / $chartMax) * $chartPlotHeight);
    $notSentY = $chartTop + $chartPlotHeight - (((int)$trendPoint['not_sent'] / $chartMax) * $chartPlotHeight);
    $sentPoints[] = round($x, 2) . ',' . round($sentY, 2);
    $notSentPoints[] = round($x, 2) . ',' . round($notSentY, 2);
}
$flashMessage = flash_message();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard SMS</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-dashboard">
    <div id="wrapper" class="dashboard-shell">
        <div id="header">
            <div>
                <p class="eyebrow">Pannello operativo</p>
                <h2><?php echo is_super_admin() ? 'Dashboard SMS globale' : 'Dashboard · ' . htmlspecialchars((string)($_SESSION['company_name'] ?? 'Azienda'), ENT_QUOTES, 'UTF-8'); ?></h2>
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

        <section class="hero-panel">
            <div>
                <p class="eyebrow">Panoramica</p>
                <h3><?php echo is_super_admin() ? 'Dashboard operativa globale' : 'Dashboard aziendale'; ?></h3>
                <p class="muted-text">Controlla utenti, provider, invii riusciti e attività recenti da un unico punto.</p>
            </div>
            <div class="stats-grid">
                <article class="stat-card">
                    <span class="stat-icon">✉</span>
                    <strong><?php echo $totalMessages; ?></strong>
                    <span><?php echo is_super_admin() ? 'Messaggi totali' : (user_can('view_dashboard') ? 'Messaggi del Team' : 'I miei messaggi'); ?></span>
                </article>
                <article class="stat-card">
                    <span class="stat-icon">◉</span>
                    <strong><?php echo count($providers); ?></strong>
                    <span>Provider attivi</span>
                </article>
                <article class="stat-card">
                    <span class="stat-icon">✓</span>
                    <strong><?php echo number_format($successRate, 1, ',', ''); ?>%</strong>
                    <span>Invii riusciti</span>
                </article>
            </div>
        </section>

        <?php if (is_super_admin()) : ?>
        <section class="card message-trend-card">
            <div class="section-heading"><div><p class="eyebrow">Billing SMS</p><h3>Acquisti, vendite e profitto</h3><p class="muted-text">Valori consolidati degli SMS inviati con fatturazione attiva.</p></div></div>
            <div class="stats-grid">
                <article class="stat-card"><strong>€ <?php echo number_format((float)$billingStats['purchases'], 4, ',', '.'); ?></strong><span>Costi di acquisto</span></article>
                <article class="stat-card"><strong>€ <?php echo number_format((float)$billingStats['sales'], 4, ',', '.'); ?></strong><span>Vendite alle aziende</span></article>
                <article class="stat-card"><strong>€ <?php echo number_format((float)$billingStats['profit'], 4, ',', '.'); ?></strong><span>Profitto</span></article>
            </div>
        </section>
        <?php endif; ?>

        <section class="card message-trend-card">
            <div class="section-heading trend-heading">
                <div>
                    <p class="eyebrow">Andamento invii</p>
                    <h3>Messaggi inviati versus non inviati</h3>
                    <p class="muted-text">Ultimi 14 giorni · <?php echo is_super_admin() ? 'tutte le aziende' : (user_can('view_dashboard') ? 'dati del Team' : 'dati personali'); ?></p>
                </div>
                <div class="trend-legend" aria-label="Legenda grafico">
                    <span><i class="legend-dot sent-dot"></i> Inviati</span>
                    <span><i class="legend-dot failed-dot"></i> Non inviati</span>
                </div>
            </div>
            <div class="trend-chart-wrap">
                <svg class="trend-chart" viewBox="0 0 <?php echo $chartWidth; ?> <?php echo $chartHeight; ?>" role="img" aria-labelledby="trendChartTitle trendChartDescription">
                    <title id="trendChartTitle">Andamento dei messaggi inviati e non inviati negli ultimi 14 giorni</title>
                    <desc id="trendChartDescription">Grafico a linee che confronta quotidianamente gli invii riusciti con quelli non riusciti.</desc>
                    <?php for ($gridIndex = 0; $gridIndex <= 5; $gridIndex++) : ?>
                        <?php $gridY = $chartTop + ($gridIndex / 5) * $chartPlotHeight; $gridValue = (int)round($chartMax * (1 - $gridIndex / 5)); ?>
                        <line class="chart-grid-line" x1="<?php echo $chartLeft; ?>" y1="<?php echo round($gridY, 2); ?>" x2="<?php echo $chartWidth - $chartRight; ?>" y2="<?php echo round($gridY, 2); ?>"></line>
                        <text class="chart-axis-label chart-y-label" x="<?php echo $chartLeft - 10; ?>" y="<?php echo round($gridY + 4, 2); ?>"><?php echo $gridValue; ?></text>
                    <?php endfor; ?>
                    <?php foreach ($messageTrend as $index => $trendPoint) : ?>
                        <?php if ($index % 2 === 0 || $index === $trendCount - 1) : ?>
                            <?php $labelX = $chartLeft + ($trendCount > 1 ? ($index / ($trendCount - 1)) * $chartPlotWidth : 0); ?>
                            <text class="chart-axis-label chart-x-label" x="<?php echo round($labelX, 2); ?>" y="<?php echo $chartHeight - 14; ?>"><?php echo htmlspecialchars((string)$trendPoint['label'], ENT_QUOTES, 'UTF-8'); ?></text>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <polyline class="trend-line sent-line" points="<?php echo htmlspecialchars(implode(' ', $sentPoints), ENT_QUOTES, 'UTF-8'); ?>"></polyline>
                    <polyline class="trend-line failed-line" points="<?php echo htmlspecialchars(implode(' ', $notSentPoints), ENT_QUOTES, 'UTF-8'); ?>"></polyline>
                    <?php foreach ($messageTrend as $index => $trendPoint) : ?>
                        <?php $pointX = $chartLeft + ($trendCount > 1 ? ($index / ($trendCount - 1)) * $chartPlotWidth : 0); $sentY = $chartTop + $chartPlotHeight - (((int)$trendPoint['sent'] / $chartMax) * $chartPlotHeight); $failedY = $chartTop + $chartPlotHeight - (((int)$trendPoint['not_sent'] / $chartMax) * $chartPlotHeight); ?>
                        <circle class="trend-point sent-point" cx="<?php echo round($pointX, 2); ?>" cy="<?php echo round($sentY, 2); ?>" r="4"><title><?php echo htmlspecialchars((string)$trendPoint['label'], ENT_QUOTES, 'UTF-8'); ?>: <?php echo (int)$trendPoint['sent']; ?> inviati</title></circle>
                        <circle class="trend-point failed-point" cx="<?php echo round($pointX, 2); ?>" cy="<?php echo round($failedY, 2); ?>" r="4"><title><?php echo htmlspecialchars((string)$trendPoint['label'], ENT_QUOTES, 'UTF-8'); ?>: <?php echo (int)$trendPoint['not_sent']; ?> non inviati</title></circle>
                    <?php endforeach; ?>
                </svg>
            </div>
        </section>

        <div class="card-grid dashboard-grid dashboard-activity-grid">
            <section class="card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Attività recente</p>
                        <h3><?php echo is_super_admin() ? 'Ultimi invii' : (user_can('view_team_messages') ? 'Ultimi invii del Team' : 'I miei ultimi invii'); ?></h3>
                    </div>
                    <?php if (user_can('manage_users')) : ?><a href="<?php echo app_url('logs'); ?>" class="text-link">Apri log completi</a><?php endif; ?>
                </div>
                <div class="table-wrap compact-table">
                    <table class="data-table">
                        <thead>
                            <tr><th>Data</th><th>Provider</th><th>Destinatario</th><th>Stato</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentLogs)) : ?>
                                <tr><td colspan="4">Nessun log disponibile.</td></tr>
                            <?php else : ?>
                                <?php foreach ($recentLogs as $log) : ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($log['provider_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($log['recipient'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="status-pill <?php echo ($log['status'] ?? '') === 'sent' ? 'sent' : 'failed'; ?>"><?php echo ($log['status'] ?? '') === 'sent' ? 'Inviato' : 'Non inviato'; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card quick-actions-card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Scorciatoie</p>
                        <h3>Azioni rapide</h3>
                    </div>
                </div>
                <p class="muted-text">Accedi subito alle funzioni più utilizzate.</p>
                <div class="quick-actions-list">
                    <?php if (user_can('send_single')) : ?><a href="<?php echo app_url('send-single'); ?>" class="action-btn">Nuovo invio singolo</a><?php endif; ?>
                    <?php if (user_can('send_bulk') && user_can('create_campaigns')) : ?><a href="<?php echo app_url('campaigns'); ?>" class="secondary-action">Crea una campagna</a><?php endif; ?>
                    <?php if (user_can('send_bulk') && user_can('create_lists')) : ?><a href="<?php echo app_url('lists'); ?>" class="secondary-action">Importa una lista</a><?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
