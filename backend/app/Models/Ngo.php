<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ngo extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'paypal_account',
        'address',
    ];

    public function causes()
    {
        return $this->belongsToMany(Cause::class, 'ngo_causes')
            ->withPivot('percentage')
            ->withTimestamps();
    }
}
