<?php

namespace Lc\Fel\Data;

class FelBranchData
{
    public function __construct(
        public readonly ?int $id,
        public readonly int|string|null $sourceBranchId,
        public readonly ?int $companyId,
        public readonly string $name,
        public readonly ?string $address = null,
        public readonly string $establishmentCode = '1',
        public readonly ?string $logoPath = null,
        public readonly array $settings = [],
    ) {
    }
}
