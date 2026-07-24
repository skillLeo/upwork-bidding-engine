<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A permanent tombstone: once a lead's external_id lands here, the Vollna
 * importer refuses to ever recreate it, regardless of whether the Lead row
 * itself still exists. See the migration for why this is a separate table
 * rather than a soft-delete on `leads`.
 */
class DeletedLeadExternalId extends Model
{
    use BelongsToTenant;

    protected $fillable = ['external_id'];
}
