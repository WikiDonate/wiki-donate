<?php

namespace App\Services\Charity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verified US-charity autocomplete for donation formulas.
 *
 * Only organizations with a valid EIN are ever returned — EIN-less rows
 * cannot be selected, so every saved formula row identifies a real,
 * donatable US charity.
 *
 * Primary: Charity Navigator (rated charities first = popular, established
 * orgs). Requires free self-serve keys; when absent, or on any upstream
 * failure, degrades to the ProPublica Nonprofit Explorer (IRS data, no key).
 *
 * Results are cached for one hour per normalized query, and only non-empty
 * results are cached so a failed call can never poison a query. Never
 * throws: worst case is an empty list with free-text entry as fallback.
 */
class VerifiedCharitySearchService
{
    private const CN_BASE_URL = 'https://api.data.charitynavigator.org/v2/Organizations';

    private const PP_BASE_URL = 'https://projects.propublica.org/nonprofits/api/v2';

    private const CACHE_TTL_SECONDS = 3600;

    private const RESULT_LIMIT = 8;

    /**
     * @return array<int, array{name: string, ein: string, city: string|null, state: string|null, rating: float|null, source: string}>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $cacheKey = 'verified_charity_search:'.md5(mb_strtolower($query));

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $results = $this->searchCharityNavigator($query);

        if (empty($results)) {
            $results = $this->searchProPublica($query);
        }

        if (! empty($results)) {
            Cache::put($cacheKey, $results, self::CACHE_TTL_SECONDS);
        }

        return $results;
    }

    /**
     * Charity Navigator: rated US charities, best-rated first.
     */
    private function searchCharityNavigator(string $query): array
    {
        $appId = config('services.charity_navigator.app_id');
        $appKey = config('services.charity_navigator.app_key');

        if (empty($appId) || empty($appKey)) {
            return [];
        }

        try {
            $response = Http::timeout(3)
                ->acceptJson()
                ->get(self::CN_BASE_URL, [
                    'app_id' => $appId,
                    'app_key' => $appKey,
                    'search' => $query,
                    'searchType' => 'NAME_ONLY',
                    'rated' => 'true',
                    'pageSize' => 25,
                    'pageNum' => 1,
                ]);

            if ($response->failed()) {
                Log::warning('Charity Navigator search error', [
                    'status' => $response->status(),
                    'query' => $query,
                ]);

                return [];
            }

            return $this->normalizeCharityNavigator($response->json() ?? []);
        } catch (\Throwable $e) {
            Log::warning('Charity Navigator search failed: '.$e->getMessage(), ['query' => $query]);

            return [];
        }
    }

    private function normalizeCharityNavigator(array $payload): array
    {
        // The endpoint returns either a bare array or { organizations: [...] }.
        $orgs = array_is_list($payload) ? $payload : ($payload['organizations'] ?? []);
        $results = [];

        foreach ($orgs as $org) {
            if (! is_array($org)) {
                continue;
            }

            $ein = $this->cleanEin($org['ein'] ?? null);
            $name = trim((string) ($org['charityName'] ?? $org['name'] ?? ''));

            // EIN-only: skip anything that cannot identify a real charity.
            if ($ein === null || $name === '') {
                continue;
            }

            $results[] = [
                'name' => $name,
                'ein' => $ein,
                'city' => $org['city'] ?? null,
                'state' => $org['state'] ?? null,
                'rating' => $this->extractRating($org),
                'source' => 'charity_navigator',
            ];
        }

        // Popular first: best-rated at the top, unrated at the bottom.
        usort($results, fn ($a, $b) => ($b['rating'] ?? -1) <=> ($a['rating'] ?? -1));

        return array_slice($results, 0, self::RESULT_LIMIT);
    }

    private function extractRating(array $org): ?float
    {
        foreach (['rating', 'stars'] as $key) {
            if (is_numeric($org[$key] ?? null)) {
                return (float) $org[$key];
            }
        }

        $current = $org['currentRating'] ?? null;
        if (is_array($current)) {
            foreach (['stars', 'score', 'rating'] as $key) {
                if (is_numeric($current[$key] ?? null)) {
                    return (float) $current[$key];
                }
            }
        }

        return null;
    }

    /**
     * ProPublica fallback: IRS tax-exempt data, EIN-filtered.
     */
    private function searchProPublica(string $query): array
    {
        try {
            $response = Http::timeout(3)
                ->acceptJson()
                ->get(self::PP_BASE_URL.'/search.json', ['q' => $query]);

            if ($response->failed()) {
                Log::warning('ProPublica charity search error', [
                    'status' => $response->status(),
                    'query' => $query,
                ]);

                return [];
            }

            $results = [];
            foreach ($response->json('organizations', []) as $org) {
                if (! is_array($org)) {
                    continue;
                }

                $ein = $this->cleanEin($org['ein'] ?? null);
                $name = trim((string) ($org['name'] ?? ''));

                if ($ein === null || $name === '') {
                    continue;
                }

                $results[] = [
                    'name' => $name,
                    'ein' => $ein,
                    'city' => $org['city'] ?? null,
                    'state' => $org['state'] ?? null,
                    'rating' => null,
                    'source' => 'propublica',
                ];

                if (count($results) >= self::RESULT_LIMIT) {
                    break;
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Log::warning('ProPublica charity search failed: '.$e->getMessage(), ['query' => $query]);

            return [];
        }
    }

    /**
     * Canonical EIN form (digits only, uppercased) — matches the
     * Organization model's mutator so registry lookups hit.
     */
    private function cleanEin(mixed $ein): ?string
    {
        if ($ein === null) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $ein));

        return $clean === '' ? null : $clean;
    }
}
