<?php

require_once dirname(__DIR__) . '/classes/ClientIpResolver.php';

return [
    // In produzione è preferibile impostare SMS_TRUSTED_PROXIES con gli IP/CIDR
    // esatti di Nginx Proxy Manager, separati da virgola.
    'trusted_proxies' => ClientIpResolver::trustedProxies(),
    'geo_enabled' => strtolower((string)getenv('SMS_IP_GEO_ENABLED')) !== 'false',
    'geo_endpoint' => 'https://ipwho.is/%s?fields=success,country,country_code',
    'geo_cache_days' => 30,
];
