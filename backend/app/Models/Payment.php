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
        'source',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
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
            'source' => $data['source'] ?? 'stripe',
        ]);
    }
}
