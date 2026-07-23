<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Files\LegalDocumentFilePolicy;
use App\Services\LegalArchive\Files\LegalDocumentFileRejected;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

final class LegalDocumentFilePolicyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = [
            'max_size_bytes' => 1024,
            'allowed_extensions' => ['pdf', 'docx'],
            'allowed_mime_types' => [
                'pdf' => ['application/pdf'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ],
        ];
    }

    public function test_accepts_file_when_size_extension_and_detected_mime_match_allowlist(): void
    {
        $upload = UploadedFile::fake()->createWithContent('contract.pdf', "%PDF-1.7\ncontract");

        (new LegalDocumentFilePolicy($this->configuration))->assertUploadAllowed($upload);

        self::assertTrue(true);
    }

    public function test_rejects_disguised_executable_even_when_client_mime_claims_pdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'legal-document-');
        self::assertIsString($path);
        file_put_contents($path, "MZ\x90\x00executable");

        try {
            $upload = new UploadedFile($path, 'contract.pdf', 'application/pdf', UPLOAD_ERR_OK, true);

            $this->expectException(LegalDocumentFileRejected::class);

            (new LegalDocumentFilePolicy($this->configuration))->assertUploadAllowed($upload);
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_extension_outside_allowlist(): void
    {
        $upload = UploadedFile::fake()->createWithContent('contract.exe', 'MZ');

        $this->expectException(LegalDocumentFileRejected::class);

        (new LegalDocumentFilePolicy($this->configuration))->assertUploadAllowed($upload);
    }

    public function test_rejects_file_larger_than_configured_limit(): void
    {
        $upload = UploadedFile::fake()->createWithContent('contract.pdf', "%PDF-1.7\n".str_repeat('a', 2048));

        $this->expectException(LegalDocumentFileRejected::class);

        (new LegalDocumentFilePolicy($this->configuration))->assertUploadAllowed($upload);
    }
}
