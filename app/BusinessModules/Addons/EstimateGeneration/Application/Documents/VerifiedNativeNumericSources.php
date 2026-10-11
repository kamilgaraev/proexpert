<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** In-process proof created by a native parser, never deserialized from model JSON. */
final readonly class VerifiedNativeNumericSources
{
    public function __construct(private array $fingerprints)
    {
        if (! array_is_list($fingerprints) || count($fingerprints) > 10000) {
            throw new InvalidArgumentException('native_numeric_proof_manifest_invalid');
        }
        foreach ($fingerprints as $fingerprint) {
            if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
                throw new InvalidArgumentException('native_numeric_proof_fingerprint_invalid');
            }
        }
    }

    public static function fingerprint(ObservationClaim $claim): string
    {
        return hash('sha256', CanonicalPipelineJson::encode(['organization_id' => $claim->organizationId,
            'project_id' => $claim->projectId, 'session_id' => $claim->sessionId, 'source_version' => $claim->sourceVersion,
            'entity_key' => $claim->entityKey, 'parameter' => $claim->factType, 'value' => $claim->value,
            'unit' => $claim->unit, 'locator' => $claim->locator]));
    }

    public function certifies(ObservationClaim $claim): bool
    {
        return in_array(self::fingerprint($claim), $this->fingerprints, true);
    }
}
