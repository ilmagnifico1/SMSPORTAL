<?php

declare(strict_types=1);

final class ProviderSimulator
{
    public const SCENARIOS = ['success', 'reject', 'provider_error', 'rate_limit', 'timeout', 'mixed'];

    public function simulate(array $payload, string $scenario, int $timeoutMs): array
    {
        $to = $this->normalizePhone((string)($payload['to'] ?? $payload['To'] ?? ''));
        $from = trim((string)($payload['from'] ?? $payload['From'] ?? ''));
        $text = trim((string)($payload['text'] ?? $payload['Body'] ?? ''));

        if ($to === '' || $text === '' || mb_strlen($text) > 5000 || mb_strlen($from) > 100) {
            return $this->response(422, 'invalid_request', 'Destinatario, mittente o testo non validi.', $to, $from, $text);
        }

        $scenario = strtolower(trim($scenario));
        if (!in_array($scenario, self::SCENARIOS, true)) {
            return $this->response(400, 'invalid_scenario', 'Scenario di simulazione non riconosciuto.', $to, $from, $text);
        }
        if ($scenario === 'mixed') {
            $bucket = (int)sprintf('%u', crc32($to . "\0" . $text)) % 10;
            $scenario = match ($bucket) {
                0 => 'reject',
                1 => 'provider_error',
                2 => 'rate_limit',
                default => 'success',
            };
        }

        return match ($scenario) {
            'success' => $this->response(202, 'sent', 'Messaggio di test accettato.', $to, $from, $text),
            'reject' => $this->response(422, 'rejected', 'Messaggio rifiutato dallo scenario di test.', $to, $from, $text),
            'provider_error' => $this->response(503, 'provider_error', 'Errore simulato del gateway.', $to, $from, $text),
            'rate_limit' => $this->response(429, 'rate_limited', 'Limite richieste simulato.', $to, $from, $text, ['retry_after' => 30]),
            'timeout' => $this->response(504, 'timeout', 'Timeout simulato del gateway.', $to, $from, $text, ['delay_ms' => $timeoutMs]),
        };
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', trim($phone)) ?: '';
        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }
        return preg_match('/^\+[1-9][0-9]{6,14}$/', $phone) === 1 ? $phone : '';
    }

    private function response(int $httpCode, string $status, string $message, string $to, string $from, string $text, array $extra = []): array
    {
        return [
            'http_code' => $httpCode,
            'body' => array_merge([
                'id' => 'test_' . bin2hex(random_bytes(12)),
                'status' => $status,
                'message' => $message,
                'to' => $to,
                'from' => $from,
                'segments' => $this->segments($text),
                'test_mode' => true,
                'created_at' => gmdate('c'),
            ], $extra),
        ];
    }

    private function segments(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $unicode = preg_match('/[^\x00-\x7F]/', $text) === 1;
        $single = $unicode ? 70 : 160;
        $concatenated = $unicode ? 67 : 153;
        $length = mb_strlen($text);
        return $length <= $single ? 1 : (int)ceil($length / $concatenated);
    }
}
