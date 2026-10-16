<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Append-only audit trail of transaction-relevant events.
 *
 * Every meaningful write (org upserts, PayPal email changes, verification
 * changes, payout creates and payout blocks) is recorded here with the
 * acting user, timestamp, and structured before/after values so admins can
 * filter and export a complete, report-friendly history.
 */
class TransactionLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid',
        'event',
        'actor_id',
        'actor_role',
        'subject_type',
        'subject_id',
        'message',
        'before',
        'after',
        'meta',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'meta' => 'array',
        'created_at' => 'datetime',
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

    /**
     * Convenience factory. Never throws: auditing must not break the
     * primary write it accompanies.
     *
     * @param  array  $data  keys: event, actor_id, actor_role, subject_type,
     *                       subject_id, message, before, after, meta
     */
    public static function record(string $event, array $data = []): void
    {
        try {
            static::create(array_merge(['event' => $event], $data));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
