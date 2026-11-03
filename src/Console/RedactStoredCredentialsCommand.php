<?php

namespace Lc\Fel\Console;

use Illuminate\Console\Command;
use Lc\Fel\Models\FelDocument;
use Lc\Fel\Services\Certifiers\GuatefacturasCertifier;

/**
 * Versiones anteriores a 1.1.0 guardaban el envelope SOAP con el password FEL
 * en texto plano. Este comando lo reemplaza en los documentos ya guardados.
 */
class RedactStoredCredentialsCommand extends Command
{
    protected $signature = 'fel:redact-credentials {--dry-run : Solo cuenta los documentos afectados}';

    protected $description = 'Elimina el password FEL de los envelopes SOAP guardados en documentos FEL.';

    public function handle(): int
    {
        $query = FelDocument::query()
            ->where('soap_request', 'like', '%<pPassword>%')
            ->where('soap_request', 'not like', '%<pPassword>********</pPassword>%');

        $count = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info("Documentos con password visible: {$count}");

            return self::SUCCESS;
        }

        $updated = 0;
        $query->select(['id', 'soap_request'])->chunkById(200, function ($documents) use (&$updated) {
            foreach ($documents as $document) {
                FelDocument::query()->whereKey($document->id)->update([
                    'soap_request' => GuatefacturasCertifier::redactCredentials((string) $document->soap_request),
                ]);
                $updated++;
            }
        });

        $this->info("Documentos limpiados: {$updated}");

        return self::SUCCESS;
    }
}
