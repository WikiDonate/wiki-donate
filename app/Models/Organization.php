<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'normalized_name',
        'ein',
        'city',
        'state',
        'country',
        'website',
        'paypal_email',
        'payout_methods',
        'payout_status',
        'verified_at',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'payout_methods' => 'array',
    ];

    /**
     * Default payout rail for orgs with no explicit selection (legacy rows).
     */
    public const DEFAULT_PAYOUT_METHOD = 'paypal';

    /**
     * Normalize the EIN on assignment so lookups work regardless of
     * formatting (hyphens, spaces, etc.).
     */
    public function setEinAttribute(?string $value): void
    {
        $this->attributes['ein'] = self::normalizeEin($value);
    }

    /**
     * Canonical EIN form: alphanumerics only, uppercased. Directory APIs
     * (ProPublica) return the EIN as an integer, dropping the leading zero of
     * an eight-digit EIN (06-0726487 arrives as 60726487), so those get the
     * zero back. Longer/shorter values are left as typed rather than silently
     * rewritten — validation is responsible for rejecting them.
     */
    public static function normalizeEin(mixed $ein): ?string
    {
        if (! is_string($ein) && ! is_int($ein)) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/[^a-zA-Z0-9]/u', '', (string) $ein));

        if ($clean === '') {
            return null;
        }

        if (ctype_digit($clean) && strlen($clean) === 8) {
            $clean = '0'.$clean;
        }

        return $clean;
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

    /**
     * Payout method codes selected for this org.
     *
     * Always returns a non-empty, normalized list: legacy rows (null column)
     * and rows saved before the payment-method registry existed fall back to
     * ['paypal'], matching the historical single-rail behavior.
     *
     * @return array<int, string>
     */
    public function payoutMethods(): array
    {
        $methods = array_values(array_filter(array_map(
            fn ($code) => is_string($code) ? strtolower(trim($code)) : null,
            (array) ($this->payout_methods ?? [])
        )));

        return $methods !== [] ? array_values(array_unique($methods)) : [self::DEFAULT_PAYOUT_METHOD];
    }

    /**
     * Whether a given payment-method code is an enabled payout rail for this
     * org. Used by the payout gate so an org only receives money through a
     * rail an admin actually configured.
     */
    public function allowsPayoutMethod(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return in_array(strtolower($code), $this->payoutMethods(), true);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * Whether this org can receive a PayPal payout right now: a confirmed
     * receiving email plus an explicit verified status.
     */
    public function isPayoutReady(): bool
    {
        return $this->payout_status === 'verified' && filled($this->paypal_email);
    }

    /**
     * Whether the registry considers this org verified.
     * Custom (free-text) orgs stay unverified until an admin verifies them.
     */
    public function isVerified(): bool
    {
        return $this->payout_status === 'verified' && $this->verified_at !== null;
    }
}
