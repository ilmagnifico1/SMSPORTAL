<?php

declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formName = (string)($_POST['name'] ?? ($editingCompany ? $editingCompany->name : ''));
$formActive = $submitted
    ? !empty($_POST['active'])
    : (!$editingCompany || $editingCompany->active);
?>
<!DOCTYPE html>
<html lang="<?php echo $escape(app_locale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione aziende</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="imgs/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="imgs/favicon-180.png?v=2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loggedstyle.css">
</head>
<body class="page-companies settings-area">
<div id="wrapper" class="dashboard-shell">
    <div id="header">
        <div>
            <p class="eyebrow">Multi-azienda</p>
            <h2>Gestione aziende</h2>
        </div>
        <div class="header-actions">
            <span class="user-badge"><?php echo $escape($_SESSION['logged']); ?></span>
            <a href="<?php echo app_url('logout'); ?>" class="logout-link">Logout</a>
        </div>
    </div>

    <?php require dirname(__DIR__, 3) . '/inc/top_nav.php'; ?>
    <?php require dirname(__DIR__, 3) . '/inc/settings_sidebar.php'; ?>

    <?php if ($flashMessage !== '') : ?>
        <div class="alert alert-danger"><?php echo $escape($flashMessage); ?></div>
    <?php endif; ?>
    <?php if ($message !== '') : ?>
        <div class="alert"><?php echo $escape($message); ?></div>
    <?php endif; ?>

    <section class="page-heading">
        <div>
            <p class="eyebrow">Struttura organizzativa</p>
            <h1>Aziende</h1>
            <p class="muted-text">Il Super Admin decide quali provider rendere disponibili a ciascuna azienda.</p>
        </div>
        <span class="heading-pill">Solo Super Admin</span>
    </section>

    <section class="card">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Tenant</p>
                <h3>Aziende configurate</h3>
            </div>
            <button type="button" class="modal-trigger-btn" id="openCompanyModal">Nuova azienda</button>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome azienda</th>
                    <th>Provider autorizzati</th>
                    <th>Credito residuo</th>
                    <th>Profitto generato</th>
                    <th>Stato</th>
                    <th>Creata</th>
                    <th>Azioni</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($companies as $company) : ?>
                    <tr>
                        <td><?php echo $company->id; ?></td>
                        <td><?php echo $escape($company->name); ?></td>
                        <td><?php echo $escape($company->providerNames ? implode(', ', $company->providerNames) : 'Nessuno'); ?></td>
                        <td><strong>€ <?php echo number_format($company->creditBalance, 4, ',', '.'); ?></strong></td>
                        <td class="<?php echo $company->profitTotal >= 0 ? 'credit-positive' : 'credit-negative'; ?>">
                            € <?php echo number_format($company->profitTotal, 4, ',', '.'); ?>
                        </td>
                        <td>
                            <span class="status-pill <?php echo $company->active ? 'sent' : 'failed'; ?>">
                                <?php echo $company->active ? 'Attiva' : 'Disattiva'; ?>
                            </span>
                        </td>
                        <td><?php echo $escape($company->createdAt); ?></td>
                        <td><a class="action-btn" href="<?php echo app_url('companies', ['edit' => $company->id]); ?>">Modifica</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="modal-overlay <?php echo $openModal ? 'is-active' : ''; ?>" id="companyModal">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <p class="eyebrow"><?php echo $editingCompany ? 'Modifica' : 'Nuova'; ?></p>
                <h3><?php echo $editingCompany ? 'Aggiorna azienda' : 'Crea azienda'; ?></h3>
            </div>
            <button type="button" class="modal-close-btn" id="closeCompanyModal" aria-label="Chiudi popup">x</button>
        </div>
        <form method="post" class="stacked-form">
            <input type="hidden" name="action" value="save_company">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo $editingCompany ? $editingCompany->id : 0; ?>">

            <label for="name">Nome azienda</label>
            <input id="name" name="name" required value="<?php echo $escape($formName); ?>">

            <div class="checkbox-row">
                <label>
                    <input type="checkbox" name="active" value="1" <?php echo $formActive ? 'checked' : ''; ?>>
                    Azienda attiva
                </label>
            </div>

            <div>
                <label>Provider disponibili per l'azienda</label>
                <p class="muted-text">L'Admin aziendale potrà assegnare agli utenti soltanto i provider selezionati qui.</p>
                <div class="permissions-grid">
                    <?php if ($allProviders === []) : ?>
                        <span class="muted-text">Nessun provider configurato.</span>
                    <?php else : ?>
                        <?php foreach ($allProviders as $provider) : ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="provider_ids[]"
                                    value="<?php echo (int)$provider['id']; ?>"
                                    <?php echo in_array((int)$provider['id'], $selectedProviderIds, true) ? 'checked' : ''; ?>
                                >
                                <?php echo $escape((string)$provider['name']); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <input type="submit" value="Salva azienda">
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('companyModal');
    var openButton = document.getElementById('openCompanyModal');
    var closeButton = document.getElementById('closeCompanyModal');
    if (!modal || !openButton || !closeButton) return;
    openButton.addEventListener('click', function () { modal.classList.add('is-active'); });
    function closeModal() { window.location.href = <?php echo json_encode(app_url('companies')); ?>; }
    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
}());
</script>
</body>
</html>
