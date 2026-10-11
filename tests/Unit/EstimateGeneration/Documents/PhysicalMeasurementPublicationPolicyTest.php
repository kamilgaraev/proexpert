<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPublication;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\VerifiedNativeNumericSources;
use PHPUnit\Framework\TestCase;

final class PhysicalMeasurementPublicationPolicyTest extends TestCase
{
    public function test_three_agreeing_observers_and_a_photo_polygon_cannot_confirm_a_metric_area(): void
    {
        $claims = array_map(fn (string $role): ObservationClaim => $this->claim($role), ['observer_literal', 'observer_construction', 'observer_risk']);
        $decisions = array_map(static fn (ObservationClaim $claim): ArbitrationDecision => new ArbitrationDecision($claim->id, 'accepted', [$claim->id], [$claim->evidenceRef], 'all_observers_agree', null), $claims);
        $publication = new DocumentUnitPublication($claims, $decisions);
        self::assertSame(['candidate'], array_values(array_unique(array_column($publication->decisions, 'status'))));
        self::assertSame('numeric_source_requires_user_confirmation', $publication->decisions[0]->reasonCode);
    }

    public function test_a_native_reference_without_validated_value_is_not_numeric_proof_and_proof_cannot_be_reused_for_another_number(): void
    {
        $claim = $this->claim('observer_literal');
        $decision = new ArbitrationDecision($claim->id, 'accepted', [$claim->id], [$claim->evidenceRef], 'native_ref_exists', null);
        self::assertSame('candidate', (new DocumentUnitPublication([$claim], [$decision]))->decisions[0]->status);
        $sources = new VerifiedNativeNumericSources([VerifiedNativeNumericSources::fingerprint($claim)]);
        self::assertSame('accepted', (new DocumentUnitPublication([$claim], [$decision], numericSources: $sources))->decisions[0]->status);
        $changed = $this->claim('observer_literal', '30');
        self::assertSame('candidate', (new DocumentUnitPublication([$changed], [$decision], numericSources: $sources))->decisions[0]->status);
    }

    private function claim(string $role, string $area = '25.97'): ObservationClaim
    {
        return new ObservationClaim(str_replace('observer_', '', $role).':1', $role, 'room.kitchen', 'area',
            ['type' => 'number', 'data' => $area], 'm2', str_replace('observer_', '', $role).':source:1', true,
            1, 2, 3, 'sha256:'.str_repeat('a', 64), ['document_id' => 4, 'page' => 1, 'explicit' => true,
                'native_reference' => 'cad:dimension:23', 'coordinate_space' => 'raster_image_normalized'], 1);
    }
}
