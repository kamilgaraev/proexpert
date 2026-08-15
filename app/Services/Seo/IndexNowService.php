<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use RuntimeException;

final class IndexNowService
{
    public function __construct(
        private readonly Factory $http,
        private readonly Repository $config,
    ) {}

    /**
     * @param  array<int, string>  $urls
     */
    public function submit(array $urls): void
    {
        $settings = (array) $this->config->get('blog.indexnow', []);

        if (! (bool) ($settings['enabled'] ?? false)) {
            return;
        }

        $urls = array_values(array_unique(array_filter(
            $urls,
            static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false,
        )));

        if ($urls === []) {
            return;
        }

        $response = $this->http
            ->timeout((int) ($settings['timeout_seconds'] ?? 10))
            ->acceptJson()
            ->post((string) $settings['endpoint'], [
                'host' => (string) $settings['host'],
                'key' => (string) $settings['key'],
                'keyLocation' => (string) $settings['key_location'],
                'urlList' => $urls,
            ]);

        if (! in_array($response->status(), [200, 202], true)) {
            throw new RuntimeException('IndexNow request failed with HTTP '.$response->status());
        }
    }
}
