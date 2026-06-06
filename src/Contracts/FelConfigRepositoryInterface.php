<?php

namespace Lc\Fel\Contracts;

use Lc\Fel\Models\FelBranchSetting;
use Lc\Fel\Models\FelCompany;
use Lc\Fel\Models\FelCompanyCredential;

interface FelConfigRepositoryInterface
{
    public function company(?int $companyId = null): ?FelCompany;

    public function branchSetting(int|string|null $branchSourceId, ?int $companyId = null): ?FelBranchSetting;

    public function credentials(FelCompany $company): ?FelCompanyCredential;

    public function posSettings(int|string|null $branchSourceId = null): array;
}
