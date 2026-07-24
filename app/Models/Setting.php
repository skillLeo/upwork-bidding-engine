<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantOrPlatform;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw key/value row. Encryption of `value` when `is_secret` is true, and
 * caching, are handled by App\Services\SettingsService — never read/write
 * this model directly outside of that service.
 */
#[Fillable(['key', 'value', 'group', 'is_secret'])]
class Setting extends Model
{
    use BelongsToTenantOrPlatform;

    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
        ];
    }
}
