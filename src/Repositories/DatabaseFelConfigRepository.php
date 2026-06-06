<?php

namespace Lc\Fel\Repositories;

use Lc\Fel\Contracts\FelConfigRepositoryInterface;
use Lc\Fel\Models\FelBranchSetting;
use Lc\Fel\Models\FelCompany;
use Lc\Fel\Models\FelCompanyCredential;

class DatabaseFelConfigRepository implements FelConfigRepositoryInterface
{
    public function company(?int $companyId = null): ?FelCompany
    {
        $query = FelCompany::query()->where('is_active', true);

        if ($companyId !== null) {
            return $query->whereKey($companyId)->first();
        }

        return $query->orderBy('id')->first();
    }

    public function branchSetting(int|string|null $branchSourceId, ?int $companyId = null): ?FelBranchSetting
    {
        $company = $this->company($companyId);
        if (!$company) {
            return null;
        }

        $query = FelBranchSetting::query()
            ->where('fel_company_id', $company->id)
            ->where('is_active', true);

        if ($branchSourceId !== null && $branchSourceId !== '') {
            $branch = (clone $query)->where('branch_source_id', $branchSourceId)->first();
            if ($branch) {
                return $branch;
            }
        }

        return $query->whereNull('branch_source_id')->first();
    }

    public function credentials(FelCompany $company): ?FelCompanyCredential
    {
        return $company->credential()->first();
    }

    public function posSettings(int|string|null $branchSourceId = null): array
    {
        $company = $this->company();
        $branch = $company ? $this->branchSetting($branchSourceId, $company->id) : null;

        return [
            'enabled' => (bool) ($company?->show_pos_switch ?? config('fel.defaults.show_pos_switch', false)),
            'auto_mark' => (bool) ($company?->auto_mark_pos_switch ?? config('fel.defaults.auto_mark_pos_switch', false)),
            'default_document_type' => $company?->default_document_type ?: config('fel.defaults.document_type', 'FACT'),
            'company_id' => $company?->id,
            'branch_setting_id' => $branch?->id,
            'monthly_limit' => $company?->monthly_dte_limit,
            'document_types' => config('fel.document_types', []),
            'defaults' => config('fel.defaults.receiver', []),
        ];
    }
}
