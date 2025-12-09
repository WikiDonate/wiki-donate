<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'payment_id',
        'customer_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'source',       
        'cause_id',      
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function cause()
    {
    return $this->belongsTo(Cause::class);
    }

    public static function recordPayment(array $data): Payment
{
    return Payment::create([
        'user_id' => $data['user_id'],
        'payment_id' => $data['payment_id'],
        'customer_id' => $data['customer_id'],
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'status' => $data['status'],
        'cause_id' => $data['cause_id'] ?? null,
        'source' => $data['source'] ?? 'stripe',
    ]);
}
}


