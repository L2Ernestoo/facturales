<?php

namespace Lc\Fel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lc\Fel\Models\Concerns\UsesFelTable;

class FelDocument extends Model
{
    use UsesFelTable;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CERTIFIED = 'CERTIFIED';
    public const STATUS_ERROR = 'ERROR';
    public const STATUS_ANNULLED = 'ANNULLED';

    protected static string $felTableConfigKey = 'documents';

    protected $fillable = [
        'fel_company_id',
        'fel_branch_setting_id',
        'source_type',
        'source_id',
        'document_type',
        'status',
        'reference',
        'buyer_nit',
        'buyer_name',
        'buyer_address',
        'total',
        'currency',
        'fel_serie',
        'fel_preimpreso',
        'fel_uuid',
        'request_xml',
        'response_body',
        'soap_request',
        'certified_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'certified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(FelCompany::class, 'fel_company_id');
    }

    public function branchSetting(): BelongsTo
    {
        return $this->belongsTo(FelBranchSetting::class, 'fel_branch_setting_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FelDocumentItem::class, 'fel_document_id');
    }
}
