<?php

namespace Lc\Fel\Data;

class FelItemData
{
    public function __construct(
        public readonly string $code,
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unitPrice,
        public readonly string $measure = '1',
        public readonly float $discount = 0.0,
        public readonly array $metadata = [],
    ) {
    }
}
