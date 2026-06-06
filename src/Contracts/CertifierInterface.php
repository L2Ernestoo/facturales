<?php

namespace Lc\Fel\Contracts;

use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Models\FelDocument;

interface CertifierInterface
{
    public function certify(FelDocumentData $data, FelDocument $document): array;

    public function annul(FelDocument $document, string $reason, array $context = []): array;
}
