<?php
require_once 'inc/option.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

require_permission('send_bulk', default_authorized_page());

$app = new SmsApp();
$deviceAuth = new DeviceAuthManager();
$providers = $app->getProviders(['active' => '1']);
$lists = $app->getLists();
$feedback = '';
$csvResults = [];
$flashMessage = flash_message();
$openModal = false;
$runResult = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'campaign_progress') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($app->getCampaignProgress((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_campaign_batch') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token di sicurezza non valido.']);
        exit;
    }
    session_write_close();
    $batchResult = $app->processCampaignBatch((int)($_POST['id'] ?? 0), 3);
    http_response_code(!empty($batchResult['success']) ? 200 : 500);
    echo json_encode($batchResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_campaign_job') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token di sicurezza non valido.']);
        exit;
    }
    session_write_close();
    $cancelResult = $app->cancelCampaign((int)($_POST['id'] ?? 0));
    http_response_code(!empty($cancelResult['success']) ? 200 : 409);
    echo json_encode($cancelResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'estimate_campaign') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'can_start' => false, 'message' => 'Token di sicurezza non valido.']);
        exit;
    }
    $estimate = $app->estimateCampaignCost(
        (int)($_POST['provider_id'] ?? 0),
        (int)($_POST['list_id'] ?? 0),
        (string)($_POST['message'] ?? '')
    );
    echo json_encode($estimate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $feedback = 'Token di sicurezza non valido.';
    } elseif (($_POST['action'] ?? '') === 'save_campaign') {
        $saveResult = $app->saveCampaign($_POST, (string)$_SESSION['logged']);
        $feedback = (string)($saveResult['message'] ?? '');
        $openModal = !empty($saveResult['success']) ? false : true;
        $targetCampaignList = $app->getListById((int)($_POST['list_id'] ?? 0));
        system_log(!empty($saveResult['success']) ? 'info' : 'error', 'campaign', !empty($saveResult['success']) ? 'campaign.saved' : 'campaign.save_failed', $feedback, [
            'company_id' => (int)($targetCampaignList['company_id'] ?? current_company_id()),
            'team_id' => (int)($targetCampaignList['team_id'] ?? current_team_id()),
            'campaign_id' => (int)($_POST['id'] ?? 0),
            'campaign_name' => trim((string)($_POST['name'] ?? '')),
            'provider_id' => (int)($_POST['provider_id'] ?? 0),
            'list_id' => (int)($_POST['list_id'] ?? 0),
        ]);
    } elseif (($_POST['action'] ?? '') === 'delete_campaign') {
        $campaignToDelete = $app->getCampaignById((int)($_POST['id'] ?? 0));
        $deleted = $app->deleteCampaign((int)($_POST['id'] ?? 0));
        $feedback = $deleted ? 'Campagna eliminata.' : 'Impossibile eliminare la campagna.';
        system_log($deleted ? 'info' : 'error', 'campaign', $deleted ? 'campaign.deleted' : 'campaign.delete_failed', $feedback, [
            'company_id' => (int)($campaignToDelete['company_id'] ?? current_company_id()),
            'team_id' => (int)($campaignToDelete['team_id'] ?? current_team_id()),
            'campaign_id' => (int)($_POST['id'] ?? 0),
            'campaign_name' => (string)($campaignToDelete['name'] ?? ''),
        ]);
    } elseif (($_POST['action'] ?? '') === 'run_campaign') {
        $campaignToRun = $app->getCampaignById((int)($_POST['id'] ?? 0));
        $campaignEstimate = $campaignToRun ? $app->estimateSavedCampaign((int)$campaignToRun['id']) : [];
        if (empty($campaignEstimate['can_start'])) {
            $feedback = (string)($campaignEstimate['message'] ?? 'Campagna bloccata: credito insufficiente o tariffa non disponibile.');
            system_log('warning', 'campaign', 'campaign.preflight_failed', $feedback, [
                'company_id' => (int)($campaignToRun['company_id'] ?? current_company_id()),
                'team_id' => (int)($campaignToRun['team_id'] ?? current_team_id()),
                'campaign_id' => (int)($_POST['id'] ?? 0),
                'expected_cost' => (float)($campaignEstimate['expected_cost'] ?? 0),
                'available_credit' => (float)($campaignEstimate['balance'] ?? 0),
            ]);
        } else {
        $campaignPayload = $deviceAuth->campaignPayload($app, (int)($_POST['id'] ?? 0));
        if ($campaignPayload === null || !$deviceAuth->consumeAuthorization(
            (string)($_POST['authorization_id'] ?? ''), current_user_id(), current_company_id(), 'campaign', $campaignPayload
        )) {
            $feedback = 'Invio bloccato: autorizzazione dell’estensione assente, non valida o scaduta.';
            system_log('warning', 'security', 'device.authorization_missing', $feedback, [
                'action_type' => 'campaign', 'campaign_id' => (int)($_POST['id'] ?? 0),
            ]);
        } else {
            $runResult = $app->queueCampaign((int)($_POST['id'] ?? 0), (string)$_SESSION['logged']);
            $feedback = (string)($runResult['message'] ?? '');
            system_log(!empty($runResult['success']) ? 'info' : 'error', 'campaign', !empty($runResult['success']) ? 'campaign.queued' : 'campaign.failed', $feedback, [
                'company_id' => (int)($campaignToRun['company_id'] ?? current_company_id()),
                'team_id' => (int)($campaignToRun['team_id'] ?? current_team_id()),
                'campaign_id' => (int)($_POST['id'] ?? 0),
                'processed' => 0,
            ]);
        }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_campaign'
    && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(!empty($runResult['success']) ? 200 : 422);
    echo json_encode($runResult + ['message' => $feedback], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$editingCampaign = !empty($_GET['edit']) && user_can('edit_campaigns') ? $app->getCampaignById((int)$_GET['edit']) : null;
if ($editingCampaign) {
    $openModal = true;
}

$campaigns = $app->getCampaigns();
$campaignEstimates = [];
foreach ($campaigns as $campaign) {
    $campaignEstimates[(int)$campaign['id']] = $app->estimateCampaignCost(
        (int)($campaign['provider_id'] ?? 0),
        (int)($campaign['list_id'] ?? 0),
        (string)($campaign['message'] ?? '')
    );
}
$companies = is_super_admin() ? $app->getCompanies() : [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione campagne</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css?v=20260720-queue2">
</head>
<body class="page-campaigns">
    <div id="wrapper" class="dashboard-shell">
        <div id="header">
            <div>
                <p class="eyebrow">Invio massivo</p>
                <h2>Gestione campagne</h2>
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
            <div class="alert"><?php echo htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="hero-panel compact-hero">
            <div>
                <p class="eyebrow">Invii organizzati</p>
                <h3>Gestione campagne</h3>
                <p class="muted-text">Combina provider, lista, mittente e testo; crea o modifica una campagna dal pannello dedicato.</p>
            </div>
        </section>

        <div class="modal-overlay <?php echo $openModal ? 'is-active' : ''; ?>" id="campaignModal">
            <div class="modal-card campaign-modal-card">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow"><?php echo $editingCampaign ? 'Modifica campagna' : 'Nuova campagna'; ?></p>
                        <h3><?php echo $editingCampaign ? 'Aggiorna campagna' : 'Crea campagna'; ?></h3>
                    </div>
                    <button type="button" class="modal-close-btn" id="closeCampaignModal" aria-label="Chiudi popup">x</button>
                </div>

        <section class="campaign-compose">
            <div class="card">
                <form method="post" enctype="multipart/form-data" class="stacked-form two-columns-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_campaign">
                    <input type="hidden" name="id" value="<?php echo (int)($editingCampaign['id'] ?? 0); ?>">

                    <div class="full-span">
                        <label for="name">Nome campagna</label>
                        <input type="text" name="name" id="name" placeholder="Esempio: Promemoria appuntamenti" value="<?php echo htmlspecialchars((string)($_POST['name'] ?? ($editingCampaign['name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div>
                        <label for="provider_id">Provider</label>
                        <select name="provider_id" id="provider_id" required>
                            <option value="">Seleziona provider</option>
                            <?php $selectedProvider = (string)($_POST['provider_id'] ?? ($editingCampaign['provider_id'] ?? '')); ?>
                            <?php foreach ($providers as $provider) : ?>
                                <option value="<?php echo (int)$provider['id']; ?>" <?php echo $selectedProvider === (string)$provider['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($provider['name'] . (is_super_admin() ? ' · Azienda #' . (int)$provider['company_id'] : ''), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="list_id">Lista destinatari</label>
                        <select name="list_id" id="list_id" required>
                            <option value="" data-total="0">Seleziona lista</option>
                            <?php $selectedList = (string)($_POST['list_id'] ?? ($editingCampaign['list_id'] ?? '')); ?>
                            <?php foreach ($lists as $list) : ?>
                                <option value="<?php echo (int)$list['id']; ?>" data-total="<?php echo (int)($list['total_contacts'] ?? 0); ?>" <?php echo $selectedList === (string)$list['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$list['name'] . (is_super_admin() ? ' · Azienda #' . (int)$list['company_id'] : ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int)($list['total_contacts'] ?? 0); ?> contatti</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full-span">
                        <label for="from">Numero o alias in uscita</label>
                        <input type="text" name="from" id="from" placeholder="Alias o numero mittente" value="<?php echo htmlspecialchars((string)($_POST['from'] ?? ($editingCampaign['sender'] ?? $_SESSION['logged'])), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="full-span">
                        <label for="message">Messaggio campagna</label>
                        <textarea name="message" id="message" placeholder="Scrivi il messaggio da inviare a tutta la campagna" required><?php echo htmlspecialchars((string)($_POST['message'] ?? ($editingCampaign['message'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="full-span form-actions"><input type="submit" value="<?php echo $editingCampaign ? 'Aggiorna campagna' : 'Salva campagna'; ?>"></div>
                </form>
            </div>

            <aside class="card campaign-summary">
                <p class="eyebrow">Riepilogo</p>
                <h3>Prima di salvare</h3>
                <div class="summary-row"><span>Destinatari validi</span><strong id="campaignRecipientCount">0</strong></div>
                <div class="summary-row"><span>Provider</span><strong id="campaignProviderName">Da selezionare</strong></div>
                <div class="summary-row"><span>Spesa prevista</span><strong id="campaignExpectedCost">€ 0,0000</strong></div>
                <div class="summary-row"><span>Credito disponibile</span><strong id="campaignAvailableCredit">—</strong></div>
                <div class="summary-row"><span>Stato</span><span class="status-pill" id="campaignEstimateStatus">Da calcolare</span></div>
                <p class="hint-text" id="campaignEstimateMessage">Seleziona provider e lista, poi scrivi il messaggio per calcolare la spesa.</p>
            </aside>
        </section>
            </div>
        </div>

        <section class="card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Archivio campagne</p>
                    <h3>Campagne salvate</h3>
                </div>
                <?php if (user_can('create_campaigns')) : ?><button type="button" class="modal-trigger-btn" id="openCampaignModal">Nuova campagna</button><?php endif; ?>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Campagna</th><?php if (is_super_admin()) : ?><th>Azienda</th><?php endif; ?><th>Provider</th><th>Lista</th><th>Spesa prevista</th><th>Mittente</th><th>Ultimo esito</th><th>Ultimo invio</th><th>Azioni</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campaigns)) : ?>
                            <tr><td colspan="<?php echo is_super_admin() ? 9 : 8; ?>">Nessuna campagna salvata.</td></tr>
                        <?php else : ?>
                            <?php foreach ($campaigns as $campaign) : ?>
                                <?php $campaignProvider = $app->getProviderById((int)$campaign['provider_id']); ?>
                                <?php $campaignList = $app->getListById((int)($campaign['list_id'] ?? 0)); ?>
                                <?php $jobActive = in_array((string)($campaign['last_status'] ?? ''), ['queued', 'sending'], true) && trim((string)($campaign['job_token'] ?? '')) !== ''; ?>
                                <?php $jobTotal = max(0, (int)($campaign['job_total'] ?? 0)); $jobProcessed = max(0, min($jobTotal, (int)($campaign['job_processed'] ?? 0))); $jobPercent = $jobTotal > 0 ? (int)floor(($jobProcessed / $jobTotal) * 100) : 0; ?>
                                <tr data-campaign-row data-campaign-id="<?php echo (int)$campaign['id']; ?>" data-job-active="<?php echo $jobActive ? '1' : '0'; ?>">
                                    <td><?php echo htmlspecialchars((string)$campaign['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if (is_super_admin()) : ?><td><?php $companyName = '-'; foreach ($companies as $company) { if ((int)$company['id'] === (int)$campaign['company_id']) { $companyName = (string)$company['name']; break; } } echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                                    <td><?php echo htmlspecialchars((string)($campaignProvider['name'] ?? 'Provider'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($campaignList['name'] ?? 'Lista'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php $campaignEstimate = $campaignEstimates[(int)$campaign['id']] ?? []; ?>
                                    <td><strong>€ <?php echo number_format((float)($campaignEstimate['expected_cost'] ?? 0), 4, ',', '.'); ?></strong><br><span class="status-pill <?php echo !empty($campaignEstimate['can_start']) ? 'sent' : 'failed'; ?>"><?php echo !empty($campaignEstimate['can_start']) ? 'Credito sufficiente' : 'Bloccata'; ?></span></td>
                                    <td><?php echo htmlspecialchars((string)($campaign['sender'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="wrap-text" data-campaign-result><?php echo htmlspecialchars((string)($campaign['last_result'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($campaign['last_sent_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="actions-cell">
                                        <?php if (user_can('edit_campaigns')) : ?><a href="<?php echo app_url('campaigns', ['edit' => (int)$campaign['id']]); ?>" class="action-btn">Modifica</a><?php endif; ?>
                                        <form method="post" data-device-authorized="campaign" data-async-submit="true" class="campaign-run-form">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="run_campaign">
                                            <input type="hidden" name="id" value="<?php echo (int)$campaign['id']; ?>">
                                            <input type="hidden" name="authorization_id" value="">
                                            <button type="submit" class="action-btn" data-can-start="<?php echo !empty($campaignEstimate['can_start']) ? '1' : '0'; ?>" <?php echo empty($campaignEstimate['can_start']) || $jobActive ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars((string)($campaignEstimate['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">Invia</button>
                                        </form>
                                        <div class="campaign-job-progress" data-campaign-progress <?php echo !$jobActive && $jobTotal <= 0 ? 'hidden' : ''; ?>>
                                            <div class="campaign-progress-track"><span style="width:<?php echo $jobPercent; ?>%"></span></div>
                                            <strong data-progress-label><?php echo $jobPercent; ?>%</strong>
                                            <small data-progress-remaining><?php echo max(0, $jobTotal - $jobProcessed); ?> rimasti</small>
                                            <button type="button" class="campaign-cancel-btn" data-cancel-campaign <?php echo !$jobActive ? 'hidden' : ''; ?>>Ferma</button>
                                        </div>
                                        <?php if (user_can('delete_campaigns')) : ?><form method="post" onsubmit="return confirm('Eliminare questa campagna?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_campaign">
                                            <input type="hidden" name="id" value="<?php echo (int)$campaign['id']; ?>">
                                            <button type="submit" class="action-btn danger-btn">Elimina</button>
                                        </form><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if (!empty($csvResults)) : ?>
            <section class="card results-card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Esito invio</p>
                        <h3>Risultati ultima campagna eseguita</h3>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Destinatario</th><th>Stato</th><th>Risposta</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($csvResults as $row) : ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($row['recipient'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="status-pill <?php echo ($row['status'] ?? '') === 'sent' ? 'sent' : 'failed'; ?>"><?php echo htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars((string)($row['response'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <script>
        (function () {
            var modal = document.getElementById('campaignModal');
            var openBtn = document.getElementById('openCampaignModal');
            var closeBtn = document.getElementById('closeCampaignModal');
            var provider = document.getElementById('provider_id');
            var list = document.getElementById('list_id');
            var providerName = document.getElementById('campaignProviderName');
            var recipientCount = document.getElementById('campaignRecipientCount');
            var message = document.getElementById('message');
            var expectedCost = document.getElementById('campaignExpectedCost');
            var availableCredit = document.getElementById('campaignAvailableCredit');
            var estimateStatus = document.getElementById('campaignEstimateStatus');
            var estimateMessage = document.getElementById('campaignEstimateMessage');
            var campaignForm = modal ? modal.querySelector('form') : null;
            var estimateTimer = null;
            var estimateRequest = null;

            if (!modal || !closeBtn || !provider || !list || !providerName || !recipientCount || !message || !campaignForm) {
                return;
            }

            if (openBtn) {
                openBtn.addEventListener('click', function () {
                    modal.classList.add('is-active');
                });
            }

            closeBtn.addEventListener('click', function () {
                window.location.href = <?php echo json_encode(app_url('campaigns')); ?>;
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    window.location.href = <?php echo json_encode(app_url('campaigns')); ?>;
                }
            });

            function updateSummary() {
                providerName.textContent = provider.selectedIndex > 0 ? provider.options[provider.selectedIndex].text : 'Da selezionare';
                recipientCount.textContent = list.selectedIndex > 0 ? (list.options[list.selectedIndex].dataset.total || '0') : '0';
                window.clearTimeout(estimateTimer);
                estimateTimer = window.setTimeout(loadEstimate, 250);
            }

            function formatEuro(value) {
                return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(Number(value || 0));
            }

            function setEstimateState(label, text, ready) {
                estimateStatus.textContent = label;
                estimateStatus.className = 'status-pill ' + (ready ? 'sent' : 'failed');
                estimateMessage.textContent = text;
            }

            function loadEstimate() {
                if (!provider.value || !list.value || !message.value.trim()) {
                    expectedCost.textContent = formatEuro(0);
                    availableCredit.textContent = '—';
                    setEstimateState('Da calcolare', 'Seleziona provider e lista, poi scrivi il messaggio per calcolare la spesa.', false);
                    return;
                }
                if (estimateRequest) estimateRequest.abort();
                estimateRequest = new AbortController();
                setEstimateState('Calcolo…', 'Analisi dei destinatari e delle relative tariffe in corso.', false);
                var data = new URLSearchParams();
                data.set('action', 'estimate_campaign');
                data.set('csrf_token', campaignForm.querySelector('[name="csrf_token"]').value);
                data.set('provider_id', provider.value);
                data.set('list_id', list.value);
                data.set('message', message.value);
                fetch(<?php echo json_encode(app_url('campaigns')); ?>, { method: 'POST', body: data, signal: estimateRequest.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) { return response.json(); })
                    .then(function (estimate) {
                        expectedCost.textContent = formatEuro(estimate.expected_cost);
                        availableCredit.textContent = estimate.billing_enabled ? formatEuro(estimate.balance) : formatEuro(estimate.balance) + ' · controllo disattivato';
                        var detail = estimate.message || 'Stima non disponibile.';
                        if (estimate.quoted_count !== undefined) detail += ' Destinatari tariffati: ' + estimate.quoted_count + '/' + estimate.recipient_count + '.';
                        setEstimateState(estimate.can_start ? 'Credito sufficiente' : 'Campagna bloccata', detail, Boolean(estimate.can_start));
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') setEstimateState('Errore', 'Impossibile calcolare la spesa prevista.', false);
                    });
            }
            provider.addEventListener('change', updateSummary);
            list.addEventListener('change', updateSummary);
            message.addEventListener('input', updateSummary);
            updateSummary();
        }());
    </script>
    <script>
        (function () {
            var campaignUrl = <?php echo json_encode(app_url('campaigns')); ?>;

            function updateProgress(row, progress) {
                if (!row || !progress) return;
                var panel = row.querySelector('[data-campaign-progress]');
                var button = row.querySelector('.campaign-run-form button[type="submit"]');
                var result = row.querySelector('[data-campaign-result]');
                var cancelButton = row.querySelector('[data-cancel-campaign]');
                var percent = Math.max(0, Math.min(100, Number(progress.percent || 0)));
                if (panel) {
                    panel.hidden = false;
                    panel.querySelector('.campaign-progress-track span').style.width = percent + '%';
                    panel.querySelector('[data-progress-label]').textContent = percent + '%';
                    panel.querySelector('[data-progress-remaining]').textContent = Number(progress.remaining || 0) + ' rimasti su ' + Number(progress.total || 0);
                }
                if (result && progress.message) result.textContent = progress.message;
                row.dataset.jobActive = progress.active ? '1' : '0';
                if (button) button.disabled = Boolean(progress.active) || button.dataset.canStart !== '1';
                if (cancelButton) cancelButton.hidden = !progress.active;
            }

            function processNextBatch(row) {
                if (!row || row.dataset.pumping === '1' || row.dataset.jobActive !== '1') return;
                var form = row.querySelector('.campaign-run-form');
                if (!form) return;
                row.dataset.pumping = '1';
                var body = new URLSearchParams();
                body.set('action', 'process_campaign_batch');
                body.set('id', row.dataset.campaignId);
                body.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
                fetch(campaignUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: body
                }).then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data.success) throw new Error(data.message || 'Batch non riuscito');
                        return data;
                    });
                }).then(function (progress) {
                    updateProgress(row, progress);
                    row.dataset.pumping = '0';
                    if (progress.active) window.setTimeout(function () { processNextBatch(row); }, progress.busy ? 1000 : 150);
                }).catch(function () {
                    row.dataset.pumping = '0';
                    var remaining = row.querySelector('[data-progress-remaining]');
                    if (remaining) remaining.textContent = 'Connessione interrotta, nuovo tentativo...';
                    window.setTimeout(function () { processNextBatch(row); }, 2000);
                });
            }

            document.querySelectorAll('.campaign-run-form[data-async-submit="true"]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    var authorization = form.querySelector('[name="authorization_id"]');
                    if (!authorization || !authorization.value) return;
                    event.preventDefault();
                    var row = form.closest('[data-campaign-row]');
                    var button = form.querySelector('button[type="submit"]');
                    if (button) button.disabled = true;
                    fetch(campaignUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(form)
                    }).then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok || !data.success) throw new Error(data.message || 'Impossibile avviare la campagna');
                            return data;
                        });
                    }).then(function (data) {
                        updateProgress(row, data.progress || data);
                        authorization.value = '';
                        processNextBatch(row);
                    }).catch(function (error) {
                        authorization.value = '';
                        if (button) button.disabled = false;
                        var status = form.querySelector('.device-auth-status');
                        if (status) {
                            status.hidden = false;
                            status.classList.add('alert-danger');
                            status.textContent = error.message;
                        }
                    });
                });
            });

            document.querySelectorAll('[data-cancel-campaign]').forEach(function (cancelButton) {
                cancelButton.addEventListener('click', function () {
                    var row = cancelButton.closest('[data-campaign-row]');
                    var form = row ? row.querySelector('.campaign-run-form') : null;
                    if (!row || !form || row.dataset.jobActive !== '1') return;
                    cancelButton.disabled = true;
                    cancelButton.textContent = 'Arresto...';
                    var body = new URLSearchParams();
                    body.set('action', 'cancel_campaign_job');
                    body.set('id', row.dataset.campaignId);
                    body.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
                    fetch(campaignUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: body
                    }).then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok || !data.success) throw new Error(data.message || 'Impossibile fermare la campagna');
                            return data;
                        });
                    }).then(function (progress) {
                        updateProgress(row, progress);
                        cancelButton.textContent = 'Ferma';
                        cancelButton.disabled = false;
                    }).catch(function (error) {
                        cancelButton.textContent = 'Riprova arresto';
                        cancelButton.disabled = false;
                        var remaining = row.querySelector('[data-progress-remaining]');
                        if (remaining) remaining.textContent = error.message;
                    });
                });
            });

            document.querySelectorAll('[data-campaign-row][data-job-active="1"]').forEach(function (row) {
                processNextBatch(row);
            });
        }());
    </script>
    <script src="js/device-auth.js?v=20260720-queue2" defer></script>
</body>
</html>
