<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ClientIpResolver.php';

$failures = [];
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = sprintf('%s: atteso %s, ottenuto %s', $label, var_export($expected, true), var_export($actual, true));
    }
};

$trusted = ['10.8.3.22/32'];
$assertSame('proxy esatto', '203.0.113.77', ClientIpResolver::resolve([
    'REMOTE_ADDR' => '10.8.3.22',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
    'HTTP_X_REAL_IP' => '203.0.113.77',
], $trusted));
$assertSame('header spoofato da sorgente non attendibile', '198.51.100.44', ClientIpResolver::resolve([
    'REMOTE_ADDR' => '198.51.100.44',
    'HTTP_X_FORWARDED_FOR' => '10.8.3.22',
    'HTTP_X_REAL_IP' => '10.8.3.22',
], $trusted));
$assertSame('catena proxy con prefisso spoofato', '203.0.113.77', ClientIpResolver::resolve([
    'REMOTE_ADDR' => '10.8.3.22',
    'HTTP_X_FORWARDED_FOR' => '192.0.2.99, 203.0.113.77',
], $trusted));
$assertSame('HTTPS dal proxy attendibile', true, ClientIpResolver::isSecureRequest([
    'REMOTE_ADDR' => '10.8.3.22',
    'HTTP_X_FORWARDED_PROTO' => 'https',
], $trusted));
$assertSame('HTTPS spoofato', false, ClientIpResolver::isSecureRequest([
    'REMOTE_ADDR' => '198.51.100.44',
    'HTTP_X_FORWARDED_PROTO' => 'https',
], $trusted));
$assertSame('IPv6', '2001:db8::123', ClientIpResolver::resolve([
    'REMOTE_ADDR' => '::1',
    'HTTP_X_FORWARDED_FOR' => '2001:db8::123',
], ['::1/128']));

$firewallSource = (string)file_get_contents(dirname(__DIR__) . '/classes/AppFirewall.php');
$assertSame('nessun bypass implicito 10.8/16', false, str_contains($firewallSource, "ipInCidr(\$ip, '10.8.0.0/16')"));

if ($failures !== []) {
    fwrite(STDERR, "Firewall self-test fallito:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Firewall self-test: OK\n";
