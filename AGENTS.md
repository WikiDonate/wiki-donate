# AGENTS.md - WikiDonate

Single repo: Laravel 13 API (`app/`, `routes/api*.php`) + Vue 3 SPA (`resources/js/`). `public/build/` is Vite output — generated, never edit.

Branches: `main` is the deploy branch and may lag; `develop` is the active feature line and is currently ahead (USD-only currency + payout reconciliation). Run `git branch --show-current` before assuming a command or file below exists — items marked `develop` are not on `main` yet.

## Setup & dev

```bash
composer install
npm install            # CI uses `npm ci`
cp .env.example .env
php artisan key:generate
php artisan migrate
```

- A — built SPA: `npm run build` then `php artisan serve` → `:8000`.
- B — Vite dev: `php artisan serve` + `npm run dev` → `:5173`; `/api` is proxied to `:8000` (`vite.config.js`).
- `VITE_*` are build-time (`import.meta.env`). After changing them, re-run `npm run build` (or restart Vite).
- Payment gateway credentials are **DB rows, not env**: no `STRIPE_*` / `PAYPAL_*` / `VITE_*_PUBLIC_KEY` in `.env` (see “Payment config” under Gotchas).
- `.env.example` sets `QUEUE_CONNECTION=database`; queued mail/notifications only send while `php artisan queue:work` is running.

## Verify (mirrors `.github/workflows/ci.yml`)

```bash
./vendor/bin/pint --test
php artisan test --compact              # full suite ~1–2 min (much faster on a warm cache)
php artisan test --filter=OrganizationPayoutTest
npm run lint                            # eslint resources/js
npm run format:check                    # prettier --check resources/js
npm run build
```

- **Every completed task runs all four checks**: `./vendor/bin/pint --test`, `php artisan test`, `npx eslint resources/js`, `npx prettier --check resources/js`. Report the result of each; fix failures before finishing.
- There is **no TypeScript** in this repo: no `tsconfig.json`, no `typescript`/`vue-tsc`, no `typecheck` script. The SPA is plain JS + Vue SFCs, so don’t claim a “TS check” ran — say it’s not configured (adding one requires the tooling first).
- CI (PHP 8.4 / Node 22) gates `deploy.yml`; deploy runs on the VPS: composer, `npm ci` + build, `php artisan migrate --force`, cache rebuild.
- **Deploy never seeds.** Seeding is manual: `php artisan db:seed` (demo data only when `APP_ENV=local`; other seeders in `database/seeders/`). Never add `--seed` or `migrate:fresh` to a deploy path.
- Blade entry `resources/views/app.blade.php` uses `public/hot` (dev) else `public/build/.vite/manifest.json`, else hard-codes `http://localhost:5173`. Blank SPA = missing build or not running Vite.

## Architecture

- All routes are under `v1` (`routes/api.php`). `require editor.php` runs **before** `articles/{slug}` so `articles/my` is not swallowed by the slug route. `admin.php` loads last (`role:Admin`); `editor.php` is `role:Admin|Editor`. Public article index/show use `OptionalAuth` (selects the sanctum guard without requiring a token).
- Role-gated `auth:sanctum` groups also use `FreshAuthGuard`, including the donor report (`report/donations`): Sanctum memoizes the first resolved user per process, so long-lived runtimes (tests, Octane, workers) would otherwise reuse a previous request’s identity — for the report that means serving another user’s donations. Don’t remove it as “redundant”, and put it on any new authenticated route.
- API-only app: `bootstrap/app.php` disables guest redirects and renders JSON `401`; don’t add HTML auth redirects.
- `routes/web.php` SPA catch-all excludes `api|build|storage|vendor|sanctum|up|when|oauth|_ignition|telescope|horizon`. A new top-level path prefix must be added to that negative lookahead or it will serve the SPA.
- Organization autocomplete has two very different endpoints: `GET v1/organizations/search` (`Admin|Editor`) is **registry-only** for formula rows; `GET v1/charities/search` is **admin-only** despite having no `admin/` prefix (external Charity Navigator/ProPublica, merges local verified orgs first). Donor/editor paths must never call external charity APIs.
- SPA auth: Bearer token from `localStorage.token` (`resources/js/config/apiConfig.js`). The axios response interceptor returns `res.data` and rejects with `error.response.data`, so callers check `response.success`. A `401` clears `localStorage` and hard-redirects to `/login`.
- Router guards are `requiresAuth` / `requiresAdmin` (`roles?.includes('Admin')`, case-sensitive). No `scrollBehavior` and no `?redirect=`; the `/:pathMatch(.*)*` catch-all redirects to `/`, so broken links look like the home page.
- `definePageMeta` / `navigateTo` in `resources/js/shims.js` are no-ops auto-imported by Vite. Authorization is router meta only — never rely on `definePageMeta({middleware:'auth'})`.
- Vite: `base:'/build/'`, `outDir:'public/build'`, `publicDir:false`, manifest on, aliases `@` / `~` → `resources/js`. Auto-imports vue/vue-router/vue-i18n/@vueuse/head plus `stores/**` and `composables/**`, and auto-registers `components/*.vue` — so `ref`, `useAuthStore`, `ConfirmModal` often need no import. `manualChunks` splits vue/fontawesome/stripe/vendor.
- i18n: `vue-i18n`, **42** locales in `resources/js/i18n/locales/`, lazily loaded via `import.meta.glob`; RTL list (`ar, ur, he, fa`) in `index.js`. Don’t re-add the legacy Google Translate widget. Regenerate with `scripts/build_locales.py`, backfill with `scripts/backfill_locales.py`. Adding a key to `en.json` alone fails `PaymentMethodLocaleTest` (it requires every locale to carry the exact same key set) — run the backfill script before finishing.
- Swagger UI at `/api/documentation` (`darkaonline/l5-swagger`).

## Conventions

- Pint uses the `laravel` preset (`pint.json`). Return generic 500 messages; don’t expose `$e->getMessage()` to clients.
- Migrations: `created_at` / `updated_at` must be the **last** columns — call `$table->timestamps()` last on create. A later `$table->string(...)` lands after the timestamps on MySQL, so use `->after('<existing column>')`. Fix already-deployed ordering only with a new MySQL-only `MODIFY ... AFTER` migration (pattern on `develop`: `2026_10_31_000004_move_timestamps_last_on_payout_tables.php`); sqlite tests build fresh and won’t catch it. Verify with `information_schema.COLUMNS ... ORDER BY ORDINAL_POSITION`. Never rename a deployed migration file — prod re-runs it as new.
- Prettier: `.prettierrc` is authoritative (no-semi, single quotes, 4 spaces, width 100, trailing commas). `.prettierrc.cjs` is a stale duplicate — don’t edit it.
- ESLint flat config disables `vue/multi-word-component-names`, `vue/max-attributes-per-line`, `vue/html-self-closing` and ignores `public/build`.
- `resources/js/services/*.js` are thin axios wrappers. Pinia uses `pinia-plugin-persistedstate`; `authStore` persists token/user (XSS-sensitive — don’t widen persisted paths).

## Gotchas agents miss

- Tests use sqlite `:memory:` with `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `MAIL_MAILER=array` (`phpunit.xml`), so no MySQL/Redis/SMTP is needed. Don’t assume sqlite catches MySQL schema problems.
- Keep `phpunit.xml` values in `<server>` tags, not `<env>`: PHPUnit’s `<env>` never overwrites a variable already exported by the shell, and Laravel’s `Env` repository reads `$_SERVER` first. If the surrounding shell exports `DB_CONNECTION=mysql` / `APP_ENV=local` (Docker/dev shells often do), `<env>` is ignored and `RefreshDatabase` runs `migrate:fresh` against the real dev database — wiping it. `<server>` is written to `$_SERVER` unconditionally, so the suite stays on sqlite.
- Donation lists (donor `GET v1/report/donations` and admin `GET v1/admin/donations`) expose `distribution_status` from `OrganizationPayoutService::distributionStatuses()`: formula-level payout coverage of completed USD owed (`distributed` / `partial` / `pending`), counting `paid` + `pending` payouts and excluding `reversed`. It is `null` unless the donation is completed **and** in the supported currency, and all donations sharing a formula show the same value. Keep it aligned with the payout balance math (don’t add a second coverage calculation).
- Stale Vite bundle is the top false “regression”: `VITE_*` bake in at build time. Check the served bundle first — `grep -o "baseURL:[^,}]*" public/build/assets/apiConfig-*.js` — then `npm run build` + hard refresh. Never hand-edit `public/build/`.
- Fresh MySQL fails where sqlite won’t: `organization_payouts` (2026_09_15) predates `organizations` (2026_09_26), so `->constrained('organizations')` breaks fresh installs (MySQL 1824). Add FKs conditionally (`Schema::hasTable` + try/catch).
- Mail: `.env.example` ships `MAIL_MAILER=log` (dev SMTP is blocked). `CustomOrganizationNotifier` uses the `failover` mailer (smtp → log) so a dead SMTP can’t crash a worker. Debug in order: `php artisan queue:failed` → `MAIL_*` in the log body → SMTP `535` (credentials) vs `refused/timeout` (server/firewall). Production needs a running `queue:work` or `jobs` piles up.
- `APP_URL` / `APP_FRONTEND_URL` must match the serving origin or emailed links (`OrganizationEmailConfirmationService::confirmUrl()`) point at the wrong host. Empty `APP_FRONTEND_URL` falls back to `APP_URL` via `?:` — the `config()` default doesn’t rescue an empty string.
- Formula rows: allocation identity is the normalized org name (`OrganizationPayout::makeKey`). On `develop`, `DonationFormulaController` rejects the same org twice in one formula (the duplicate row would be silently unpayable). Rows store a `payout_status` / `is_verified` snapshot, but reads re-derive it from the `organizations` registry (`enrichWithVerification`) — keep the live registry authoritative or verify/unverify won’t reflect on articles.
- `ConfirmModal` renders text via `tx()`: an existing i18n key is translated, anything else is shown verbatim. Pass a key or an already-translated string; the prop defaults are valid keys.
- Only `App\Services\Organization\OrganizationRegistryService` is live. The old `app/Services/OrganizationRegistryService.php` was deleted — don’t resurrect that path.
- Payment config is DB-owned (`payment_methods`): `config` = non-secret metadata (mode, currencies, webhook route/events, limits); `credentials` = encrypted client id/secret/webhook secret (`encrypted:array`, `$hidden`, not fillable). Read via `App\Services\Payment\PaymentCredentials` → `PaymentMethod::mode()` / `credential()` → `PayPalClient` / `StripeProvider`. Public keys come from `GET v1/payment-methods` (`public_key`); admin views read all rows via `GET v1/admin/payment-methods`. `PayPalClient` defaults to sandbox when the registry is unreadable. Donor checkout is method-parameterised (`v1/payment/{method}/create-order|capture-order|status/{reference}`); the only webhook URL is `v1/webhooks/{method}`.
- Currency (on `develop`): payouts are USD-only (`config/donations.php`, `App\Rules\SupportedCurrency`). Legacy non-USD `completed` donations are excluded from balances — `php artisan donations:legacy-currency` lists them; never sum them as dollars. `main` still has the older multi-currency balance math.
- Payout reconciliation (on `develop`): an ambiguous submit (timeout with no response, or provider 5xx) must stay `pending`, never `failed` — the money may have moved and `failed` invites a retry. `payouts:sync` re-submits with the same `sender_batch_id` (the row `uuid`); a duplicate-id rejection reconciles to the original batch. Status transitions are guarded (`whereIn('status', …)`) so a webhook reversal racing a poll can’t be overwritten. Never release a stuck pending row or mint a new uuid to retry.
- Payout sync performance: `syncPending()` polls batches through `PayPalClient::showBatchPayouts()` (`Http::pool`), and the OAuth token is cached (`paypal:access_token:{sha1(baseUrl|client_id)}`). Don’t reintroduce per-row `showBatchPayout()` polling (each call is ~4–7s, so ten pending rows turned a 5s sync into 70s) or per-instance token fetches. Indexes for these queries live in `2026_11_12_000001_add_payout_sync_indexes.php` — keep `(status, payout_batch_id)` covered.
- Payout balances are snapshot-based: `OrganizationPayoutService::allocationBasis()` derives owed from each donation’s `metadata.formula_snapshot` (the live formula only for legacy rows without one). Never go back to `SUM(donations) × current percentage` — editing a formula rewrote the past, producing negative balances and letting the platform pay out more than it collected. Snapshot-only allocations (org removed from the live formula) stay payable.
- Payout fees are provider-reported, not a fixed rate: the batch sets `fees_payer=RECEIVER`, `amount` stays gross, and `fee_amount` / `net_amount` / `fee_percent` are filled from PayPal’s `payout_item_fee` (or `batch_header.fees`) on create, sync and webhook. `fee_source` records `pending_provider|paypal|legacy_fixed`. `payout_fee_estimate_percent` is display-only for the Pay modal; `operational_cost_percent` is the donor-facing platform cost. Never reintroduce a computed percentage deduction.
- On `develop`, payout balances/history are rename-safe: match by `organization_key` **or** `organization_id`, never the key alone, or renaming an org blanks its history while the balance still shows paid. Scheduler: `payouts:sync` every 15 min plus `--recheck-paid` daily.
- Webhook idempotency lives in `payment_webhook_events` (`PaymentWebhookEvent`), **not** in the cache. The unique `(provider, event_id)` key is the lock; a deploy's `cache:clear` used to erase the old file-cache marker, so a PayPal retransmission looked new. Rows are written *before* signature verification (forged requests must leave a trace); a `failed` row is re-opened on retry, `processed`/`ignored` are final. Signature headers are stored as a SHA-256 prefix only — never add the raw header bag. `webhooks:prune --days=90` is scheduled daily and never deletes `failed` rows.
