<?php

namespace Lc\Fel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Lc\Fel\Models\Concerns\UsesFelTable;

class FelCompany extends Model
{
    use UsesFelTable;

    protected static string $felTableConfigKey = 'companies';

    protected $fillable = [
        'name',
        'nit',
        'regime',
        'certifier',
        'mode',
        'default_document_type',
        'show_pos_switch',
        'auto_mark_pos_switch',
        'monthly_dte_limit',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'show_pos_switch' => 'boolean',
        'auto_mark_pos_switch' => 'boolean',
        'monthly_dte_limit' => 'integer',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function credential(): HasOne
    {
        return $this->hasOne(FelCompanyCredential::class, 'fel_company_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(FelBranchSetting::class, 'fel_company_id');
    }
}
