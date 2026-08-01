<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting;

final class BudgetPlanFactReleaseCandidateLayout
{
    public const REQUEST_ID = 'budget_plan_fact_release_request';

    public const CANDIDATE_MANIFEST = 'budget-plan-fact-candidate-manifest-v1.json';

    public const CONFORMANCE_EVIDENCE = 'budget-plan-fact-conformance-evidence-v1.json';

    public const PROOF_TEMPLATE = 'budget-plan-fact-proof-template-v1.json';

    public const REQUEST_FILE = self::REQUEST_ID.'.json';

    /** @return array{candidate_manifest: string, conformance_evidence: string, proof_template: string} */
    public static function artifactPaths(): array
    {
        return [
            'candidate_manifest' => self::CANDIDATE_MANIFEST,
            'conformance_evidence' => self::CONFORMANCE_EVIDENCE,
            'proof_template' => self::PROOF_TEMPLATE,
        ];
    }

    /** @return array{request_id: string, schema_version: string, code: string, commit_sha: string, proof_sha256: string, artifact_paths: array{candidate_manifest: string, conformance_evidence: string, proof_template: string}} */
    public static function request(string $commitSha, string $proofSha256): array
    {
        return [
            'request_id' => self::REQUEST_ID,
            'schema_version' => '1.0.0',
            'code' => BudgetPlanFactCandidateContract::CODE,
            'commit_sha' => $commitSha,
            'proof_sha256' => $proofSha256,
            'artifact_paths' => self::artifactPaths(),
        ];
    }
}
