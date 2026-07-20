<?php
require_once dirname(__DIR__) . '/classes/DeviceAuthManager.php';

$message = "SMS-AUTH-V1\n" . str_repeat('a', 48) . "\nchallenge\n" . str_repeat('b', 64) . "\n2000000000\n123e4567-e89b-42d3-a456-426614174000";
if (hash('sha256', $message) !== 'e2b8e27be801beb3b37beff41b8fcf1d2577944ccef1f545f5d80c67e6215583') throw new RuntimeException('Messaggio di test non coerente.');
$jwk = [
    'kty' => 'EC',
    'crv' => 'P-256',
    'x' => '_RZXUuE_WHRIiuMBIMvYCCh-FYhBNJzOOH0e_NJsk-Y',
    'y' => 'IdQmS-38kvzyi_op5GYX1PchVjaxiAU5h_ulVZK0-5o',
    'ext' => true,
];
$rawSignature = 'jMheoYwvmG9COUN5GocotzRe-9SAlIf3EXCCnKGUrWbakdR7_2dpFfYeSEJfIcN9zwpiiy2awq6VkUcpVuYlRg';

$reflection = new ReflectionClass(DeviceAuthManager::class);
$manager = $reflection->newInstanceWithoutConstructor();
$decode = $reflection->getMethod('base64UrlDecode');
$toDer = $reflection->getMethod('rawEcdsaToDer');
$rawBytes = $decode->invoke($manager, $rawSignature);
if (!is_string($rawBytes) || strlen($rawBytes) !== 64) throw new RuntimeException('Decodifica firma raw non valida.');
$derBytes = $toDer->invoke($manager, $rawBytes);
if (bin2hex($derBytes) !== '30460221008cc85ea18c2f986f423943791a8728b7345efbd4809487f71170829ca194ad66022100da91d47bff676915f61e48425f21c37dcf0a628b2d9ac2ae9591472956e62546') {
    throw new RuntimeException('Conversione firma raw/DER non valida.');
}
$x = $decode->invoke($manager, $jwk['x']);
$y = $decode->invoke($manager, $jwk['y']);
$prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004');
$spki = base64_encode($prefix . $x . $y);
if ($spki !== 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE/RZXUuE/WHRIiuMBIMvYCCh+FYhBNJzOOH0e/NJsk+Yh1CZL7fyS/PKL+inkZhfU9yFWNrGIBTmH+6VVkrT7mg==') {
    throw new RuntimeException('Conversione JWK/SPKI non valida: ' . $spki);
}
$pem = "-----BEGIN PUBLIC KEY-----\r\n" . chunk_split($spki, 64, "\r\n") . "-----END PUBLIC KEY-----\r\n";
$directKey = openssl_pkey_get_public($pem);
if ($directKey === false) throw new RuntimeException('Importazione diretta SPKI fallita.');
$directResult = openssl_verify($message, $derBytes, $directKey, OPENSSL_ALGO_SHA256);
if ($directResult !== 1) {
    $rawResult = openssl_verify($message, $rawBytes, $directKey, OPENSSL_ALGO_SHA256);
    throw new RuntimeException('Verifica diretta SPKI fallita (DER/raw): ' . $directResult . '/' . $rawResult);
}
$verify = $reflection->getMethod('verifySignature');
$verified = $verify->invoke($manager, $jwk, $message, $rawSignature);
if ($verified !== true) {
    $errors = [];
    while (($error = openssl_error_string()) !== false) $errors[] = $error;
    throw new RuntimeException('Verifica della firma WebCrypto raw fallita.' . ($errors ? ' OpenSSL: ' . implode(' | ', $errors) : ''));
}

echo "Device authorization crypto self-test: OK\n";
