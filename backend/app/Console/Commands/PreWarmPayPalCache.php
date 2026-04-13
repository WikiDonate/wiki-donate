<?php

namespace App\Console\Commands;

use App\Services\PayPalCharityScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PreWarmPayPalCache extends Command
{
    protected $signature = 'paypal:scrape-charities
                            {--keywords= : Comma-separated keywords (default: all common categories) }
                            {--refresh : Clear cache before scraping }';

    protected $description = 'Pre-warm PayPal charities cache for fast API responses';

    private const DEFAULT_KEYWORDS = [
        'education',
        'health',
        'children',
        'animals',
        'disaster',
        'environment',
        'hunger',
        'women',
        'medical',
        'community',
    ];

    public function handle(PayPalCharityScraper $scraper): int
    {
        $keywordsOption = $this->option('keywords');
        $refresh = $this->option('refresh');

        $keywords = $keywordsOption
            ? explode(',', $keywordsOption)
            : self::DEFAULT_KEYWORDS;

        $keywords = array_map('trim', $keywords);

        $this->info('Pre-warming PayPal charities cache...');
        $this->info('Keywords: '.implode(', ', $keywords));
        $this->newLine();

        $bar = $this->output->createProgressBar(count($keywords));
        $bar->start();

        $totalCharities = 0;
        $errors = [];

        foreach ($keywords as $keyword) {
            try {
                if ($refresh) {
                    $keywordSlug = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower(trim($keyword)));
                    $cacheKey = PayPalCharityScraper::CACHE_PREFIX.$keywordSlug;
                    Cache::forget($cacheKey);
                }

                $result = $scraper->scrapeAndCache($keyword);
                $count = $result['meta']['total'] ?? 0;
                $totalCharities += $count;
            } catch (\Exception $e) {
                $errors[] = "{$keyword}: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('✓ Pre-warming complete!');
        $this->info("  Total charities cached: {$totalCharities}");

        if (! empty($errors)) {
            $this->warn('Errors encountered:');
            foreach ($errors as $error) {
                $this->warn("  - {$error}");
            }
        }

        return Command::SUCCESS;
    }

    private function clearCache(array $keywords): void
    {
        foreach ($keywords as $keyword) {
            $keywordSlug = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower(trim($keyword)));
            $cacheKey = PayPalCharityScraper::CACHE_PREFIX.$keywordSlug;
            cache()->forget($cacheKey);
        }

        $this->info('Cache cleared.');
        $this->newLine();
    }
}
