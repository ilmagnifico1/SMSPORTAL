<?php

declare(strict_types=1);

final class AdminDashboard
{
    public static function run(array $config, MessageStore $store, string $path, string $method): never
    {
        self::startSecureSession();
        self::headers();

        $configured = trim((string)($config['admin_password_hash'] ?? '')) !== '';
        if (!$configured) {
            self::renderLogin('Console non configurata. Imposta INTERNAL_PROVIDER_ADMIN_PASSWORD_HASH.', true);
        }

        if ($path === '/logout' || $path === '/admin/logout') {
            if ($method !== 'POST' || !self::validCsrf((string)($_POST['csrf'] ?? ''))) {
                self::error(403, 'Richiesta di uscita non valida.');
            }
            $_SESSION = [];
            session_regenerate_id(true);
            self::redirect('/');
        }

        if (!self::authenticated()) {
            $error = '';
            if ($method === 'POST') {
                if (!self::validCsrf((string)($_POST['csrf'] ?? ''))) {
                    self::error(403, 'Sessione scaduta. Ricarica la pagina e riprova.');
                }
                $password = (string)($_POST['password'] ?? '');
                if (password_verify($password, (string)$config['admin_password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['internal_provider_admin'] = true;
                    $_SESSION['login_at'] = time();
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    self::redirect('/');
                }
                $_SESSION['login_failures'] = min(5, (int)($_SESSION['login_failures'] ?? 0) + 1);
                usleep((int)$_SESSION['login_failures'] * 200000);
                $error = 'Password non valida.';
            }
            self::renderLogin($error, false);
        }

        if ($method !== 'GET') {
            self::error(405, 'Metodo non consentito.');
        }

        $filters = [
            'outcome' => in_array((string)($_GET['outcome'] ?? 'all'), ['all', 'success', 'failed'], true)
                ? (string)($_GET['outcome'] ?? 'all') : 'all',
            'scenario' => in_array((string)($_GET['scenario'] ?? ''), ProviderSimulator::SCENARIOS, true)
                ? (string)$_GET['scenario'] : '',
            'query' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
        ];
        $requestedLimit = (int)($_GET['limit'] ?? 50);
        $limit = in_array($requestedLimit, [25, 50, 100, 200], true) ? $requestedLimit : 50;
        self::renderDashboard($store->search($filters, $limit), $store->statistics(), $filters, $limit);
    }

    private static function startSecureSession(): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('internal_provider_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    private static function headers(): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Cache-Control: no-store, private');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    private static function authenticated(): bool
    {
        return ($_SESSION['internal_provider_admin'] ?? false) === true;
    }

    private static function validCsrf(string $token): bool
    {
        return $token !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $token);
    }

    private static function redirect(string $location): never
    {
        header('Location: ' . $location, true, 303);
        exit;
    }

    private static function renderLogin(string $message, bool $configurationError): never
    {
        http_response_code($configurationError ? 503 : 200);
        $csrf = self::escape((string)($_SESSION['csrf'] ?? ''));
        $notice = $message !== '' ? '<div class="notice ' . ($configurationError ? 'warning' : 'error') . '">' . self::escape($message) . '</div>' : '';
        $form = $configurationError ? '' : '<form method="post" action="/" class="login-form"><label for="password">Password amministratore</label><input id="password" name="password" type="password" required autofocus autocomplete="current-password"><input type="hidden" name="csrf" value="' . $csrf . '"><button type="submit">Accedi alla console</button></form>';
        echo self::page('Accesso', '<main class="login-shell"><section class="login-card"><div class="brand-mark">IP</div><p class="eyebrow">SMS TEST PROVIDER</p><h1>Console messaggi</h1><p class="muted">Area riservata per controllare gli esiti delle simulazioni.</p>' . $notice . $form . '</section></main>');
        exit;
    }

    private static function renderDashboard(array $messages, array $stats, array $filters, int $limit): never
    {
        $success = (int)($stats['success'] ?? 0);
        $failed = (int)($stats['failed'] ?? 0);
        $total = (int)($stats['total'] ?? 0);
        $rate = number_format((float)($stats['success_rate'] ?? 0), 1, ',', '.');
        $scenarioOptions = '<option value="">Tutti gli scenari</option>';
        foreach (ProviderSimulator::SCENARIOS as $scenario) {
            $selected = $filters['scenario'] === $scenario ? ' selected' : '';
            $scenarioOptions .= '<option value="' . self::escape($scenario) . '"' . $selected . '>' . self::escape($scenario) . '</option>';
        }
        $limitOptions = '';
        foreach ([25, 50, 100, 200] as $value) {
            $limitOptions .= '<option value="' . $value . '"' . ($limit === $value ? ' selected' : '') . '>' . $value . '</option>';
        }

        $rows = '';
        foreach ($messages as $message) {
            $httpCode = (int)($message['http_code'] ?? 0);
            $ok = $httpCode >= 200 && $httpCode < 300 && (string)($message['status'] ?? '') === 'sent';
            $statusClass = $ok ? 'success' : 'failed';
            $statusLabel = $ok ? 'Riuscito' : 'Fallito';
            $created = self::formatDate((string)($message['created_at'] ?? ''));
            $body = trim((string)($message['message'] ?? ''));
            $messageInfo = $body !== '' ? '<details><summary>' . (int)($message['message_length'] ?? 0) . ' caratteri</summary><p>' . nl2br(self::escape($body)) . '</p></details>' : (int)($message['message_length'] ?? 0) . ' caratteri';
            $rows .= '<tr><td><time>' . self::escape($created) . '</time><small>UTC</small></td><td><span class="badge ' . $statusClass . '">' . $statusLabel . '</span><small>' . self::escape((string)($message['status'] ?? 'unknown')) . '</small></td><td><strong>' . self::escape((string)($message['to_masked'] ?? '')) . '</strong><small>da ' . self::escape((string)($message['from'] ?? '')) . '</small></td><td><code>' . self::escape((string)($message['scenario'] ?? '')) . '</code></td><td><strong>HTTP ' . $httpCode . '</strong><small>' . self::escape((string)($message['remote_ip'] ?? '')) . '</small></td><td>' . $messageInfo . '</td><td><code class="message-id">' . self::escape((string)($message['id'] ?? '')) . '</code></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="empty">Nessun messaggio corrisponde ai filtri selezionati.</td></tr>';
        }

        $csrf = self::escape((string)($_SESSION['csrf'] ?? ''));
        $outcome = (string)$filters['outcome'];
        $content = '<header class="topbar"><div><p class="eyebrow">SMS INTERNAL PROVIDER</p><h1>Registro messaggi</h1></div><div class="top-actions"><span class="live-dot">Servizio attivo</span><form method="post" action="/logout"><input type="hidden" name="csrf" value="' . $csrf . '"><button class="secondary" type="submit">Esci</button></form></div></header><main class="container"><section class="stats"><article><span>Messaggi registrati</span><strong>' . $total . '</strong></article><article class="positive"><span>Riusciti</span><strong>' . $success . '</strong></article><article class="negative"><span>Falliti</span><strong>' . $failed . '</strong></article><article><span>Tasso di successo</span><strong>' . $rate . '%</strong></article></section><section class="panel"><form method="get" action="/" class="filters"><div><label for="q">Cerca</label><input id="q" name="q" value="' . self::escape((string)$filters['query']) . '" placeholder="ID, numero, mittente o IP"></div><div><label for="outcome">Esito</label><select id="outcome" name="outcome"><option value="all"' . ($outcome === 'all' ? ' selected' : '') . '>Tutti</option><option value="success"' . ($outcome === 'success' ? ' selected' : '') . '>Riusciti</option><option value="failed"' . ($outcome === 'failed' ? ' selected' : '') . '>Falliti</option></select></div><div><label for="scenario">Scenario</label><select id="scenario" name="scenario">' . $scenarioOptions . '</select></div><div><label for="limit">Righe</label><select id="limit" name="limit">' . $limitOptions . '</select></div><button type="submit">Applica filtri</button><a class="reset" href="/">Azzera</a></form><div class="table-wrap"><table><thead><tr><th>Data</th><th>Esito</th><th>Destinatario</th><th>Scenario</th><th>Risposta</th><th>Messaggio</th><th>ID provider</th></tr></thead><tbody>' . $rows . '</tbody></table></div><footer class="panel-footer">Sono mostrati al massimo ' . $limit . ' record. I numeri telefonici restano mascherati.</footer></section></main>';
        echo self::page('Registro messaggi', $content);
        exit;
    }

    private static function page(string $title, string $content): string
    {
        return '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::escape($title) . ' · SMS Internal Provider</title><link rel="stylesheet" href="/admin.css"></head><body>' . $content . '</body></html>';
    }

    private static function error(int $status, string $message): never
    {
        http_response_code($status);
        echo self::page('Errore', '<main class="login-shell"><section class="login-card"><h1>Errore ' . $status . '</h1><p>' . self::escape($message) . '</p><a class="button-link" href="/">Torna alla console</a></section></main>');
        exit;
    }

    private static function formatDate(string $date): string
    {
        try {
            return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'))->format('d/m/Y H:i:s');
        } catch (Throwable) {
            return $date;
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
