<?php

namespace Lc\Fel\Services;

use InvalidArgumentException;
use Lc\Fel\Contracts\DocumentBuilderInterface;
use Lc\Fel\Services\Builders\FacturaEspecialBuilder;
use Lc\Fel\Services\Builders\GeneralDocumentBuilder;

class DocumentBuilderFactory
{
    public function make(string $documentType): DocumentBuilderInterface
    {
        $documentType = strtoupper(trim($documentType));
        $supported = config('fel.document_types', []);

        if (!in_array($documentType, $supported, true)) {
            throw new InvalidArgumentException("El tipo de documento FEL '{$documentType}' no es soportado.");
        }

        return match ($documentType) {
            'FESP' => new FacturaEspecialBuilder(),
            default => new GeneralDocumentBuilder($documentType),
        };
    }
}
