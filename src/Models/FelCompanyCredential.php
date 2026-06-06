<?php

namespace Lc\Fel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Lc\Fel\Models\Concerns\UsesFelTable;

class FelCompanyCredential extends Model
{
    use UsesFelTable;

    protected static string $felTableConfigKey = 'credentials';

    protected $fillable = [
        'fel_company_id',
        'user',
        'password',
        'basic_user',
        'basic_password',
        'url_test',
        'url_prod',
        'machine_id',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    protected $hidden = [
        'password',
        'basic_password',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(FelCompany::class, 'fel_company_id');
    }

    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = filled($value) ? Crypt::encryptString((string) $value) : null;
    }

    public function getPasswordPlainAttribute(): ?string
    {
        return $this->decryptNullable($this->attributes['password'] ?? null);
    }

    public function setBasicPasswordAttribute($value): void
    {
        $this->attributes['basic_password'] = filled($value) ? Crypt::encryptString((string) $value) : null;
    }

    public function getBasicPasswordPlainAttribute(): ?string
    {
        return $this->decryptNullable($this->attributes['basic_password'] ?? null);
    }

    private function decryptNullable(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
