<?php

namespace App\Models;

use Database\Factories\SavedFilterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'is_default', 'is_pinned', 'criteria'])]
class SavedFilter extends Model
{
    /** @use HasFactory<SavedFilterFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_pinned' => 'boolean',
            'criteria' => 'array',
        ];
    }
}
