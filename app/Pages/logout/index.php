<?php
require_once 'inc/option.php';

if (!empty($_SESSION['logged'])) {
    system_log('info', 'auth', 'logout', 'Disconnessione utente.', [], (string)$_SESSION['logged']);
}
session_destroy();
header('Location: ' . app_url('login'));
exit;
?>
