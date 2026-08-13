<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use InvalidArgumentException;

final readonly class AiRoleRunInput
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public ?int $documentId,
        public ?int $pageId,
        public string $subjectType,
        public string $subjectId,
        public string $subjectVersion,
        public AiAnalysisRole $role,
        public string $model,
        public string $promptContractVersion,
        public string $inputFingerprint,
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1
            || ($documentId !== null && $documentId < 1)
            || ($pageId !== null && $pageId < 1)
            || preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $subjectType) !== 1
            || trim($subjectId) === '' || strlen($subjectId) > 160
            || trim($subjectVersion) === '' || strlen($subjectVersion) > 160
            || trim($model) === '' || strlen($model) > 160
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/D', $promptContractVersion) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $inputFingerprint) !== 1) {
            throw new InvalidArgumentException('ai_role_run_input_invalid');
        }
    }

    public function identityFingerprint(): string
    {
        return hash('sha256', json_encode([
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'session_id' => $this->sessionId,
            'document_id' => $this->documentId,
            'page_id' => $this->pageId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_version' => $this->subjectVersion,
            'role' => $this->role->value,
            'model' => $this->model,
            'prompt_contract_version' => $this->promptContractVersion,
            'input_fingerprint' => $this->inputFingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
