<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PayPalCharityScraper
{
    private const BASE_URL = 'https://www.paypal.com/fundraiser/hub';

    private const CACHE_TTL_MINUTES = 60;

    private const MAX_CHARITIES = 1000;

    public const CACHE_PREFIX = 'paypal_charities:top'.self::MAX_CHARITIES.':';

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function scrapeCharities(string $keyword = ''): array
    {
        $cacheKey = $this->getCacheKey($keyword);

        $cachedData = Cache::get($cacheKey);
        if ($cachedData !== null) {
            return [
                'data' => $cachedData['data'],
                'meta' => [
                    'total' => $cachedData['total'],
                    'cached' => true,
                    'cached_at' => $cachedData['cached_at'],
                ],
            ];
        }

        return $this->scrapeAndCache($keyword);
    }

    public function scrapeAndCache(string $keyword = ''): array
    {
        $cacheKey = $this->getCacheKey($keyword);

        try {
            $charities = $this->fetchTopCharities($keyword);
            $total = count($charities);

            $cachedAt = now()->toIso8601String();
            Cache::put($cacheKey, [
                'data' => $charities,
                'total' => $total,
                'cached_at' => $cachedAt,
            ], now()->addMinutes(self::CACHE_TTL_MINUTES));

            return [
                'data' => $charities,
                'meta' => [
                    'total' => $total,
                    'cached' => false,
                    'cached_at' => $cachedAt,
                ],
            ];
        } catch (\Exception $e) {
            report($e);

            return [
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'cached' => false,
                    'cached_at' => null,
                    'error' => 'Failed to fetch charities: '.$e->getMessage(),
                ],
            ];
        }
    }

    private function fetchTopCharities(string $keyword): array
    {
        $allCharities = [];
        $pagesNeeded = (int) ceil(self::MAX_CHARITIES / 24);

        for ($page = 1; $page <= $pagesNeeded && count($allCharities) < self::MAX_CHARITIES; $page++) {
            $charities = $this->fetchCharitiesFromPage($keyword, $page);

            if (empty($charities)) {
                break;
            }

            foreach ($charities as $charity) {
                if (count($allCharities) >= self::MAX_CHARITIES) {
                    break 2;
                }
                $allCharities[] = $charity;
            }
        }

        return $allCharities;
    }

    private function fetchCharitiesFromPage(string $keyword, int $page): array
    {
        $queryParams = [
            'keywords' => $keyword,
            'page' => $page,
            'pageSize' => 24,
            'sortBy' => 'trending',
            'type' => 'charities',
        ];

        $url = self::BASE_URL.'?'.http_build_query($queryParams);

        $response = Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ])->get($url);

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch PayPal page: '.$response->status());
        }

        $html = $response->body();

        return $this->extractCharityData($html);
    }

    private function extractCharityData(string $html): array
    {
        $charities = [];

        $pattern = '/\{"nonprofit_id":"(\d+)","name":"([^"]+)","ein":"([^"]*)"(.*?)\}/s';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $id = $match[1];
                $name = $match[2];
                $ein = $match[3];
                $rest = $match[4];

                $description = null;
                if (preg_match('/"description":"([^"]*)"/', $rest, $descMatch)) {
                    $description = $this->cleanDescription($descMatch[1]);
                }

                $charityType = null;
                if (preg_match('/"charity_type":"([^"]*)"/', $rest, $typeMatch)) {
                    $charityType = $typeMatch[1];
                }

                $categories = [];
                if (preg_match_all('/"name":"([^"]+)".*?"cause_area"/', $rest, $catMatches)) {
                    $categories = $catMatches[1];
                }

                $charities[] = [
                    'id' => $id,
                    'name' => $name,
                    'description' => $description,
                    'logo' => null,
                    'category' => ! empty($categories) ? implode(', ', $categories) : null,
                    'ein' => $ein,
                    'url' => 'https://www.paypal.com/fundraiser/charity/'.$id,
                    'charity_type' => $charityType,
                ];
            }
        }

        return $charities;
    }

    private function cleanDescription(string $description): string
    {
        $description = strip_tags($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');

        return trim($description);
    }

    private function getCacheKey(string $keyword): string
    {
        $keywordSlug = $keyword === '' ? 'all' : preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($keyword));

        return self::CACHE_PREFIX.$keywordSlug;
    }
}
