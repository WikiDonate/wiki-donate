<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use PaypalPayoutsSDK\Core\PayPalHttpClient;
use PaypalPayoutsSDK\Core\SandboxEnvironment;
use PaypalPayoutsSDK\Payouts\PayoutsPostRequest;
use Illuminate\Support\Facades\Log;

class PaymentLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'cause_id',
        'ngo_id',
        'payment_id',
        'user_id',
        'amount',
        'currency',
        'paypal_payout_id',
        'paypal_status',
    ];

    public function cause()
    {
        return $this->belongsTo(Cause::class);
    }

    public function ngo()
    {
        return $this->belongsTo(Ngo::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function distributeAndCreatePaymentLogs($payment, $cause_id, $user_id)
    {
     

        $ngo_causes = NgoCause::with('ngo')->where('cause_id', $cause_id)->get();

        $paymentLogs = [];

        // Initialize PayPal HTTP client
        
        $clientId = config('services.paypal.client_id');
        $clientSecret = config('services.paypal.secret');
        $environment = new SandboxEnvironment($clientId, $clientSecret);
        $client = new PayPalHttpClient($environment);
    

        foreach ($ngo_causes as $ngo_cause) {
            $amount = $payment->amount * ($ngo_cause->percentage / 100);
          

            if (!empty($ngo_cause->ngo->paypal_account) && $amount > 0) {
                try {
                    $request = new PayoutsPostRequest();
                    $request->body = [
                        'sender_batch_header' => [
                            'sender_batch_id' => uniqid(),
                            'email_subject' => 'You have a payment from donation',
                        ],
                        'items' => [
                            [
                                'recipient_type' => 'EMAIL',
                                'amount' => [
                                    'value' => number_format($amount, 2, '.', ''),
                                    'currency' => $payment->currency,
                                ],
                                'note' => 'Thank you for your service',
                                'receiver' => $ngo_cause->ngo->paypal_account,
                                'sender_item_id' => uniqid(),
                            ]
                        ]
                    ];

                  

                    $response = $client->execute($request);
                    $result = $response->result;

                  

                    $paymentLogs[] = PaymentLog::create([
                        'cause_id' => $cause_id,
                        'ngo_id' => $ngo_cause->ngo_id,
                        'payment_id' => $payment->id,
                        'amount' => $amount,
                        'currency' => $payment->currency,
                        'user_id' => $user_id,
                        'paypal_payout_id' => $result->batch_header->payout_batch_id,
                        'paypal_status' => $result->batch_header->batch_status,
                    ]);
                } catch (\Exception $e) {
                   

                    $paymentLogs[] = PaymentLog::create([
                        'cause_id' => $cause_id,
                        'ngo_id' => $ngo_cause->ngo_id,
                        'payment_id' => $payment->id,
                        'amount' => $amount,
                        'currency' => $payment->currency,
                        'user_id' => $user_id,
                        'paypal_status' => 'failed',
                    ]);
                }
            } else {
              

                $paymentLogs[] = PaymentLog::create([
                    'cause_id' => $cause_id,
                    'ngo_id' => $ngo_cause->ngo_id,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'currency' => $payment->currency,
                    'user_id' => $user_id,
                    'paypal_status' => 'no_paypal_account',
                ]);
            }
        }


        return $paymentLogs;
    }
}