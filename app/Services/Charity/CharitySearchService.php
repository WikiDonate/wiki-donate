<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * US charity identity lookup backed by the ProPublica Nonprofit Explorer
 * API (IRS tax-exempt data, ~1.9M orgs, free, no key required).
 *
 * This resolves identity only (legal name + EIN + location). It does NOT
 * provide a payout endpoint — sending money additionally requires a
 * confirmed receiving address (e.g. PayPal email) stored per organization.
 *
 * Results are cached for one hour per normalized query: directory data
 * barely changes, and this keeps autocomplete fast while cutting ~90%+
 * of external calls versus live lookup per keystroke.
 */
class CharitySearchService
{
    private const BASE_URL = 'https://projects.propublica.org/nonprofits/api/v2';

    private const CACHE_TTL_SECONDS = 3600;

    private const RESULT_LIMIT = 8;

    /**
     * Search US tax-exempt organizations by keyword.
     *
     * Never throws: external failure degrades to an empty list so the
     * formula modal always stays usable with free-text entry.
     *
     * @return array<int, array{name: string, ein: string|null, city: string|null, state: string|null}>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $cacheKey = 'charity_search:'.md5(mb_strtolower($query));

        // Cache hits must never hide fresh data: only non-empty results are
        // cached, so one slow/failed upstream call can't poison a query with
        // an empty list for the whole TTL.
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $results = $this->fetch($query);

        if (! empty($results)) {
            Cache::put($cacheKey, $results, self::CACHE_TTL_SECONDS);
        }

        return $results;
    }

    /**
     * Query the upstream API. Never throws — failure degrades to [].
     *
     * @return array<int, array{name: string, ein: string|null, city: string|null, state: string|null}>
     */
    private function fetch(string $query): array
    {
        try {
            $response = Http::timeout(3)
                ->acceptJson()
                ->get(self::BASE_URL.'/search.json', ['q' => $query]);

            if ($response->failed()) {
                Log::warning('Charity search upstream error', [
                    'status' => $response->status(),
                    'query' => $query,
                ]);

                return [];
            }

            return $this->normalize($response->json('organizations', []));
        } catch (\Throwable $e) {
            Log::warning('Charity search failed: '.$e->getMessage(), ['query' => $query]);

            return [];
        }
    }

    /**
     * Trim the upstream payload to the fields the UI needs.
     */
    private function normalize(array $organizations): array
    {
        $results = [];

        foreach ($organizations as $org) {
            if (! is_array($org) || empty($org['name'])) {
                continue;
            }

            $results[] = [
                'name' => (string) $org['name'],
                'ein' => isset($org['ein']) ? (string) $org['ein'] : null,
                'city' => $org['city'] ?? null,
                'state' => $org['state'] ?? null,
            ];

            if (count($results) >= self::RESULT_LIMIT) {
                break;
            }
        }

        return $results;
    }
}
