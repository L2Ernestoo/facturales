<?php

namespace Lc\Fel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lc\Fel\Models\Concerns\UsesFelTable;

class FelAnnulment extends Model
{
    use UsesFelTable;

    protected static string $felTableConfigKey = 'annulments';

    protected $fillable = [
        'fel_document_id',
        'reason',
        'annulled_at',
        'status',
        'response_body',
        'error_message',
        'user_id',
        'metadata',
    ];

    protected $casts = [
        'annulled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(FelDocument::class, 'fel_document_id');
    }
}
