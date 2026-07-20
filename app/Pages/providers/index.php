<?php
require_once 'inc/option.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

require_permission('manage_providers');

$app = new SmsApp();
$credits = new CreditManager();
$message = '';
$flashMessage = flash_message();
$openModal = false;
$openCreditModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Token di sicurezza non valido.';
    } elseif ($_POST['action'] === 'save_provider') {
        $saved = $app->saveProvider($_POST);
        $message = $saved ? 'Provider salvato.' : 'Impossibile salvare provider.';
        $openModal = !$saved;
        system_log($saved ? 'info' : 'error', 'provider', $saved ? 'config.saved' : 'config.save_failed', $message, [
            'company_id' => (int)($_POST['company_id'] ?? current_company_id()),
            'provider_id' => (int)($_POST['id'] ?? 0),
            'provider_name' => trim((string)($_POST['name'] ?? '')),
            'provider_type' => trim((string)($_POST['provider_type'] ?? 'generic')),
            'endpoint' => trim((string)($_POST['endpoint'] ?? '')),
        ]);
    } elseif ($_POST['action'] === 'delete_provider') {
        $providerToDelete = $app->getProviderById((int)($_POST['id'] ?? 0));
        $deleted = $app->deleteProvider((int)($_POST['id'] ?? 0));
        $message = $deleted ? 'Provider eliminato.' : 'Impossibile eliminare provider.';
        system_log($deleted ? 'info' : 'error', 'provider', $deleted ? 'config.deleted' : 'config.delete_failed', $message, [
            'company_id' => (int)($providerToDelete['company_id'] ?? current_company_id()),
            'provider_id' => (int)($_POST['id'] ?? 0),
            'provider_name' => (string)($providerToDelete['name'] ?? ''),
        ]);
    } elseif ($_POST['action'] === 'adjust_provider_credit') {
        $saved = $credits->adjustProviderBalance((int)($_POST['provider_id'] ?? 0), (float)str_replace(',', '.', (string)($_POST['amount'] ?? 0)), (string)($_POST['description'] ?? ''), (string)$_SESSION['logged']);
        $message = $saved ? 'Movimento credito provider registrato.' : 'Impossibile registrare il movimento. Verifica importo e saldo.';
        $openCreditModal = !$saved;
        system_log($saved ? 'info' : 'error', 'provider_credit', $saved ? 'provider_credit.adjusted' : 'provider_credit.adjust_failed', $message, ['provider_id' => (int)($_POST['provider_id'] ?? 0), 'amount' => (float)($_POST['amount'] ?? 0)]);
    }
}

$filters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'active' => (string)($_GET['active'] ?? 'all'),
    'company_id' => (int)($_GET['company_id'] ?? 0),
];
$companies = is_super_admin() ? $app->getCompanies(true) : [];
$providers = $app->getProviders($filters);
$providerBalances = [];
foreach ($credits->getProviderBalances() as $providerBalance) { $providerBalances[(int)$providerBalance['provider_id']] = $providerBalance; }
$providerTransactions = $credits->getProviderTransactions(0, 100);
$testProviderLogs = is_super_admin() ? $app->getInternalTestLogs(100) : [];
$testProviderStats = is_super_admin() ? $app->getInternalTestStats() : ['total' => 0, 'sent' => 0, 'failed' => 0];
$creditProviderId = (int)($_GET['credit_provider'] ?? ($_POST['provider_id'] ?? 0));
$creditProvider = $creditProviderId > 0 ? $app->getProviderById($creditProviderId) : null;
if ($creditProvider && (string)($creditProvider['provider_type'] ?? '') === 'internal') { $creditProvider = null; $creditProviderId = 0; }
if ($creditProvider && !empty($_GET['credit_provider'])) { $openCreditModal = true; }
$editingProvider = !empty($_GET['edit']) ? $app->getProviderById((int)$_GET['edit']) : null;
if ($editingProvider) {
    $openModal = true;
}
$submittedProvider = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_provider';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione provider</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-providers settings-area">
    <div id="wrapper" class="dashboard-shell">
        <div id="header">
            <div>
                <p class="eyebrow">Configurazione gateway</p>
                <h2>Gestione provider</h2>
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

        <?php if ($message !== '') : ?>
            <div class="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="page-heading">
            <div>
                <p class="eyebrow">Gateway SMS</p>
                <h1>Gestione provider</h1>
                <p class="muted-text">Configura i gateway, ricarica il credito provider e controlla automaticamente le uscite SMS.</p>
            </div>
            <span class="heading-pill">Provider flessibili e centralizzati</span>
        </section>

        <div>
            <div class="modal-overlay <?php echo $openModal ? 'is-active' : ''; ?>" id="providerModal">
                <div class="modal-card">
                    <div class="modal-header">
                    <div>
                        <p class="eyebrow"><?php echo $editingProvider ? 'Modifica' : 'Nuovo'; ?></p>
                        <h3><?php echo $editingProvider ? 'Aggiorna provider' : 'Configura provider'; ?></h3>
                    </div>
                        <button type="button" class="modal-close-btn" id="closeProviderModal" aria-label="Chiudi popup">x</button>
                    </div>
                <form method="post" class="stacked-form two-columns-form">
                    <?php if ($openModal && $message !== '') : ?><div class="alert full-span"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_provider">
                    <input type="hidden" name="id" value="<?php echo (int)($editingProvider['id'] ?? 0); ?>">
                    <?php if (is_super_admin()) : ?><div class="full-span"><label for="company_id">Azienda</label><?php $selectedCompany = (int)($_POST['company_id'] ?? ($editingProvider['company_id'] ?? current_company_id())); ?><select name="company_id" id="company_id" required><?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>" <?php echo $selectedCompany === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><?php endif; ?>
                    <div>
                        <label for="name">Nome</label>
                        <input type="text" name="name" id="name" value="<?php echo htmlspecialchars((string)($_POST['name'] ?? ($editingProvider['name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div>
                        <label for="provider_type">Tipo connessione</label>
                        <select name="provider_type" id="provider_type">
                            <?php $selectedProviderType = (string)($_POST['provider_type'] ?? ($editingProvider['provider_type'] ?? 'generic')); ?>
                            <option value="generic" <?php echo $selectedProviderType === 'generic' ? 'selected' : ''; ?>>Generico</option>
                            <option value="twilio" <?php echo $selectedProviderType === 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                            <option value="internal" <?php echo $selectedProviderType === 'internal' ? 'selected' : ''; ?>>Test interno · solo Super Admin</option>
                        </select>
                    </div>
                    <div>
                        <label for="request_type">Request type</label>
                        <select name="request_type" id="request_type">
                            <?php $selectedRequestType = (string)($_POST['request_type'] ?? ($editingProvider['request_type'] ?? 'GET')); ?>
                            <option value="GET" <?php echo $selectedRequestType === 'GET' ? 'selected' : ''; ?>>GET</option>
                            <option value="POST" <?php echo $selectedRequestType === 'POST' ? 'selected' : ''; ?>>POST</option>
                        </select>
                    </div>
                    <div class="full-span">
                        <label for="endpoint">Endpoint</label>
                        <input type="text" name="endpoint" id="endpoint" value="<?php echo htmlspecialchars((string)($_POST['endpoint'] ?? ($editingProvider['endpoint'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" required>
                        <small>Twilio: https://api.twilio.com/2010-04-01/Accounts/ACCOUNT_SID/Messages.json</small>
                        <small>Test interno: https://provtest.book-my.eu/api/v1/messages?scenario=success</small>
                    </div>
                    <div>
                        <label for="username">Username</label>
                        <input type="text" name="username" id="username" value="<?php echo htmlspecialchars((string)($_POST['username'] ?? ($editingProvider['username'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                        <small>Per Twilio: Account SID oppure API Key SID.</small>
                    </div>
                    <div>
                        <label for="password">Password</label>
                        <input type="password" name="password" id="password" value="" autocomplete="new-password" placeholder="<?php echo $editingProvider ? 'Lascia vuoto per non modificare' : ''; ?>">
                        <small>Per Twilio: Auth Token oppure API Key Secret.</small>
                    </div>
                    <div>
                        <label for="api_key">API key</label>
                        <input type="password" name="api_key" id="api_key" value="" autocomplete="off" placeholder="<?php echo $editingProvider ? 'Lascia vuoto per non modificare' : ''; ?>">
                        <small>Per il test interno deve coincidere con INTERNAL_PROVIDER_API_KEY ed essere lunga almeno 32 caratteri.</small>
                    </div>
                    <div>
                        <label for="default_from">Mittente di default</label>
                        <input type="text" name="default_from" id="default_from" value="<?php echo htmlspecialchars((string)($_POST['default_from'] ?? ($editingProvider['default_from'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                        <small>Per Twilio: numero mittente in formato E.164 oppure Messaging Service SID (MG...).</small>
                    </div>
                    <div class="checkbox-row full-span">
                        <label><input type="checkbox" name="active" value="1" <?php echo $submittedProvider ? (!empty($_POST['active']) ? 'checked' : '') : (!isset($editingProvider['active']) || (int)$editingProvider['active'] === 1 ? 'checked' : ''); ?>> Provider attivo</label>
                    </div>
                    <div class="full-span form-actions">
                        <input type="submit" value="<?php echo $editingProvider ? 'Aggiorna provider' : 'Salva provider'; ?>">
                    </div>
                </form>
                </div>
            </div>

            <section class="card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Catalogo</p>
                        <h3>Provider configurati</h3>
                    </div>
                    <button type="button" class="modal-trigger-btn" id="openProviderModal">Nuovo provider</button>
                </div>

                <form method="get" class="filter-bar"><input type="hidden" name="route" value="providers">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cerca nome, endpoint o mittente">
                    <select name="active">
                        <option value="all" <?php echo $filters['active'] === 'all' ? 'selected' : ''; ?>>Tutti</option>
                        <option value="1" <?php echo $filters['active'] === '1' ? 'selected' : ''; ?>>Attivi</option>
                        <option value="0" <?php echo $filters['active'] === '0' ? 'selected' : ''; ?>>Disattivi</option>
                    </select>
                    <?php if (is_super_admin()) : ?><select name="company_id"><option value="0">Tutte le aziende</option><?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>" <?php echo $filters['company_id'] === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><?php endif; ?>
                    <input type="submit" value="Filtra">
                </form>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Nome</th><?php if (is_super_admin()) : ?><th>Azienda</th><th>Credito residuo</th><th>Profitto</th><?php endif; ?><th>Tipo</th><th>Stato</th><th>Azioni</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($providers)) : ?>
                                <tr><td colspan="<?php echo is_super_admin() ? 7 : 4; ?>">Nessun provider trovato.</td></tr>
                            <?php else : ?>
                                <?php foreach ($providers as $provider) : ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($provider['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <?php if (is_super_admin()) : ?><td><?php $companyName = '-'; foreach ($companies as $company) { if ((int)$company['id'] === (int)$provider['company_id']) { $companyName = (string)$company['name']; break; } } echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                                        <?php if (is_super_admin()) : ?><?php $providerCredit = $providerBalances[(int)$provider['id']] ?? ['balance' => 0, 'profit_total' => 0]; $isTestProvider = (string)($provider['provider_type'] ?? '') === 'internal'; ?><td class="<?php echo $isTestProvider ? '' : ((float)$providerCredit['balance'] > 0 ? 'credit-positive' : 'credit-negative'); ?>"><strong><?php echo $isTestProvider ? 'Non applicabile' : '€ ' . number_format((float)$providerCredit['balance'], 4, ',', '.'); ?></strong></td><td class="<?php echo $isTestProvider ? '' : ((float)$providerCredit['profit_total'] >= 0 ? 'credit-positive' : 'credit-negative'); ?>"><strong><?php echo $isTestProvider ? 'Separato dai dati reali' : '€ ' . number_format((float)$providerCredit['profit_total'], 4, ',', '.'); ?></strong></td><?php endif; ?>
                                        <?php $providerType = (string)($provider['provider_type'] ?? 'generic'); $providerTypeLabel = $providerType === 'twilio' ? 'Twilio' : ($providerType === 'internal' ? 'TEST INTERNO' : 'Generico'); ?>
                                        <td><span class="provider-tag <?php echo htmlspecialchars($providerType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $providerTypeLabel; ?></span></td>
                                        <td><span class="status-pill <?php echo (int)$provider['active'] === 1 ? 'sent' : 'failed'; ?>"><?php echo (int)$provider['active'] === 1 ? 'Attivo' : 'Disattivo'; ?></span></td>
                                        <td class="actions-cell">
                                            <a href="<?php echo app_url('providers', ['edit' => (int)$provider['id']]); ?>" class="table-link">Modifica</a>
                                            <?php if (is_super_admin() && (string)($provider['provider_type'] ?? '') !== 'internal') : ?><a href="<?php echo app_url('providers', ['credit_provider' => (int)$provider['id']]); ?>" class="action-btn">Ricarica</a><?php endif; ?>
                                            <form method="post">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_provider">
                                                <input type="hidden" name="id" value="<?php echo (int)$provider['id']; ?>">
                                                <input type="submit" value="Elimina" class="danger-btn">
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php if (is_super_admin()) : ?>
            <section class="card provider-test-history">
                <div class="section-heading"><div><p class="eyebrow">Ambiente isolato</p><h3>Ultimi invii del provider fittizio</h3><p class="muted-text">Questi eventi non modificano credito, costi, ricavi o statistiche degli SMS reali.</p></div><span class="provider-tag internal">TEST</span></div>
                <div class="stats-grid stats-footer"><article class="stat-card"><strong><?php echo (int)$testProviderStats['total']; ?></strong><span>Simulazioni totali</span></article><article class="stat-card"><strong><?php echo (int)$testProviderStats['sent']; ?></strong><span>Successi simulati</span></article><article class="stat-card"><strong><?php echo (int)$testProviderStats['failed']; ?></strong><span>Errori simulati</span></article></div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Data</th><th>Provider</th><th>Utente</th><th>Destinatario</th><th>Stato</th><th>HTTP</th><th>Risposta</th></tr></thead><tbody>
                <?php if (!$testProviderLogs) : ?><tr><td colspan="7">Nessun invio di test registrato.</td></tr><?php else : foreach ($testProviderLogs as $testLog) : ?><tr><td><?php echo htmlspecialchars((string)$testLog['created_at'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$testLog['provider_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$testLog['user_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$testLog['recipient'], ENT_QUOTES, 'UTF-8'); ?></td><td><span class="status-pill <?php echo (string)$testLog['status'] === 'sent' ? 'sent' : 'failed'; ?>"><?php echo htmlspecialchars((string)$testLog['status'], ENT_QUOTES, 'UTF-8'); ?></span></td><td><?php echo (int)$testLog['http_code']; ?></td><td class="wrap-text"><?php echo htmlspecialchars((string)$testLog['response'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; endif; ?>
                </tbody></table></div>
            </section>
            <section class="card provider-credit-history">
                <div class="section-heading"><div><p class="eyebrow">Uscite e ricariche</p><h3>Movimenti credito provider</h3></div></div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Data</th><th>Provider</th><th>Tipo</th><th>Importo</th><th>Descrizione</th></tr></thead><tbody>
                <?php if (!$providerTransactions) : ?><tr><td colspan="5">Nessun movimento provider.</td></tr><?php else : foreach ($providerTransactions as $transaction) : ?><tr><td><?php echo htmlspecialchars((string)$transaction['created_at'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$transaction['provider_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$transaction['transaction_type'], ENT_QUOTES, 'UTF-8'); ?></td><td class="<?php echo (float)$transaction['amount'] >= 0 ? 'credit-positive' : 'credit-negative'; ?>">€ <?php echo number_format((float)$transaction['amount'], 4, ',', '.'); ?></td><td><?php echo htmlspecialchars((string)$transaction['description'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; endif; ?>
                </tbody></table></div>
            </section>
            <?php endif; ?>
        </div>
    </div>
    <?php if (is_super_admin()) : ?><div class="modal-overlay <?php echo $openCreditModal ? 'is-active' : ''; ?>" id="providerCreditModal"><div class="modal-card"><div class="modal-header"><div><p class="eyebrow">Credito provider</p><h3>Ricarica o storno<?php echo $creditProvider ? ' · ' . htmlspecialchars((string)$creditProvider['name'], ENT_QUOTES, 'UTF-8') : ''; ?></h3></div><button type="button" class="modal-close-btn" id="closeProviderCreditModal" aria-label="Chiudi popup">x</button></div><form method="post" class="stacked-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="adjust_provider_credit"><label for="credit_provider_id">Provider</label><select name="provider_id" id="credit_provider_id" required><?php foreach ($providers as $provider) : if ((string)($provider['provider_type'] ?? '') === 'internal') continue; ?><option value="<?php echo (int)$provider['id']; ?>" <?php echo $creditProviderId === (int)$provider['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$provider['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><label for="provider_credit_amount">Importo (positivo ricarica, negativo storno)</label><input type="number" step="0.0001" name="amount" id="provider_credit_amount" required><label for="provider_credit_description">Descrizione</label><input name="description" id="provider_credit_description" maxlength="500" placeholder="Esempio: ricarica provider luglio"><p class="muted-text">Il credito provider è obbligatorio: se il saldo è zero o insufficiente l’invio viene bloccato. Il costo d’acquisto viene riservato prima dell’invio e rimborsato automaticamente in caso di errore.</p><div class="form-actions"><input type="submit" value="Registra movimento"></div></form></div></div><?php endif; ?>
    <script>
        (function () {
            var modal = document.getElementById('providerModal');
            var openBtn = document.getElementById('openProviderModal');
            var closeBtn = document.getElementById('closeProviderModal');

            if (!modal || !openBtn || !closeBtn) {
                return;
            }

            openBtn.addEventListener('click', function () {
                modal.classList.add('is-active');
            });

            closeBtn.addEventListener('click', function () {
                window.location.href = <?php echo json_encode(app_url('providers')); ?>;
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    window.location.href = <?php echo json_encode(app_url('providers')); ?>;
                }
            });
            var creditModal=document.getElementById('providerCreditModal'),creditClose=document.getElementById('closeProviderCreditModal'),providersUrl=<?php echo json_encode(app_url('providers')); ?>;if(creditModal&&creditClose){creditClose.addEventListener('click',function(){window.location.href=providersUrl;});creditModal.addEventListener('click',function(event){if(event.target===creditModal)window.location.href=providersUrl;});}
        }());
    </script>
</body>
</html>
