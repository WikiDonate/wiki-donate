# Wiki Donate

Wiki Donate is a platform designed to streamline and facilitate donations for a variety of causes. This project includes robust features such as user authentication, article contributions, community discussions, donation management, and more. Built with a modular architecture, it ensures scalability and maintainability.

The API documentation can be accessed at `https://your-domain/api/documentation`.

## Project Structure

The entire application lives in a single Laravel project at the repository root:

- `app/`: server-side code, built using Laravel
- `resources/js/`: the client-side Vue.js SPA (Vue 3 + Vue Router + Pinia), built with Vite
- `routes/`: API and web routes
- `public/`: the web root (served directly; contains the built Vite assets in `public/build`)

## Tech Stack

### Backend

- Laravel 13: for building RESTful APIs and handling server-side logic
- MySQL: as the database solution for managing structured data
- Sanctum: token-based authentication for the SPA
- PHP 8.4+

### Frontend

- Vue 3 (Vue Router + Pinia): Single Page Application
- Vite: build tooling
- Tailwind CSS: for designing a clean and modern UI

### Deployment and Hosting

- Shared hosting / any PHP host: point the document root to `public/`
- Run `npm run build` before deploying so the compiled assets exist in `public/build`

## Features

### Core Features

#### Donation Management

- Allows users to make donations, track their contributions, and view donation history.

#### Authentication and Authorization

- Secure login and registration system for users and administrators.

#### Admin Dashboard

- Admins can manage donations, approve or reject user submissions, and generate reports.

#### Responsive Design

- A mobile-first design to ensure accessibility across devices.

### Additional Features

#### Article Contributions

- Users can write, edit, and publish articles related to donation causes, guidelines, or success stories.

#### Talk (Community Discussions)

- A dedicated space where users can engage in discussions, ask questions, or share feedback.

#### Review and Feedback System

- Allows users to leave reviews and rate their experience with the platform or specific donation campaigns.

#### Search and Filtering

- Comprehensive search functionality to filter articles, discussions, and donation campaigns.

#### Multi-Language Support

- Support for multiple languages to cater to a diverse user base.

#### Real-time Notifications

- Notify users of updates, approved articles, new comments, or milestones achieved in campaigns.

#### User Profiles

- Each user has a customizable profile that displays their activities, contributions, and reviews.

#### Analytics and Reports

- Admins can generate insights about donation trends, active users, and campaign performance.

## Development

### Setup

1. Install PHP dependencies: `composer install`
2. Install frontend dependencies: `npm install`
3. Copy `.env.example` to `.env` and configure the database connection
4. Generate encryption keys: `php artisan key:generate`
5. Run migrations: `php artisan migrate`

Payment gateways are configured **after** migrating, by editing the
`payment_methods` rows (see [Environment Variables](#environment-variables)).

### Environment Variables

Configuration lives in `.env`. Frontend (Vite) build-time variables are
consumed in the SPA via `import.meta.env` and must be prefixed with `VITE_`:

| Variable | Description |
| --- | --- |
| `VITE_API_URL` | Base URL for the API (default `/api/v1`) |
| `VITE_AI_CHATBOT_API_KEY` | API key for the AI chatbot widget |
| `VITE_APP_NAME` | App name exposed to the frontend |

| Variable | Description |
| --- | --- |
| `APP_URL` / `APP_FRONTEND_URL` | Must match the serving origin, or emailed links go to the wrong host |
| `DONATION_TRANSACTION_COST_PERCENT` | Payout/transaction-cost rate (default `5`) |
| `DONATION_CURRENCY` | The only supported currency (default `USD`) |

Changing `VITE_*` values requires a re-run of `npm run build` (or `npm run dev`)
to take effect.

**Payment gateway configuration is DB-owned, not env-owned.** There are no
`STRIPE_*` / `PAYPAL_*` / `VITE_STRIPE_*` / `VITE_PAYPAL_*` keys. Each gateway
is a row in the `payment_methods` table:

- `config` — non-secret metadata: `mode` (`sandbox` | `live`), currencies,
  webhook route + subscribed events, limits.
- `credentials` — **encrypted** (`encrypted:array`, needs `APP_KEY`): client
  id/secret and webhook id/secret. Never fillable, never returned by the API.

The SPA reads public keys from `GET /v1/payment-methods` (`public_key`) or
`GET /v1/payment-methods/{code}/public-key`; payment servers read the secrets
via `App\Services\Payment\PaymentCredentials`. Re-introducing per-gateway env
keys would create a second source of truth — don't.

### Webhooks

There is one webhook endpoint per gateway:

```
POST {APP_URL}/api/v1/webhooks/{method}
```

e.g. `https://example.com/api/v1/webhooks/paypal`. The route is public but every
delivery is **signature-verified** before processing, so an unsigned request is
rejected with `401`.

To wire up PayPal (also required for payouts — see below):

1. PayPal Developer Dashboard → **Apps & Credentials** → your app →
   **Sandbox**/**Live** → **Webhooks** → **Add Webhook**.
2. URL: `{APP_URL}/api/v1/webhooks/paypal`.
3. Subscribe to at least:

   ```
   PAYMENT.CAPTURE.COMPLETED
   PAYMENT.PAYOUTSBATCH.SUCCESS
   PAYMENT.PAYOUTSBATCH.DENIED
   PAYMENT.PAYOUTS-ITEM.SUCCEEDED
   PAYMENT.PAYOUTS-ITEM.FAILED
   PAYMENT.PAYOUTS-ITEM.RETURNED
   PAYMENT.PAYOUTS-ITEM.BLOCKED
   PAYMENT.PAYOUTS-ITEM.CANCELED
   PAYMENT.PAYOUTS-ITEM.UNCLAIMED
   ```

4. Copy the **Webhook ID** and store it on the registry row (encrypted):

   ```php
   $m = App\Models\PaymentMethod::where('code', 'paypal')->first();
   $c = $m->credentials;
   $c['webhook_id'] = 'WH-XXXXXXXXXXXXXXXX';
   $m->forceFill(['credentials' => $c])->save();
   app(App\Services\Payment\PaymentCredentials::class)->flush();
   ```

PayPal can only reach a **publicly resolvable HTTPS URL**. For local
development, use a tunnel (`cloudflared tunnel --url http://localhost:8000`, or
`ngrok http 8000`) and point the webhook at the tunnel URL. Avoid localtunnel /
`loca.lt` — its interstitial page usually breaks PayPal's delivery.

## Organization Payouts

Verified organizations are paid out from the donations allocated to them.

- **Ledger:** `organization_payouts` is append-only. The live balance for an
  allocation is always `owed − sum(paid + pending)`, never a stored snapshot.
- **Flow:** an admin types a **gross** amount; the server deducts the
  configurable transaction cost (`DONATION_TRANSACTION_COST_PERCENT`) and
  transfers the **net** to the organization. Client-supplied fee/net values are
  rejected (`prohibited`) — the split is server-enforced and frozen per row.
- **Guard:** payouts are blocked unless the organization is **verified** and the
  requested rail is enabled on it (PayPal additionally needs a receiving email).
- **Currency:** the platform is **USD-only**. `config('donations.currency')`
  (env `DONATION_CURRENCY`, default `USD`) is the single source of truth; every
  donation and payout in another code is rejected with a `422` rather than
  silently coerced. Donations are summed into one USD balance per allocation.
  Legacy non-USD `completed` rows (stored before the restriction) are **excluded**
  from USD balances rather than counted as dollars (which would overpay). List
  them with the read-only audit command `php artisan donations:legacy-currency`
  (add `--json` for machine output) and reconcile them manually.
- **Reconciliation:** PayPal settles asynchronously.

  | Mechanism | Trigger | Purpose |
  | --- | --- | --- |
  | Synchronous response | On submit | Marks `paid`/`failed` immediately when PayPal answers inline |
  | Webhook | PayPal push | Confirms `pending → paid`, records failures and reversals |
  | `php artisan payouts:sync` | Every 15 min (scheduler) + admin "Sync statuses" | Polls the PayPal Payouts API; re-submits rows PayPal never acknowledged |
  | `php artisan payouts:sync --recheck-paid` | Daily (scheduler) | Re-polls recently paid rows for a late `RETURNED`/`REFUNDED`/`REVERSED`/`UNCLAIMED` |

  The webhook is a **latency optimisation, not a dependency** — `payouts:sync`
  reconciles via outbound API calls and works without it. It runs every 15
  minutes when `php artisan schedule:work` (or a cron entry) is active.

- **Ambiguous submissions are never released.** If the submit call times out
  (no response) or PayPal returns a 5xx, the row stays `pending`; the scheduler
  re-submits it with the **same `sender_batch_id`** (the row `uuid`). PayPal
  dedupes that id, and a duplicate-id rejection is reconciled to the original
  batch instead of being marked failed — so an admin retry can never pay twice.
- **Row statuses:** `pending` (submitted, awaiting settlement), `paid`,
  `failed` (definitively rejected; excluded from the balance, safe to retry),
  `reversed` (funds returned after success; balance re-opened). Reversals are
  idempotent: the first webhook/poll wins and duplicates are ignored.
- **Admin routes** (`role:Admin`): `GET payouts`, `GET payouts/allocations`,
  `GET payouts/history`, `POST payouts`, `POST payouts/sync`.

Live production requires a running scheduler for `payouts:sync`:

```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

(or run `php artisan schedule:work` under a supervisor). Requires a running
`queue:work` too, since notification mail is queued.

### Running locally

**Option A — Laravel serves the built SPA:**

1. Build the frontend: `npm run build`
2. Start the server: `php artisan serve`
3. Open `http://localhost:8000`

**Option B — Vite dev server with API proxy:**

1. Start the Laravel server: `php artisan serve`
2. Start Vite: `npm run dev`
3. Open `http://localhost:5173` (`/api` requests are proxied to Laravel)

The Laravel web route serves the SPA for all non-API, non-asset paths.

### Testing

The backend suite runs on sqlite `:memory:` and needs no MySQL/Redis/SMTP
(configured in `phpunit.xml`):

- Backend tests: `php artisan test --compact`
- Single class while iterating: `php artisan test --filter=OrganizationPayoutTest`
- Code style: `./vendor/bin/pint --test`
- Frontend lint / format: `npm run lint` and `npm run format:check`
- Frontend production build check: `npm run build`

CI (`.github/workflows/ci.yml`) runs all of the above on pushes/PRs to `main`.

## Contribution

If you wish to contribute to Wiki Donate, follow these steps:

1. Fork this repository
2. Create a feature branch: `git checkout -b feature-name`
3. Commit your changes: `git commit -m "Description of changes"`
4. Push to the branch: `git push origin feature-name`
5. Open a pull request

## License

This project is licensed under the MIT License.