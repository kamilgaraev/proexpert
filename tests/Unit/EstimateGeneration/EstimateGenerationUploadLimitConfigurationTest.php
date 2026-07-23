<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest;
use PHPUnit\Framework\TestCase;

class EstimateGenerationUploadLimitConfigurationTest extends TestCase
{
    public function test_ai_estimate_document_request_allows_two_hundred_megabyte_files(): void
    {
        $rules = (new UploadEstimateGenerationDocumentsRequest())->rules();

        self::assertContains('max:204800', $rules['files.*']);
    }

    public function test_public_nginx_templates_allow_two_hundred_megabyte_uploads(): void
    {
        foreach ([
            'scripts/nginx-config-admin.conf',
            'scripts/nginx-config-api.conf',
            'scripts/nginx-config-lk.conf',
        ] as $relativePath) {
            $contents = file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $relativePath);

            preg_match_all('/client_max_body_size\s+(\d+)M\s*;/i', (string) $contents, $matches);

            self::assertNotEmpty($matches[1], $relativePath);

            foreach ($matches[1] as $limit) {
                self::assertGreaterThanOrEqual(200, (int) $limit, $relativePath);
            }
        }
    }
}
