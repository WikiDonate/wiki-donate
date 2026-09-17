<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DonationFormula extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'article_id',
        'user_id',
        'name',
        'formula',
        'details',
    ];

    protected $casts = [
        'formula' => 'array',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
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

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function donations()
    {
        return $this->hasMany(Donation::class, 'donation_formula_id');
    }

    /**
     * Whether this formula has at least one completed donation.
     */
    public function hasCompletedDonation(): bool
    {
        return $this->donations()->where('status', 'completed')->exists();
    }
}
