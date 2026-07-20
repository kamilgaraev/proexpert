<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Audit\LegalDocumentSourceEventId;
use App\Services\LegalArchive\LegalDocumentCreateFailed;
use App\Services\LegalArchive\Sources\LegalDocumentCreateRequestFingerprint;
use App\Services\LegalArchive\Sources\LegalDocumentSourceCreateIdentity;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalDocumentSourceCreateReplayTest extends TestCase
{
    public function test_same_tenant_actor_source_and_key_is_a_canonical_replay(): void
    {
        $identity = LegalDocumentSourceCreateIdentity::fromInput(20, 7, $this->sourceInput());
        $document = $this->document(20, 7, 'contract', '41', 'erp-command-9');

        self::assertNotNull($identity);
        self::assertTrue($identity->matches($document));
    }

    #[DataProvider('mismatchProvider')]
    public function test_actor_tenant_source_or_key_mismatch_is_not_a_replay(array $attributes): void
    {
        $identity = LegalDocumentSourceCreateIdentity::fromInput(20, 7, $this->sourceInput());

        self::assertNotNull($identity);
        self::assertFalse($identity->matches($this->document(...$attributes)));
    }

    public static function mismatchProvider(): array
    {
        return [
            'tenant' => [[21, 7, 'contract', '41', 'erp-command-9']],
            'actor' => [[20, 8, 'contract', '41', 'erp-command-9']],
            'type' => [[20, 7, 'purchase_order', '41', 'erp-command-9']],
            'source id' => [[20, 7, 'contract', '42', 'erp-command-9']],
            'key' => [[20, 7, 'contract', '41', 'erp-command-10']],
            'deleted dossier' => [[20, 7, 'contract', '41', 'erp-command-9', true]],
        ];
    }

    public function test_source_event_id_uses_source_key_and_actor_tenant_namespace(): void
    {
        $first = LegalDocumentSourceCreateIdentity::fromInput(20, 7, $this->sourceInput());
        $otherActor = LegalDocumentSourceCreateIdentity::fromInput(20, 8, $this->sourceInput());
        $otherTenant = LegalDocumentSourceCreateIdentity::fromInput(21, 7, $this->sourceInput());

        self::assertNotNull($first);
        self::assertNotNull($otherActor);
        self::assertNotNull($otherTenant);
        self::assertSame(
            LegalDocumentSourceEventId::canonical('create:org-20:actor-7:contract:41', 'erp-command-9'),
            $first->sourceEventId(),
        );
        self::assertNotSame($first->sourceEventId(), $otherActor->sourceEventId());
        self::assertNotSame($first->sourceEventId(), $otherTenant->sourceEventId());
    }

    public function test_system_actor_has_a_stable_isolated_namespace(): void
    {
        $identity = LegalDocumentSourceCreateIdentity::fromInput(20, null, $this->sourceInput());

        self::assertNotNull($identity);
        self::assertSame(
            LegalDocumentSourceEventId::canonical('create:org-20:actor-system:contract:41', 'erp-command-9'),
            $identity->sourceEventId(),
        );
        self::assertTrue($identity->matches($this->document(20, null, 'contract', '41', 'erp-command-9')));
        self::assertFalse($identity->matches($this->document(20, 7, 'contract', '41', 'erp-command-9')));
    }

    public function test_winner_of_a_concurrent_create_is_replayable_by_the_same_command(): void
    {
        $firstAttempt = LegalDocumentSourceCreateIdentity::fromInput(20, 7, $this->sourceInput());
        $retryAfterUniqueViolation = LegalDocumentSourceCreateIdentity::fromInput(20, 7, $this->sourceInput());
        $winner = $this->document(20, 7, 'contract', '41', 'erp-command-9');

        self::assertNotNull($firstAttempt);
        self::assertNotNull($retryAfterUniqueViolation);
        self::assertSame($firstAttempt->sourceEventId(), $retryAfterUniqueViolation->sourceEventId());
        self::assertTrue($retryAfterUniqueViolation->matches($winner));
    }

    public function test_request_fingerprint_covers_payload_actor_tenant_source_and_file_identity(): void
    {
        $file = UploadedFile::fake()->createWithContent('contract.pdf', 'same-pdf-content');
        $data = $this->sourceInput() + [
            'title' => 'Договор поставки',
            'type_profile_code' => 'supply_contract',
            'file' => $file,
            'metadata' => ['b' => 2, 'a' => 1],
            'links' => [['link_type' => 'source', 'display_name' => 'Поставка']],
        ];

        $first = LegalDocumentCreateRequestFingerprint::fromRequest(20, 7, $data, $file);
        $reordered = LegalDocumentCreateRequestFingerprint::fromRequest(20, 7, [
            ...$this->sourceInput(),
            'links' => $data['links'],
            'metadata' => ['a' => 1, 'b' => 2],
            'type_profile_code' => 'supply_contract',
            'title' => 'Договор поставки',
        ], $file);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first);
        self::assertSame($first, $reordered);
        self::assertNotSame($first, LegalDocumentCreateRequestFingerprint::fromRequest(20, 8, $data, $file));
        self::assertNotSame($first, LegalDocumentCreateRequestFingerprint::fromRequest(20, 7, [...$data, 'title' => 'Иной'], $file));
        self::assertNotSame(
            $first,
            LegalDocumentCreateRequestFingerprint::fromRequest(
                20,
                7,
                $data,
                UploadedFile::fake()->createWithContent('contract.pdf', 'different-pdf-content'),
            ),
        );
    }

    public function test_long_source_and_aggregate_event_ids_are_canonical_and_bounded(): void
    {
        $key = str_repeat('ключ', 47).'x';
        $identity = LegalDocumentSourceCreateIdentity::fromInput(20, 7, [
            'source_type' => 'contract', 'source_id' => 41, 'source_idempotency_key' => $key,
        ]);

        self::assertNotNull($identity);
        self::assertLessThanOrEqual(191, strlen($identity->sourceEventId()));
        self::assertMatchesRegularExpression('/:[a-f0-9]{64}$/D', $identity->sourceEventId());
        $aggregate = LegalDocumentSourceEventId::canonical('legal_document:999', $identity->sourceEventId());
        self::assertLessThanOrEqual(191, strlen($aggregate));
        self::assertSame($aggregate, LegalDocumentSourceEventId::canonical($aggregate));
        self::assertStringNotContainsString($key, $identity->sourceEventId());
    }

    public function test_durable_create_failure_preserves_document_and_original_error_for_recovery(): void
    {
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 17, 'source_create_status' => 'failed']);
        $original = new \RuntimeException('s3_upload_failed');
        $failure = new LegalDocumentCreateFailed($document, false, $original);

        self::assertSame($document, $failure->document);
        self::assertSame($original, $failure->getPrevious());
        self::assertSame('s3_upload_failed', $failure->getMessage());
        self::assertFalse($failure->repeatCreateRequired);
    }

    #[DataProvider('partialSourceProvider')]
    public function test_partial_source_identity_is_rejected_before_persistence(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        LegalDocumentSourceCreateIdentity::fromInput(20, 7, $input);
    }

    public static function partialSourceProvider(): array
    {
        return [
            'type only' => [['source_type' => 'contract']],
            'id only' => [['source_id' => 41]],
            'key only' => [['source_idempotency_key' => 'erp-command-9']],
            'type and id' => [['source_type' => 'contract', 'source_id' => 41]],
            'type and key' => [['source_type' => 'contract', 'source_idempotency_key' => 'erp-command-9']],
            'id and key' => [['source_id' => 41, 'source_idempotency_key' => 'erp-command-9']],
        ];
    }

    public function test_absent_source_identity_keeps_manual_create_available(): void
    {
        self::assertNull(LegalDocumentSourceCreateIdentity::fromInput(20, 7, []));
    }

    public function test_source_identity_is_normalized_before_persistence_and_replay(): void
    {
        $identity = LegalDocumentSourceCreateIdentity::fromInput(20, 7, [
            'source_type' => ' contract ',
            'source_id' => '0041',
            'source_idempotency_key' => ' erp-command-9 ',
        ]);

        self::assertNotNull($identity);
        self::assertSame([
            'source_type' => 'contract',
            'source_id' => '41',
            'source_idempotency_key' => 'erp-command-9',
        ], $identity->normalizeInput([]));
        self::assertTrue($identity->matches($this->document(20, 7, 'contract', '41', 'erp-command-9')));
    }

    #[DataProvider('invalidSourceProvider')]
    public function test_invalid_source_identity_is_rejected_by_service_boundary(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        LegalDocumentSourceCreateIdentity::fromInput(20, 7, $input);
    }

    public static function invalidSourceProvider(): array
    {
        return [
            'whitespace type' => [[
                'source_type' => '   ', 'source_id' => 41, 'source_idempotency_key' => 'command',
            ]],
            'unsupported type' => [[
                'source_type' => 'invoice', 'source_id' => 41, 'source_idempotency_key' => 'command',
            ]],
            'array id' => [[
                'source_type' => 'contract', 'source_id' => [41], 'source_idempotency_key' => 'command',
            ]],
            'zero id' => [[
                'source_type' => 'contract', 'source_id' => 0, 'source_idempotency_key' => 'command',
            ]],
            'negative id' => [[
                'source_type' => 'contract', 'source_id' => -1, 'source_idempotency_key' => 'command',
            ]],
            'decimal id' => [[
                'source_type' => 'contract', 'source_id' => '1.5', 'source_idempotency_key' => 'command',
            ]],
            'whitespace key' => [[
                'source_type' => 'contract', 'source_id' => 41, 'source_idempotency_key' => '   ',
            ]],
            'oversized key' => [[
                'source_type' => 'contract', 'source_id' => 41, 'source_idempotency_key' => str_repeat('a', 192),
            ]],
        ];
    }

    public function test_registry_maps_unique_race_to_replay_or_deterministic_conflict(): void
    {
        $registry = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/LegalArchiveRegistryService.php');
        $request = file_get_contents(__DIR__.'/../../../app/Http/Requests/Api/V1/Admin/LegalArchive/StoreLegalArchiveDocumentRequest.php');

        self::assertIsString($registry);
        self::assertIsString($request);
        self::assertStringContainsString('catch (QueryException $exception)', $registry);
        self::assertStringContainsString('resolveSourceCreateReplay(', $registry);
        self::assertStringContainsString('normalizeInput($data)', $registry);
        self::assertStringContainsString("->where('source_idempotency_key', \$identity->idempotencyKey)", $registry);
        self::assertStringContainsString("'source_event_id' => \$sourceCreateIdentity?->sourceEventId()", $registry);
        self::assertStringNotContainsString("\$data['idempotency_key']", $registry);
        self::assertStringNotContainsString('forceDelete()', $registry);
        self::assertStringContainsString("'source_create_status' => 'pending'", $registry);
        self::assertStringContainsString('source_request_fingerprint', $registry);
        self::assertStringContainsString("'source_create_status' => 'failed'", $registry);
        self::assertStringContainsString("'source_create_status' => 'completed'", $registry);
        self::assertStringContainsString("->where('source_create_status', 'completed')", $registry);
        self::assertStringContainsString('completeCreateAfterVersion(', $registry);
        self::assertLessThan(
            strpos($registry, '$sourceCreateIdentity = $this->sourceCreateIdentity'),
            strpos($registry, '$this->documentFileService->assertUploadAllowed($file)'),
        );
        self::assertStringContainsString('required_with:source_id,source_idempotency_key', $request);
        self::assertStringContainsString('required_with:source_type,source_idempotency_key', $request);
        self::assertStringContainsString('required_with:source_type,source_id', $request);
    }

    private function sourceInput(): array
    {
        return [
            'source_type' => 'contract',
            'source_id' => 41,
            'source_idempotency_key' => 'erp-command-9',
        ];
    }

    private function document(
        int $organizationId,
        ?int $actorId,
        string $sourceType,
        string $sourceId,
        string $key,
        bool $deleted = false,
    ): LegalArchiveDocument {
        $document = new LegalArchiveDocument;
        $document->setRawAttributes([
            'organization_id' => $organizationId,
            'created_by_user_id' => $actorId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_idempotency_key' => $key,
            'deleted_at' => $deleted ? '2026-07-20 12:00:00' : null,
        ]);

        return $document;
    }
}
