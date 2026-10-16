<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    /**
     * Comprehensive, idempotent demo dataset for local testing.
     *
     * Safe to re-run: every row is keyed by a natural unique attribute
     * (email, slug, EIN, gateway IDs) via firstOrCreate / firstOrNew.
     *
     * Run with: php artisan db:seed --class=DemoDataSeeder
     */
    public function run(): void
    {
        // Roles must exist before assignment when this seeder runs standalone
        // (DatabaseSeeder also calls RoleSeeder first — firstOrCreate keeps
        // both paths safe).
        $this->call(RoleSeeder::class);

        $admin = $this->seedUsers();
        $orgs = $this->seedOrganizations();
        $formulas = $this->seedArticlesAndFormulas($admin, $orgs);
        $this->seedDonations($admin, $formulas);
        $this->seedPayouts($admin, $formulas);

        Cache::store('file')->forget('dashboard');

        $this->command->info('Demo data seeded successfully!');
        $this->command->info('Admin:  gshahaj+admin@gmail.com / Test1234');
        $this->command->info('Editor: editor@demo.local / Test1234');
        $this->command->info('Donor:  donor@demo.local / Test1234');
    }

    private function seedUsers(): User
    {
        $admin = User::firstOrCreate(
            ['email' => 'gshahaj+admin@gmail.com'],
            [
                'username' => 'admin',
                'name' => 'Site Admin',
                'password' => bcrypt('Test1234'),
                'email_verified_at' => now(),
            ],
        );
        if (! $admin->email_verified_at) {
            $admin->email_verified_at = now();
            $admin->save();
        }
        $admin->assignRole('Admin');

        $editor = User::firstOrCreate(
            ['email' => 'editor@demo.local'],
            [
                'username' => 'demoeditor',
                'name' => 'Demo Editor',
                'password' => bcrypt('Test1234'),
                'email_verified_at' => now(),
            ],
        );
        $editor->assignRole('Editor');

        User::firstOrCreate(
            ['email' => 'donor@demo.local'],
            [
                'username' => 'demodonor',
                'name' => 'Demo Donor',
                'password' => bcrypt('Test1234'),
                'email_verified_at' => now(),
            ],
        );

        return $admin;
    }

    /**
     * Real US charities (name + EIN) so formula autocomplete and payouts
     * can be exercised end to end. Two are payout-ready with a confirmed
     * PayPal receiving email; the rest stay unverified on purpose.
     */
    private function seedOrganizations(): array
    {
        $rows = [
            [
                'name' => 'American National Red Cross', 'ein' => '53-0196605',
                'city' => 'Washington', 'state' => 'DC',
                'paypal_email' => 'donations@redcross-demo.local', 'payout_status' => 'verified',
            ],
            [
                'name' => 'UNICEF USA', 'ein' => '13-1760110',
                'city' => 'New York', 'state' => 'NY',
                'paypal_email' => 'giving@unicefusa-demo.local', 'payout_status' => 'verified',
            ],
            [
                'name' => 'Doctors Without Borders USA', 'ein' => '13-3433452',
                'city' => 'New York', 'state' => 'NY',
            ],
            [
                'name' => 'Feeding America', 'ein' => '36-3673599',
                'city' => 'Chicago', 'state' => 'IL',
            ],
            [
                'name' => 'Save the Children Federation', 'ein' => '06-0726487',
                'city' => 'Fairfield', 'state' => 'CT',
            ],
        ];

        $orgs = [];
        foreach ($rows as $row) {
            // The model mutator normalizes EIN on save (strips dashes), but
            // NOT in WHERE clauses — so normalize here for an idempotent lookup.
            $ein = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $row['ein']));
            $org = Organization::firstOrCreate(
                ['ein' => $ein],
                $row + ['verified_at' => ($row['payout_status'] ?? null) === 'verified' ? now() : null],
            );
            $orgs[$row['name']] = $org;
        }

        return $orgs;
    }

    private function seedArticlesAndFormulas(User $admin, array $orgs): array
    {
        $articles = [
            [
                'title' => 'Clean Water Initiative',
                'slug' => 'clean-water-initiative',
                'content' => '<h2>Clean water for everyone</h2><p>Millions lack safe drinking water. This fund splits donations across vetted water charities.</p>',
                'splits' => [
                    ['American National Red Cross', 50],
                    ['UNICEF USA', 30],
                    ['Feeding America', 20],
                ],
            ],
            [
                'title' => 'Education For All',
                'slug' => 'education-for-all',
                'content' => '<h2>Schooling changes lives</h2><p>Support learning programs and school meals around the world.</p>',
                'splits' => [
                    ['UNICEF USA', 60],
                    ['Save the Children Federation', 40],
                ],
            ],
            [
                'title' => 'Health Care Access',
                'slug' => 'health-care-access',
                'content' => '<h2>Care where it is needed most</h2><p>Emergency medical aid and frontline clinics.</p>',
                'splits' => [
                    ['Doctors Without Borders USA', 70],
                    ['American National Red Cross', 30],
                ],
            ],
            [
                'title' => 'Climate Action Fund',
                'slug' => 'climate-action-fund',
                'content' => '<h2>Act on climate</h2><p>Community resilience and disaster response as extremes grow.</p>',
                'splits' => [
                    ['American National Red Cross', 40],
                    ['Feeding America', 35],
                    ['Doctors Without Borders USA', 25],
                ],
            ],
        ];

        $formulas = [];
        foreach ($articles as $a) {
            $article = Article::firstOrCreate(
                ['slug' => $a['slug']],
                [
                    'title' => $a['title'],
                    'content' => $a['content'],
                    'type' => 'article',
                    'user_id' => $admin->id,
                    'is_active' => true,
                ],
            );

            $formulaRows = array_map(fn ($s) => [
                'organization' => $s[0],
                'ein' => $orgs[$s[0]]->ein ?? null,
                'percentage' => $s[1],
            ], $a['splits']);

            $formula = DonationFormula::firstOrCreate(
                ['article_id' => $article->id, 'name' => $a['title'].' Split'],
                [
                    'user_id' => $admin->id,
                    'formula' => $formulaRows,
                    'details' => 'Demo allocation — percentages must total 100%.',
                ],
            );

            $formulas[] = $formula;
        }

        return $formulas;
    }

    private function seedDonations(User $admin, array $formulas): void
    {
        $donor = User::where('email', 'donor@demo.local')->first();

        $demo = [
            // ---- Stripe (completed) ----
            ['key' => ['stripe_session_id' => 'cs_demo_001'], 'pi' => 'pi_demo_001', 'formula' => 0, 'user' => $donor?->id, 'name' => 'Demo Donor', 'email' => 'donor@demo.local', 'amount' => 120.00, 'currency' => 'USD', 'status' => 'completed', 'days' => 1, 'details' => 'Clean water one-time gift.'],
            ['key' => ['stripe_session_id' => 'cs_demo_002'], 'pi' => 'pi_demo_002', 'formula' => 1, 'user' => null, 'name' => 'Guest Giver', 'email' => 'guest@example.com', 'amount' => 45.50, 'currency' => 'USD', 'status' => 'completed', 'days' => 3, 'details' => null],
            ['key' => ['stripe_session_id' => 'cs_demo_003'], 'pi' => 'pi_demo_003', 'formula' => 2, 'user' => $admin->id, 'name' => 'Site Admin', 'email' => 'gshahaj+admin@gmail.com', 'amount' => 500.00, 'currency' => 'USD', 'status' => 'completed', 'days' => 6, 'details' => 'Test pledge.'],
            // ---- PayPal (completed) ----
            ['key' => ['paypal_order_id' => 'PAYD-DEMO-0001'], 'capture' => 'CAPTURE-DEMO-0001', 'formula' => 0, 'user' => null, 'name' => 'Amina Rahman', 'email' => 'amina.rahman@example.com', 'amount' => 250.00, 'currency' => 'USD', 'status' => 'completed', 'days' => 2, 'details' => 'Monthly support.'],
            ['key' => ['paypal_order_id' => 'PAYD-DEMO-0002'], 'capture' => 'CAPTURE-DEMO-0002', 'formula' => 3, 'user' => $donor?->id, 'name' => 'Demo Donor', 'email' => 'donor@demo.local', 'amount' => 75.00, 'currency' => 'EUR', 'status' => 'completed', 'days' => 5, 'details' => null],
            // ---- Other states for dashboard/report coverage ----
            ['key' => ['paypal_order_id' => 'PAYD-DEMO-0003'], 'capture' => null, 'formula' => 1, 'user' => null, 'name' => 'Pending Pat', 'email' => 'pending@example.com', 'amount' => 25.00, 'currency' => 'USD', 'status' => 'pending', 'days' => 0, 'details' => 'Awaiting approval.'],
            ['key' => ['paypal_order_id' => 'PAYD-DEMO-0004'], 'capture' => null, 'formula' => 2, 'user' => null, 'name' => 'Failed Fran', 'email' => 'failed@example.com', 'amount' => 60.00, 'currency' => 'GBP', 'status' => 'failed', 'days' => 4, 'details' => 'Declined.'],
            ['key' => ['stripe_session_id' => 'cs_demo_004'], 'pi' => null, 'formula' => 3, 'user' => null, 'name' => 'Expired Ed', 'email' => null, 'amount' => 15.00, 'currency' => 'USD', 'status' => 'expired', 'days' => 12, 'details' => null],
        ];

        foreach ($demo as $row) {
            $formula = $formulas[$row['formula']] ?? null;
            $isPaypal = isset($row['key']['paypal_order_id']);

            $metadata = [
                'source' => $isPaypal ? 'paypal' : 'stripe_checkout',
                'details' => $row['details'],
            ];

            $donation = Donation::firstOrNew($row['key']);
            if (! $donation->exists) {
                $donation->fill([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $row['user'],
                    'donation_formula_id' => $formula?->id,
                    'stripe_payment_intent_id' => $row['pi'] ?? null,
                    'paypal_order_id' => $row['key']['paypal_order_id'] ?? null,
                    'donor_name' => $row['name'],
                    'donor_email' => $row['email'],
                    'amount' => $row['amount'],
                    'currency' => $row['currency'],
                    'status' => $row['status'],
                    'metadata' => $metadata,
                ]);
                if (! empty($row['capture'])) {
                    $metadata['payment_id'] = $row['capture'];
                    $donation->metadata = $metadata;
                }
                $donation->saveQuietly();
                $donation->forceFill([
                    'created_at' => Carbon::now()->subDays($row['days']),
                    'updated_at' => Carbon::now()->subDays($row['days']),
                ])->saveQuietly();
            }
        }
    }

    private function seedPayouts(User $admin, array $formulas): void
    {
        $first = $formulas[0] ?? null;
        if (! $first) {
            return;
        }

        $payouts = [
            ['org' => 'American National Red Cross', 'amount' => 120.00, 'method' => 'paypal', 'note' => 'First quarterly disbursement', 'days' => 2],
            ['org' => 'UNICEF USA', 'amount' => 50.00, 'method' => 'paypal', 'note' => 'Partial payment', 'days' => 1],
        ];

        foreach ($payouts as $p) {
            $exists = OrganizationPayout::where('donation_formula_id', $first->id)
                ->where('organization_key', OrganizationPayout::makeKey($p['org']))
                ->where('amount', $p['amount'])
                ->exists();

            if ($exists) {
                continue;
            }

            OrganizationPayout::create([
                'donation_formula_id' => $first->id,
                'organization_name' => $p['org'],
                'organization_key' => OrganizationPayout::makeKey($p['org']),
                'amount' => $p['amount'],
                'currency' => 'usd',
                'type' => 'partial',
                'status' => 'paid',
                'paid_at' => Carbon::now()->subDays($p['days']),
                'actor_id' => $admin->id,
                'method' => $p['method'],
                'note' => $p['note'],
            ]);
        }
    }
}
