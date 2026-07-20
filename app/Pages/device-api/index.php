<?php
require_once 'inc/option.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function device_api_response(array $data, int $status = 200): never {
    http_response_code($status);
    foreach (['message', 'error'] as $messageKey) {
        if (isset($data[$messageKey]) && is_string($data[$messageKey])) $data[$messageKey] = app_translate_text($data[$messageKey]);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function device_api_input(): array {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

try {
    $manager = new DeviceAuthManager();
    $input = device_api_input();
    $action = trim((string)($input['api_action'] ?? $_GET['api_action'] ?? ''));

    // Queste due operazioni non richiedono la sessione web: conoscere ID e challenge
    // non basta ad approvare, perché serve la firma della chiave privata nell'estensione.
    if ($action === 'details') {
        $details = $manager->getAuthorizationDetails(
            (string)($_GET['authorization_id'] ?? ''),
            (string)($_GET['challenge'] ?? '')
        );
        device_api_response($details ? ['success' => true, 'authorization' => $details] : [
            'success' => false,
            'message' => 'Autorizzazione inesistente, scaduta o già utilizzata.',
        ], $details ? 200 : 404);
    }

    if ($action === 'approve') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            device_api_response(['success' => false, 'message' => 'Metodo non consentito.'], 405);
        }
        $approved = $manager->approveAuthorization(
            (string)($input['authorization_id'] ?? ''),
            (string)($input['device_uuid'] ?? ''),
            (string)($input['signature'] ?? '')
        );
        system_log($approved ? 'info' : 'warning', 'security', $approved ? 'device.authorization_approved' : 'device.authorization_rejected',
            $approved ? 'Invio approvato dalla chiave del dispositivo.' : 'Firma del dispositivo rifiutata.', [
                'authorization_id' => substr((string)($input['authorization_id'] ?? ''), 0, 48),
                'device_uuid' => substr((string)($input['device_uuid'] ?? ''), 0, 36),
            ]);
        device_api_response(['success' => $approved, 'message' => $approved ? 'Invio autorizzato.' : 'Firma non valida o autorizzazione scaduta.'], $approved ? 200 : 403);
    }

    if (empty($_SESSION['logged']) || current_user_id() <= 0 || current_company_id() <= 0) {
        device_api_response(['success' => false, 'message' => 'Sessione non valida.'], 401);
    }

    if ($action === 'register') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
            device_api_response(['success' => false, 'message' => 'Token di sicurezza non valido.'], 403);
        }
        $jwk = $input['public_jwk'] ?? [];
        if (is_string($jwk)) {
            $jwk = json_decode($jwk, true);
        }
        $result = $manager->registerDevice(
            current_user_id(),
            current_company_id(),
            (string)($input['device_uuid'] ?? ''),
            is_array($jwk) ? $jwk : [],
            (string)($input['device_name'] ?? '')
        );
        system_log(!empty($result['success']) ? 'info' : 'warning', 'security', 'device.registration', (string)($result['message'] ?? ''), [
            'device_uuid' => substr((string)($input['device_uuid'] ?? ''), 0, 36),
            'status' => (string)($result['status'] ?? ''),
        ]);
        device_api_response($result, !empty($result['success']) ? 200 : 422);
    }

    if ($action === 'status') {
        $authorizationId = (string)($_GET['authorization_id'] ?? $input['authorization_id'] ?? '');
        $authorizationStatus = $manager->authorizationStatus($authorizationId, current_user_id());
        if ($authorizationStatus === 'expired') {
            $expired = $manager->claimExpiredAuthorizationLog($authorizationId, current_user_id());
            if ($expired) {
                system_log('warning', 'security', 'device.authorization_expired',
                    'Richiesta di autorizzazione scaduta senza approvazione.', [
                        'authorization_id' => (string)$expired['authorization_id'],
                        'action_type' => (string)$expired['action_type'],
                        'device_uuid' => (string)$expired['device_uuid'],
                    ]);
            }
        }
        device_api_response([
            'success' => true,
            'status' => $authorizationStatus,
        ]);
    }

    if ($action !== 'prepare' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        device_api_response(['success' => false, 'message' => 'Operazione non valida.'], 400);
    }
    if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
        device_api_response(['success' => false, 'message' => 'Token di sicurezza non valido.'], 403);
    }

    $app = new SmsApp();
    $actionType = (string)($input['action_type'] ?? '');
    if ($actionType === 'single_sms') {
        if (!user_can('send_single')) {
            device_api_response(['success' => false, 'message' => 'Permesso di invio singolo mancante.'], 403);
        }
        $payload = $manager->singlePayload($input);
        $provider = $app->getProviderById((int)$payload['provider_id']);
        if (!$provider || $payload['to'] === '' || $payload['sms'] === '') {
            device_api_response(['success' => false, 'message' => 'Provider, destinatario o messaggio non validi.'], 422);
        }
        $recipient = $app->composeRecipient((string)$payload['to'], (string)$payload['country_code']);
        $summary = [
            'tipo' => 'SMS singolo',
            'provider' => (string)$provider['name'],
            'destinatario' => $recipient !== '' ? $recipient : (string)$payload['to'],
            'mittente' => (string)$payload['from'],
            'messaggio' => (string)$payload['sms'],
        ];
    } elseif ($actionType === 'campaign') {
        if (!user_can('send_bulk')) {
            device_api_response(['success' => false, 'message' => 'Permesso di invio massivo mancante.'], 403);
        }
        $payload = $manager->campaignPayload($app, (int)($input['id'] ?? 0));
        if ($payload === null || empty($payload['recipients'])) {
            device_api_response(['success' => false, 'message' => 'Campagna inesistente o senza destinatari.'], 422);
        }
        $campaign = $app->getCampaignById((int)$payload['campaign_id']);
        $provider = $app->getProviderById((int)$payload['provider_id']);
        if (!$campaign || !$provider) {
            device_api_response(['success' => false, 'message' => 'Campagna o provider non accessibile.'], 422);
        }
        $summary = [
            'tipo' => 'Campagna SMS',
            'campagna' => (string)$campaign['name'],
            'provider' => (string)$provider['name'],
            'destinatari' => count($payload['recipients']),
            'mittente' => (string)$payload['sender'],
            'messaggio' => (string)$payload['message'],
        ];
    } else {
        device_api_response(['success' => false, 'message' => 'Tipo di invio non valido.'], 422);
    }

    $result = $manager->prepareAuthorization(
        current_user_id(), current_company_id(), (string)($input['device_uuid'] ?? ''), $actionType, $payload, $summary
    );
    device_api_response($result, !empty($result['success']) ? 200 : 403);
} catch (Throwable $exception) {
    system_log('error', 'security', 'device.api_error', 'Errore nel servizio di autorizzazione dispositivo.', ['error' => $exception->getMessage()]);
    device_api_response(['success' => false, 'message' => 'Servizio di autorizzazione temporaneamente non disponibile.'], 500);
}
