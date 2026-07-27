# Stripe Testing Guide

## Prerequisites

1. **Stripe CLI** installed: https://stripe.com/docs/stripe-cli
2. A Stripe account with a test mode API key
3. Laravel application running locally

## Setup

### 1. Configure Environment

Add your test Stripe keys to `.env`:

```env
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
```

**Note:** `STRIPE_WEBHOOK_SECRET` will be provided by the Stripe CLI when you start webhook forwarding.

### 2. Start Laravel

```bash
php artisan serve --port=8000
```

### 3. Forward Webhooks with Stripe CLI

```bash
stripe listen \
  --forward-to http://localhost:8000/api/v1/stripe/webhook \
  --events checkout.session.completed,checkout.session.expired,payment_intent.succeeded,payment_intent.payment_failed
```

The CLI will output a webhook signing secret. Copy it to `.env`:

```env
STRIPE_WEBHOOK_SECRET=whsec_...  # From stripe listen output
```

### 4. Run the Tests

```bash
php artisan test --filter=StripeCheckoutTest
php artisan test --filter=StripeWebhookTest
```

## Manual Testing Flow

### Trigger a Checkout Session

```bash
curl -X POST http://localhost:8000/api/v1/stripe/checkout \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "amount": 10.00,
    "currency": "usd",
    "donor_name": "Test User",
    "donor_email": "test@example.com",
    "success_url": "http://localhost:3000/donate/success?session_id={CHECKOUT_SESSION_ID}",
    "cancel_url": "http://localhost:3000/donate/cancel"
  }'
```

Expected response:
```json
{
  "success": true,
  "message": "Checkout session created",
  "data": {
    "checkout_url": "https://checkout.stripe.com/c/pay/...",
    "session_id": "cs_test_..."
  }
}
```

### Open the Checkout URL

Open the `checkout_url` from the response in a browser. Use Stripe test card `4242 4242 4242 4242` with any future date and any CVC.

### Verify Webhook Received

Check Laravel logs:
```bash
tail -f storage/logs/laravel.log
```

Expected log entries:
```
Stripe webhook received: {"type":"checkout.session.completed","id":"evt_..."}
Donation recorded from checkout session: {"donation_id":1,"session_id":"cs_test_...","amount":10.00}
```

### Verify Database

```bash
php artisan tinker
> App\Models\Donation::latest()->first();
```

Expected: A `Donation` record with `status = "completed"` and correct amount.

## Trigger Specific Events (Stripe CLI)

```bash
# Trigger a completed checkout
stripe trigger checkout.session.completed

# Trigger payment intent events
stripe trigger payment_intent.succeeded
stripe trigger payment_intent.payment_failed
```

## Testing with PHPUnit

```bash
# Run all Stripe tests
php artisan test --filter=Stripe

# Run specific test file
php artisan test tests/Feature/StripeCheckoutTest.php
php artisan test tests/Feature/StripeWebhookTest.php
```
