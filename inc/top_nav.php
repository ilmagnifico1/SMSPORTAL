<?php
$currentNavPage = current_route();
$settingsPages = ['settings', 'companies', 'devices', 'providers', 'credits', 'firewall', 'logs', 'system-logs'];
$settingsActive = in_array($currentNavPage, $settingsPages, true);

$navClass = static function (string $page) use ($currentNavPage): string {
    return $currentNavPage === $page ? ' class="active"' : '';
};
?>
<nav class="top-nav">
    <a data-nav="dashboard" href="<?php echo app_url('dashboard'); ?>"<?php echo $navClass('dashboard'); ?>>Dashboard</a>
    <?php if (user_can('send_single')) : ?><a data-nav="send-single" href="<?php echo app_url('send-single'); ?>"<?php echo $navClass('send-single'); ?>>Invio singolo</a><?php endif; ?>
    <?php if (user_can('send_bulk')) : ?><a data-nav="campaigns" href="<?php echo app_url('campaigns'); ?>"<?php echo $navClass('campaigns'); ?>>Gestione campagne</a><?php endif; ?>
    <?php if (user_can('send_bulk')) : ?><a data-nav="lists" href="<?php echo app_url('lists'); ?>"<?php echo $navClass('lists'); ?>>Gestione liste</a><?php endif; ?>
    <?php if (user_can('manage_teams')) : ?><a data-nav="teams" href="<?php echo app_url('teams'); ?>"<?php echo $navClass('teams'); ?>>Team</a><?php endif; ?>
    <?php if (user_can('manage_users')) : ?><a data-nav="users" href="<?php echo app_url('users'); ?>"<?php echo $navClass('users'); ?>>Gestione utenti</a><?php endif; ?>

    <a data-nav="settings" href="<?php echo app_url('settings'); ?>" class="nav-settings-link<?php echo $settingsActive ? ' active' : ''; ?>">Impostazioni</a>
</nav>
<script>window.SMS_I18N=<?php echo json_encode(app_client_translations(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;window.smsTranslate=function(key){return window.SMS_I18N[key]||key;};</script>
