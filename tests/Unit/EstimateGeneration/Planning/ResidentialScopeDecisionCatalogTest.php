<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Planning\ResidentialScopeDecisionCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialScopeDecisionCatalogTest extends TestCase
{
    #[Test]
    public function it_validates_complete_bounded_decisions(): void
    {
        $result = (new ResidentialScopeDecisionCatalog)->validate([
            [
                'key' => 'heating_source',
                'option' => 'heat_pump',
                'status' => 'documented',
                'confidence' => 0.87654,
                'evidence_ids' => ['evidence:1'],
            ],
            [
                'key' => 'wastewater_destination',
                'option' => null,
                'status' => 'needs_data',
                'confidence' => 0,
                'evidence_ids' => [],
            ],
        ], ['evidence:1'], [
            'heating_source' => ['option' => 'heat_pump', 'evidence_ids' => ['evidence:1']],
        ]);

        self::assertSame('heat_pump', $result['heating_source']['option'] ?? null);
        self::assertSame(0.8765, $result['heating_source']['confidence'] ?? null);
        self::assertSame('needs_data', $result['wastewater_destination']['status'] ?? null);
    }

    #[Test]
    public function documented_decision_without_server_verified_option_is_rejected(): void
    {
        $rows = $this->validRows();
        $rows[0] = [
            'key' => 'heating_source',
            'option' => 'heat_pump',
            'status' => 'documented',
            'confidence' => 0.9,
            'evidence_ids' => ['evidence:1'],
        ];

        self::assertNull((new ResidentialScopeDecisionCatalog)->validate($rows, ['evidence:1']));
    }

    #[Test]
    public function ai_contract_excludes_documented_and_exposes_safe_preliminary_defaults(): void
    {
        $definitions = (new ResidentialScopeDecisionCatalog)->aiDefinitions();

        self::assertSame('electric_boiler', $definitions['heating_source']['preliminary_default']);
        self::assertSame('septic', $definitions['wastewater_destination']['preliminary_default']);
        foreach ($definitions as $definition) {
            self::assertSame(['preliminary', 'needs_data'], $definition['statuses']);
            self::assertNotContains('documented', $definition['statuses']);
        }
    }

    #[Test]
    public function preliminary_decision_cannot_override_versioned_safe_default(): void
    {
        $rows = $this->validRows();
        $rows[0]['option'] = 'gas_boiler';

        self::assertNull((new ResidentialScopeDecisionCatalog)->validate($rows, []));
    }

    #[Test]
    public function preliminary_decision_cannot_claim_documentary_evidence(): void
    {
        $rows = $this->validRows();
        $rows[0]['evidence_ids'] = ['evidence:1'];

        self::assertNull((new ResidentialScopeDecisionCatalog)->validate($rows, ['evidence:1']));
    }

    #[Test]
    public function it_rejects_extra_keys_unknown_options_and_fake_evidence(): void
    {
        foreach (['extra_key', 'unknown_option', 'fake_evidence'] as $case) {
            $rows = $this->validRows();
            if ($case === 'extra_key') {
                $rows[0]['price'] = 100;
            } elseif ($case === 'unknown_option') {
                $rows[0]['option'] = 'solid_fuel_boiler';
            } else {
                $rows[0]['evidence_ids'] = ['fake:999'];
            }

            self::assertNull(
                (new ResidentialScopeDecisionCatalog)->validate($rows, ['evidence:1']),
                $case,
            );
        }
    }

    private function validRows(): array
    {
        return [
            [
                'key' => 'heating_source',
                'option' => 'electric_boiler',
                'status' => 'preliminary',
                'confidence' => 0.9,
                'evidence_ids' => [],
            ],
            [
                'key' => 'wastewater_destination',
                'option' => 'septic',
                'status' => 'preliminary',
                'confidence' => 0.6,
                'evidence_ids' => [],
            ],
        ];
    }
}
