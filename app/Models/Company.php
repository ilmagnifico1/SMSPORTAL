<?php

declare(strict_types=1);

namespace App\Models;

final class Company
{
    public int $id;
    public string $name;
    public bool $active;
    public string $createdAt;
    public array $providerNames = [];
    public float $creditBalance = 0.0;
    public float $profitTotal = 0.0;

    public static function fromArray(array $row): self
    {
        $company = new self();
        $company->id = (int)($row['id'] ?? 0);
        $company->name = (string)($row['name'] ?? '');
        $company->active = (int)($row['active'] ?? 0) === 1;
        $company->createdAt = (string)($row['created_at'] ?? '');
        return $company;
    }
}
