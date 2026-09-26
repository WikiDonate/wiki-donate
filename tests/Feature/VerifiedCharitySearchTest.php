<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VerifiedCharitySearchTest extends TestCase
{
    private function cnPayload(): array
    {
        return [
            ['charityName' => 'Low Rated Org', 'ein' => '11-1111111', 'city' => 'Austin', 'state' => 'TX', 'currentRating' => ['stars' => 2]],
            ['charityName' => 'Top Rated Org', 'ein' => '22-2222222', 'city' => 'Boston', 'state' => 'MA', 'currentRating' => ['stars' => 4]],
            ['charityName' => 'No Ein Org', 'city' => 'Nowhere', 'state' => 'ZZ'],
            ['charityName' => '', 'ein' => '33-3333333'],
        ];
    }

    private function ppPayload(): array
    {
        return [
            'organizations' => [
                ['name' => 'American National Red Cross', 'ein' => 530196605, 'city' => 'Washington', 'state' => 'DC'],
                ['name' => 'Einless Org'],
            ],
        ];
    }

    public function test_charity_navigator_results_are_ein_filtered_and_rating_sorted(): void
    {
        config(['services.charity_navigator.app_id' => 'test-id']);
        config(['services.charity_navigator.app_key' => 'test-key']);
        Http::fake([
            'api.data.charitynavigator.org/*' => Http::response($this->cnPayload(), 200),
        ]);

        $data = $this->getJson('/api/v1/charities/search?q=top%20rated')->json('data');

        $this->assertCount(2, $data);
        // Popular first: 4 stars before 2 stars; EIN-less rows dropped.
        $this->assertSame('Top Rated Org', $data[0]['name']);
        $this->assertSame('222222222', $data[0]['ein']);
        $this->assertEquals(4, $data[0]['rating']);
        $this->assertSame('charity_navigator', $data[0]['source']);
        $this->assertSame('Low Rated Org', $data[1]['name']);
    }

    public function test_falls_back_to_propublica_when_navigator_fails(): void
    {
        config(['services.charity_navigator.app_id' => 'test-id']);
        config(['services.charity_navigator.app_key' => 'test-key']);
        Http::fake([
            'api.data.charitynavigator.org/*' => Http::response(null, 500),
            'projects.propublica.org/*' => Http::response($this->ppPayload(), 200),
        ]);

        $data = $this->getJson('/api/v1/charities/search?q=red%20cross')->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('American National Red Cross', $data[0]['name']);
        $this->assertSame('530196605', $data[0]['ein']);
        $this->assertSame('propublica', $data[0]['source']);
    }

    public function test_propublica_only_when_keys_missing(): void
    {
        config(['services.charity_navigator.app_id' => null]);
        config(['services.charity_navigator.app_key' => null]);
        Http::fake([
            'projects.propublica.org/*' => Http::response($this->ppPayload(), 200),
        ]);

        $data = $this->getJson('/api/v1/charities/search?q=red%20cross')->json('data');

        $this->assertCount(1, $data);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'propublica'));
    }

    public function test_repeat_query_is_served_from_cache(): void
    {
        config(['services.charity_navigator.app_id' => null]);
        config(['services.charity_navigator.app_key' => null]);
        Http::fake([
            'projects.propublica.org/*' => Http::response($this->ppPayload(), 200),
        ]);

        $this->getJson('/api/v1/charities/search?q=red%20cross')->assertOk();
        $this->getJson('/api/v1/charities/search?q=Red%20Cross')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_empty_results_are_not_cached(): void
    {
        Http::fake(['projects.propublica.org/*' => Http::response(null, 500)]);

        $this->getJson('/api/v1/charities/search?q=red%20cross')->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/v1/charities/search?q=red%20cross')->assertOk()
            ->assertJsonPath('data', []);

        Http::assertSentCount(2);
    }

    public function test_short_query_returns_empty_without_http_call(): void
    {
        Http::fake();

        $this->getJson('/api/v1/charities/search?q=x')->assertOk()
            ->assertJsonPath('data', []);
        Http::assertNothingSent();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
