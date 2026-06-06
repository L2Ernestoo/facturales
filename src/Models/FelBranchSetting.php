<?php

namespace Lc\Fel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lc\Fel\Models\Concerns\UsesFelTable;

class FelBranchSetting extends Model
{
    use UsesFelTable;

    protected static string $felTableConfigKey = 'branch_settings';

    protected $fillable = [
        'fel_company_id',
        'branch_source_id',
        'fiscal_name',
        'address',
        'logo_path',
        'establishment_code',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(FelCompany::class, 'fel_company_id');
    }
}
