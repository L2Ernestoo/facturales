<?php

namespace Lc\Fel\Contracts;

use Lc\Fel\Data\FelDocumentData;

interface FelSourceMapperInterface
{
    public function map(mixed $source, array $options = []): FelDocumentData;
}
