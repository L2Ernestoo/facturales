<?php

namespace Lc\Fel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Lc\Fel\Contracts\CertifierInterface;
use Lc\Fel\Contracts\FelConfigRepositoryInterface;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Models\FelAnnulment;
use Lc\Fel\Models\FelDocument;

class FelManager
{
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

        $document = DB::transaction(function () use ($data) {
            $document = FelDocument::query()->create([
                'fel_company_id' => $data->company->id,
                'fel_branch_setting_id' => $data->branch->id,
                'source_type' => $data->sourceType,
                'source_id' => (string) $data->sourceId,
                'document_type' => $data->documentType,
                'status' => FelDocument::STATUS_PENDING,
                'reference' => $data->reference,
                'buyer_nit' => $data->receiver->nit,
                'buyer_name' => $data->receiver->name,
                'buyer_address' => $data->receiver->address,
                'total' => $data->total,
                'metadata' => ['options' => $data->options],
            ]);

            foreach ($data->items as $item) {
                $document->items()->create([
                    'source_item_id' => $item->metadata['source_item_id'] ?? null,
                    'product_code' => $item->code,
                    'description' => $item->description,
                    'measure' => $item->measure,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unitPrice,
                    'discount' => $item->discount,
                    'line_total' => round($item->quantity * $item->unitPrice - $item->discount, 2),
                    'metadata' => $item->metadata,
                ]);
            }

            return $document;
        });

        return $this->certifyExisting($document, $data);
    }

    public function certifyExisting(FelDocument $document, FelDocumentData $data): array
    {
        $result = $this->certifier->certify($data, $document);

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
            'debug' => $result['debug'] ?? [],
        ];
    }

    public function annul(FelDocument $document, string $reason, array $context = []): array
    {
        $annulment = FelAnnulment::query()->create([
            'fel_document_id' => $document->id,
            'reason' => $reason,
            'status' => 'PENDING',
            'user_id' => $context['user_id'] ?? null,
        ]);

        $result = $this->certifier->annul($document, $reason, $context);

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
    }
}
