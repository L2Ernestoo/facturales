<?php

namespace Lc\Fel\Services;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lc\Fel\Contracts\CertifierInterface;
use Lc\Fel\Contracts\FelConfigRepositoryInterface;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Data\FelItemData;
use Lc\Fel\Models\FelAnnulment;
use Lc\Fel\Models\FelCompany;
use Lc\Fel\Models\FelDocument;
use Throwable;

class FelManager
{
    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly CertifierInterface $certifier,
        private readonly FelConfigRepositoryInterface $configRepository,
    ) {
    }

    public function posSettings(int|string|null $branchSourceId = null): array
    {
        $settings = $this->configRepository->posSettings($branchSourceId);
        $settings['usage'] = $settings['company_id']
            ? $this->monthlyUsage((int) $settings['company_id'])
            : ['limit' => null, 'used' => 0, 'remaining' => null, 'percent' => 0];

        return $settings;
    }

    public function monthlyUsage(?int $companyId = null, ?Carbon $month = null): array
    {
        $company = $this->configRepository->company($companyId);
        if (!$company) {
            return ['limit' => null, 'used' => 0, 'remaining' => null, 'percent' => 0];
        }

        $month = $month ?: Carbon::now(config('fel.timezone', 'America/Guatemala'));
        $used = FelDocument::query()
            ->where('fel_company_id', $company->id)
            ->where('status', FelDocument::STATUS_CERTIFIED)
            ->whereBetween('certified_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->count();

        $limit = $company->monthly_dte_limit;

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'percent' => $limit ? min(100, round(($used / $limit) * 100, 2)) : 0,
        ];
    }

    public function issue(FelDocumentData $data): array
    {
        $usage = $this->monthlyUsage($data->company->id);
        if ($usage['limit'] !== null && $usage['used'] >= $usage['limit']) {
            return [
                'success' => false,
                'blocked' => true,
                'error' => 'Se alcanzó el límite mensual de DTE configurado.',
                'usage' => $usage,
            ];
        }

        $totalMismatch = $this->totalMismatch($data);
        if ($totalMismatch !== null && config('fel.strict_totals', false)) {
            return [
                'success' => false,
                'blocked' => true,
                'error' => sprintf(
                    'El total enviado (%.2f) no coincide con la suma de los items (%.2f).',
                    $totalMismatch['declared'],
                    $totalMismatch['items']
                ),
            ];
        }

        $document = DB::transaction(function () use ($data, $totalMismatch) {
            $document = FelDocument::query()->create([
                'fel_company_id' => $data->company->id,
                'fel_branch_setting_id' => $data->branch->id,
                'source_type' => $data->sourceType,
                'source_id' => (string) $data->sourceId,
                'status' => FelDocument::STATUS_PENDING,
                ...$this->documentAttributes($data),
                'metadata' => array_filter([
                    'options' => $data->options,
                    'total_mismatch' => $totalMismatch,
                ], fn ($value) => $value !== null),
            ]);

            foreach ($data->items as $item) {
                $document->items()->create($this->itemAttributes($item));
            }

            return $document;
        });

        return $this->certify($document, $data, false);
    }

    public function certifyExisting(FelDocument $document, FelDocumentData $data): array
    {
        return $this->certify($document, $data, true);
    }

    private function certify(FelDocument $document, FelDocumentData $data, bool $syncSnapshot): array
    {
        $document->refresh();

        // Un DTE certificado no se vuelve a enviar: el certificador emitiría otro.
        if ($document->status === FelDocument::STATUS_CERTIFIED) {
            return $this->alreadyCertifiedResult($document);
        }
        if ($document->status === FelDocument::STATUS_ANNULLED) {
            return [
                'success' => false,
                'document' => $document->fresh(['items']),
                'error' => 'El DTE fue anulado; emite un documento nuevo en lugar de reintentar.',
                'debug' => [],
            ];
        }

        return $this->withDocumentLock($document, 'certify', function () use ($document, $data, $syncSnapshot) {
            // Otro proceso pudo certificarlo mientras esperábamos.
            $document->refresh();
            if ($document->status === FelDocument::STATUS_CERTIFIED) {
                return $this->alreadyCertifiedResult($document);
            }

            if ($syncSnapshot) {
                $this->syncDocument($document, $data);
            }

            $result = $this->certifierFor($document->company)->certify($data, $document);

            if (($result['success'] ?? false) === true) {
                return [
                    'success' => true,
                    'document' => $document->fresh(['items']),
                    'data' => $result['data'] ?? [],
                ];
            }

            return [
                'success' => false,
                'document' => $document->fresh(['items']),
                'error' => $result['error'] ?? 'No se pudo certificar el DTE.',
                'uncertain' => (bool) ($result['uncertain'] ?? false),
                'debug' => $result['debug'] ?? [],
            ];
        }, 'El DTE ya se está certificando en otro proceso. Espera unos segundos y revisa su estado.');
    }

    public function annul(FelDocument $document, string $reason, array $context = []): array
    {
        $document->refresh();

        $precondition = match (true) {
            $document->status === FelDocument::STATUS_ANNULLED => 'El DTE ya fue anulado.',
            $document->status !== FelDocument::STATUS_CERTIFIED => 'Solo se pueden anular DTE certificados.',
            blank($document->fel_serie) || blank($document->fel_preimpreso) => 'El DTE no tiene serie y preimpreso para anularlo.',
            default => null,
        };
        if ($precondition !== null) {
            return ['success' => false, 'annulment' => null, 'error' => $precondition];
        }

        return $this->withDocumentLock($document, 'annul', function () use ($document, $reason, $context) {
            $document->refresh();
            if ($document->status === FelDocument::STATUS_ANNULLED) {
                return ['success' => false, 'annulment' => null, 'error' => 'El DTE ya fue anulado.'];
            }

            $annulment = FelAnnulment::query()->create([
                'fel_document_id' => $document->id,
                'reason' => $reason,
                'status' => 'PENDING',
                'user_id' => $context['user_id'] ?? null,
            ]);

            $result = $this->certifierFor($document->company)->annul($document, $reason, $context);

            if (($result['success'] ?? false) === true) {
                $annulment->update([
                    'status' => 'ANNULLED',
                    'annulled_at' => Carbon::now(config('fel.timezone', 'America/Guatemala')),
                    'response_body' => $result['data']['raw_response'] ?? json_encode($result['data'] ?? []),
                ]);

                $document->update(['status' => FelDocument::STATUS_ANNULLED]);

                return ['success' => true, 'annulment' => $annulment->fresh(), 'data' => $result['data'] ?? []];
            }

            $annulment->update([
                'status' => 'ERROR',
                'error_message' => $result['error'] ?? 'No se pudo anular el DTE.',
                'response_body' => $result['data']['raw_response'] ?? null,
            ]);

            return ['success' => false, 'annulment' => $annulment->fresh(), 'error' => $annulment->error_message];
        }, 'El DTE ya se está anulando en otro proceso. Espera unos segundos y revisa su estado.');
    }

    /**
     * Usa el certificador configurado para la empresa del documento. Si la app
     * registró su propio CertifierInterface (por ejemplo un fake en pruebas),
     * se respeta ese binding.
     */
    private function certifierFor(?FelCompany $company): CertifierInterface
    {
        $certifiers = config('fel.certifiers', []);
        $class = $company?->certifier ? ($certifiers[$company->certifier] ?? null) : null;

        if (!$class || $this->certifier instanceof $class) {
            return $this->certifier;
        }
        if (!in_array(get_class($this->certifier), $certifiers, true)) {
            return $this->certifier;
        }

        return Container::getInstance()->make($class);
    }

    private function alreadyCertifiedResult(FelDocument $document): array
    {
        return [
            'success' => true,
            'already_certified' => true,
            'document' => $document->fresh(['items']),
            'data' => [
                'uuid' => $document->fel_uuid,
                'serie' => $document->fel_serie,
                'preimpreso' => $document->fel_preimpreso,
            ],
        ];
    }

    /**
     * El snapshot guardado debe reflejar lo que realmente se envía al
     * certificador, también cuando se reintenta con datos corregidos.
     */
    private function syncDocument(FelDocument $document, FelDocumentData $data): void
    {
        DB::transaction(function () use ($document, $data) {
            $metadata = $document->metadata ?: [];
            $metadata['options'] = $data->options;
            unset($metadata['total_mismatch']);

            $mismatch = $this->totalMismatch($data);
            if ($mismatch !== null) {
                $metadata['total_mismatch'] = $mismatch;
            }

            $document->update([
                ...$this->documentAttributes($data),
                'metadata' => $metadata,
            ]);

            $document->items()->delete();
            foreach ($data->items as $item) {
                $document->items()->create($this->itemAttributes($item));
            }
        });
    }

    private function documentAttributes(FelDocumentData $data): array
    {
        return [
            'document_type' => $data->documentType,
            'reference' => $data->reference,
            'buyer_nit' => $data->receiver->nit,
            'buyer_name' => $data->receiver->name,
            'buyer_address' => $data->receiver->address,
            'total' => $data->total,
        ];
    }

    private function itemAttributes(FelItemData $item): array
    {
        return [
            'source_item_id' => $item->metadata['source_item_id'] ?? null,
            'product_code' => $item->code,
            'description' => $item->description,
            'measure' => $item->measure,
            'quantity' => $item->quantity,
            'unit_price' => $item->unitPrice,
            'discount' => $item->discount,
            'line_total' => round($item->quantity * $item->unitPrice - $item->discount, 2),
            'metadata' => $item->metadata,
        ];
    }

    /**
     * Compara el total declarado con la suma de los items, que es lo que
     * realmente se certifica. FESP calcula su propio total.
     */
    private function totalMismatch(FelDocumentData $data): ?array
    {
        if (strtoupper($data->documentType) === 'FESP') {
            return null;
        }

        $itemsTotal = round(array_sum(array_map(
            fn (FelItemData $item) => round($item->quantity * $item->unitPrice, 2) - round($item->discount, 2),
            $data->items
        )), 2);
        $declared = round($data->total, 2);

        if (abs($itemsTotal - $declared) <= 0.01) {
            return null;
        }

        Log::warning('FEL document total does not match items total', [
            'source_type' => $data->sourceType,
            'source_id' => $data->sourceId,
            'declared' => $declared,
            'items' => $itemsTotal,
        ]);

        return ['declared' => $declared, 'items' => $itemsTotal];
    }

    /**
     * Evita que dos peticiones simultáneas (doble clic, reintentos) certifiquen
     * o anulen el mismo documento. Si el cache de la app no soporta locks, se
     * continúa sin lock como en versiones anteriores.
     */
    private function withDocumentLock(FelDocument $document, string $action, callable $callback, string $busyMessage): array
    {
        $lock = null;
        try {
            $key = sprintf(
                'fel:%s:%s:%s:%s',
                $action,
                $document->getConnection()->getDatabaseName(),
                $document->getTable(),
                $document->getKey()
            );
            $lock = Cache::lock($key, self::LOCK_SECONDS);
            if (!$lock->get()) {
                return [
                    'success' => false,
                    'document' => $document->fresh(['items']),
                    'error' => $busyMessage,
                    'busy' => true,
                    'debug' => [],
                ];
            }
        } catch (Throwable) {
            $lock = null;
        }

        try {
            return $callback();
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
                // El lock expira solo; no debe ocultar el resultado.
            }
        }
    }
}
