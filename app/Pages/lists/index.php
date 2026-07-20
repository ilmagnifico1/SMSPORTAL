<?php
require_once 'inc/option.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

require_permission('send_bulk', default_authorized_page());

$app = new SmsApp();
$feedback = '';
$flashMessage = flash_message();
$openModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $feedback = 'Token di sicurezza non valido.';
    } elseif (($_POST['action'] ?? '') === 'save_list') {
        $saveResult = $app->saveList($_POST, $_FILES['csv_file'] ?? null, (string)$_SESSION['logged']);
        $feedback = (string)($saveResult['message'] ?? '');
        $openModal = !empty($saveResult['success']) ? false : true;
        system_log(!empty($saveResult['success']) ? 'info' : 'error', 'list', !empty($saveResult['success']) ? 'list.saved' : 'list.save_failed', $feedback, [
            'company_id' => (int)($_POST['company_id'] ?? current_company_id()),
            'team_id' => (int)($_POST['team_id'] ?? current_team_id()),
            'list_id' => (int)($_POST['id'] ?? 0),
            'list_name' => trim((string)($_POST['name'] ?? '')),
            'csv_name' => (string)($_FILES['csv_file']['name'] ?? ''),
        ]);
    } elseif (($_POST['action'] ?? '') === 'delete_list') {
        $listToDelete = $app->getListById((int)($_POST['id'] ?? 0));
        $deleted = $app->deleteList((int)($_POST['id'] ?? 0));
        $feedback = $deleted ? 'Lista eliminata.' : 'Impossibile eliminare la lista.';
        system_log($deleted ? 'info' : 'error', 'list', $deleted ? 'list.deleted' : 'list.delete_failed', $feedback, [
            'company_id' => (int)($listToDelete['company_id'] ?? current_company_id()),
            'team_id' => (int)($listToDelete['team_id'] ?? current_team_id()),
            'list_id' => (int)($_POST['id'] ?? 0),
            'list_name' => (string)($listToDelete['name'] ?? ''),
        ]);
    }
}

$editingList = !empty($_GET['edit']) && user_can('edit_lists') ? $app->getListById((int)$_GET['edit']) : null;
if ($editingList) {
    $openModal = true;
}

$lists = $app->getLists();
$companies = is_super_admin() ? $app->getCompanies(true) : [];
$teams = $app->getTeams(['active' => '1']);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione liste</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-lists">
    <div id="wrapper" class="dashboard-shell">
        <div id="header">
            <div>
                <p class="eyebrow">Rubriche destinatari</p>
                <h2>Gestione liste</h2>
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
                <p class="eyebrow">Contatti organizzati</p>
                <h3>Liste destinatari</h3>
                <p class="muted-text">Importa i numeri da CSV, controlla i contatti e riutilizza le liste nelle campagne.</p>
            </div>
        </section>

        <section class="card import-flow-card">
            <div class="import-flow">
                <div class="flow-step"><span>1</span><strong>Prepara il file</strong><small>Numeri in formato internazionale</small></div>
                <i></i>
                <div class="flow-step"><span>2</span><strong>Importa il CSV</strong><small>Il portale valida i contatti</small></div>
                <i></i>
                <div class="flow-step"><span>3</span><strong>Usa la lista</strong><small>Associala a una campagna</small></div>
            </div>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Archivio liste</p>
                    <h3>Liste salvate</h3>
                </div>
                <?php if (user_can('create_lists')) : ?><button type="button" class="modal-trigger-btn" id="openListModal">Crea lista</button><?php endif; ?>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>ID lista</th><th>Nome lista</th><?php if (is_super_admin()) : ?><th>Azienda</th><?php endif; ?><th>Team</th><th>Origine import</th><th>Contatti</th><th>Ricevuti</th><th>Non inviati</th><th>Log</th><th>Azioni</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lists)) : ?>
                            <tr><td colspan="<?php echo is_super_admin() ? 10 : 9; ?>">Nessuna lista salvata.</td></tr>
                        <?php else : ?>
                            <?php foreach ($lists as $list) : ?>
                                <tr>
                                    <td><?php echo (int)$list['id']; ?></td>
                                    <td><?php echo htmlspecialchars((string)$list['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if (is_super_admin()) : ?><td><?php $companyName = '-'; foreach ($companies as $company) { if ((int)$company['id'] === (int)$list['company_id']) { $companyName = (string)$company['name']; break; } } echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                                    <td><?php $teamName = '-'; foreach ($teams as $team) { if ((int)$team['id'] === (int)$list['team_id']) { $teamName = (string)$team['name']; break; } } echo htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($list['csv_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)($list['total_contacts'] ?? 0); ?></td>
                                    <td><?php echo (int)($list['sent_count'] ?? 0); ?></td>
                                    <td><?php echo (int)($list['failed_count'] ?? 0); ?></td>
                                    <td class="wrap-text"><?php echo htmlspecialchars((string)($list['last_log'] ?? 'Nessun invio registrato'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="actions-cell">
                                        <?php if (user_can('edit_lists')) : ?><a href="<?php echo app_url('lists', ['edit' => (int)$list['id']]); ?>" class="action-btn">Modifica</a><?php endif; ?>
                                        <?php if (user_can('delete_lists')) : ?><form method="post" onsubmit="return confirm('Eliminare questa lista?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_list">
                                            <input type="hidden" name="id" value="<?php echo (int)$list['id']; ?>">
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
    </div>

    <div class="modal-overlay <?php echo $openModal ? 'is-active' : ''; ?>" id="listModal">
        <div class="modal-card">
            <div class="modal-header">
                <div>
                    <p class="eyebrow"><?php echo $editingList ? 'Modifica lista' : 'Nuova lista'; ?></p>
                    <h3><?php echo $editingList ? 'Aggiorna lista' : 'Crea lista'; ?></h3>
                </div>
                <button type="button" class="modal-close-btn" id="closeListModal" aria-label="Chiudi popup">x</button>
            </div>

            <form method="post" enctype="multipart/form-data" class="stacked-form two-columns-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_list">
                <input type="hidden" name="id" value="<?php echo (int)($editingList['id'] ?? 0); ?>">

                <?php if (is_super_admin()) : ?><div><label for="company_id">Azienda</label><?php $selectedCompany = (int)($_POST['company_id'] ?? ($editingList['company_id'] ?? current_company_id())); ?><select name="company_id" id="company_id" required><?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>" <?php echo $selectedCompany === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <div><label for="team_id">Team</label><?php $selectedTeam = (int)($_POST['team_id'] ?? ($editingList['team_id'] ?? current_team_id())); ?><select name="team_id" id="team_id" required><option value="">Seleziona team</option><?php foreach ($teams as $team) : ?><option value="<?php echo (int)$team['id']; ?>" data-company-id="<?php echo (int)$team['company_id']; ?>" <?php echo $selectedTeam === (int)$team['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$team['name'] . (is_super_admin() ? ' · ' . (string)$team['company_name'] : ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>

                <div class="full-span">
                    <label for="name">Nome lista</label>
                    <input type="text" name="name" id="name" placeholder="Esempio: Clienti Romania" value="<?php echo htmlspecialchars((string)($_POST['name'] ?? ($editingList['name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="full-span">
                    <div class="csv-field-heading">
                    <label for="csv_file">Importa lead da CSV (opzionale)</label>
                        <a href="templates/template_lista.csv" class="action-btn template-download-btn" download="template_lista.csv">Scarica template CSV</a>
                    </div>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv">
                    <p class="hint-text">Compila la colonna <strong>number</strong> usando il formato internazionale, per esempio +393471234567. Il file non viene conservato: i numeri vengono salvati nel database.</p>
                    <?php if ($editingList && !empty($editingList['csv_name'])) : ?>
                        <p class="hint-text">Ultima origine importata: <?php echo htmlspecialchars((string)$editingList['csv_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="full-span form-actions">
                    <input type="submit" value="<?php echo $editingList ? 'Aggiorna lista' : 'Salva lista'; ?>">
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var modal = document.getElementById('listModal');
            var openBtn = document.getElementById('openListModal');
            var closeBtn = document.getElementById('closeListModal');
            var companySelect = document.getElementById('company_id');
            var teamSelect = document.getElementById('team_id');

            if (!modal || !closeBtn) {
                return;
            }

            if (openBtn) {
                openBtn.addEventListener('click', function () {
                    modal.classList.add('is-active');
                });
            }

            closeBtn.addEventListener('click', function () {
                window.location.href = <?php echo json_encode(app_url('lists')); ?>;
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    window.location.href = <?php echo json_encode(app_url('lists')); ?>;
                }
            });
            if (companySelect && teamSelect) {
                var filterTeams = function () {
                    Array.prototype.forEach.call(teamSelect.options, function (option) { if (option.value) option.hidden = option.getAttribute('data-company-id') !== companySelect.value; });
                    if (teamSelect.selectedOptions.length && teamSelect.selectedOptions[0].hidden) teamSelect.value = '';
                };
                companySelect.addEventListener('change', filterTeams);
                filterTeams();
            }
        }());
    </script>
</body>
</html>
