<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Signatures\LegalContractSignerCoverage;
use PHPUnit\Framework\TestCase;

final class LegalContractSignerCoverageTest extends TestCase
{
    public function test_full_party_legal_name_is_preserved_without_relaxing_manual_signer_limits(): void
    {
        $name = str_repeat('Д', 512);
        $signer = new \App\Services\LegalArchive\Signatures\SignerIdentity('party', $name, partyId: 1, partyRole: 'other');
        self::assertSame($name, $signer->canonical()['name']);
        $this->expectException(\DomainException::class);
        new \App\Services\LegalArchive\Signatures\SignerIdentity('manual', $name);
    }

    public function test_every_frozen_party_must_be_covered_by_completed_signer_sets(): void
    {
        $parties = [['id' => 11, 'party_organization_id' => 7, 'tax_number' => '0012345678'], ['id' => 12], ['id' => 13]];
        $first = [['kind' => 'party', 'party_id' => 11]];
        $second = [['kind' => 'party', 'party_id' => 12]];
        $third = [['kind' => 'party', 'party_id' => 13]];
        self::assertFalse(LegalContractSignerCoverage::covers($parties, [$first]));
        self::assertFalse(LegalContractSignerCoverage::covers($parties, [$first, $second]));
        self::assertTrue(LegalContractSignerCoverage::covers($parties, [$first, $second, $third]));
        self::assertTrue(LegalContractSignerCoverage::covers($parties, [[...$first, ...$second, ...$third]]));
        self::assertFalse(LegalContractSignerCoverage::covers($parties, [[...$first, ...$second, ['kind' => 'manual', 'party_id' => 13]]]));
        self::assertFalse(LegalContractSignerCoverage::covers([], [$first]));
    }

    public function test_organization_identity_requires_the_exact_frozen_organization_and_tax_number(): void
    {
        $party = [['id' => 11, 'party_organization_id' => 7, 'tax_number' => '0012345678']];
        self::assertTrue(LegalContractSignerCoverage::covers($party, [[['kind' => 'organization', 'organization_id' => 7, 'tax_number' => '0012345678']]]));
        self::assertFalse(LegalContractSignerCoverage::covers($party, [[['kind' => 'organization', 'organization_id' => 7, 'tax_number' => '12345678']]]));
        self::assertFalse(LegalContractSignerCoverage::covers($party, [[['kind' => 'organization', 'organization_id' => 8, 'tax_number' => '0012345678']]]));
        self::assertFalse(LegalContractSignerCoverage::covers($party, [[['kind' => 'user', 'organization_id' => 7]]]));
        self::assertFalse(LegalContractSignerCoverage::covers($party, [[['kind' => 'party', 'party_id' => 99]]]));
    }
}
