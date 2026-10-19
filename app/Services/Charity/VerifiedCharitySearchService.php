<?php

namespace App\Services\Charity;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verified US-charity autocomplete — ADMIN USE ONLY.
 *
 * This is directory identity lookup used by the admin "Add organization"
 * modal to seed the curated `organizations` registry. It is exposed through
 * `GET /v1/charities/search`, which is guarded by auth:sanctum + role:Admin.
 * Donor/editor formula autocomplete reads the registry instead
 * (`GET /v1/admin/organizations`), so no donor-facing request ever reaches a
 * third-party charity API. Do not call this from donor paths.
 *
 * External directory results (Charity Navigator / ProPublica) are EIN-only
 * so every selectable row identifies a real, donatable US charity. Local
 * registry orgs with payout_status=verified are merged first — including
 * admin-verified custom names without an EIN — so a verification immediately
 * makes the org selectable in the admin picker.
 *
 * Primary: Charity Navigator (rated charities first = popular, established
 * orgs). Requires free self-serve keys; when absent, or on any upstream
 * failure, degrades to the ProPublica Nonprofit Explorer (IRS data, no key).
 *
 * Only external results are cached (one hour per normalized query); local
 * registry hits are always fresh so verification shows up immediately, and
 * only non-empty external results are cached so a failed call can never
 * poison a query. Never throws: worst case is an empty list with free-text
 * entry as fallback.
 */
class VerifiedCharitySearchService
{
    private const CN_BASE_URL = 'https://api.data.charitynavigator.org/v2/Organizations';

    private const PP_BASE_URL = 'https://projects.propublica.org/nonprofits/api/v2';

    private const CACHE_TTL_SECONDS = 3600;

    private const RESULT_LIMIT = 10;

    /**
     * @return array<int, array{name: string, ein: string|null, city: string|null, state: string|null, rating: float|null, source: string}>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $external = $this->cachedExternalSearch($query);
        $local = $this->searchLocalRegistry($query);

        if (empty($local)) {
            return $external;
        }

        // Local verified orgs first; skip external dupes (same EIN or name).
        $seenEin = [];
        $seenName = [];
        foreach ($local as $row) {
            if (! empty($row['ein'])) {
                $seenEin[$row['ein']] = true;
            }
            $seenName[Organization::normalizeName($row['name'])] = true;
        }

        $merged = $local;
        foreach ($external as $row) {
            $ein = $row['ein'] ?? null;
            $nameKey = Organization::normalizeName($row['name'] ?? '');
            if (($ein && isset($seenEin[$ein])) || isset($seenName[$nameKey])) {
                continue;
            }
            $merged[] = $row;
            if (count($merged) >= self::RESULT_LIMIT) {
                break;
            }
        }

        return array_slice($merged, 0, self::RESULT_LIMIT);
    }

    /**
     * Admin-verified local orgs matching the query — always fresh (never
     * cached) so a verification is selectable immediately.
     */
    private function searchLocalRegistry(string $query): array
    {
        try {
            $orgs = Organization::query()
                ->where('payout_status', 'verified')
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', '%'.$query.'%')
                        ->orWhere('ein', 'like', '%'.$query.'%');
                })
                ->orderBy('name')
                ->limit(self::RESULT_LIMIT)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Local organization search failed: '.$e->getMessage(), ['query' => $query]);

            return [];
        }

        return $orgs->map(fn (Organization $org) => [
            'name' => $org->name,
            'ein' => $org->ein,
            'city' => $org->city,
            'state' => $org->state,
            'website' => $org->website,
            'rating' => null,
            'source' => 'local',
            'verified' => true,
        ])->all();
    }

    private function cachedExternalSearch(string $query): array
    {
        $cacheKey = 'verified_charity_search:external:'.md5(mb_strtolower($query));

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
                'website' => $this->cleanWebsite($org['website'] ?? $org['charityWebsite'] ?? null),
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
                    'website' => $this->cleanWebsite($org['website'] ?? null),
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
     * Normalize a directory-provided website to an absolute HTTPS URL.
     * Returns null for anything that is not plausibly a URL.
     */
    private function cleanWebsite(mixed $website): ?string
    {
        $website = trim((string) ($website ?? ''));

        if ($website === '' || str_contains($website, ' ')) {
            return null;
        }

        if (! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }

        if (filter_var($website, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return mb_substr($website, 0, 500);
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
