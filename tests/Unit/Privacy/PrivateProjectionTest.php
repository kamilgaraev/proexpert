<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use App\Services\Privacy\Contracts\PrivateField;
use App\Services\Privacy\Contracts\PrivateProjection;
use App\Services\Privacy\Contracts\PrivateProjectionAuthority;
use App\Services\Privacy\Contracts\PrivateSourceVersion;
use App\Services\Privacy\Contracts\PrivacyCategory;
use App\Services\Privacy\Contracts\ProjectionInput;
use App\Services\Privacy\Contracts\SyntheticFixture;
use App\Services\Privacy\PrivateProjectionFactory;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestProjectionAuthority implements PrivateProjectionAuthority
{
    public ?AuthenticatedPrivateContext $context;
    public array $sourceList;
    public array $fieldList;
    public ?PrivateSourceVersion $current;
    public bool $selectionAllowed = true;
    public bool $projectRequired = true;
    public array $deniedFields = [];
    public bool $unavailable = false;
    public int $authenticateCalls = 0;
    public ?int $revokeAtAuthentication = null;

    public function __construct()
    {
        $this->context = new AuthenticatedPrivateContext(
            123456789,
            223456789,
            323456789,
            'most-ai-v1-purpose-policy/0.2-product-approved-20261005',
            'acl/1',
        );
        $source = self::source();
        $this->sourceList = [$source];
        $this->current = $source;
        $this->fieldList = [
            new PrivateField('material', PrivacyCategory::Business, 'бетон'),
            new PrivateField('quantity', PrivacyCategory::Business, '12.5'),
            new PrivateField('unit', PrivacyCategory::Business, 'm3'),
            new PrivateField('selected_text', PrivacyCategory::FreeText, SyntheticFixture::named('concrete-quantity-v1')->text()),
        ];
    }

    public static function source(
        int $organizationId = 223456789,
        ?int $projectId = 323456789,
        string $revision = 'fixture/1',
        string $sourceClass = 'synthetic',
        string $deletionState = 'present',
        string $freshness = 'fresh',
    ): PrivateSourceVersion {
        return new PrivateSourceVersion(
            423456789,
            $organizationId,
            $projectId,
            $revision,
            'synthetic-concrete/1',
            'source-acl/1',
            $deletionState,
            $freshness,
            $sourceClass,
        );
    }

    public function authenticate(): ?AuthenticatedPrivateContext
    {
        $this->authenticateCalls++;

        if ($this->unavailable) {
            throw new RuntimeException('SECRET raw SQL person passport /private/customer');
        }

        if ($this->revokeAtAuthentication !== null && $this->authenticateCalls >= $this->revokeAtAuthentication) {
            return null;
        }

        return $this->context;
    }

    public function requiresProject(AuthenticatedPrivateContext $context): bool
    {
        return $this->projectRequired;
    }

    public function sources(AuthenticatedPrivateContext $context, ProjectionInput $input): array
    {
        return $this->sourceList;
    }

    public function allowsSelection(
        AuthenticatedPrivateContext $context,
        ProjectionInput $input,
        PrivateSourceVersion $source,
    ): bool {
        return $this->selectionAllowed && $input->selectedUnitId() === $source->sourceId();
    }

    public function allowsField(
        AuthenticatedPrivateContext $context,
        PrivateSourceVersion $source,
        PrivateField $field,
    ): bool {
        return !in_array($field->name(), $this->deniedFields, true);
    }

    public function fields(AuthenticatedPrivateContext $context, PrivateSourceVersion $source): array
    {
        return $this->fieldList;
    }

    public function currentSource(
        AuthenticatedPrivateContext $context,
        PrivateSourceVersion $source,
    ): ?PrivateSourceVersion {
        return $this->current;
    }
}

final class PrivateProjectionTest extends TestCase
{
    public static function input(string $question = 'Покажи объём бетона.'): ProjectionInput
    {
        return new ProjectionInput($question, 423456789);
    }

    public function testPrincipalComesFromAuthorityAndSelectionIsSeparatelyAuthorized(): void
    {
        $authority = new TestProjectionAuthority();
        $factory = new PrivateProjectionFactory($authority);
        $decision = $factory->create(self::input());

        self::assertTrue($decision->isReady());
        $projection = $decision->value();
        self::assertInstanceOf(PrivateProjection::class, $projection);
        self::assertSame(123456789, $projection->context()->actorId());
        self::assertSame(223456789, $projection->context()->organizationId());
        self::assertNotSame($projection->input()->selectedUnitId(), $projection->context()->actorId());
        self::assertSame('access_denied', $factory->create(new ProjectionInput('Покажи объём бетона.', 999))->reason());
    }

    public function testUnboundAuthorityIsBlocked(): void
    {
        self::assertSame('dependency_unavailable', (new PrivateProjectionFactory())->create(self::input())->reason());
    }

    #[DataProvider('denialCases')]
    public function testSecurityDependenciesFailClosed(string $case): void
    {
        $authority = new TestProjectionAuthority();

        match ($case) {
            'missing_auth' => $authority->context = null,
            'missing_project' => $authority->context = new AuthenticatedPrivateContext(123456789, 223456789, null, 'policy/1', 'acl/1'),
            'tenant' => $authority->sourceList = [TestProjectionAuthority::source(999)],
            'project' => $authority->sourceList = [TestProjectionAuthority::source(projectId: 999)],
            'entity_acl' => $authority->selectionAllowed = false,
            'field_acl' => $authority->deniedFields = ['quantity'],
            'deleted' => $authority->sourceList = [TestProjectionAuthority::source(deletionState: 'deleted')],
            'stale' => $authority->sourceList = [TestProjectionAuthority::source(freshness: 'stale')],
            'unknown_source' => $authority->sourceList = [TestProjectionAuthority::source(sourceClass: 'unknown')],
            'unknown_freshness' => $authority->sourceList = [TestProjectionAuthority::source(freshness: 'unknown')],
            'unknown_current' => $authority->current = null,
            'version_changed' => $authority->current = TestProjectionAuthority::source(revision: 'fixture/2'),
            'unavailable' => $authority->unavailable = true,
            'empty_sources' => $authority->sourceList = [],
            'arbitrary_source' => $authority->sourceList = [(object) ['is_safe' => true]],
            'arbitrary_field' => $authority->fieldList = [(object) ['is_safe' => true]],
            'duplicate_source' => $authority->sourceList[] = $authority->sourceList[0],
            'duplicate_field' => $authority->fieldList[] = $authority->fieldList[0],
        };

        $decision = (new PrivateProjectionFactory($authority))->create(self::input());
        self::assertFalse($decision->isReady());
        self::assertNull($decision->value());
        self::assertStringNotContainsString('SECRET', json_encode($decision, JSON_THROW_ON_ERROR));
    }

    public static function denialCases(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'missing_auth', 'missing_project', 'tenant', 'project', 'entity_acl', 'field_acl',
            'deleted', 'stale', 'unknown_source', 'unknown_freshness', 'unknown_current',
            'version_changed', 'unavailable', 'empty_sources', 'arbitrary_source', 'arbitrary_field',
            'duplicate_source', 'duplicate_field',
        ]);
    }

    public function testRevokeDuringProjectionDoesNotReturnAReadySnapshot(): void
    {
        $authority = new TestProjectionAuthority();
        $authority->revokeAtAuthentication = 2;
        self::assertFalse((new PrivateProjectionFactory($authority))->create(self::input())->isReady());
    }

    public function testPrivateValuesCannotBeSerialized(): void
    {
        $authority = new TestProjectionAuthority();
        $projection = (new PrivateProjectionFactory($authority))->create(self::input())->value();
        foreach ([$authority->context, $authority->sourceList[0], $authority->fieldList[0], self::input(), $projection] as $private) {
            foreach (['json', 'php'] as $mode) {
                try {
                    $mode === 'json' ? json_encode($private, JSON_THROW_ON_ERROR) : serialize($private);
                    self::fail('Private value was serialized');
                } catch (LogicException $exception) {
                    self::assertSame('private_serialization_forbidden', $exception->getMessage());
                }
            }
        }
    }

    public function testProjectionOwnsImmutableFieldSnapshots(): void
    {
        $authority = new TestProjectionAuthority();
        $projection = (new PrivateProjectionFactory($authority))->create(self::input())->value();
        self::assertInstanceOf(PrivateProjection::class, $projection);
        $external = $projection->fields();
        $external[0] = new PrivateField('material', PrivacyCategory::Business, 'changed');
        $authority->fieldList[0] = $external[0];
        self::assertSame('бетон', $projection->fields()[0]->value());
        $this->expectException(\Error::class);
        $projection->fields()[0]->value = 'mutated';
    }
}
