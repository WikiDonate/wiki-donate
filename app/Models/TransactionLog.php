<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Append-only audit log for transaction-related events.
 *
 * Designed to be report-friendly: each row carries actor, subject,
 * before/after snapshots, and searchable event/category tags.
 */
class TransactionLog extends Model
{
    protected $fillable = [
        'uuid',
        'event',
        'category',
        'subject_type',
        'subject_id',
        'actor_id',
        'before',
        'after',
        'note',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Record an audit row.
     */
    public static function record(
        string $event,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
        ?string $category = null,
        ?string $note = null,
    ): self {
        return static::create([
            'event' => $event,
            'category' => $category ?? self::categoryFor($event),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'actor_id' => $actorId,
            'before' => $before,
            'after' => $after,
            'note' => $note,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Default category bucket based on event prefix.
     */
    private static function categoryFor(string $event): string
    {
        return match (true) {
            str_starts_with($event, 'payout') => 'payout',
            str_starts_with($event, 'organization') => 'organization',
            str_starts_with($event, 'donation') => 'donation',
            default => 'general',
        };
    }
}
