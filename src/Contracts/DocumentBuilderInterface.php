<?php

namespace Lc\Fel\Contracts;

use Lc\Fel\Data\FelDocumentData;

interface DocumentBuilderInterface
{
    public function build(FelDocumentData $data): string;
}
