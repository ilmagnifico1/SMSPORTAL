<?php
require_once 'inc/option.php';
if (empty($_SESSION['logged'])) { header('Location: ' . app_url('login')); exit; }
require_permission('manage_firewall');
$firewall = new AppFirewall();
$app = new SmsApp();
$message = '';
$openModal = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Token di sicurezza non valido.';
    } elseif (($_POST['action'] ?? '') === 'toggle_firewall') {
        $saved = $firewall->setEnabled(!empty($_POST['enabled']));
        $message = $saved ? 'Stato firewall aggiornato.' : 'Impossibile attivare il firewall: autorizza prima il tuo utente e l\'IP corrente con una regola attiva.';
    } elseif (($_POST['action'] ?? '') === 'save_rule') {
        $saved = $firewall->saveRule($_POST);
        $message = $saved ? 'Regola firewall salvata.' : 'Impossibile salvare la regola. Verifica IP e, se non è un accesso Super Admin, azienda e utenti autorizzati.';
        $openModal = !$saved;
    } elseif (($_POST['action'] ?? '') === 'delete_rule') {
        $saved = $firewall->deleteRule((int)($_POST['id'] ?? 0));
        $message = $saved ? 'Regola eliminata.' : 'Impossibile eliminare la regola.';
    } elseif (($_POST['action'] ?? '') === 'unblock_ip') {
        $saved = $firewall->unblockIp((int)($_POST['id'] ?? 0));
        $message = $saved ? 'Indirizzo IP rimosso dalla lista dei blocchi.' : 'Impossibile rimuovere il blocco IP.';
    } elseif (($_POST['action'] ?? '') === 'review_access_request') {
        $decision = (string)($_POST['decision'] ?? '');
        $saved = $firewall->reviewAccessRequest(
            (int)($_POST['id'] ?? 0),
            $decision,
            (int)($_POST['company_id'] ?? 0),
            (array)($_POST['user_ids'] ?? [])
        );
        $message = $saved
            ? ($decision === 'approve' ? 'Richiesta accettata: IP sbloccato e regola firewall creata.' : 'Richiesta di accesso rifiutata.')
            : 'Impossibile elaborare la richiesta. Per accettarla seleziona azienda e almeno un utente autorizzato.';
        if ($saved) {
            SystemLogger::record('info', 'security', 'firewall.access_request_reviewed', 'Richiesta di accesso elaborata dal Super Admin.', [
                'request_id' => (int)($_POST['id'] ?? 0),
                'decision' => $decision,
                'company_id' => (int)($_POST['company_id'] ?? 0),
                'user_ids' => array_values(array_map('intval', (array)($_POST['user_ids'] ?? []))),
            ], (string)($_SESSION['logged'] ?? ''));
        }
    } elseif (($_POST['action'] ?? '') === 'update_access_request') {
        $saved = $firewall->updateApprovedAccessRequest(
            (int)($_POST['id'] ?? 0),
            (int)($_POST['company_id'] ?? 0),
            (array)($_POST['user_ids'] ?? [])
        );
        $message = $saved ? 'Richiesta accettata aggiornata.' : 'Impossibile aggiornare la richiesta: seleziona azienda e almeno un utente.';
        if ($saved) {
            SystemLogger::record('info', 'security', 'firewall.access_request_updated', 'Autorizzazione di una richiesta accettata modificata.', ['request_id' => (int)($_POST['id'] ?? 0)], (string)($_SESSION['logged'] ?? ''));
        }
    } elseif (($_POST['action'] ?? '') === 'delete_access_request') {
        $saved = $firewall->deleteApprovedAccessRequest((int)($_POST['id'] ?? 0));
        $message = $saved ? 'Richiesta eliminata, autorizzazione revocata e IP nuovamente bloccato.' : 'Impossibile eliminare la richiesta accettata.';
        if ($saved) {
            SystemLogger::record('warning', 'security', 'firewall.access_request_deleted', 'Richiesta accettata eliminata e accesso revocato.', ['request_id' => (int)($_POST['id'] ?? 0)], (string)($_SESSION['logged'] ?? ''));
        }
    }
}
$rules = $firewall->getRules();
$blockedIps = $firewall->getBlockedIps();
$accessRequests = $firewall->getAccessRequests();
$editingRule = !empty($_GET['edit']) ? $firewall->getRule((int)$_GET['edit']) : null;
if ($editingRule) { $openModal = true; }
$companies = $app->getCompanies(true);
$users = $app->getUsers(['active' => '1']);
$pendingAccessRequestCount = count(array_filter($accessRequests, static fn(array $request): bool => (string)$request['status'] === 'pending'));
$selectedUsers = array_map('intval', (array)($_POST['user_ids'] ?? ($editingRule['user_ids'] ?? [])));
$superAdminOnly = !empty($_POST['super_admin_only']) || (!$_POST && !empty($editingRule['super_admin_only']));
$currentIp = SystemLogger::clientIp();
$serverIp = SystemLogger::serverIp();
$proxyIp = SystemLogger::proxyIp();
$portalHost = SystemLogger::portalHost();
$publicPortalIp = SystemLogger::publicPortalIp();
?>
<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Firewall applicativo</title><link rel="icon" href="imgs/favicon.ico?v=2" sizes="any"><link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/loggedstyle.css"></head><body class="page-firewall settings-area"><div id="wrapper" class="dashboard-shell">
<div id="header"><div><p class="eyebrow">Sicurezza</p><h2>Firewall applicativo</h2></div><div class="header-actions"><span class="user-badge"><?php echo htmlspecialchars($_SESSION['logged'], ENT_QUOTES, 'UTF-8'); ?></span><a href="<?php echo app_url('logout'); ?>" class="logout-link">Logout</a></div></div>
<?php require 'inc/top_nav.php'; ?>
<?php require 'inc/settings_sidebar.php'; ?>
<?php if($message !== ''):?><div class="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif;?>
<section class="page-heading"><div><p class="eyebrow">Controllo accessi IP</p><h1>Firewall interno</h1><p class="muted-text">Nega gli accessi non autorizzati associando più IP o reti a più utenti della stessa azienda. Nessuna rete interna viene autorizzata automaticamente.</p><p class="muted-text">IP client rilevato per le regole: <?php echo htmlspecialchars($currentIp, ENT_QUOTES, 'UTF-8'); ?></p></div><div class="heading-ip-group"><span class="heading-pill">IP web server: <?php echo htmlspecialchars($serverIp, ENT_QUOTES, 'UTF-8'); ?></span><span class="heading-pill public-ip-pill">IP pubblico: <?php echo htmlspecialchars($publicPortalIp, ENT_QUOTES, 'UTF-8'); ?></span><span class="heading-pill host-pill">Host portale: <?php echo htmlspecialchars($portalHost, ENT_QUOTES, 'UTF-8'); ?></span><span class="heading-pill proxy-pill">IP proxy: <?php echo htmlspecialchars($proxyIp, ENT_QUOTES, 'UTF-8'); ?></span></div></section>
<section class="card firewall-status-card"><div class="section-heading"><div><p class="eyebrow">Stato</p><h3><?php echo $firewall->isEnabled() ? 'Firewall attivo' : 'Firewall non attivo'; ?></h3><p class="muted-text"><?php echo $firewall->isEnabled() ? 'Politica attiva: nega salvo regola autorizzata.' : 'Configura le regole e poi attiva il controllo.'; ?></p></div><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_firewall"><label class="firewall-toggle"><input type="checkbox" name="enabled" value="1" <?php echo $firewall->isEnabled() ? 'checked' : ''; ?> onchange="this.form.submit()"> Firewall abilitato</label></form></div></section>
<section class="card"><div class="section-heading"><div><p class="eyebrow">Whitelist</p><h3>Regole autorizzate</h3></div><button type="button" class="modal-trigger-btn" id="openFirewallModal">Nuova regola</button></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Nome</th><th>Azienda</th><th>IP / CIDR</th><th>Utenti</th><th>Stato</th><th>Azioni</th></tr></thead><tbody><?php if(!$rules):?><tr><td colspan="6">Nessuna regola configurata.</td></tr><?php else:?><?php foreach($rules as $rule):?><tr><td><?php echo htmlspecialchars((string)$rule['name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$rule['company_name'], ENT_QUOTES, 'UTF-8'); ?></td><td class="wrap-text"><?php echo nl2br(htmlspecialchars((string)$rule['ip_ranges'], ENT_QUOTES, 'UTF-8')); ?></td><td><?php echo (int)$rule['super_admin_only'] === 1 ? '<span class="status-pill sent">Solo Super Admin</span>' : htmlspecialchars((string)($rule['user_names'] ?: 'Nessuno'), ENT_QUOTES, 'UTF-8'); ?></td><td><span class="status-pill <?php echo (int)$rule['active'] === 1 ? 'sent' : 'failed'; ?>"><?php echo (int)$rule['active'] === 1 ? 'Attiva' : 'Disattiva'; ?></span></td><td class="actions-cell"><a class="action-btn" href="<?php echo app_url('firewall', ['edit' => (int)$rule['id']]); ?>">Modifica</a><form method="post" onsubmit="return confirm('Eliminare la regola?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?php echo (int)$rule['id']; ?>"><button type="submit" class="danger-btn">Elimina</button></form></td></tr><?php endforeach;?><?php endif;?></tbody></table></div></section>
<section class="card firewall-access-requests-card">
<div class="section-heading"><div><p class="eyebrow">Autorizzazioni</p><h3>Richieste di accesso</h3><p class="muted-text">Accetta una richiesta scegliendo l’azienda e gli utenti ai quali autorizzare l’IP.</p></div><span class="heading-pill"><?php echo $pendingAccessRequestCount; ?> in attesa</span></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Richiedente</th><th>IP</th><th>Data</th><th>Stato</th><th>Gestione richiesta</th></tr></thead><tbody>
<?php if (!$accessRequests) : ?><tr><td colspan="5">Nessuna richiesta di accesso.</td></tr><?php else : foreach ($accessRequests as $accessRequest) : ?>
<tr>
<td><strong><?php echo htmlspecialchars(trim((string)$accessRequest['first_name'] . ' ' . (string)$accessRequest['last_name']), ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars((string)$accessRequest['email'], ENT_QUOTES, 'UTF-8'); ?></small></td>
<td><strong><?php echo htmlspecialchars((string)$accessRequest['ip_address'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($accessRequest['country_name'])) : ?><small><?php echo htmlspecialchars((string)$accessRequest['country_name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
<td><?php echo htmlspecialchars((string)$accessRequest['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php $requestStatus=(string)$accessRequest['status']; ?><span class="status-pill <?php echo $requestStatus === 'approved' ? 'sent' : ($requestStatus === 'rejected' ? 'failed' : 'pending'); ?>"><?php echo $requestStatus === 'approved' ? 'Accettata' : ($requestStatus === 'rejected' ? 'Rifiutata' : 'In attesa'); ?></span><?php if ($requestStatus !== 'pending' && !empty($accessRequest['reviewer_name'])) : ?><small>da <?php echo htmlspecialchars((string)$accessRequest['reviewer_name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
<td><?php if ($requestStatus === 'pending') : ?>
<form method="post" class="access-review-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="review_access_request"><input type="hidden" name="id" value="<?php echo (int)$accessRequest['id']; ?>">
<label>Azienda<select name="company_id" class="access-request-company"><option value="">Seleziona azienda</option><?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>"><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
<div class="permissions-grid access-request-users"><?php foreach ($users as $targetUser) : ?><label data-company-id="<?php echo (int)$targetUser['company_id']; ?>" hidden><input type="checkbox" name="user_ids[]" value="<?php echo (int)$targetUser['id']; ?>"> <?php echo htmlspecialchars((string)$targetUser['username'], ENT_QUOTES, 'UTF-8'); ?></label><?php endforeach; ?></div>
<div class="actions-cell"><button type="submit" name="decision" value="approve" class="action-btn">Accetta</button><button type="submit" name="decision" value="reject" class="danger-btn" formnovalidate>Rifiuta</button></div>
</form>
<?php elseif ($requestStatus === 'approved') : ?>
<form method="post" class="access-review-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="update_access_request"><input type="hidden" name="id" value="<?php echo (int)$accessRequest['id']; ?>">
<label>Azienda<select name="company_id" class="access-request-company"><?php foreach ($companies as $company) : ?><option value="<?php echo (int)$company['id']; ?>" <?php echo (int)$accessRequest['company_id'] === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
<div class="permissions-grid access-request-users"><?php foreach ($users as $targetUser) : ?><label data-company-id="<?php echo (int)$targetUser['company_id']; ?>" hidden><input type="checkbox" name="user_ids[]" value="<?php echo (int)$targetUser['id']; ?>" <?php echo in_array((int)$targetUser['id'], (array)$accessRequest['authorized_user_ids'], true) ? 'checked' : ''; ?>> <?php echo htmlspecialchars((string)$targetUser['username'], ENT_QUOTES, 'UTF-8'); ?></label><?php endforeach; ?></div>
<button type="submit" class="action-btn">Aggiorna</button>
</form>
<form method="post" class="access-request-delete-form" onsubmit="return confirm('Eliminare la richiesta, revocare l’autorizzazione e bloccare nuovamente questo IP?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_access_request"><input type="hidden" name="id" value="<?php echo (int)$accessRequest['id']; ?>"><button type="submit" class="danger-btn">Elimina</button></form>
<?php else : ?><span class="muted-text">IP ancora bloccato</span><?php endif; ?></td>
</tr>
<?php endforeach; endif; ?></tbody></table></div>
</section>
<script>(function(){document.querySelectorAll('.access-review-form').forEach(function(form){var company=form.querySelector('.access-request-company'),users=form.querySelector('.access-request-users');function filterUsers(){users.querySelectorAll('[data-company-id]').forEach(function(label){var visible=company.value!==''&&label.getAttribute('data-company-id')===company.value;label.hidden=!visible;if(!visible)label.querySelector('input').checked=false;});}company.addEventListener('change',filterUsers);filterUsers();});}());</script>
<section class="card firewall-blocked-card"><div class="section-heading"><div><p class="eyebrow">Protezione attiva</p><h3>Indirizzi IP bloccati</h3><p class="muted-text">Tentativi provenienti da IP che non rispettano le regole di autorizzazione.</p></div><span class="heading-pill"><?php echo count($blockedIps); ?> IP bloccati</span></div><div class="table-wrap"><table class="data-table firewall-blocked-table"><thead><tr><th>IP / Paese</th><th>Utente</th><th>Azienda</th><th>Pagina richiesta</th><th>Tentativi</th><th>Primo tentativo</th><th>Ultimo tentativo</th><th>Azioni</th></tr></thead><tbody><?php if(!$blockedIps):?><tr><td colspan="8">Nessun tentativo bloccato.</td></tr><?php else:foreach($blockedIps as $blocked): $countryCode=strtolower(trim((string)$blocked['country_code']));?><tr><td><strong class="log-country-line"><?php if(preg_match('/^[a-z]{2}$/',$countryCode)):?><img class="country-flag-image" src="https://flagcdn.com/24x18/<?php echo htmlspecialchars($countryCode,ENT_QUOTES,'UTF-8'); ?>.png" srcset="https://flagcdn.com/48x36/<?php echo htmlspecialchars($countryCode,ENT_QUOTES,'UTF-8'); ?>.png 2x" width="24" height="18" loading="lazy" alt=""><?php else:?><span class="country-flag-fallback"><?php echo htmlspecialchars((string)($blocked['flag']?:'🌐'),ENT_QUOTES,'UTF-8'); ?></span><?php endif;?><span><?php echo htmlspecialchars((string)$blocked['ip_address'],ENT_QUOTES,'UTF-8'); ?></span></strong><small><?php echo htmlspecialchars((string)($blocked['country_name']?:'Sconosciuto'),ENT_QUOTES,'UTF-8'); ?></small></td><td><?php echo htmlspecialchars((string)($blocked['user_name']?:'Sconosciuto'),ENT_QUOTES,'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)($blocked['company_name']?:'-'),ENT_QUOTES,'UTF-8'); ?></td><td class="wrap-text"><?php echo htmlspecialchars((string)($blocked['request_uri']?:'-'),ENT_QUOTES,'UTF-8'); ?></td><td><strong><?php echo (int)$blocked['attempt_count']; ?></strong></td><td><?php echo htmlspecialchars((string)$blocked['first_attempt_at'],ENT_QUOTES,'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$blocked['last_attempt_at'],ENT_QUOTES,'UTF-8'); ?></td><td><form method="post" onsubmit="return confirm('Rimuovere il blocco per questo IP?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="unblock_ip"><input type="hidden" name="id" value="<?php echo (int)$blocked['id']; ?>"><button type="submit" class="action-btn">Rimuovi blocco</button></form></td></tr><?php endforeach;endif;?></tbody></table></div></section></div>
<div class="modal-overlay <?php echo $openModal ? 'is-active' : ''; ?>" id="firewallModal"><div class="modal-card"><div class="modal-header"><div><p class="eyebrow">Whitelist</p><h3><?php echo $editingRule ? 'Modifica regola' : 'Nuova regola firewall'; ?></h3></div><button type="button" class="modal-close-btn" id="closeFirewallModal">x</button></div><form method="post" class="stacked-form"><input type="hidden" name="action" value="save_rule"><input type="hidden" name="id" value="<?php echo (int)($editingRule['id'] ?? 0); ?>"><?php echo csrf_field(); ?><label>Nome regola</label><input name="name" required value="<?php echo htmlspecialchars((string)($_POST['name'] ?? ($editingRule['name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"><label class="firewall-toggle"><input type="checkbox" name="super_admin_only" id="superAdminOnly" value="1" <?php echo $superAdminOnly ? 'checked' : ''; ?>> Accesso Super Admin</label><p class="hint-text">Autorizza questi IP esclusivamente per gli utenti con ruolo Super Admin.</p><div id="firewallCompanyGroup"><label>Azienda</label><?php $selectedCompany=(int)($_POST['company_id'] ?? ($editingRule['company_id'] ?? 0)); ?><select name="company_id" id="firewallCompany"><option value="">Seleziona azienda</option><?php foreach($companies as $company):?><option value="<?php echo (int)$company['id']; ?>" <?php echo $selectedCompany === (int)$company['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach;?></select></div><label>IP e reti autorizzate</label><textarea name="ip_ranges" required placeholder="192.0.2.10&#10;198.51.100.0/24"><?php echo htmlspecialchars((string)($_POST['ip_ranges'] ?? ($editingRule['ip_ranges'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></textarea><p class="hint-text">Inserisci più IP o CIDR separati da riga, virgola o spazio.</p><div id="firewallUsersGroup"><label>Utenti autorizzati</label><div class="permissions-grid" id="firewallUsers"><?php foreach($users as $targetUser):?><label data-company-id="<?php echo (int)$targetUser['company_id']; ?>"><input type="checkbox" name="user_ids[]" value="<?php echo (int)$targetUser['id']; ?>" <?php echo in_array((int)$targetUser['id'], $selectedUsers, true) ? 'checked' : ''; ?>> <?php echo htmlspecialchars((string)$targetUser['username'] . ' · ' . (string)$targetUser['company_name'], ENT_QUOTES, 'UTF-8'); ?></label><?php endforeach;?></div></div><label><input type="checkbox" name="active" value="1" <?php echo !$editingRule || (int)$editingRule['active'] === 1 ? 'checked' : ''; ?>> Regola attiva</label><div class="form-actions"><input type="submit" value="Salva regola"></div></form></div></div>
<script>(function(){var modal=document.getElementById('firewallModal'),open=document.getElementById('openFirewallModal'),close=document.getElementById('closeFirewallModal'),company=document.getElementById('firewallCompany'),users=document.getElementById('firewallUsers'),superOnly=document.getElementById('superAdminOnly'),companyGroup=document.getElementById('firewallCompanyGroup'),usersGroup=document.getElementById('firewallUsersGroup');if(open)open.addEventListener('click',function(){modal.classList.add('is-active');});if(close)close.addEventListener('click',function(){window.location.href=<?php echo json_encode(app_url('firewall')); ?>;});if(modal)modal.addEventListener('click',function(event){if(event.target===modal)window.location.href=<?php echo json_encode(app_url('firewall')); ?>;});function filterUsers(){if(!company||!users||superOnly.checked)return;users.querySelectorAll('[data-company-id]').forEach(function(label){var visible=label.getAttribute('data-company-id')===company.value;label.hidden=!visible;if(!visible)label.querySelector('input').checked=false;});}function toggleSuperAdminAccess(){var active=!!(superOnly&&superOnly.checked);if(company){company.disabled=active;company.required=!active;}if(companyGroup)companyGroup.classList.toggle('is-disabled',active);if(usersGroup)usersGroup.classList.toggle('is-disabled',active);if(users){users.querySelectorAll('input').forEach(function(input){input.disabled=active;if(active)input.checked=false;});}if(!active)filterUsers();}if(company)company.addEventListener('change',filterUsers);if(superOnly)superOnly.addEventListener('change',toggleSuperAdminAccess);toggleSuperAdminAccess();filterUsers();}());</script></body></html>
