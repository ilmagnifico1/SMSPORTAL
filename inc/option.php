<?php
require_once __DIR__ . '/routing.php';
require_once dirname(__DIR__) . '/classes/ClientIpResolver.php';
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; img-src 'self' data: https://flagcdn.com; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'");
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
if (session_status() === PHP_SESSION_NONE) {
    $secureCookie = ClientIpResolver::isSecureRequest();
    if ($secureCookie && !headers_sent()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    session_name('sms_portal_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/i18n.php';
app_enable_output_localization();

if (!empty($_SESSION['logged'])) {
    $now = time();
    $idleLimit = max(300, (int)(getenv('SMS_SESSION_IDLE_SECONDS') ?: 1800));
    $absoluteLimit = max($idleLimit, (int)(getenv('SMS_SESSION_ABSOLUTE_SECONDS') ?: 28800));
    $expired = (!empty($_SESSION['last_activity_at']) && $now - (int)$_SESSION['last_activity_at'] > $idleLimit)
        || (!empty($_SESSION['authenticated_at']) && $now - (int)$_SESSION['authenticated_at'] > $absoluteLimit);
    if ($expired) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_regenerate_id(true);
    } else {
        $_SESSION['last_activity_at'] = $now;
        if (empty($_SESSION['last_regenerated_at']) || $now - (int)$_SESSION['last_regenerated_at'] > 900) {
            session_regenerate_id(true);
            $_SESSION['last_regenerated_at'] = $now;
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
    }
}

require_once dirname(__DIR__) . '/app/Core/Autoloader.php';
App\Core\Autoloader::register(dirname(__DIR__));

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(?string $token): bool {
    return is_string($token) && hash_equals(csrf_token(), $token);
}

const AUTH_CONTEXT_VERSION = 5;

function permission_map_from_user(array $user): array {
    $isSuperAdmin = (string)($user['role'] ?? '') === 'super_admin';
    $isAdmin = in_array((string)($user['role'] ?? 'user'), ['admin', 'super_admin'], true);
    return [
        'send_single' => $isSuperAdmin || (!array_key_exists('can_send_single', $user) || $user['can_send_single'] === null ? true : (int)$user['can_send_single'] === 1),
        'send_bulk' => $isSuperAdmin || (!array_key_exists('can_send_bulk', $user) || $user['can_send_bulk'] === null ? true : (int)$user['can_send_bulk'] === 1),
        'manage_providers' => $isSuperAdmin,
        'manage_users' => $isAdmin,
        'manage_teams' => $isAdmin,
        'manage_companies' => $isSuperAdmin,
        'view_dashboard' => $isSuperAdmin || (!array_key_exists('can_view_dashboard', $user) || $user['can_view_dashboard'] === null ? true : (int)$user['can_view_dashboard'] === 1),
        'view_campaigns' => $isSuperAdmin || (!array_key_exists('can_view_campaigns', $user) || $user['can_view_campaigns'] === null ? true : (int)$user['can_view_campaigns'] === 1),
        'view_lists' => $isSuperAdmin || (!array_key_exists('can_view_lists', $user) || $user['can_view_lists'] === null ? true : (int)$user['can_view_lists'] === 1),
        'view_team_messages' => $isSuperAdmin || (!array_key_exists('can_view_team_messages', $user) || $user['can_view_team_messages'] === null ? true : (int)$user['can_view_team_messages'] === 1),
        'create_campaigns' => $isSuperAdmin || (!array_key_exists('can_create_campaigns', $user) || $user['can_create_campaigns'] === null ? true : (int)$user['can_create_campaigns'] === 1),
        'edit_campaigns' => $isSuperAdmin || (!array_key_exists('can_edit_campaigns', $user) || $user['can_edit_campaigns'] === null ? true : (int)$user['can_edit_campaigns'] === 1),
        'delete_campaigns' => $isSuperAdmin || (!array_key_exists('can_delete_campaigns', $user) || $user['can_delete_campaigns'] === null ? true : (int)$user['can_delete_campaigns'] === 1),
        'create_lists' => $isSuperAdmin || (!array_key_exists('can_create_lists', $user) || $user['can_create_lists'] === null ? true : (int)$user['can_create_lists'] === 1),
        'edit_lists' => $isSuperAdmin || (!array_key_exists('can_edit_lists', $user) || $user['can_edit_lists'] === null ? true : (int)$user['can_edit_lists'] === 1),
        'delete_lists' => $isSuperAdmin || (!array_key_exists('can_delete_lists', $user) || $user['can_delete_lists'] === null ? true : (int)$user['can_delete_lists'] === 1),
        'view_credits' => $isAdmin,
        'manage_credits' => $isSuperAdmin,
        'manage_firewall' => $isSuperAdmin,
    ];
}

function current_permissions(): array {
    return is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
}

function current_user_role(): string {
    return (string)($_SESSION['role'] ?? 'user');
}

function is_super_admin(): bool {
    return current_user_role() === 'super_admin';
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_company_id(): int {
    return (int)($_SESSION['company_id'] ?? 0);
}

function current_team_id(): int {
    return (int)($_SESSION['team_id'] ?? 0);
}

function set_user_session_context(array $user): void {
    $_SESSION['user_id'] = (int)($user['id'] ?? 0);
    $_SESSION['role'] = (string)($user['role'] ?? 'user');
    $_SESSION['company_id'] = (int)($user['company_id'] ?? 0);
    $_SESSION['team_id'] = (int)($user['team_id'] ?? 0);
    $_SESSION['company_name'] = (string)($user['company_name'] ?? '');
    $_SESSION['team_name'] = (string)($user['team_name'] ?? '');
    $_SESSION['preferred_language'] = app_normalize_locale($user['preferred_language'] ?? APP_DEFAULT_LOCALE);
    $_SESSION['permissions'] = permission_map_from_user($user);
    $_SESSION['auth_context_version'] = AUTH_CONTEXT_VERSION;
}

function user_can(string $permission): bool {
    $permissions = current_permissions();
    return !empty($permissions[$permission]);
}

function default_authorized_page(): string {
    // if (user_can('send_single')) {
    //     return app_url('send-single');
    // }
    return app_url('dashboard');
}

function require_permission(string $permission, string $redirect = ''): void {
    $redirect = $redirect !== '' ? $redirect : app_url('dashboard');
    if (!user_can($permission)) {
        SystemLogger::record('warning', 'security', 'permission.denied', 'Accesso negato per permessi insufficienti.', [
            'permission' => $permission,
            'redirect' => $redirect,
        ]);
        $_SESSION['flash_error'] = 'Non hai i permessi per usare questa funzione.';
        header('Location: ' . $redirect);
        exit;
    }
}

function flash_message(): string {
    $message = (string)($_SESSION['flash_error'] ?? '');
    unset($_SESSION['flash_error']);
    return $message;
}

function system_log(string $level, string $category, string $event, string $message, array $context = [], string $userName = ''): void {
    SystemLogger::record($level, $category, $event, $message, $context, $userName);
}

SystemLogger::boot();
$firewall = null;
$clientIp = SystemLogger::clientIp();
try {
    $firewall = new AppFirewall();
    if ($firewall->isBlockedIp($clientIp)) {
        $firewall->recordDeniedAttempt(current_user_id(), current_company_id(), (string)($_SESSION['logged'] ?? ''), $clientIp, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
        header('Location: ' . app_url('access-denied'));
        exit;
    }
    // Il firewall deve proteggere anche la pagina di login: prima che esista una
    // sessione utente, l'IP client deve comparire in almeno una regola attiva.
    if (empty($_SESSION['logged']) && !$firewall->isIpAuthorizedByAnyRule($clientIp)) {
        SystemLogger::record('warning', 'security', 'firewall.anonymous_ip_denied', 'Accesso anonimo negato: IP non presente nelle regole firewall.', [
            'ip' => $clientIp,
            'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        ]);
        $firewall->recordDeniedAttempt(0, 0, '', $clientIp, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
        header('Location: ' . app_url('access-denied'));
        exit;
    }
} catch (Throwable $exception) {
    SystemLogger::record('error', 'security', 'firewall.blocklist_check_failed', 'Errore durante il controllo preventivo degli IP bloccati.', ['error' => $exception->getMessage(), 'ip' => $clientIp]);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
    header('Location: ' . app_url('access-denied'));
    exit;
}
if (!empty($_SESSION['logged']) && (
    (int)($_SESSION['auth_context_version'] ?? 0) !== AUTH_CONTEXT_VERSION
    || !isset($_SESSION['role'])
    || !array_key_exists('manage_teams', (array)($_SESSION['permissions'] ?? []))
    || !array_key_exists('view_dashboard', (array)($_SESSION['permissions'] ?? []))
    || time() - (int)($_SESSION['auth_context_checked_at'] ?? 0) > 60
)) {
    try {
        new SmsApp();
        $_SESSION['auth_context_checked_at'] = time();
        if (empty($_SESSION['logged'])) {
            header('Location: ' . app_url('login'));
            exit;
        }
    } catch (Throwable $exception) {
        SystemLogger::record('error', 'tenant', 'session_context.failed', 'Impossibile aggiornare il contesto aziendale della sessione.', ['error' => $exception->getMessage()]);
    }
}
if (!empty($_SESSION['logged'])) {
    try {
        $firewall = $firewall ?? new AppFirewall();
        if (!$firewall->isAllowed(current_user_id(), current_company_id(), $clientIp)) {
            $blockedUser = (string)$_SESSION['logged'];
            SystemLogger::record('warning', 'security', 'firewall.access_denied', 'Accesso negato dal firewall applicativo.', [
                'company_id' => current_company_id(),
                'team_id' => current_team_id(),
                'user_id' => current_user_id(),
                'ip' => $clientIp,
            ], $blockedUser);
            $firewall->recordDeniedAttempt(current_user_id(), current_company_id(), $blockedUser, $clientIp, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: ' . app_url('access-denied'));
            exit;
        }
    } catch (Throwable $exception) {
        SystemLogger::record('error', 'security', 'firewall.check_failed', 'Errore durante il controllo del firewall applicativo.', ['error' => $exception->getMessage()]);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
        header('Location: ' . app_url('access-denied'));
        exit;
    }
}
?>
