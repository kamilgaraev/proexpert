<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\Services\LegalArchive\Workflow\LegalWorkflowResubmissionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalWorkflowResubmissionPolicyTest extends TestCase
{
    #[DataProvider('sameVersionTerminalStatuses')]
    public function test_same_version_cannot_be_resubmitted_after_a_content_terminal_decision(string $status): void
    {
        self::assertTrue(LegalWorkflowResubmissionPolicy::requiresNewVersion(
            $this->instance($status, 19, str_repeat('a', 64)),
            $this->version(19, str_repeat('a', 64)),
        ));
    }

    public function test_new_content_and_retryable_terminal_states_can_be_submitted(): void
    {
        $version = $this->version(20, str_repeat('b', 64));

        self::assertFalse(LegalWorkflowResubmissionPolicy::requiresNewVersion(
            $this->instance('approved', 19, str_repeat('a', 64)),
            $version,
        ));
        self::assertFalse(LegalWorkflowResubmissionPolicy::requiresNewVersion(
            $this->instance('cancelled', 20, str_repeat('b', 64)),
            $version,
        ));
        self::assertFalse(LegalWorkflowResubmissionPolicy::requiresNewVersion(
            $this->instance('expired', 20, str_repeat('b', 64)),
            $version,
        ));
    }

    public static function sameVersionTerminalStatuses(): array
    {
        return [
            'approved' => ['approved'],
            'rejected' => ['rejected'],
            'returned' => ['returned'],
        ];
    }

    private function instance(string $status, int $versionId, string $contentHash): LegalWorkflowInstance
    {
        return (new LegalWorkflowInstance)->forceFill([
            'status' => $status,
            'document_version_id' => $versionId,
            'document_content_hash' => $contentHash,
        ]);
    }

    private function version(int $id, string $contentHash): LegalArchiveDocumentVersion
    {
        return (new LegalArchiveDocumentVersion)->forceFill([
            'id' => $id,
            'content_hash' => $contentHash,
        ]);
    }
}
