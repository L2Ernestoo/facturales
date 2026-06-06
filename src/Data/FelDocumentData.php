<?php

namespace Lc\Fel\Data;

use Carbon\Carbon;

class FelDocumentData
{
    /**
     * @param array<int, FelItemData> $items
     */
    public function __construct(
        public readonly string $documentType,
        public readonly string $reference,
        public readonly Carbon $date,
        public readonly FelCompanyData $company,
        public readonly FelBranchData $branch,
        public readonly FelReceiverData $receiver,
        public readonly array $items,
        public readonly float $total,
        public readonly string $sourceType,
        public readonly int|string $sourceId,
        public readonly array $options = [],
    ) {
    }
}
