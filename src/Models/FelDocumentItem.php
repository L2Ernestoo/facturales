<?php

namespace Lc\Fel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lc\Fel\Models\Concerns\UsesFelTable;

class FelDocumentItem extends Model
{
    use UsesFelTable;

    protected static string $felTableConfigKey = 'document_items';

    protected $fillable = [
        'fel_document_id',
        'source_item_id',
        'product_code',
        'description',
        'measure',
        'quantity',
        'unit_price',
        'discount',
        'line_total',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'unit_price' => 'decimal:7',
        'discount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(FelDocument::class, 'fel_document_id');
    }
}
