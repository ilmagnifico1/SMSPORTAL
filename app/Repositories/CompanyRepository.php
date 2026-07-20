<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Company;
use CreditManager;
use SmsApp;

final class CompanyRepository
{
    public function __construct(
        private SmsApp $app,
        private CreditManager $credits
    ) {
    }

    public function all(): array
    {
        return array_map(
            static fn(array $row): Company => Company::fromArray($row),
            $this->app->getCompanies()
        );
    }

    public function find(int $id): ?Company
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->app->getCompanyById($id);
        return $row ? Company::fromArray($row) : null;
    }

    public function save(array $data): bool
    {
        return $this->app->saveCompany($data);
    }

    public function allProviders(): array
    {
        return $this->app->getProviders(['active' => 'all']);
    }

    public function providerIds(int $companyId): array
    {
        return $this->app->getCompanyProviderIds($companyId);
    }

    public function financials(): array
    {
        return $this->credits->getCompanyFinancials();
    }
}
