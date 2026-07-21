<?php
require_once 'inc/option.php';

if (empty($_SESSION['logged'])) {
    header('Location: ' . app_url('login'));
    exit;
}

require_permission('manage_users');

$app = new SmsApp();
$message = '';
$flashMessage = flash_message();
$openModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Token di sicurezza non valido.';
    } elseif ($_POST['action'] === 'save_user') {
        $saved = $app->saveUser($_POST);
        $message = $saved ? 'Utente salvato.' : 'Impossibile salvare utente. Controlla username e password.';
        $openModal = !$saved;
        system_log($saved ? 'info' : 'error', 'user', $saved ? 'user.saved' : 'user.save_failed', $message, [
            'company_id' => (int)($_POST['company_id'] ?? current_company_id()),
            'user_id' => (int)($_POST['id'] ?? 0),
            'target_username' => trim((string)($_POST['username'] ?? '')),
            'role' => trim((string)($_POST['role'] ?? 'user')),
        ]);
    } elseif ($_POST['action'] === 'delete_user') {
        $userToDelete = $app->getUserById((int)($_POST['id'] ?? 0));
        $deleted = $app->deleteUser((int)($_POST['id'] ?? 0));
        $message = $deleted ? 'Utente eliminato.' : 'Impossibile eliminare utente.';
        system_log($deleted ? 'info' : 'error', 'user', $deleted ? 'user.deleted' : 'user.delete_failed', $message, [
            'company_id' => (int)($userToDelete['company_id'] ?? current_company_id()),
            'user_id' => (int)($_POST['id'] ?? 0),
            'target_username' => (string)($userToDelete['username'] ?? ''),
        ]);
    }
}

$filters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'active' => (string)($_GET['active'] ?? 'all'),
    'company_id' => (int)($_GET['company_id'] ?? 0),
    'team_id' => (int)($_GET['team_id'] ?? 0),
];
$companies = $app->getCompanies(true);
$teams = $app->getTeams(['active' => 'all', 'company_id' => $filters['company_id']]);
$users = $app->getUsers($filters);
$editingUser = !empty($_GET['edit']) ? $app->getUserById((int)$_GET['edit']) : null;
if ($editingUser) {
    $openModal = true;
}
$submittedUser = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_user';
$availableProviders = $app->getProviders(['active' => '1']);
$providerCompanyMap = [];
if (is_super_admin()) {
    foreach ($companies as $company) {
        foreach ($app->getCompanyProviderIds((int)$company['id']) as $providerId) {
            $providerCompanyMap[$providerId][] = (int)$company['id'];
        }
    }
}
$selectedProviderIds = $submittedUser
    ? array_map('intval', (array)($_POST['provider_ids'] ?? []))
    : ($editingUser
        ? $app->getUserProviderIds((int)$editingUser['id'])
        : []);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione utenti</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-users">
    <div id="wrapper" class="dashboard-shell">
        <div id="header">
            <div>
                <p class="eyebrow">Amministrazione</p>
                <h2>Gestione utenti</h2>
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

        <?php if ($message !== '') : ?>
            <div class="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="page-heading">
            <div>
                <p class="eyebrow">Amministrazione</p>
                <h1>Gestione utenti</h1>
                <p class="muted-text">Crea account e assegna i permessi operativi.</p>
            </div>
            <span class="heading-pill">Accessi e permessi sotto controllo</span>
        </section>

        <div>
            <div class="modal-overlay <?php echo $openModal ? 'is-active' : ''; ?>" id="userModal">
                <div class="modal-card">
                    <div class="modal-header">
                    <div>
                        <p class="eyebrow"><?php echo $editingUser ? 'Modifica' : 'Nuovo'; ?></p>
                        <h3><?php echo $editingUser ? 'Aggiorna utente' : 'Crea utente'; ?></h3>
                    </div>
                        <button type="button" class="modal-close-btn" id="closeUserModal" aria-label="Chiudi popup">x</button>
                    </div>
                <form method="post" class="stacked-form two-columns-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="id" value="<?php echo (int)($editingUser['id'] ?? 0); ?>">
                    <div>
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars((string)($_POST['username'] ?? ($editingUser['username'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div>
                        <label for="password">Password <?php echo $editingUser ? '(lascia vuoto per non cambiarla)' : ''; ?></label>
                        <input type="password" id="password" name="password" <?php echo $editingUser ? '' : 'required'; ?>>
                    </div>
                    <div>
                        <label for="role">Ruolo</label>
                        <select name="role" id="role">
                            <?php $selectedRole = (string)($_POST['role'] ?? ($editingUser['role'] ?? 'user')); ?>
                            <option value="user" <?php echo $selectedRole === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="admin" <?php echo $selectedRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <?php if (is_super_admin()) : ?><option value="super_admin" <?php echo $selectedRole === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option><?php endif; ?>
                        </select>
                    </div>
                    <?php if (is_super_admin()) : ?>
                    <div>
                        <label for="company_id">Azienda</label>
                        <?php $selectedCompany = (int)($_POST['company_id'] ?? ($editingUser['company_id'] ?? current_company_id())); ?>
                        <select name="company_id" id="company_id" required>
                            <?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>" <?php echo $selectedCompany === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label for="team_id">Team</label>
                        <?php $selectedTeam = (int)($_POST['team_id'] ?? ($editingUser['team_id'] ?? current_team_id())); ?>
                        <select name="team_id" id="team_id" required>
                            <option value="">Seleziona team</option>
                            <?php foreach ($teams as $team) : ?><option value="<?php echo (int)$team['id']; ?>" data-company-id="<?php echo (int)$team['company_id']; ?>" <?php echo $selectedTeam === (int)$team['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$team['name'] . (is_super_admin() ? ' · ' . (string)$team['company_name'] : ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="checkbox-row">
                        <label><input type="checkbox" name="active" value="1" <?php echo $submittedUser ? (!empty($_POST['active']) ? 'checked' : '') : (!isset($editingUser['active']) || (int)$editingUser['active'] === 1 ? 'checked' : ''); ?>> Attivo</label>
                    </div>
                    <div class="full-span permissions-grid">
                        <label><input type="checkbox" name="can_send_single" value="1" <?php echo $submittedUser ? (!empty($_POST['can_send_single']) ? 'checked' : '') : (!isset($editingUser['can_send_single']) || (int)$editingUser['can_send_single'] === 1 ? 'checked' : ''); ?>> Invio SMS singoli</label>
                        <label><input type="checkbox" name="can_send_bulk" value="1" <?php echo $submittedUser ? (!empty($_POST['can_send_bulk']) ? 'checked' : '') : (!isset($editingUser['can_send_bulk']) || (int)$editingUser['can_send_bulk'] === 1 ? 'checked' : ''); ?>> Invio SMS multipli</label>
                        <label><input type="checkbox" name="can_manage_users" value="1" <?php echo $submittedUser ? (!empty($_POST['can_manage_users']) ? 'checked' : '') : (!isset($editingUser['can_manage_users']) || (int)$editingUser['can_manage_users'] === 1 ? 'checked' : ''); ?>> Gestione utenti</label>
                        <p class="full-span muted-text">Selezionato: vede i dati del proprio Team. Non selezionato: vede soltanto i propri dati.</p>
                        <label><input type="checkbox" name="can_view_dashboard" value="1" <?php echo $submittedUser ? (!empty($_POST['can_view_dashboard']) ? 'checked' : '') : (!isset($editingUser['can_view_dashboard']) || (int)$editingUser['can_view_dashboard'] === 1 ? 'checked' : ''); ?>> Dati Team nella Dashboard</label>
                        <label><input type="checkbox" name="can_view_campaigns" value="1" <?php echo $submittedUser ? (!empty($_POST['can_view_campaigns']) ? 'checked' : '') : (!isset($editingUser['can_view_campaigns']) || (int)$editingUser['can_view_campaigns'] === 1 ? 'checked' : ''); ?>> Campagne del Team</label>
                        <label><input type="checkbox" name="can_view_lists" value="1" <?php echo $submittedUser ? (!empty($_POST['can_view_lists']) ? 'checked' : '') : (!isset($editingUser['can_view_lists']) || (int)$editingUser['can_view_lists'] === 1 ? 'checked' : ''); ?>> Liste del Team</label>
                        <label><input type="checkbox" name="can_view_team_messages" value="1" <?php echo $submittedUser ? (!empty($_POST['can_view_team_messages']) ? 'checked' : '') : (!isset($editingUser['can_view_team_messages']) || (int)$editingUser['can_view_team_messages'] === 1 ? 'checked' : ''); ?>> Ultimi messaggi del Team</label>
                        <p class="full-span muted-text">Operazioni consentite su campagne e liste.</p>
                        <label><input type="checkbox" name="can_create_campaigns" value="1" <?php echo $submittedUser ? (!empty($_POST['can_create_campaigns']) ? 'checked' : '') : (!isset($editingUser['can_create_campaigns']) || (int)$editingUser['can_create_campaigns'] === 1 ? 'checked' : ''); ?>> Crea campagne</label>
                        <label><input type="checkbox" name="can_edit_campaigns" value="1" <?php echo $submittedUser ? (!empty($_POST['can_edit_campaigns']) ? 'checked' : '') : (!isset($editingUser['can_edit_campaigns']) || (int)$editingUser['can_edit_campaigns'] === 1 ? 'checked' : ''); ?>> Modifica campagne</label>
                        <label><input type="checkbox" name="can_delete_campaigns" value="1" <?php echo $submittedUser ? (!empty($_POST['can_delete_campaigns']) ? 'checked' : '') : (!isset($editingUser['can_delete_campaigns']) || (int)$editingUser['can_delete_campaigns'] === 1 ? 'checked' : ''); ?>> Elimina campagne</label>
                        <label><input type="checkbox" name="can_create_lists" value="1" <?php echo $submittedUser ? (!empty($_POST['can_create_lists']) ? 'checked' : '') : (!isset($editingUser['can_create_lists']) || (int)$editingUser['can_create_lists'] === 1 ? 'checked' : ''); ?>> Crea liste</label>
                        <label><input type="checkbox" name="can_edit_lists" value="1" <?php echo $submittedUser ? (!empty($_POST['can_edit_lists']) ? 'checked' : '') : (!isset($editingUser['can_edit_lists']) || (int)$editingUser['can_edit_lists'] === 1 ? 'checked' : ''); ?>> Modifica liste</label>
                        <label><input type="checkbox" name="can_delete_lists" value="1" <?php echo $submittedUser ? (!empty($_POST['can_delete_lists']) ? 'checked' : '') : (!isset($editingUser['can_delete_lists']) || (int)$editingUser['can_delete_lists'] === 1 ? 'checked' : ''); ?>> Elimina liste</label>
                    </div>
                    <div class="full-span">
                        <label>Provider visibili all'utente</label>
                        <div class="permissions-grid" id="providerPermissions">
                            <?php if (empty($availableProviders)) : ?>
                                <span class="muted-text">Nessun provider disponibile per l'azienda.</span>
                            <?php else : ?>
                                <?php foreach ($availableProviders as $provider) : ?>
                                    <label data-company-ids=",<?php echo htmlspecialchars(implode(',', is_super_admin() ? ($providerCompanyMap[(int)$provider['id']] ?? []) : [current_company_id()]), ENT_QUOTES, 'UTF-8'); ?>,">
                                        <input type="checkbox" name="provider_ids[]" value="<?php echo (int)$provider['id']; ?>" <?php echo in_array((int)$provider['id'], $selectedProviderIds, true) ? 'checked' : ''; ?>>
                                        <?php echo htmlspecialchars((string)$provider['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="full-span form-actions">
                        <input type="submit" value="<?php echo $editingUser ? 'Aggiorna utente' : 'Salva utente'; ?>">
                    </div>
                </form>
                </div>
            </div>

            <section class="card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Ricerca</p>
                        <h3>Utenti registrati</h3>
                    </div>
                    <button type="button" class="modal-trigger-btn" id="openUserModal">Nuovo utente</button>
                </div>
                <form method="get" class="filter-bar"><input type="hidden" name="route" value="users">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cerca username o ruolo">
                    <?php if (is_super_admin()) : ?><select name="company_id"><option value="0">Tutte le aziende</option><?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>" <?php echo $filters['company_id'] === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><?php endif; ?>
                    <select name="team_id"><option value="0">Tutti i team</option><?php foreach ($teams as $team) : ?><option value="<?php echo (int)$team['id']; ?>" <?php echo $filters['team_id'] === (int)$team['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$team['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                    <select name="active">
                        <option value="all" <?php echo $filters['active'] === 'all' ? 'selected' : ''; ?>>Tutti</option>
                        <option value="1" <?php echo $filters['active'] === '1' ? 'selected' : ''; ?>>Attivi</option>
                        <option value="0" <?php echo $filters['active'] === '0' ? 'selected' : ''; ?>>Disattivi</option>
                    </select>
                    <input type="submit" value="Filtra">
                </form>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Username</th><th>Azienda</th><th>Team</th><th>Ruolo</th><th>Permessi</th><th>Stato</th><th>Creato</th><th>Azioni</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)) : ?>
                                <tr><td colspan="8">Nessun utente trovato.</td></tr>
                            <?php else : ?>
                                <?php foreach ($users as $user) : ?>
                                    <?php
                                    $role = (string)($user['role'] ?? 'user');
                                    $active = !array_key_exists('active', $user) || $user['active'] === null ? 1 : (int)$user['active'];
                                    $createdAt = (string)($user['created_at'] ?? '-');
                                    $permissionLabels = [];
                                    if (!array_key_exists('can_send_single', $user) || (int)($user['can_send_single'] ?? 1) === 1) { $permissionLabels[] = 'Singolo'; }
                                    if (!array_key_exists('can_send_bulk', $user) || (int)($user['can_send_bulk'] ?? 1) === 1) { $permissionLabels[] = 'Multiplo'; }
                                    if (!array_key_exists('can_manage_users', $user) || (int)($user['can_manage_users'] ?? 1) === 1) { $permissionLabels[] = 'Utenti'; }
                                    $permissionLabels[] = (!array_key_exists('can_view_dashboard', $user) || (int)($user['can_view_dashboard'] ?? 1) === 1) ? 'Dashboard Team' : 'Dashboard personale';
                                    $permissionLabels[] = (!array_key_exists('can_view_campaigns', $user) || (int)($user['can_view_campaigns'] ?? 1) === 1) ? 'Campagne Team' : 'Campagne personali';
                                    $permissionLabels[] = (!array_key_exists('can_view_lists', $user) || (int)($user['can_view_lists'] ?? 1) === 1) ? 'Liste Team' : 'Liste personali';
                                    $permissionLabels[] = (!array_key_exists('can_view_team_messages', $user) || (int)($user['can_view_team_messages'] ?? 1) === 1) ? 'Messaggi Team' : 'Messaggi personali';
                                    if (!array_key_exists('can_create_campaigns', $user) || (int)($user['can_create_campaigns'] ?? 1) === 1) { $permissionLabels[] = 'Crea campagne'; }
                                    if (!array_key_exists('can_edit_campaigns', $user) || (int)($user['can_edit_campaigns'] ?? 1) === 1) { $permissionLabels[] = 'Modifica campagne'; }
                                    if (!array_key_exists('can_delete_campaigns', $user) || (int)($user['can_delete_campaigns'] ?? 1) === 1) { $permissionLabels[] = 'Elimina campagne'; }
                                    if (!array_key_exists('can_create_lists', $user) || (int)($user['can_create_lists'] ?? 1) === 1) { $permissionLabels[] = 'Crea liste'; }
                                    if (!array_key_exists('can_edit_lists', $user) || (int)($user['can_edit_lists'] ?? 1) === 1) { $permissionLabels[] = 'Modifica liste'; }
                                    if (!array_key_exists('can_delete_lists', $user) || (int)($user['can_delete_lists'] ?? 1) === 1) { $permissionLabels[] = 'Elimina liste'; }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($user['company_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($user['team_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(!empty($permissionLabels) ? implode(', ', $permissionLabels) : 'Nessuno', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="status-pill <?php echo $active === 1 ? 'sent' : 'failed'; ?>"><?php echo $active === 1 ? 'Attivo' : 'Disattivo'; ?></span></td>
                                        <td><?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="actions-cell">
                                            <a href="<?php echo app_url('users', ['edit' => (int)$user['id']]); ?>" class="table-link">Modifica</a>
                                            <form method="post">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
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
        </div>
    </div>
    <script>
        (function () {
            var modal = document.getElementById('userModal');
            var openBtn = document.getElementById('openUserModal');
            var closeBtn = document.getElementById('closeUserModal');
            var companySelect = document.getElementById('company_id');
            var teamSelect = document.getElementById('team_id');
            var providerPermissions = document.getElementById('providerPermissions');

            if (!modal || !openBtn || !closeBtn) {
                return;
            }

            openBtn.addEventListener('click', function () {
                modal.classList.add('is-active');
            });

            closeBtn.addEventListener('click', function () {
                window.location.href = <?php echo json_encode(app_url('users')); ?>;
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    window.location.href = <?php echo json_encode(app_url('users')); ?>;
                }
            });

            if (companySelect && teamSelect) {
                var filterTeams = function () {
                    var companyId = companySelect.value;
                    Array.prototype.forEach.call(teamSelect.options, function (option) {
                        if (!option.value) return;
                        option.hidden = option.getAttribute('data-company-id') !== companyId;
                    });
                    if (teamSelect.selectedOptions.length && teamSelect.selectedOptions[0].hidden) teamSelect.value = '';
                    if (providerPermissions) {
                        Array.prototype.forEach.call(providerPermissions.querySelectorAll('[data-company-ids]'), function (label) {
                            var visible = label.getAttribute('data-company-ids').indexOf(',' + companyId + ',') !== -1;
                            label.hidden = !visible;
                            if (!visible) {
                                var checkbox = label.querySelector('input[type="checkbox"]');
                                if (checkbox) checkbox.checked = false;
                            }
                        });
                    }
                };
                companySelect.addEventListener('change', filterTeams);
                filterTeams();
            }
        }());
    </script>
</body>
</html>
