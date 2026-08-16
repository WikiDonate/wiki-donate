<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class VerifyPayPalCredentials extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paypal:verify-credentials';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the PayPal client ID / secret and the configured mode (sandbox vs live)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $clientId = config('services.paypal.client_id');
        $clientSecret = config('services.paypal.client_secret');
        $mode = config('services.paypal.mode', 'sandbox');

        if (! $clientId || ! $clientSecret) {
            $this->error('PayPal credentials are missing. Set PAYPAL_CLIENT_ID and PAYPAL_SECRET in your .env.');

            return self::FAILURE;
        }

        $mode = in_array($mode, ['live', 'sandbox'], true) ? $mode : 'sandbox';
        $baseUrl = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $this->line('Testing credentials against PayPal <comment>'.$mode.'</comment> endpoint: '.$baseUrl);

        $response = Http::withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post($baseUrl.'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->successful()) {
            $body = $response->json();
            $this->info('Credentials are VALID.');
            $this->line('Access token obtained, expires in: '.($body['expires_in'] ?? 'n/a').'s.');
            $this->line('Your app is configured to use PayPal '.$mode.'.');

            return self::SUCCESS;
        }

        $body = $response->json();
        $error = $body['error'] ?? 'unknown_error';
        $description = $body['error_description'] ?? $response->body();

        $this->error('Credentials are INVALID for the '.$mode.' endpoint.');
        $this->line('error: '.$error);
        $this->line('description: '.$description);
        $this->newLine();
        $this->warn('Suggestions:');
        $this->line('  1. The Client ID and Secret must come from the SAME PayPal app.');
        $this->line('  2. Make sure you copied them from the correct environment (Sandbox vs Live) matching PAYPAL_MODE.');
        $this->line('  3. Watch out for leading/trailing spaces or a truncated value when pasting into .env.');
        $this->line('  4. After updating .env, run: php artisan config:clear');

        return self::FAILURE;
    }
}
