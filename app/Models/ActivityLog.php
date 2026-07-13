<?php

namespace App\Models;

use App\Enums\ActivityType;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'subject_type', 'subject_id', 'meta', 'user_id'])]
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Single entry point for writing to the activity feed so every module
     * logs the same shape. Never throws — logging must never break the
     * calling flow (webhook intake, scoring, notifications, etc).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        ActivityType|string $type,
        ?Model $subject = null,
        array $meta = [],
        ?int $userId = null,
    ): ?self {
        try {
            return self::create([
                'type' => $type instanceof ActivityType ? $type->value : $type,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'meta' => $meta,
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
