<?php

declare(strict_types=1);

namespace Tests\Unit\Enterprise\MultiOrganization\Website;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

final class HoldingSitePreviewTokenTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 5).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_new_preview_links_work_and_previously_public_tokens_are_revoked(): void
    {
        $site = $this->site();
        parse_str((string) parse_url($site->getPreviewUrl(), PHP_URL_QUERY), $query);
        self::assertTrue($site->isValidPreviewToken($query['token']));
        $legacyToken = hash('sha256', $site->id.$site->updated_at->timestamp.config('app.key'));
        self::assertFalse($site->isValidPreviewToken($legacyToken));
    }

    public function test_preview_token_stays_bound_to_the_site_and_current_update(): void
    {
        $site = $this->site();
        parse_str((string) parse_url($site->getPreviewUrl(), PHP_URL_QUERY), $query);
        $site->id = 8;
        self::assertFalse($site->isValidPreviewToken($query['token']));
        $site->id = 7;
        $site->updated_at = '2026-10-15 07:31:00';
        self::assertFalse($site->isValidPreviewToken($query['token']));
        self::assertFalse($site->isValidPreviewToken('invalid'));
    }

    private function site(): HoldingSite
    {
        $site = new HoldingSite;
        $site->forceFill(['id' => 7, 'domain' => 'holding.example.test', 'updated_at' => '2026-10-15 07:30:00']);

        return $site;
    }
}
