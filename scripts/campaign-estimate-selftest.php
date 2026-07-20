<?php

require_once dirname(__DIR__) . '/classes/CreditManager.php';

function assertEstimate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE company_credits (company_id INTEGER PRIMARY KEY, balance DECIMAL(14,4), billing_enabled INTEGER)');
$pdo->exec('CREATE TABLE provider_credits (provider_id INTEGER PRIMARY KEY, balance DECIMAL(14,4), credit_control_enabled INTEGER)');
$pdo->exec('CREATE TABLE company_provider_prices (company_id INTEGER, provider_id INTEGER, prefix TEXT, destination TEXT, operator_name TEXT, sale_price DECIMAL(10,4), active INTEGER)');
$pdo->exec('CREATE TABLE provider_prefix_costs (provider_id INTEGER, prefix TEXT, destination TEXT, operator_name TEXT, purchase_price DECIMAL(10,4), active INTEGER)');
$pdo->exec("INSERT INTO company_credits VALUES (1, 0.5000, 1)");
$pdo->exec("INSERT INTO provider_credits VALUES (7, 1.0000, 1)");
$pdo->exec("INSERT INTO company_provider_prices VALUES (1, 7, '39320', 'Italia', 'Operatore test', 0.0500, 1), (1, 7, '*', 'Altri Paesi', '', 0.1000, 1)");
$pdo->exec("INSERT INTO provider_prefix_costs VALUES (7, '39320', 'Italia', 'Operatore test', 0.0200, 1), (7, '*', 'Altri Paesi', '', 0.0400, 1)");

$reflection = new ReflectionClass(CreditManager::class);
/** @var CreditManager $credits */
$credits = $reflection->newInstanceWithoutConstructor();
$pdoProperty = $reflection->getProperty('pdo');
$pdoProperty->setValue($credits, $pdo);
$message = str_repeat('A', 161);
$recipients = [['phone' => '+39 320 1234567'], ['phone' => '+40 712 345678']];

$estimate = $credits->estimateCampaign(1, 7, $recipients, $message);
assertEstimate($estimate['can_start'] === true, 'La campagna finanziabile risulta bloccata.');
assertEstimate((int)$estimate['recipient_count'] === 2 && (int)$estimate['quoted_count'] === 2, 'Conteggio destinatari errato.');
assertEstimate((int)$estimate['segments'] === 4, 'Conteggio segmenti errato.');
assertEstimate(abs((float)$estimate['expected_cost'] - 0.3000) < 0.00001, 'Spesa prevista errata.');
assertEstimate(count((array)$estimate['breakdown']) === 2, 'Raggruppamento Paese/operatore errato.');

$pdo->exec('UPDATE company_credits SET balance = 0.2900 WHERE company_id = 1');
$insufficient = $credits->estimateCampaign(1, 7, $recipients, $message);
assertEstimate($insufficient['can_start'] === false && $insufficient['company_credit_sufficient'] === false, 'Il credito insufficiente non blocca la campagna.');

$pdo->exec("DELETE FROM company_provider_prices WHERE prefix = '*'");
$missingPrice = $credits->estimateCampaign(1, 7, $recipients, $message);
assertEstimate((int)$missingPrice['quoted_count'] === 1 && count((array)$missingPrice['errors']) === 1, 'La tariffa mancante non viene rilevata.');

echo "Campaign estimate self-test: OK\n";
