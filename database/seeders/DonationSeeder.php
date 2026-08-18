<?php

namespace Database\Seeders;

use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class DonationSeeder extends Seeder
{
    /**
     * Seed demo donations for the admin donations dashboard.
     *
     * Run with: php artisan db:seed --class=DonationSeeder
     */
    public function run(): void
    {
        $userIds = User::orderBy('id')->pluck('id', 'username')->all();
        $formulaIds = DonationFormula::orderBy('id')->pluck('id')->all();

        $demo = [
            // ---- PayPal donations (source = paypal) ----
            [
                'user_id' => $userIds['WikiDonate'] ?? null,
                'donor_name' => 'Amina Rahman',
                'donor_email' => 'amina.rahman@example.com',
                'amount' => 250.00,
                'currency' => 'USD',
                'status' => 'completed',
                'paypal_order_id' => 'PAYD-8P2D3K1X',
                'payment_id' => 'CAPTURE-AMN-8821',
                'formula_index' => 0,
                'details' => 'Monthly support for the education fund.',
                'days_ago' => 2,
            ],
            [
                'user_id' => $userIds['abdullah'] ?? null,
                'donor_name' => 'Abdullah',
                'donor_email' => 'ab@aa.com',
                'amount' => 75.50,
                'currency' => 'EUR',
                'status' => 'completed',
                'paypal_order_id' => 'PAYD-5Q7H8J2W',
                'payment_id' => 'CAPTURE-ABD-5533',
                'formula_index' => 1,
                'details' => null,
                'days_ago' => 6,
            ],
            [
                'user_id' => null,
                'donor_name' => 'Guest Donor',
                'donor_email' => 'guest.donor@example.com',
                'amount' => 25.00,
                'currency' => 'USD',
                'status' => 'pending',
                'paypal_order_id' => 'PAYD-1M3N6P8R',
                'payment_id' => null,
                'formula_index' => 2,
                'details' => 'Processing PayPal approval.',
                'days_ago' => 1,
            ],
            [
                'user_id' => null,
                'donor_name' => 'Maya Patel',
                'donor_email' => 'maya.patel@example.com',
                'amount' => 500.00,
                'currency' => 'USD',
                'status' => 'failed',
                'paypal_order_id' => 'PAYD-6T2V4Y7Z',
                'payment_id' => null,
                'formula_index' => 3,
                'details' => 'Payment declined by the buyer.',
                'days_ago' => 4,
            ],

            // ---- Stripe donations (source = stripe_checkout) ----
            [
                'user_id' => $userIds['ab2'] ?? null,
                'donor_name' => 'Ab2',
                'donor_email' => 'abc@aa.com',
                'amount' => 125.00,
                'currency' => 'USD',
                'status' => 'completed',
                'stripe_session_id' => 'cs_demo_seed_'.uniqid(),
                'stripe_payment_intent_id' => 'pi_demo_seed_'.uniqid(),
                'formula_index' => 0,
                'details' => 'One-time donation after reading the article.',
                'days_ago' => 3,
            ],
            [
                'user_id' => null,
                'donor_name' => 'Omar Haddad',
                'donor_email' => 'omar.haddad@example.com',
                'amount' => 40.25,
                'currency' => 'GBP',
                'status' => 'completed',
                'stripe_session_id' => 'cs_demo_seed_'.uniqid(),
                'stripe_payment_intent_id' => 'pi_demo_seed_'.uniqid(),
                'formula_index' => 1,
                'details' => null,
                'days_ago' => 9,
            ],
            [
                'user_id' => null,
                'donor_name' => 'Anonymous',
                'donor_email' => null,
                'amount' => 15.00,
                'currency' => 'USD',
                'status' => 'expired',
                'stripe_session_id' => 'cs_demo_seed_'.uniqid(),
                'stripe_payment_intent_id' => null,
                'formula_index' => 2,
                'details' => null,
                'days_ago' => 12,
            ],
            [
                'user_id' => null,
                'donor_name' => 'Fatima Noor',
                'donor_email' => 'fatima.noor@example.com',
                'amount' => 1000.00,
                'currency' => 'USD',
                'status' => 'completed',
                'stripe_session_id' => 'cs_demo_seed_'.uniqid(),
                'stripe_payment_intent_id' => 'pi_demo_seed_'.uniqid(),
                'formula_index' => 3,
                'details' => 'Special campaign pledge.',
                'days_ago' => 15,
            ],
        ];

        foreach ($demo as $row) {
            $stripeSession = $row['stripe_session_id'] ?? null;
            $paymentIntent = $row['stripe_payment_intent_id'] ?? null;
            $paypalOrder = $row['paypal_order_id'] ?? null;
            $paymentId = $row['payment_id'] ?? null;
            $formulaId = $formulaIds[$row['formula_index']] ?? null;

            $metadata = [
                'source' => $paypalOrder ? 'paypal' : 'stripe_checkout',
                'details' => $row['details'],
            ];

            if ($paymentId) {
                $metadata['payment_id'] = $paymentId;
            }

            $donation = Donation::create([
                'user_id' => $row['user_id'],
                'donation_formula_id' => $formulaId,
                'stripe_session_id' => $stripeSession,
                'stripe_payment_intent_id' => $paymentIntent,
                'paypal_order_id' => $paypalOrder,
                'donor_name' => $row['donor_name'],
                'donor_email' => $row['donor_email'],
                'amount' => $row['amount'],
                'currency' => $row['currency'],
                'status' => $row['status'],
                'metadata' => $metadata,
            ]);

            $donation->forceFill(['created_at' => now()->subDays($row['days_ago']), 'updated_at' => now()->subDays($row['days_ago'])])->saveQuietly();
        }

        // Bust the dashboard cache so the demo rows appear immediately.
        Cache::store('file')->forget('dashboard');

        $this->command?->info('Seeded '.count($demo).' demo donations.');
    }
}
