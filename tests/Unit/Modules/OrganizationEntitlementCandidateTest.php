<?php

declare(strict_types=1);

namespace Tests\Unit\Modules;

use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use LogicException;
use PHPUnit\Framework\TestCase;

final class OrganizationEntitlementCandidateTest extends TestCase
{
    public function testInheritedMatchingReadKeepsCustomGetterAndStrictComparison(): void
    {
        $source = new MutableEntitlementCandidateFixture;
        $source->modules = [37 => ['ai-assistant', 'ai_assistant', 0, '0', 'ai-assistant']];

        self::assertSame(['ai-assistant', 'ai-assistant'], $source->getEffectiveModuleSlugsMatching(37, 'ai-assistant'));
        self::assertSame(['0'], $source->getEffectiveModuleSlugsMatching(37, '0'));
        self::assertSame([37, 37], $source->reads);
    }

    public function testCustomGetterRemainsFreshAndOrganizationsRemainSeparate(): void
    {
        $source = new MutableEntitlementCandidateFixture;
        $source->modules = [37 => ['ai-assistant'], 91 => []];
        self::assertSame(['ai-assistant'], $source->getEffectiveModuleSlugsMatching(37, 'ai-assistant'));
        $source->modules[37] = [];

        self::assertSame([], $source->getEffectiveModuleSlugsMatching(37, 'ai-assistant'));
        self::assertSame([], $source->getEffectiveModuleSlugsMatching(91, 'ai-assistant'));
        self::assertSame([37, 37, 91], $source->reads);
    }

    public function testCustomBinaryValuesKeepTheLegacyStrictMembership(): void
    {
        $source = new MutableEntitlementCandidateFixture;
        foreach (["binary\0module", "invalid\xffmodule", str_repeat('x', 1025)] as $candidate) {
            $source->modules = [37 => [$candidate, 'another-module']];
            self::assertSame([$candidate], $source->getEffectiveModuleSlugsMatching(37, $candidate));
        }
        self::assertSame([37, 37, 37], $source->reads);
    }

    public function testMatchingReadPreservesCustomGetterExceptions(): void
    {
        $source = new MutableEntitlementCandidateFixture;
        $source->failure = new LogicException('entitlement_source_unavailable');
        $this->expectExceptionObject($source->failure);

        $source->getEffectiveModuleSlugsMatching(37, 'ai-assistant');
    }
}

final class MutableEntitlementCandidateFixture extends OrganizationEntitlementService
{
    public array $modules = [];
    public array $reads = [];
    public ?LogicException $failure = null;

    public function __construct()
    {
        parent::__construct(new PackageCatalogService);
    }

    public function getEffectiveModuleSlugs(int $organizationId): array
    {
        $this->reads[] = $organizationId;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->modules[$organizationId] ?? [];
    }
}
