<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NgoCause extends Model
{
    use HasFactory;

    protected $table = 'ngo_causes';

    protected $fillable = [
        'cause_id',
        'ngo_id',
        'percentage',
    ];

    public function cause()
    {
        return $this->belongsTo(Cause::class);
    }

    public function ngo()
    {
        return $this->belongsTo(Ngo::class);
    }
}
