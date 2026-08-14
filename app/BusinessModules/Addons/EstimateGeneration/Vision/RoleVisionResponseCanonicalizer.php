<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

final class RoleVisionResponseCanonicalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $auxiliaryMetadata
     */
    public function canonicalize(array $payload, array $auxiliaryMetadata): RoleVisionResponseCanonicalization
    {
        $isObserver = isset($auxiliaryMetadata['observer']);
        $arbitration = $auxiliaryMetadata['arbitration'] ?? null;
        if (! $isObserver && ! is_array($arbitration)) {
            return new RoleVisionResponseCanonicalization($payload, []);
        }

        $repairs = [];
        if (($payload['schema_version'] ?? null) === '3') {
            $payload['schema_version'] = 3;
            $repairs[] = 'schema_version_string_to_integer';
        }

        $sheet = $payload['project_sheet_analysis'] ?? null;
        if (! is_array($sheet) || array_is_list($sheet)) {
            return new RoleVisionResponseCanonicalization($payload, $repairs);
        }
        if (($sheet['contractVersion'] ?? null) === 'sheet-analysis:v2') {
            $sheet['contractVersion'] = ProjectSheetAnalysisData::CONTRACT_VERSION;
            $repairs[] = 'sheet_analysis_v2_to_v3';
        }

        $facts = $sheet['facts'] ?? null;
        if (! is_array($facts) || ! array_is_list($facts) || count($facts) > 64) {
            $payload['project_sheet_analysis'] = $sheet;

            return new RoleVisionResponseCanonicalization($payload, $repairs);
        }

        if ($isObserver) {
            foreach ($facts as $index => $fact) {
                if (is_array($fact) && ($fact['contractVersion'] ?? null) === 'sheet-analysis:v2') {
                    $facts[$index]['contractVersion'] = ProjectSheetAnalysisData::CONTRACT_VERSION;
                }
            }
        } elseif (is_array($arbitration)) {
            [$facts, $canonicalClaimAdded] = $this->canonicalizeArbitrationClaims(
                $facts,
                is_array($arbitration['claims'] ?? null) ? $arbitration['claims'] : [],
            );
            if ($canonicalClaimAdded) {
                $repairs[] = 'arbiter_canonical_claim_from_allowlist';
            }
        }

        $sheet['facts'] = $facts;
        $payload['project_sheet_analysis'] = $sheet;

        return new RoleVisionResponseCanonicalization($payload, array_values(array_unique($repairs)));
    }

    /**
     * @param  list<mixed>  $facts
     * @param  array<mixed>  $claims
     * @return array{list<mixed>, bool}
     */
    private function canonicalizeArbitrationClaims(array $facts, array $claims): array
    {
        $claimsById = [];
        foreach ($claims as $claim) {
            if (! is_array($claim) || ! is_string($claim['id'] ?? null)) {
                continue;
            }
            $claimsById[$claim['id']] = $claim;
        }

        $added = false;
        foreach ($facts as $index => $fact) {
            if (! is_array($fact)
                || array_key_exists('canonical_claim', $fact)
                || ! in_array($fact['status'] ?? null, ['accepted', 'candidate'], true)
                || ! is_string($fact['claim_id'] ?? null)) {
                continue;
            }
            $claim = $claimsById[$fact['claim_id']] ?? null;
            $supporting = $fact['supporting_claim_ids'] ?? null;
            if (! is_array($claim) || ! is_array($supporting) || ! in_array($fact['claim_id'], $supporting, true)
                || ! is_string($claim['entity_key'] ?? null)
                || ! is_string($claim['fact_type'] ?? null)
                || ! is_array($claim['value'] ?? null)
                || ! array_key_exists('unit', $claim)) {
                continue;
            }
            $facts[$index]['canonical_claim'] = [
                'entity_key' => $claim['entity_key'],
                'fact_type' => $claim['fact_type'],
                'value' => $claim['value'],
                'unit' => $claim['unit'],
                'source_claim_id' => $fact['claim_id'],
            ];
            $added = true;
        }

        return [$facts, $added];
    }
}
