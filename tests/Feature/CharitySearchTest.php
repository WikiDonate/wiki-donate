<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CharitySearchTest extends TestCase
{
    use RefreshDatabase;

    private function upstreamPayload(): array
    {
        return [
            'organizations' => [
                [
                    'name' => 'American Red Cross',
                    'ein' => '530196605',
                    'city' => 'Washington',
                    'state' => 'DC',
                    'extra_noise' => 'dropped',
                ],
                ['name' => '', 'ein' => '000000000'],
                'not-an-array',
            ],
        ];
    }

    public function test_search_returns_normalized_results(): void
    {
        Http::fake([
            'projects.propublica.org/*' => Http::response($this->upstreamPayload(), 200),
        ]);

        $response = $this->getJson('/api/v1/charities/search?q=red%20cross');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [
                [
                    'name' => 'American Red Cross',
                    'ein' => '530196605',
                    'city' => 'Washington',
                    'state' => 'DC',
                ],
            ]);
    }

    public function test_short_query_returns_empty_without_http_call(): void
    {
        Http::fake();

        $response = $this->getJson('/api/v1/charities/search?q=x');

        $response->assertOk()->assertJsonPath('data', []);
        Http::assertNothingSent();
    }

    public function test_upstream_failure_degrades_to_empty_list(): void
    {
        Http::fake(['projects.propublica.org/*' => Http::response(null, 500)]);

        $response = $this->getJson('/api/v1/charities/search?q=red%20cross');

        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_repeat_query_is_served_from_cache(): void
    {
        Http::fake([
            'projects.propublica.org/*' => Http::response($this->upstreamPayload(), 200),
        ]);

        $this->getJson('/api/v1/charities/search?q=red%20cross')->assertOk();
        $this->getJson('/api/v1/charities/search?q=Red%20Cross')->assertOk();

        // Case-insensitive cache key: one upstream call for both.
        Http::assertSentCount(1);
    }

    public function test_empty_results_are_not_cached(): void
    {
        Http::fake(['projects.propublica.org/*' => Http::response(null, 500)]);

        $this->getJson('/api/v1/charities/search?q=red%20cross')->assertOk()
            ->assertJsonPath('data', []);
        // A failed upstream call must not poison the query: retry hits upstream again.
        $this->getJson('/api/v1/charities/search?q=red%20cross')->assertOk()
            ->assertJsonPath('data', []);

        Http::assertSentCount(2);
    }

    public function test_formula_rows_accept_optional_ein(): void
    {
        Cache::flush();
        Http::fake(['projects.propublica.org/*' => Http::response($this->upstreamPayload(), 200)]);

        $search = $this->getJson('/api/v1/charities/search?q=red%20cross')->json('data');

        $this->assertNotEmpty($search);
        $this->assertArrayHasKey('ein', $search[0]);
    }
}
