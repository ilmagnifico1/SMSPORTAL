<?php

declare(strict_types=1);

final class MessageStore
{
    public function __construct(private string $file, private bool $logMessage = false, private int $maxBytes = 10485760)
    {
    }

    public function append(array $request, array $response, string $scenario, string $remoteIp): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Directory log non disponibile.');
        }
        $this->rotateIfNeeded();
        $to = (string)($request['to'] ?? $request['To'] ?? '');
        $text = (string)($request['text'] ?? $request['Body'] ?? '');
        $row = [
            'id' => (string)($response['id'] ?? ''),
            'created_at' => (string)($response['created_at'] ?? gmdate('c')),
            'scenario' => $scenario,
            'status' => (string)($response['status'] ?? 'unknown'),
            'http_code' => (int)($response['_http_code'] ?? 0),
            'remote_ip' => $remoteIp,
            'to_masked' => $this->maskPhone($to),
            'from' => substr((string)($request['from'] ?? $request['From'] ?? ''), 0, 100),
            'message_length' => mb_strlen($text),
            'message' => $this->logMessage ? $text : '',
        ];
        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || file_put_contents($this->file, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Impossibile registrare il messaggio di test.');
        }
    }

    public function latest(int $limit): array
    {
        return array_slice($this->rows(), 0, max(1, min($limit, 200)));
    }

    public function search(array $filters, int $limit = 50): array
    {
        $result = [];
        $outcome = strtolower(trim((string)($filters['outcome'] ?? 'all')));
        $scenario = strtolower(trim((string)($filters['scenario'] ?? '')));
        $query = mb_strtolower(trim((string)($filters['query'] ?? '')));

        foreach ($this->rows() as $row) {
            $successful = $this->isSuccessful($row);
            if (($outcome === 'success' && !$successful) || ($outcome === 'failed' && $successful)) {
                continue;
            }
            if ($scenario !== '' && (string)($row['scenario'] ?? '') !== $scenario) {
                continue;
            }
            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string)($row['id'] ?? ''),
                    (string)($row['status'] ?? ''),
                    (string)($row['to_masked'] ?? ''),
                    (string)($row['from'] ?? ''),
                    (string)($row['remote_ip'] ?? ''),
                ]));
                if (!str_contains($haystack, $query)) {
                    continue;
                }
            }
            $result[] = $row;
            if (count($result) >= max(1, min($limit, 200))) {
                break;
            }
        }
        return $result;
    }

    public function statistics(): array
    {
        $statistics = ['total' => 0, 'success' => 0, 'failed' => 0, 'scenarios' => []];
        foreach ($this->rows() as $row) {
            $statistics['total']++;
            $statistics[$this->isSuccessful($row) ? 'success' : 'failed']++;
            $scenario = (string)($row['scenario'] ?? 'unknown');
            $statistics['scenarios'][$scenario] = ($statistics['scenarios'][$scenario] ?? 0) + 1;
        }
        $statistics['success_rate'] = $statistics['total'] > 0
            ? round(($statistics['success'] / $statistics['total']) * 100, 1)
            : 0.0;
        return $statistics;
    }

    private function rows(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $rows = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function isSuccessful(array $row): bool
    {
        $httpCode = (int)($row['http_code'] ?? 0);
        return $httpCode >= 200 && $httpCode < 300 && (string)($row['status'] ?? '') === 'sent';
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?: '';
        return strlen($digits) <= 4 ? str_repeat('*', strlen($digits)) : str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    private function rotateIfNeeded(): void
    {
        if (!is_file($this->file) || filesize($this->file) < $this->maxBytes) {
            return;
        }
        $archive = $this->file . '.1';
        if (is_file($archive) && !unlink($archive)) {
            throw new RuntimeException('Impossibile ruotare il registro precedente.');
        }
        if (!rename($this->file, $archive)) {
            throw new RuntimeException('Impossibile ruotare il registro corrente.');
        }
    }
}
