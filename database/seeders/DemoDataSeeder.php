<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure admin user exists
        $admin = User::firstOrCreate(
            ['email' => 'gshahaj+admin@gmail.com'],
            [
                'username' => 'admin',
                'password' => bcrypt('Test1234'),
                'email_verified_at' => now(),
            ],
        );
        if (! $admin->email_verified_at) {
            $admin->email_verified_at = now();
            $admin->save();
        }
        $admin->assignRole('Admin');

        // Articles with formulas
        $articles = [
            ['title' => 'Clean Water Initiative', 'slug' => 'clean-water-initiative'],
            ['title' => 'Education For All', 'slug' => 'education-for-all'],
            ['title' => 'Health Care Access', 'slug' => 'health-care-access'],
            ['title' => 'Climate Action Fund', 'slug' => 'climate-action-fund'],
        ];

        foreach ($articles as $i => $a) {
            $article = Article::create([
                'slug' => $a['slug'],
                'title' => $a['title'],
            ]);

            $formula = DonationFormula::create([
                'article_id' => $article->id,
                'user_id' => $admin->id,
                'name' => $a['title'].' Split',
                'formula' => [
                    ['organization' => 'Charity A', 'organization_id' => null, 'percentage' => 40],
                    ['organization' => 'Charity B', 'organization_id' => null, 'percentage' => 35],
                    ['organization' => 'Charity C', 'organization_id' => null, 'percentage' => 25],
                ],
            ]);

            // Demo donations across past weeks
            for ($d = 0; $d < 5; $d++) {
                $donation = Donation::create([
                    'uuid' => (string) Str::uuid(),
                    'donation_formula_id' => $formula->id,
                    'user_id' => $admin->id,
                    'donor_name' => fake()->name(),
                    'donor_email' => fake()->email(),
                    'amount' => fake()->randomFloat(2, 10, 500),
                    'currency' => 'usd',
                    'status' => 'completed',
                    'created_at' => Carbon::now()->subDays($i * 10 + $d * 2),
                ]);
                // Force created_at via raw update since it's not fillable
                $donation->timestamps = false;
                $donation->created_at = Carbon::now()->subDays($i * 10 + $d * 2);
                $donation->save();
                $donation->timestamps = true;
            }
        }

        // Payouts on some formulas
        $f1 = DonationFormula::first();
        if ($f1) {
            OrganizationPayout::create([
                'donation_formula_id' => $f1->id,
                'organization_name' => 'Charity A',
                'organization_key' => OrganizationPayout::makeKey('Charity A'),
                'amount' => 120.00,
                'currency' => 'usd',
                'type' => 'partial',
                'status' => 'paid',
                'paid_at' => Carbon::now()->subDays(2),
                'actor_id' => $admin->id,
                'method' => 'bank',
                'note' => 'First quarterly disbursement',
            ]);

            OrganizationPayout::create([
                'donation_formula_id' => $f1->id,
                'organization_name' => 'Charity B',
                'organization_key' => OrganizationPayout::makeKey('Charity B'),
                'amount' => 50.00,
                'currency' => 'usd',
                'type' => 'partial',
                'status' => 'paid',
                'paid_at' => Carbon::now()->subDays(1),
                'actor_id' => $admin->id,
                'method' => 'bkash',
                'note' => 'Partial payment',
            ]);
        }

        $this->command->info('Demo data seeded successfully!');
        $this->command->info('Login: gshahaj+admin@gmail.com / Test1234');
    }
}
