<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\CanonicalJson;
use App\Services\LegalArchive\Files\VersionInput;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function test_recursively_sorts_object_keys_but_preserves_list_order_and_scalar_types(): void
    {
        $first = ['z' => [['b' => 2, 'a' => 1.0], 3], 'a' => true];
        $reordered = ['a' => true, 'z' => [['a' => 1.0, 'b' => 2], 3]];

        self::assertSame(CanonicalJson::encode($first), CanonicalJson::encode($reordered));
        self::assertNotSame(CanonicalJson::encode($first), CanonicalJson::encode([
            'a' => true,
            'z' => [3, ['a' => 1.0, 'b' => 2]],
        ]));
        self::assertNotSame(CanonicalJson::encode(['value' => 1]), CanonicalJson::encode(['value' => 1.0]));
    }

    public function test_create_factory_and_version_semantics_share_the_exact_canonical_input(): void
    {
        $first = VersionInput::fromCreateData(30, [
            'version_number' => '7.5',
            'version_label' => 'Оригинал',
            'version_metadata' => ['nested' => ['b' => 2, 'a' => 1.0]],
        ]);
        $reordered = VersionInput::fromCreateData(30, [
            'version_number' => '7.5',
            'version_label' => 'Оригинал',
            'version_metadata' => ['nested' => ['a' => 1.0, 'b' => 2]],
        ]);

        self::assertSame(
            CanonicalJson::encode($first->semanticPayload()),
            CanonicalJson::encode($reordered->semanticPayload()),
        );
        self::assertSame($first->semanticFingerprint(), $reordered->semanticFingerprint());
        self::assertNotSame(
            VersionInput::fromCreateData(30, ['version_metadata' => ['value' => 1]])->semanticFingerprint(),
            VersionInput::fromCreateData(30, ['version_metadata' => ['value' => 1.0]])->semanticFingerprint(),
        );
    }

    public function test_operation_boolean_is_normalized_without_treating_postgres_false_as_truthy(): void
    {
        $operation = (object) [
            'requested_version_number' => '1.0',
            'version_label' => null,
            'uploaded_by_user_id' => 30,
            'version_metadata' => null,
            'make_current' => 'f',
        ];

        self::assertFalse(VersionInput::fromOperation($operation)->makeCurrent);
        $operation->make_current = 't';
        self::assertTrue(VersionInput::fromOperation($operation)->makeCurrent);
        $operation->make_current = 'unknown';
        $this->expectException(\UnexpectedValueException::class);
        VersionInput::fromOperation($operation);
    }
}
