<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use App\Services\Privacy\Contracts\PrivateField;
use App\Services\Privacy\Contracts\PrivateProjection;
use App\Services\Privacy\Contracts\PrivateSourceVersion;
use App\Services\Privacy\Contracts\PrivacyCategory;
use App\Services\Privacy\Contracts\SafeContent;
use App\Services\Privacy\Contracts\SafeRepresentation;
use App\Services\Privacy\Contracts\SyntheticFixture;
use App\Services\Privacy\PrivateProjectionFactory;
use App\Services\Privacy\SafeRepresentationFactory;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PrivateProjectionTest.php';

final class SafeRepresentationTest extends TestCase
{
    private static function fixture(): array
    {
        $authority = new TestProjectionAuthority();
        $projections = new PrivateProjectionFactory($authority);
        $projection = $projections->create(PrivateProjectionTest::input())->value();
        self::assertInstanceOf(PrivateProjection::class, $projection);

        return [$authority, $projections, $projection, SafeRepresentationFactory::forOfflineFixture(
            $projections,
            SyntheticFixture::named('concrete-quantity-v1'),
        )];
    }

    public function testProductionFactoryDoesNotTreatASyntheticDTOAsImplementedPrivatePipeline(): void
    {
        [, $projections, $projection] = self::fixture();
        $decision = (new SafeRepresentationFactory($projections))->create($projection);
        self::assertFalse($decision->isReady());
        self::assertSame('required_stage_unavailable', $decision->reason());
    }

    public function testKnownStructuredPIIAndLocalCategoriesAreSuppressedWithoutNER(): void
    {
        [$authority, $projections] = self::fixture();
        foreach (['person_name', 'contact', 'real_id', 'salary', 'passport', 'snils', 'medical', 'payment', 'exact_address', 'owner_secret'] as $name) {
            $authority->fieldList[] = new PrivateField($name, PrivacyCategory::Business, 'PRIVATE-' . $name);
        }
        $projection = $projections->create(PrivateProjectionTest::input())->value();
        self::assertInstanceOf(PrivateProjection::class, $projection);
        $factory = SafeRepresentationFactory::forOfflineFixture($projections, SyntheticFixture::named('concrete-quantity-v1'));
        $decision = $factory->create($projection);
        self::assertTrue($decision->isReady());
        $representation = $decision->value();
        self::assertInstanceOf(SafeRepresentation::class, $representation);
        $json = json_encode($representation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('12.5 м³', $json);
        self::assertStringNotContainsString('PRIVATE-', $json);
        foreach (['actorId', 'organizationId', 'projectId', 'privateSourceId', '123456789', '223456789', '323456789', '423456789'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        $export = $representation->jsonSerialize();
        self::assertSame('offline-synthetic-fixture/1', $export['sanitizerVersion']);
        foreach (['representationRef', 'scopeRef', 'sourceGenerationRef'] as $key) {
            self::assertMatchesRegularExpression('/^ref_[a-f0-9]{32}$/D', $export[$key]);
        }
    }

    #[DataProvider('staleCases')]
    public function testSafeConstructionRepeatsCurrentSourcePrincipalAndFieldACL(string $case): void
    {
        [$authority, , $projection, $factory] = self::fixture();
        match ($case) {
            'actor_revoked' => $authority->context = null,
            'membership_changed' => $authority->context = new AuthenticatedPrivateContext(123456789, 999, 323456789, 'policy/2', 'acl/2'),
            'principal_acl_changed' => $authority->context = new AuthenticatedPrivateContext(
                123456789, 223456789, 323456789,
                'most-ai-v1-purpose-policy/0.2-product-approved-20261005', 'acl/2',
            ),
            'selection_revoked' => $authority->selectionAllowed = false,
            'field_revoked' => $authority->deniedFields = ['quantity'],
            'source_changed' => $authority->current = TestProjectionAuthority::source(revision: 'fixture/2'),
            'source_acl_changed' => $authority->current = new PrivateSourceVersion(
                423456789, 223456789, 323456789, 'fixture/1', 'synthetic-concrete/1',
                'source-acl/2', sourceClass: 'synthetic',
            ),
            'field_changed' => $authority->fieldList[1] = new PrivateField('quantity', PrivacyCategory::Business, '999'),
            'dependency_down' => $authority->unavailable = true,
        };
        self::assertFalse($factory->create($projection)->isReady());
    }

    public static function staleCases(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'actor_revoked', 'membership_changed', 'principal_acl_changed', 'selection_revoked', 'field_revoked',
            'source_changed', 'source_acl_changed', 'field_changed', 'dependency_down',
        ]);
    }

    #[DataProvider('unscannedSurfaces')]
    public function testUnknownCategoriesAndAuxiliarySurfacesCannotObtainASeal(string $field, PrivacyCategory $category): void
    {
        [$authority, $projections, , $factory] = self::fixture();
        $authority->fieldList[] = new PrivateField($field, $category, 'PRIVATE/raw prompt/example/JSON key');
        $projection = $projections->create(PrivateProjectionTest::input())->value();
        self::assertInstanceOf(PrivateProjection::class, $projection);
        self::assertFalse($factory->create($projection)->isReady());
    }

    public static function unscannedSurfaces(): array
    {
        return [
            ['arbitrary_key', PrivacyCategory::Business],
            ['system_prompt', PrivacyCategory::FreeText],
            ['developer_prompt', PrivacyCategory::FreeText],
            ['tool_schema', PrivacyCategory::FreeText],
            ['schema_example', PrivacyCategory::FreeText],
            ['json_key', PrivacyCategory::FreeText],
            ['ambiguous_text', PrivacyCategory::Unknown],
        ];
    }

    public function testAClientCannotDeclareRawQuestionSafeOrSupplyPublicSourceClass(): void
    {
        [$authority, $projections, , $factory] = self::fixture();
        $projection = $projections->create(PrivateProjectionTest::input('raw PII паспорт Иванов'))->value();
        self::assertInstanceOf(PrivateProjection::class, $projection);
        self::assertSame('fixture_mismatch', $factory->create($projection)->reason());

        $authority->sourceList = [TestProjectionAuthority::source(sourceClass: 'public')];
        $authority->current = $authority->sourceList[0];
        $projection = $projections->create(PrivateProjectionTest::input())->value();
        self::assertInstanceOf(PrivateProjection::class, $projection);
        self::assertSame('fixture_mismatch', $factory->create($projection)->reason());
    }

    public function testForeignCreatorIsRejectedEvenWithTheSameAuthority(): void
    {
        [$authority, , $projection] = self::fixture();
        $other = SafeRepresentationFactory::forOfflineFixture(
            new PrivateProjectionFactory($authority),
            SyntheticFixture::named('concrete-quantity-v1'),
        );
        self::assertSame('untrusted_creator', $other->create($projection)->reason());
    }

    public function testRevokeAtTheFinalCheckDoesNotSealContent(): void
    {
        [$authority, , $projection, $factory] = self::fixture();
        $authority->revokeAtAuthentication = $authority->authenticateCalls + 3;
        self::assertFalse($factory->create($projection)->isReady());
    }

    public function testSerializedExportDoesNotRetainMutableOwnedReferences(): void
    {
        [, , $projection, $factory] = self::fixture();
        $safe = $factory->create($projection)->value();
        self::assertInstanceOf(SafeRepresentation::class, $safe);
        $before = json_encode($safe, JSON_THROW_ON_ERROR);
        $export = $safe->jsonSerialize();
        $export['content']['text']['utf8Text'] = 'raw altered';
        $export['is_safe'] = true;
        self::assertSame($before, json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertNotSame($export, $safe->jsonSerialize());
    }

    #[DataProvider('closedClasses')]
    public function testConstructorsCloneAndDeserializationCannotMintClosedTypes(string $class): void
    {
        try {
            new $class();
            self::fail('Closed constructor was public');
        } catch (\Error) {
            self::assertTrue(true);
        }

        $payload = 'O:' . strlen($class) . ':"' . $class . '":0:{}';
        try {
            unserialize($payload, ['allowed_classes' => [$class]]);
            self::fail('Untrusted deserialization minted a closed type');
        } catch (LogicException) {
            self::assertTrue(true);
        }

        [, , $projection, $factory] = self::fixture();
        $object = match ($class) {
            PrivateProjection::class => $projection,
            SafeContent::class => SafeContent::prepare($factory, $projection)->value(),
            SafeRepresentation::class => $factory->create($projection)->value(),
            SyntheticFixture::class => SyntheticFixture::named('concrete-quantity-v1'),
        };
        self::assertIsObject($object);
        $this->expectException(\Error::class);
        clone $object;
    }

    public static function closedClasses(): array
    {
        return [[PrivateProjection::class], [SafeContent::class], [SafeRepresentation::class], [SyntheticFixture::class]];
    }

    public function testArbitraryDTOOrIsSafeFlagIsNotAFactoryInput(): void
    {
        [, , , $factory] = self::fixture();
        $this->expectException(\TypeError::class);
        $factory->create((object) ['is_safe' => true, 'content' => 'raw private']);
    }
}
