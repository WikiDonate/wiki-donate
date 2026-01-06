<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cause extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
    ];

    public function ngos()
    {
        return $this->belongsToMany(Ngo::class, 'ngo_causes')
                    ->withPivot('percentage')
                    ->withTimestamps();
    }
}