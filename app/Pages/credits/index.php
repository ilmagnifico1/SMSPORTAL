<?php
require_once 'inc/option.php';
require_once 'inc/country_codes.php';
if (empty($_SESSION['logged'])) { header('Location: ' . app_url('login')); exit; }
require_permission('view_credits');

$app = new SmsApp();
$credits = new CreditManager();
$message = '';
$openCreditModal = false;
$openPurchaseModal = false;
$openSaleModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Token di sicurezza non valido.';
    } else {
        $action = (string)$_POST['action'];
        if ($action === 'adjust_credit') {
            $saved = $credits->adjustBalance((int)($_POST['company_id'] ?? 0), (float)str_replace(',', '.', (string)($_POST['amount'] ?? 0)), (string)($_POST['description'] ?? ''), (string)$_SESSION['logged']);
            $message = $saved ? 'Movimento credito registrato.' : 'Impossibile registrare il movimento. Verifica importo e saldo.';
            $openCreditModal = !$saved;
        } elseif ($action === 'toggle_billing') {
            $saved = $credits->setBillingEnabled((int)($_POST['company_id'] ?? 0), !empty($_POST['billing_enabled']));
            $message = $saved ? 'Fatturazione aggiornata.' : 'Impossibile aggiornare la fatturazione.';
        } elseif ($action === 'save_purchase_cost') {
            $saved = $credits->savePurchaseCost($_POST);
            $message = $saved ? 'Costo di acquisto salvato.' : 'Impossibile salvare il costo di acquisto.';
            $openPurchaseModal = !$saved;
        } elseif ($action === 'delete_purchase_cost') {
            $saved = $credits->deletePurchaseCost((int)($_POST['id'] ?? 0));
            $message = $saved ? 'Costo di acquisto eliminato.' : 'Impossibile eliminare il costo.';
        } elseif ($action === 'save_sale_price') {
            $saved = $credits->saveSalePrice($_POST);
            $message = $saved ? 'Prezzo di vendita salvato.' : 'Impossibile salvare il prezzo: verifica che il provider sia assegnato all’azienda.';
            $openSaleModal = !$saved;
        } elseif ($action === 'delete_sale_price') {
            $saved = $credits->deleteSalePrice((int)($_POST['id'] ?? 0));
            $message = $saved ? 'Prezzo di vendita eliminato.' : 'Impossibile eliminare il prezzo.';
        }
    }
}

$balances = $credits->getBalances();
$transactions = $credits->getTransactions((int)($_GET['company_id'] ?? 0));
$purchaseCosts = $credits->getPurchaseCosts();
$salePrices = $credits->getSalePrices();
$editingPurchase = !empty($_GET['edit_purchase']) && is_super_admin() ? $credits->getPurchaseCost((int)$_GET['edit_purchase']) : null;
$editingSale = !empty($_GET['edit_sale']) && is_super_admin() ? $credits->getSalePrice((int)$_GET['edit_sale']) : null;
$editingPurchaseRule = $credits->describePricingRule((string)($editingPurchase['prefix'] ?? ''));
$editingSaleRule = $credits->describePricingRule((string)($editingSale['prefix'] ?? ''));
if ($editingPurchase) { $openPurchaseModal = true; }
if ($editingSale) { $openSaleModal = true; }
$companies = is_super_admin() ? $app->getCompanies(true) : [];
$providers = is_super_admin() ? array_values(array_filter($app->getProviders(['active' => 'all']), static fn(array $provider): bool => (string)($provider['provider_type'] ?? '') !== 'internal')) : [];
$companyProviderMap = [];
foreach ($companies as $company) { $companyProviderMap[(int)$company['id']] = $app->getCompanyProviderIds((int)$company['id']); }
$countryCodes = sms_country_codes();
$flashMessage = flash_message();
function euro4(float $value): string { return '€ ' . number_format($value, 4, ',', '.'); }

function price_input_value(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $formatted = number_format((float)$value, 4, ',', '');

    return str_ends_with($formatted, '0') ? substr($formatted, 0, -1) : $formatted;
}
function country_flag_html(?array $country): string {
    $isos = (array)($country['isos'] ?? []);
    if (!$isos) { return '<span class="country-picker-globe">🌐</span>'; }
    $html = '<span class="country-picker-flags">';
    foreach ($isos as $iso) {
        $safeIso = strtolower(preg_replace('/[^A-Z]/', '', strtoupper((string)$iso)));
        if (strlen($safeIso) !== 2) { continue; }
        $html .= '<img class="country-picker-flag" src="https://flagcdn.com/24x18/' . $safeIso . '.png" srcset="https://flagcdn.com/48x36/' . $safeIso . '.png 2x" width="24" height="18" loading="lazy" alt="">';
    }
    return $html . '</span>';
}
function pricing_rule_fields(string $scope, array $rule, array $countryCodes, string $destination, string $operatorName): void {
    $type = (string)($rule['type'] ?? 'country');
    $countryPrefix = (string)($rule['country_prefix'] ?? '');
    if ($countryPrefix === '') $countryPrefix = '39';
    ?>
    <div class="pricing-rule-builder" data-pricing-scope="<?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>">
        <label>Paese e prefisso internazionale</label>
        <select name="country_prefix" class="country-visual-select pricing-country" required>
            <option value="*" data-label="Tutti i Paesi (*)" data-country="Tariffa predefinita" <?php echo $countryPrefix === '*' ? 'selected' : ''; ?>>Tutti i Paesi (*)</option>
            <?php foreach ($countryCodes as $country) : $code = preg_replace('/\D+/', '', (string)$country['code']); ?>
                <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" data-label="<?php echo htmlspecialchars((string)$country['label'], ENT_QUOTES, 'UTF-8'); ?>" data-iso="<?php echo htmlspecialchars(implode(',', (array)($country['isos'] ?? [])), ENT_QUOTES, 'UTF-8'); ?>" data-country="<?php echo htmlspecialchars((string)preg_replace('/\s*\(\+.*/', '', (string)$country['label']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $countryPrefix === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$country['label'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <label>Tipo di tariffa</label>
        <select name="match_type" class="pricing-match-type" required>
            <option value="global" <?php echo $type === 'global' ? 'selected' : ''; ?>>Predefinita per tutti i Paesi</option>
            <option value="country" <?php echo $type === 'country' ? 'selected' : ''; ?>>Intero Paese</option>
            <option value="subprefix" <?php echo $type === 'subprefix' ? 'selected' : ''; ?>>Operatore tramite sotto-prefisso</option>
            <option value="range" <?php echo $type === 'range' ? 'selected' : ''; ?>>Operatore tramite intervallo nazionale</option>
        </select>
        <div class="pricing-conditional" data-pricing-types="subprefix">
            <label>Sotto-prefisso dopo il codice Paese</label>
            <input name="national_prefix" inputmode="numeric" maxlength="24" data-pricing-required="1" placeholder="es. 347" value="<?php echo htmlspecialchars((string)($rule['national_prefix'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <p class="hint-text">Esempio: Paese +39 e sotto-prefisso 347 riconoscono tutti i numeri +39 347…</p>
        </div>
        <div class="pricing-conditional two-columns-form" data-pricing-types="range">
            <div><label>Inizio intervallo nazionale</label><input name="range_start" inputmode="numeric" maxlength="24" data-pricing-required="1" placeholder="es. 320" value="<?php echo htmlspecialchars((string)($rule['range_start'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
            <div><label>Fine intervallo nazionale</label><input name="range_end" inputmode="numeric" maxlength="24" data-pricing-required="1" placeholder="es. 329" value="<?php echo htmlspecialchars((string)($rule['range_end'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
            <p class="hint-text">Gli estremi devono avere la stessa lunghezza. +39 con 320–329 copre i numeri che iniziano con quei valori.</p>
        </div>
        <div class="pricing-conditional" data-pricing-types="subprefix,range">
            <label>Operatore telefonico</label>
            <input name="operator_name" maxlength="150" data-pricing-required="1" placeholder="es. Operatore mobile Italia" value="<?php echo htmlspecialchars($operatorName, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <label>Destinazione</label>
        <input name="destination" class="pricing-destination" readonly value="<?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <?php
}
?>
<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Gestione Crediti</title>
<link rel="icon" href="imgs/favicon.ico?v=2" sizes="any"><link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/loggedstyle.css"></head>
<body class="page-credits settings-area"><div id="wrapper" class="dashboard-shell">
<div id="header"><div><p class="eyebrow">Amministrazione</p><h2>Gestione Crediti</h2></div><div class="header-actions"><span class="user-badge"><?php echo htmlspecialchars((string)$_SESSION['logged'], ENT_QUOTES, 'UTF-8'); ?></span><a href="<?php echo app_url('logout'); ?>" class="logout-link">Logout</a></div></div>
<?php require 'inc/top_nav.php'; ?>
<?php require 'inc/settings_sidebar.php'; ?>
<?php if($flashMessage !== ''):?><div class="alert alert-danger"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php endif;?><?php if($message !== ''):?><div class="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif;?>

<section class="page-heading"><div><p class="eyebrow">Billing per prefisso</p><h1>Gestione Crediti</h1><p class="muted-text">Costo provider, prezzo di vendita all’azienda e margine per ogni segmento SMS.</p></div><?php if(is_super_admin()):?><div class="header-actions"><button type="button" class="modal-trigger-btn" id="openCreditModal">Ricarica / storno</button><button type="button" class="modal-trigger-btn" id="openPurchaseModal">Costo acquisto</button><button type="button" class="modal-trigger-btn" id="openSaleModal">Prezzo vendita</button></div><?php endif;?></section>

<div class="stats-grid stats-footer"><?php foreach($balances as $balance):?><article class="stat-card"><strong><?php echo euro4((float)$balance['balance']); ?></strong><span><?php echo htmlspecialchars((string)$balance['company_name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int)$balance['billing_enabled'] === 1 ? 'Fatturazione attiva' : 'Fatturazione disattivata'; ?></span><?php if(is_super_admin()):?><form method="post" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_billing"><input type="hidden" name="company_id" value="<?php echo (int)$balance['company_id']; ?>"><label><input type="checkbox" name="billing_enabled" value="1" <?php echo (int)$balance['billing_enabled'] === 1 ? 'checked' : ''; ?> onchange="this.form.submit()"> Scala credito sugli invii</label></form><?php endif;?></article><?php endforeach;?></div>

<?php if(is_super_admin()):?><section class="card"><div class="section-heading"><div><p class="eyebrow">Acquisti</p><h3>Costi per Paese, operatore e intervallo</h3></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Provider</th><th>Regola</th><th>Destinazione / Operatore</th><th>Costo / segmento</th><th>Stato</th><th>Azioni</th></tr></thead><tbody><?php if(!$purchaseCosts):?><tr><td colspan="6">Nessun costo di acquisto configurato.</td></tr><?php else:foreach($purchaseCosts as $price): $rule=$credits->describePricingRule((string)$price['prefix']); $country=sms_country_for_prefix((string)$rule['country_prefix'], (string)$price['destination']);?><tr><td><?php echo htmlspecialchars((string)$price['provider_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><span class="log-country-line"><?php echo country_flag_html($country); ?><span><?php echo htmlspecialchars((string)$rule['label'], ENT_QUOTES, 'UTF-8'); ?></span></span><small><?php echo htmlspecialchars((string)$rule['type'], ENT_QUOTES, 'UTF-8'); ?></small></td><td><strong><?php echo htmlspecialchars((string)($price['operator_name'] ?: $price['destination']), ENT_QUOTES, 'UTF-8'); ?></strong><?php if((string)$price['operator_name']!==''):?><small><?php echo htmlspecialchars((string)$price['destination'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif;?></td><td><?php echo euro4((float)$price['purchase_price']); ?></td><td><span class="status-pill <?php echo (int)$price['active'] === 1 ? 'sent' : 'failed'; ?>"><?php echo (int)$price['active'] === 1 ? 'Attivo' : 'Disattivo'; ?></span></td><td class="actions-cell"><a class="action-btn" href="<?php echo app_url('credits', ['edit_purchase' => (int)$price['id']]); ?>">Modifica</a><form method="post" onsubmit="return confirm('Eliminare questo costo?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_purchase_cost"><input type="hidden" name="id" value="<?php echo (int)$price['id']; ?>"><button class="danger-btn" type="submit">Elimina</button></form></td></tr><?php endforeach;endif;?></tbody></table></div></section><?php endif;?>

<section class="card"><div class="section-heading"><div><p class="eyebrow">Vendite</p><h3>Prezzi alle aziende per Paese e operatore</h3></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Azienda</th><th>Provider</th><th>Regola / Operatore</th><?php if(is_super_admin()):?><th>Acquisto</th><?php endif;?><th>Vendita / segmento</th><?php if(is_super_admin()):?><th>Margine / segmento</th><?php endif;?><th>Stato</th><?php if(is_super_admin()):?><th>Azioni</th><?php endif;?></tr></thead><tbody><?php if(!$salePrices):?><tr><td colspan="8">Nessun prezzo di vendita configurato.</td></tr><?php else:foreach($salePrices as $price): $hasCost=$price['purchase_price']!==null; $margin=$hasCost?(float)$price['sale_price']-(float)$price['purchase_price']:null; $rule=$credits->describePricingRule((string)$price['prefix']); $country=sms_country_for_prefix((string)$rule['country_prefix'], (string)($price['destination']??''));?><tr><td><?php echo htmlspecialchars((string)$price['company_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$price['provider_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><span class="log-country-line"><?php echo country_flag_html($country); ?><span><?php echo htmlspecialchars((string)$rule['label'], ENT_QUOTES, 'UTF-8'); ?></span></span><?php if((string)($price['operator_name']??'')!==''):?><small><?php echo htmlspecialchars((string)$price['operator_name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif;?></td><?php if(is_super_admin()):?><td><?php echo $hasCost ? euro4((float)$price['purchase_price']) : 'Non configurato'; ?></td><?php endif;?><td><?php echo euro4((float)$price['sale_price']); ?></td><?php if(is_super_admin()):?><td class="<?php echo $margin !== null && $margin >= 0 ? 'credit-positive' : 'credit-negative'; ?>"><?php echo $margin === null ? '—' : euro4($margin); ?></td><?php endif;?><td><span class="status-pill <?php echo (int)$price['active'] === 1 ? 'sent' : 'failed'; ?>"><?php echo (int)$price['active'] === 1 ? 'Attivo' : 'Disattivo'; ?></span></td><?php if(is_super_admin()):?><td class="actions-cell"><a class="action-btn" href="<?php echo app_url('credits', ['edit_sale' => (int)$price['id']]); ?>">Modifica</a><form method="post" onsubmit="return confirm('Eliminare questo prezzo?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_sale_price"><input type="hidden" name="id" value="<?php echo (int)$price['id']; ?>"><button class="danger-btn" type="submit">Elimina</button></form></td><?php endif;?></tr><?php endforeach;endif;?></tbody></table></div></section>

<section class="card"><div class="section-heading"><div><p class="eyebrow">Movimenti</p><h3>Storico crediti</h3></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Data</th><th>Azienda</th><th>Tipo</th><th>Importo</th><th>Descrizione</th></tr></thead><tbody><?php if(!$transactions):?><tr><td colspan="5">Nessun movimento.</td></tr><?php else:foreach($transactions as $transaction):?><tr><td><?php echo htmlspecialchars((string)$transaction['created_at'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$transaction['company_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string)$transaction['transaction_type'], ENT_QUOTES, 'UTF-8'); ?></td><td class="<?php echo (float)$transaction['amount'] >= 0 ? 'credit-positive' : 'credit-negative'; ?>"><?php echo euro4((float)$transaction['amount']); ?></td><td><?php echo htmlspecialchars((string)$transaction['description'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach;endif;?></tbody></table></div></section>
</div>

<?php if(is_super_admin()):?>
<div class="modal-overlay <?php echo $openCreditModal ? 'is-active' : ''; ?>" id="creditModal"><div class="modal-card"><div class="modal-header"><div><p class="eyebrow">Movimento</p><h3>Ricarica o storno</h3></div><button type="button" class="modal-close-btn" data-close-modal>x</button></div><form method="post" class="stacked-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="adjust_credit"><label>Azienda</label><select name="company_id" required><?php foreach($companies as $company):?><option value="<?php echo (int)$company['id']; ?>"><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach;?></select><label>Importo (positivo ricarica, negativo storno)</label><input type="number" step="0.0001" name="amount" required><label>Descrizione</label><input name="description" maxlength="500"><div class="form-actions"><input type="submit" value="Registra movimento"></div></form></div></div>

<div class="modal-overlay <?php echo $openPurchaseModal ? 'is-active' : ''; ?>" id="purchaseModal"><div class="modal-card"><div class="modal-header"><div><p class="eyebrow">Costo provider</p><h3><?php echo $editingPurchase ? 'Modifica costo' : 'Nuovo costo di acquisto'; ?></h3></div><button type="button" class="modal-close-btn" data-close-modal>x</button></div><form method="post" class="stacked-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_purchase_cost"><input type="hidden" name="id" value="<?php echo (int)($editingPurchase['id'] ?? 0); ?>"><label>Provider</label><select name="provider_id" required><?php foreach($providers as $provider):?><option value="<?php echo (int)$provider['id']; ?>" <?php echo (int)($editingPurchase['provider_id'] ?? 0)===(int)$provider['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$provider['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach;?></select><?php pricing_rule_fields('purchase', $editingPurchaseRule, $countryCodes, (string)($editingPurchase['destination'] ?? ''), (string)($editingPurchase['operator_name'] ?? '')); ?><label>Costo di acquisto per segmento</label><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,4})?" name="purchase_price" required value="<?php echo htmlspecialchars(price_input_value($editingPurchase['purchase_price'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"><label><input type="checkbox" name="active" value="1" <?php echo !$editingPurchase || (int)$editingPurchase['active'] === 1 ? 'checked' : ''; ?>> Attivo</label><div class="form-actions"><input type="submit" value="Salva costo"></div></form></div></div>

<div class="modal-overlay <?php echo $openSaleModal ? 'is-active' : ''; ?>" id="saleModal"><div class="modal-card"><div class="modal-header"><div><p class="eyebrow">Prezzo azienda</p><h3><?php echo $editingSale ? 'Modifica prezzo' : 'Nuovo prezzo di vendita'; ?></h3></div><button type="button" class="modal-close-btn" data-close-modal>x</button></div><form method="post" class="stacked-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_sale_price"><input type="hidden" name="id" value="<?php echo (int)($editingSale['id'] ?? 0); ?>"><label>Azienda</label><select name="company_id" id="saleCompany" required><?php foreach($companies as $company):?><option value="<?php echo (int)$company['id']; ?>" <?php echo (int)($editingSale['company_id'] ?? 0)===(int)$company['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach;?></select><label>Provider assegnato all’azienda</label><select name="provider_id" id="saleProvider" required><?php foreach($providers as $provider):?><option value="<?php echo (int)$provider['id']; ?>" <?php echo (int)($editingSale['provider_id'] ?? 0)===(int)$provider['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$provider['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach;?></select><?php pricing_rule_fields('sale', $editingSaleRule, $countryCodes, (string)($editingSale['destination'] ?? ''), (string)($editingSale['operator_name'] ?? '')); ?><label>Prezzo di vendita per segmento</label><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,4})?" name="sale_price" required value="<?php echo htmlspecialchars(price_input_value($editingSale['sale_price'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"><label><input type="checkbox" name="active" value="1" <?php echo !$editingSale || (int)$editingSale['active'] === 1 ? 'checked' : ''; ?>> Attivo</label><div class="form-actions"><input type="submit" value="Salva prezzo"></div></form></div></div>
<?php endif;?>
<script>(function(){function bind(id,buttonId){var modal=document.getElementById(id),button=document.getElementById(buttonId);if(button&&modal)button.addEventListener('click',function(){modal.classList.add('is-active');});if(modal){modal.querySelectorAll('[data-close-modal]').forEach(function(close){close.addEventListener('click',function(){window.location.href=<?php echo json_encode(app_url('credits')); ?>;});});modal.addEventListener('click',function(event){if(event.target===modal)window.location.href=<?php echo json_encode(app_url('credits')); ?>;});}}bind('creditModal','openCreditModal');bind('purchaseModal','openPurchaseModal');bind('saleModal','openSaleModal');var map=<?php echo json_encode($companyProviderMap, JSON_UNESCAPED_UNICODE); ?>,company=document.getElementById('saleCompany'),provider=document.getElementById('saleProvider');function filterProviders(){if(!company||!provider)return;var allowed=(map[company.value]||[]).map(Number),selected=Number(provider.value),first='';Array.prototype.forEach.call(provider.options,function(option){var show=allowed.indexOf(Number(option.value))!==-1;option.hidden=!show;option.disabled=!show;if(show&&!first)first=option.value;});if(allowed.indexOf(selected)===-1)provider.value=first;}if(company){company.addEventListener('change',filterProviders);filterProviders();}var purchasePrefix=document.getElementById('purchasePrefix'),purchaseDestination=document.getElementById('purchaseDestination');function syncDestination(){if(!purchasePrefix||!purchaseDestination)return;var option=purchasePrefix.options[purchasePrefix.selectedIndex];purchaseDestination.value=option?option.getAttribute('data-country')||'':'';}if(purchasePrefix){purchasePrefix.addEventListener('change',syncDestination);if(!purchaseDestination.value)syncDestination();}
function addFlags(container,isoValue){var isos=(isoValue||'').split(',').filter(Boolean);if(!isos.length){var globe=document.createElement('span');globe.className='country-picker-globe';globe.textContent='🌐';container.appendChild(globe);return;}isos.forEach(function(iso){var image=document.createElement('img');image.className='country-picker-flag';image.src='https://flagcdn.com/24x18/'+iso.toLowerCase()+'.png';image.srcset='https://flagcdn.com/48x36/'+iso.toLowerCase()+'.png 2x';image.width=24;image.height=18;image.alt='';image.loading='lazy';container.appendChild(image);});}
function upgradeCountrySelect(select){var wrapper=document.createElement('div'),trigger=document.createElement('button'),panel=document.createElement('div'),search=document.createElement('input'),list=document.createElement('div');wrapper.className='country-picker';trigger.type='button';trigger.className='country-picker-trigger';trigger.setAttribute('aria-haspopup','listbox');panel.className='country-picker-panel';search.type='search';search.className='country-picker-search';search.placeholder='Cerca Paese o prefisso...';search.setAttribute('aria-label','Cerca Paese o prefisso');list.className='country-picker-list';list.setAttribute('role','listbox');panel.appendChild(search);panel.appendChild(list);select.parentNode.insertBefore(wrapper,select);wrapper.appendChild(trigger);wrapper.appendChild(panel);wrapper.appendChild(select);select.classList.add('country-picker-native');
function updateTrigger(option){trigger.innerHTML='';var flags=document.createElement('span');flags.className='country-picker-flags';addFlags(flags,option.getAttribute('data-iso'));var label=document.createElement('span');label.textContent=option.getAttribute('data-label')||option.textContent;var arrow=document.createElement('span');arrow.className='country-picker-arrow';arrow.textContent='⌄';trigger.appendChild(flags);trigger.appendChild(label);trigger.appendChild(arrow);}
Array.prototype.forEach.call(select.options,function(option,index){var item=document.createElement('button');item.type='button';item.className='country-picker-option';item.setAttribute('role','option');item.dataset.search=((option.getAttribute('data-label')||option.textContent)+' +'+option.value).toLowerCase();var flags=document.createElement('span');flags.className='country-picker-flags';addFlags(flags,option.getAttribute('data-iso'));var label=document.createElement('span');label.textContent=option.getAttribute('data-label')||option.textContent;item.appendChild(flags);item.appendChild(label);item.addEventListener('click',function(){select.selectedIndex=index;select.dispatchEvent(new Event('change',{bubbles:true}));updateTrigger(option);wrapper.classList.remove('is-open');});list.appendChild(item);});updateTrigger(select.options[select.selectedIndex]||select.options[0]);trigger.addEventListener('click',function(){var opening=!wrapper.classList.contains('is-open');document.querySelectorAll('.country-picker.is-open').forEach(function(picker){picker.classList.remove('is-open');});wrapper.classList.toggle('is-open',opening);if(opening){search.value='';Array.prototype.forEach.call(list.children,function(item){item.hidden=false;});setTimeout(function(){search.focus();},0);}});search.addEventListener('input',function(){var term=search.value.trim().toLowerCase();Array.prototype.forEach.call(list.children,function(item){item.hidden=term!==''&&item.dataset.search.indexOf(term)===-1;});});document.addEventListener('click',function(event){if(!wrapper.contains(event.target))wrapper.classList.remove('is-open');});}
document.querySelectorAll('.country-visual-select').forEach(upgradeCountrySelect);
document.querySelectorAll('.pricing-rule-builder').forEach(function(builder){
    var country=builder.querySelector('.pricing-country'),type=builder.querySelector('.pricing-match-type'),destination=builder.querySelector('.pricing-destination');
    function refresh(){
        if(!country||!type)return;
        if(country.value==='*')type.value='global';
        if(type.value==='global'&&country.value!=='*')type.value='country';
        var option=country.options[country.selectedIndex];
        if(destination)destination.value=option?option.getAttribute('data-country')||'':'';
        builder.querySelectorAll('[data-pricing-types]').forEach(function(group){
            var visible=(group.getAttribute('data-pricing-types')||'').split(',').indexOf(type.value)!==-1;
            group.hidden=!visible;
            group.querySelectorAll('[data-pricing-required]').forEach(function(input){input.required=visible;});
        });
    }
    if(country)country.addEventListener('change',refresh);
    if(type)type.addEventListener('change',function(){if(type.value==='global'&&country)country.value='*';refresh();});
    refresh();
});
}());</script></body></html>
