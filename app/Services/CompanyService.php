<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompanyRepository;

final class CompanyService
{
    public function __construct(private CompanyRepository $companies)
    {
    }

    public function pageData(?int $editingId, array $submittedData = []): array
    {
        $editingCompany = $editingId ? $this->companies->find($editingId) : null;
        $providers = $this->companies->allProviders();
        $financials = $this->companies->financials();
        $rows = [];

        foreach ($this->companies->all() as $company) {
            $companyId = $company->id;
            $providerIds = $this->companies->providerIds($companyId);
            $providerNames = [];
            foreach ($providers as $provider) {
                if (in_array((int)$provider['id'], $providerIds, true)) {
                    $providerNames[] = (string)$provider['name'];
                }
            }
            $financial = $financials[$companyId] ?? [
                'credit_balance' => 0.0,
                'profit_total' => 0.0,
            ];
            $company->providerNames = $providerNames;
            $company->creditBalance = (float)$financial['credit_balance'];
            $company->profitTotal = (float)$financial['profit_total'];
            $rows[] = $company;
        }

        $submitted = $submittedData !== [];
        $selectedProviderIds = $submitted
            ? array_values(array_unique(array_map('intval', (array)($submittedData['provider_ids'] ?? []))))
            : ($editingCompany ? $this->companies->providerIds($editingCompany->id) : []);

        return [
            'companies' => $rows,
            'editingCompany' => $editingCompany,
            'allProviders' => $providers,
            'selectedProviderIds' => $selectedProviderIds,
            'submitted' => $submitted,
        ];
    }

    public function save(array $data): bool
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return false;
        }

        $saved = $this->companies->save($data);
        $message = $saved
            ? 'Azienda salvata.'
            : 'Impossibile salvare l’azienda. Verifica che il nome non sia già utilizzato.';
        \system_log(
            $saved ? 'info' : 'error',
            'company',
            $saved ? 'company.saved' : 'company.save_failed',
            $message,
            [
                'company_id' => (int)($data['id'] ?? 0),
                'company_name' => $name,
            ]
        );

        return $saved;
    }
}
