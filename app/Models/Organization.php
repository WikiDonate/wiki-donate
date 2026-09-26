<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Organization extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'normalized_name',
        'ein',
        'city',
        'state',
        'country',
        'paypal_email',
        'payout_status',
        'verified_at',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    /**
     * Normalize the EIN on assignment so lookups work regardless of
     * formatting (hyphens, spaces, etc.).
     */
    public function setEinAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['ein'] = null;

            return;
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/u', '', $value);
        $this->attributes['ein'] = ($clean === '' || $clean === false) ? null : strtoupper($clean);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->normalized_name)) {
                $model->normalized_name = self::normalizeName($model->name);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('name') && ! $model->isDirty('normalized_name')) {
                $model->normalized_name = self::normalizeName($model->name);
            }
        });
    }

    /**
     * Normalize an organization name for stable comparison.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Find an organization by EIN or normalized name.
     */
    public static function findByEinOrName(?string $ein, string $name): ?self
    {
        if (! empty($ein)) {
            $match = self::where('ein', $ein)->first();
            if ($match) {
                return $match;
            }
        }

        return self::where('normalized_name', self::normalizeName($name))->first();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function formulas()
    {
        return $this->hasMany(DonationFormula::class);
    }
}
