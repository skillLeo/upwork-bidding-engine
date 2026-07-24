<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['endpoint', 'endpoint_hash', 'p256dh', 'auth_key', 'content_encoding'])]
class PushSubscription extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;
}
