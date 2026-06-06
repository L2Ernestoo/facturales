<?php

namespace Lc\Fel\Data;

class FelCompanyData
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $nit,
        public readonly string $regime = 'general',
        public readonly string $certifier = 'guatefacturas',
        public readonly string $mode = 'test',
        public readonly ?int $monthlyDteLimit = null,
        public readonly array $settings = [],
    ) {
    }
}
