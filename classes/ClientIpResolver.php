<?php

declare(strict_types=1);

final class ClientIpResolver
{
    /** @return list<string> */
    public static function trustedProxies(): array
    {
        $configured = trim((string)getenv('SMS_TRUSTED_PROXIES'));
        if ($configured === '') {
            $configuredFile = trim((string)getenv('SMS_CONFIG_FILE'));
            $localConfigFile = $configuredFile !== ''
                ? $configuredFile
                : dirname(__DIR__) . '/storage/config.local.php';
            if (!is_file($localConfigFile)) {
                $localConfigFile = __DIR__ . '/config.local.php';
            }
            if (is_file($localConfigFile)) {
                $localConfig = (array)require $localConfigFile;
                $localProxies = $localConfig['trusted_proxies'] ?? [];
                $configured = is_array($localProxies)
                    ? implode(',', array_map('strval', $localProxies))
                    : trim((string)$localProxies);
            }
        }

        if ($configured === '') {
            $configured = '127.0.0.1/32,::1/128';
        }

        $ranges = [];
        foreach (explode(',', $configured) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && self::validRange($candidate)) {
                $ranges[] = $candidate;
            }
        }

        return array_values(array_unique($ranges));
    }

    /** @param array<string, mixed>|null $server @param list<string>|null $trustedProxies */
    public static function resolve(?array $server = null, ?array $trustedProxies = null): string
    {
        return self::details($server, $trustedProxies)['ip'];
    }

    /**
     * @param array<string, mixed>|null $server
     * @param list<string>|null $trustedProxies
     * @return array{ip:string, proxy_chain:string, remote_addr:string, via_proxy:bool}
     */
    public static function details(?array $server = null, ?array $trustedProxies = null): array
    {
        $server ??= $_SERVER;
        $trustedProxies ??= self::trustedProxies();
        $remote = self::validIp((string)($server['REMOTE_ADDR'] ?? ''));
        $trusted = $remote !== '' && self::matchesAny($remote, $trustedProxies);
        $forwarded = self::parseForwardedFor((string)($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        $realIp = self::validIp((string)($server['HTTP_X_REAL_IP'] ?? ''));
        $clientIp = $remote;

        if ($trusted && $forwarded !== []) {
            $chain = array_merge($forwarded, [$remote]);
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                if (!self::matchesAny($chain[$i], $trustedProxies)) {
                    $clientIp = $chain[$i];
                    break;
                }
            }
        } elseif ($trusted && $realIp !== '') {
            $clientIp = $realIp;
        }

        return [
            'ip' => $clientIp !== '' ? $clientIp : '0.0.0.0',
            'proxy_chain' => $trusted ? implode(', ', array_filter(array_merge($forwarded, [$remote]))) : '',
            'remote_addr' => $remote,
            'via_proxy' => $trusted && $clientIp !== $remote,
        ];
    }

    /** @param array<string, mixed>|null $server @param list<string>|null $trustedProxies */
    public static function isSecureRequest(?array $server = null, ?array $trustedProxies = null): bool
    {
        $server ??= $_SERVER;
        $directHttps = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off';
        if ($directHttps) {
            return true;
        }

        $trustedProxies ??= self::trustedProxies();
        $remote = self::validIp((string)($server['REMOTE_ADDR'] ?? ''));
        if ($remote === '' || !self::matchesAny($remote, $trustedProxies)) {
            return false;
        }

        $forwardedProto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        return $forwardedProto === 'https';
    }

    /** @param list<string> $ranges */
    private static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::ipInCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function parseForwardedFor(string $value): array
    {
        $result = [];
        foreach (explode(',', $value) as $candidate) {
            $ip = self::validIp(trim($candidate, " \t\n\r\0\x0B\"[]"));
            if ($ip !== '') {
                $result[] = $ip;
            }
        }
        return $result;
    }

    private static function validIp(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    private static function validRange(string $range): bool
    {
        if (!str_contains($range, '/')) {
            return self::validIp($range) !== '';
        }
        [$network, $prefix] = explode('/', $range, 2);
        if (self::validIp($network) === '' || !ctype_digit($prefix)) {
            return false;
        }
        $maxBits = str_contains($network, ':') ? 128 : 32;
        return (int)$prefix >= 0 && (int)$prefix <= $maxBits;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return hash_equals($cidr, $ip);
        }
        [$network, $prefix] = explode('/', $cidr, 2);
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }
        $bits = (int)$prefix;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($ipBinary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }
}
