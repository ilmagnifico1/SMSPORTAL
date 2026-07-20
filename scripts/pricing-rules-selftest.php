<?php

require_once dirname(__DIR__) . '/classes/CreditManager.php';

$reflection = new ReflectionClass(CreditManager::class);
/** @var CreditManager $manager */
$manager = $reflection->newInstanceWithoutConstructor();
$matcher = $reflection->getMethod('matchPrefix');
$builder = $reflection->getMethod('pricingRuleFromInput');

$prices = [
    ['prefix' => '*', 'name' => 'globale'],
    ['prefix' => '39', 'name' => 'Italia'],
    ['prefix' => '39:320-329', 'name' => 'intervallo'],
    ['prefix' => '39320', 'name' => 'sotto-prefisso'],
];
$cases = [
    '393201234567' => 'sotto-prefisso',
    '393251234567' => 'intervallo',
    '393301234567' => 'Italia',
    '447700900000' => 'globale',
];

foreach ($cases as $number => $expected) {
    $matched = $matcher->invoke($manager, $prices, $number);
    if ((string)($matched['name'] ?? '') !== $expected) {
        throw new RuntimeException('Regola errata per il numero ' . $number . '.');
    }
}

$range = $builder->invoke($manager, [
    'match_type' => 'range',
    'country_prefix' => '39',
    'range_start' => '320',
    'range_end' => '329',
]);
$subprefix = $builder->invoke($manager, [
    'match_type' => 'subprefix',
    'country_prefix' => '39',
    'national_prefix' => '347',
]);

if ($range !== '39:320-329' || $subprefix !== '39347') {
    throw new RuntimeException('Normalizzazione delle regole tariffarie non valida.');
}

echo "Pricing rules self-test: OK\n";
