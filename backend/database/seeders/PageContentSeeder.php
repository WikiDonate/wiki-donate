<?php

namespace Database\Seeders;

use App\Models\PageContent;
use Illuminate\Database\Seeder;

class PageContentSeeder extends Seeder
{
    public function run(): void
    {
        PageContent::firstOrCreate(
            ['page' => 'how-it-works'],
            [
                'content' => [
                    [
                        'title' => 'Search for a cause or idea',
                        'icon' => ['fas', 'search'],
                        'description' => [
                            ['type' => 'text', 'value' => 'On the search bar, you can search for a cause that you want to donate to like '],
                            ['type' => 'link', 'value' => 'diabetes'],
                        ],
                    ],
                    [
                        'title' => 'Choose a donation formula',
                        'icon' => ['fas', 'calculator'],
                        'description' => [
                            ['type' => 'text', 'value' => "A donation formula defines what collection of recipients and in what proportions would be best to distribute the donation in order to help your selected cause or idea. The page may have a list of donation formulas from different editors, such as international diabetes associations. Click 'Donate' on the donation formula that you like best."],
                        ],
                    ],
                    [
                        'title' => 'Donate!',
                        'icon' => ['fas', 'donate'],
                        'description' => [
                            ['type' => 'text', 'value' => 'Currently, only PayPal and Stripe is supported. On the payment page, confirm your donation.'],
                        ],
                    ],
                    [
                        'title' => 'Go further! Start your own cause',
                        'icon' => ['fas', 'lightbulb'],
                        'description' => [
                            ['type' => 'text', 'value' => "You can start a page for a cause or an idea, such as a new strategy to reduce deaths from air pollution. First search for it on the search bar. If it is not available, then click 'Save' to create the new page."],
                        ],
                    ],
                    [
                        'title' => 'Go further! Write your own donation formula',
                        'icon' => ['fas', 'pen'],
                        'description' => [
                            ['type' => 'text', 'value' => "Click the button 'Create donation formula' at the bottom of the page of the cause or idea that you are interested in. Fill in your specifications with the correct spelling. Any donation to a recipient who is unavailable will be refunded back to the donor. Click 'Save'. Now other donors could use your formula too! "],
                        ],
                    ],
                ],
            ]
        );
    }
}
