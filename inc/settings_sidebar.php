<?php
$currentSettingsPage = current_route();
$settingsLinkClass = static function (string $page) use ($currentSettingsPage): string {
    return $currentSettingsPage === $page ? ' class="active" aria-current="page"' : '';
};
?>
<section class="settings-dock" aria-label="Navigazione impostazioni">
    <a class="settings-dock-title<?php echo $currentSettingsPage === 'settings' ? ' active' : ''; ?>" href="<?php echo app_url('settings'); ?>">
        <span class="settings-panel-gear" aria-hidden="true"></span>
        <span><strong>Impostazioni</strong><small>Control Room</small></span>
    </a>
    <nav class="settings-dock-nav" aria-label="Sezioni impostazioni">
        <?php if (is_super_admin()) : ?>
            <a href="<?php echo app_url('companies'); ?>"<?php echo $settingsLinkClass('companies'); ?>><span aria-hidden="true">&#127970;</span>Aziende</a>
        <?php endif; ?>
        <?php if (user_can('manage_users')) : ?>
            <a href="<?php echo app_url('devices'); ?>"<?php echo $settingsLinkClass('devices'); ?>><span aria-hidden="true">&#128241;</span>Dispositivi</a>
        <?php endif; ?>
        <?php if (user_can('manage_providers')) : ?>
            <a href="<?php echo app_url('providers'); ?>"<?php echo $settingsLinkClass('providers'); ?>><span aria-hidden="true">&#128421;</span>Gestione Provider</a>
        <?php endif; ?>
        <?php if (user_can('view_credits')) : ?>
            <a href="<?php echo app_url('credits'); ?>"<?php echo $settingsLinkClass('credits'); ?>><span aria-hidden="true">&#128179;</span>Gestione Crediti</a>
        <?php endif; ?>
        <?php if (user_can('manage_firewall')) : ?>
            <a href="<?php echo app_url('firewall'); ?>"<?php echo $settingsLinkClass('firewall'); ?>><span aria-hidden="true">&#128737;</span>Firewall</a>
        <?php endif; ?>
        <?php if (user_can('manage_users')) : ?>
            <a href="<?php echo app_url('logs'); ?>"<?php echo $settingsLinkClass('logs'); ?>><span aria-hidden="true">&#9993;</span>Log messaggi</a>
            <a href="<?php echo app_url('system-logs'); ?>"<?php echo $settingsLinkClass('system-logs'); ?>><span aria-hidden="true">&#8984;</span>System Log</a>
        <?php endif; ?>
    </nav>
</section>
