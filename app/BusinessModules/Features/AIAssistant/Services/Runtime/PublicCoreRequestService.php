<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Http\Requests\PublicCoreTestRequest;
use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class PublicCoreRequestService
{
    public function __construct(private readonly AIPermissionChecker $permissions,
        private readonly PublicCoreAssistantRuntime $runtime)
    {
    }

    public function readiness(User $viewer, int $organizationId): array
    {
        $this->assertViewer($viewer, $organizationId);

        return $this->runtime->readiness($viewer, $organizationId);
    }

    public function submit(User $viewer, int $organizationId, array $command): array
    {
        $this->assertViewer($viewer, $organizationId);
        $required = ['fixture_id', 'fixture_version', 'input_id', 'request_id'];
        if (array_diff(array_keys($command), PublicCoreTestRequest::KEYS) !== []
            || array_diff($required, array_keys($command)) !== []) {
            $this->invalidInput();
        }
        foreach (['fixture_id', 'fixture_version', 'input_id'] as $key) {
            if (!is_string($command[$key]) || strlen($command[$key]) > 128
                || preg_match('/\A[A-Za-z0-9._\/-]+\z/D', $command[$key]) !== 1) {
                $this->invalidInput();
            }
        }
        if (!is_string($command['request_id'])
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $command['request_id']) !== 1
            || (array_key_exists('public_session_ref', $command) && !PublicCoreRuntimeResource::opaqueRef($command['public_session_ref']))) {
            $this->invalidInput();
        }

        return $this->runtime->submit($viewer, $organizationId, $command);
    }

    public function poll(User $viewer, int $organizationId, string $requestRef): array|JsonResponse
    {
        $this->assertViewer($viewer, $organizationId);
        if (!PublicCoreRuntimeResource::opaqueRef($requestRef)) {
            $this->invalidInput();
        }

        return $this->runtime->poll($viewer, $organizationId, $requestRef);
    }

    public function dispatch(string $requestRef): void
    {
        if (!PublicCoreRuntimeResource::opaqueRef($requestRef)) {
            $this->invalidInput();
        }
        $this->runtime->dispatchOwnedRequest($requestRef);
    }

    private function assertViewer(User $viewer, int $organizationId): void
    {
        if ($organizationId <= 0 || !$this->permissions->canUseAssistant($viewer, $organizationId, true)) {
            throw new AuthorizationException(trans_message('ai_assistant.access_denied'));
        }
    }

    private function invalidInput(): never
    {
        throw ValidationException::withMessages(['input' => trans_message('ai_assistant.request_invalid')]);
    }
}
