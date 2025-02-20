<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Donate extends Model
{
    protected $fillable = ['user_id', 'name', 'email', 'card_number', 'expiry_month', 'expiry_year', 'cvv', 'amount', 'currency', 'status'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class)->select('uuid', 'username');
    }
}
