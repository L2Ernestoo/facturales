<?php

namespace Lc\Fel\Data;

class FelReceiverData
{
    public function __construct(
        public readonly string $nit = 'CF',
        public readonly string $name = 'Consumidor Final',
        public readonly string $address = 'Ciudad',
        public readonly ?string $email = null,
        public readonly ?string $type = null,
    ) {
    }
}
