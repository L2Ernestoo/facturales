<?php

namespace Lc\Fel\Tests\Feature;

use Lc\Fel\Contracts\CertifierInterface;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Models\FelDocument;

class FakeCertifier implements CertifierInterface
{
    public int $certifyCalls = 0;

    public int $annulCalls = 0;

    public bool $succeed = true;

    public function certify(FelDocumentData $data, FelDocument $document): array
    {
        $this->certifyCalls++;

        if (!$this->succeed) {
            $document->update(['status' => FelDocument::STATUS_ERROR, 'error_message' => 'Falla simulada']);

            return ['success' => false, 'error' => 'Falla simulada'];
        }

        $document->update([
            'status' => FelDocument::STATUS_CERTIFIED,
            'fel_serie' => 'A1',
            'fel_preimpreso' => (string) (1000 + $this->certifyCalls),
            'fel_uuid' => 'UUID-' . $document->id . '-' . $this->certifyCalls,
            'certified_at' => now(),
        ]);

        return ['success' => true, 'data' => ['uuid' => $document->fel_uuid]];
    }

    public function annul(FelDocument $document, string $reason, array $context = []): array
    {
        $this->annulCalls++;

        return $this->succeed
            ? ['success' => true, 'data' => ['raw_response' => '<ok/>']]
            : ['success' => false, 'error' => 'Anulación rechazada'];
    }
}
