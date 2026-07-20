<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$root = dirname(__DIR__, 3);
require_once $root . '/classes/Connection.php';
require_once $root . '/classes/SystemLogger.php';
require_once $root . '/classes/AppFirewall.php';

$accessRequestMessage = '';
$accessRequestSuccess = false;
$accessRequestToken = $_SESSION['access_request_token'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_access') {
    $validToken = is_string($_POST['csrf_token'] ?? null) && hash_equals($accessRequestToken, (string)$_POST['csrf_token']);
    if (!$validToken) {
        $accessRequestMessage = 'Sessione scaduta. Ricarica la pagina e riprova.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        $accessRequestMessage = 'Richiesta non valida.';
    } else {
        try {
            $firewall = new AppFirewall();
            $accessRequestSuccess = $firewall->submitAccessRequest(
                SystemLogger::clientIp(),
                (string)($_POST['first_name'] ?? ''),
                (string)($_POST['last_name'] ?? ''),
                (string)($_POST['email'] ?? ''),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            $accessRequestMessage = $accessRequestSuccess
                ? 'Richiesta inviata. Un amministratore valuterà il tuo accesso.'
                : 'Controlla nome, cognome ed email e riprova.';
            if ($accessRequestSuccess) {
                SystemLogger::record('info', 'security', 'firewall.access_requested', 'Nuova richiesta di accesso da un IP bloccato.', [
                    'ip' => SystemLogger::clientIp(),
                    'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
                ]);
            }
        } catch (Throwable $exception) {
            $accessRequestMessage = 'Impossibile inviare la richiesta in questo momento.';
        }
    }
}
http_response_code(403);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accesso non autorizzato</title>
    <link rel="icon" href="imgs/favicon.ico?v=2" sizes="any">
    <style>
        :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; overflow: hidden; background: radial-gradient(circle at 50% 35%, #193664 0, #0b1d3b 34%, #050b17 75%); color: #f5f9ff; }
        body::before { content: ""; position: fixed; inset: 0; opacity: .28; background-image: linear-gradient(rgba(95,151,255,.12) 1px, transparent 1px), linear-gradient(90deg, rgba(95,151,255,.12) 1px, transparent 1px); background-size: 46px 46px; transform: perspective(600px) rotateX(64deg) scale(1.7) translateY(23%); transform-origin: center bottom; mask-image: linear-gradient(to top, #000, transparent 72%); }
        .denied-shell { position: relative; z-index: 1; display: grid; min-height: 100vh; place-items: center; padding: 30px; }
        .denied-card { width: min(680px, 100%); padding: 38px 34px 34px; border: 1px solid rgba(137,181,255,.22); border-radius: 28px; background: linear-gradient(145deg, rgba(18,42,78,.82), rgba(7,18,37,.82)); box-shadow: 0 35px 100px rgba(0,0,0,.48), inset 0 1px 0 rgba(255,255,255,.09); text-align: center; backdrop-filter: blur(18px); }
        .shield-scene { position: relative; width: 250px; height: 260px; margin: -5px auto 18px; perspective: 900px; }
        .orbit { position: absolute; top: 50%; left: 50%; width: 232px; height: 232px; border: 1px solid rgba(91,151,255,.28); border-radius: 50%; transform: translate(-50%,-50%) rotateX(68deg); box-shadow: 0 0 32px rgba(55,125,255,.18); animation: orbit 6s linear infinite; }
        .orbit::before, .orbit::after { content: ""; position: absolute; border: 1px dashed rgba(105,172,255,.25); border-radius: inherit; }
        .orbit::before { inset: 18px; animation: orbit 8s linear infinite reverse; }
        .orbit::after { inset: 42px; }
        .shield-assembly { position: absolute; top: 20px; left: 35px; width: 180px; height: 210px; transform-style: preserve-3d; animation: shieldFloat 4.2s ease-in-out infinite; }
        .shield-layer { position: absolute; inset: 0; clip-path: polygon(50% 0, 91% 16%, 85% 62%, 70% 83%, 50% 100%, 30% 83%, 15% 62%, 9% 16%); }
        .shield-shadow { background: #020712; transform: translateZ(-22px) translate(13px,16px); filter: blur(8px); opacity: .72; }
        .shield-back { background: linear-gradient(145deg,#274a88,#07152c); transform: translateZ(-10px) translate(7px,8px); box-shadow: 0 24px 45px rgba(0,0,0,.45); }
        .shield-face { display: grid; place-items: center; overflow: hidden; border: 1px solid rgba(186,216,255,.6); background: linear-gradient(135deg,#5ca5ff 0,#2464d6 42%,#102e77 100%); transform: translateZ(18px); box-shadow: inset 12px 10px 25px rgba(255,255,255,.22), inset -14px -18px 30px rgba(2,12,42,.4), 0 0 45px rgba(53,126,255,.34); }
        .shield-face::before { content: ""; position: absolute; inset: -40% 48% -20% -20%; background: linear-gradient(90deg,rgba(255,255,255,.26),rgba(255,255,255,0)); transform: skewX(-14deg); }
        .shield-face::after { content: ""; position: absolute; inset: 10px; clip-path: inherit; border: 2px solid rgba(210,229,255,.22); }
        .lock { position: relative; z-index: 2; width: 64px; height: 54px; margin-top: 31px; border: 4px solid #e9f3ff; border-radius: 12px; background: rgba(4,21,61,.38); box-shadow: 0 8px 18px rgba(0,0,0,.24); }
        .lock::before { content: ""; position: absolute; left: 50%; bottom: 43px; width: 36px; height: 35px; border: 5px solid #e9f3ff; border-bottom: 0; border-radius: 20px 20px 0 0; transform: translateX(-50%); }
        .lock::after { content: ""; position: absolute; top: 17px; left: 50%; width: 8px; height: 18px; border-radius: 8px; background: #e9f3ff; transform: translateX(-50%); }
        .status-code { display: inline-flex; padding: 7px 12px; border: 1px solid rgba(255,112,125,.28); border-radius: 999px; background: rgba(139,25,43,.18); color: #ff9ca7; font-size: 12px; font-weight: 850; letter-spacing: .15em; text-transform: uppercase; }
        h1 { margin: 17px 0 10px; font-size: clamp(29px,5vw,43px); letter-spacing: -.04em; }
        p { max-width: 520px; margin: 0 auto; color: #b8c8df; font-size: clamp(16px,2.3vw,19px); line-height: 1.65; }
        .security-note { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 22px; color: #7187a7; font-size: 12px; text-transform: uppercase; letter-spacing: .11em; }
        .security-note i { width: 7px; height: 7px; border-radius: 50%; background: #ff6877; box-shadow: 0 0 12px #ff6877; animation: pulse 1.6s ease-in-out infinite; }
        .request-access-btn { margin-top: 24px; padding: 13px 22px; border: 0; border-radius: 13px; color: #fff; font-weight: 800; cursor: pointer; background: linear-gradient(135deg,#2878ee,#6754eb); box-shadow: 0 12px 30px rgba(38,104,224,.32); }
        .access-request-form { display: none; grid-template-columns: 1fr 1fr; gap: 13px; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(137,181,255,.18); text-align: left; }
        .access-request-form.is-open { display: grid; }
        .access-request-form label { display: grid; gap: 7px; color: #b8c8df; font-size: 13px; font-weight: 700; }
        .access-request-form .full { grid-column: 1 / -1; }
        .access-request-form input { width: 100%; padding: 12px 13px; border: 1px solid rgba(137,181,255,.25); border-radius: 11px; outline: none; background: rgba(4,14,31,.72); color: #f5f9ff; }
        .access-request-form input:focus { border-color: #5ca5ff; box-shadow: 0 0 0 3px rgba(92,165,255,.13); }
        .access-request-form button { justify-self: end; padding: 12px 20px; border: 0; border-radius: 11px; background: #2d75e8; color: white; font-weight: 800; cursor: pointer; }
        .request-message { margin-top: 16px; padding: 11px 14px; border-radius: 11px; color: #ffb8bf; background: rgba(151,29,48,.2); font-size: 14px; }
        .request-message.success { color: #a9f1c5; background: rgba(24,126,73,.2); }
        .honeypot { position: absolute !important; left: -10000px !important; }
        @keyframes shieldFloat { 0%,100% { transform: rotateY(-12deg) rotateX(6deg) translateY(2px); } 50% { transform: rotateY(12deg) rotateX(-3deg) translateY(-12px); } }
        @keyframes orbit { to { transform: translate(-50%,-50%) rotateX(68deg) rotateZ(360deg); } }
        @keyframes pulse { 50% { opacity: .35; transform: scale(.7); } }
        @media (max-width: 540px) { body { overflow: auto; } .denied-shell { padding: 15px; } .denied-card { padding: 25px 18px 27px; } .shield-scene { transform: scale(.82); margin: -24px auto -8px; } .access-request-form { grid-template-columns: 1fr; } .access-request-form .full { grid-column: auto; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; } }
    </style>
</head>
<body>
    <main class="denied-shell">
        <section class="denied-card" aria-labelledby="denied-title">
            <div class="shield-scene" aria-hidden="true"><div class="orbit"></div><div class="shield-assembly"><div class="shield-layer shield-shadow"></div><div class="shield-layer shield-back"></div><div class="shield-layer shield-face"><div class="lock"></div></div></div></div>
            <span class="status-code">Errore 403</span>
            <h1 id="denied-title">Accesso non autorizzato</h1>
            <p>Il tuo indirizzo IP non è autorizzato ad accedere a questo portale. Contattare l’amministratore.</p>
            <?php if ($accessRequestMessage !== '') : ?><div class="request-message <?php echo $accessRequestSuccess ? 'success' : ''; ?>"><?php echo htmlspecialchars($accessRequestMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <button type="button" class="request-access-btn" id="requestAccessButton">Richiedi accesso</button>
            <form method="post" class="access-request-form <?php echo (!$accessRequestSuccess && $accessRequestMessage !== '') ? 'is-open' : ''; ?>" id="accessRequestForm">
                <input type="hidden" name="action" value="request_access">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($accessRequestToken, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="honeypot">Sito web<input name="website" tabindex="-1" autocomplete="off"></label>
                <label>Nome<input name="first_name" maxlength="100" autocomplete="given-name" required value="<?php echo htmlspecialchars((string)($_POST['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label>Cognome<input name="last_name" maxlength="100" autocomplete="family-name" required value="<?php echo htmlspecialchars((string)($_POST['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label class="full">Email<input type="email" name="email" maxlength="190" autocomplete="email" required value="<?php echo htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                <button type="submit" class="full">Invia richiesta</button>
            </form>
            <div class="security-note"><i></i> Connessione bloccata dal firewall applicativo</div>
        </section>
    </main>
    <script>document.getElementById('requestAccessButton').addEventListener('click',function(){document.getElementById('accessRequestForm').classList.toggle('is-open');});</script>
</body>
</html>
