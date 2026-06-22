<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Services;

use InvalidArgumentException;

final class AccessRecertificationDecisionPolicy
{
    public function assertCanDecide(int $reviewerUserId, int $subjectUserId, string $decision, array $payload): void
    {
        if ($reviewerUserId === $subjectUserId) {
            throw new InvalidArgumentException('self_review_forbidden');
        }

        if ($decision === 'approve') {
            $this->requireText($payload, 'reason', 'approve_requires_reason');

            return;
        }

        if ($decision === 'revoke') {
            $this->requireText($payload, 'reason', 'revoke_requires_reason');

            if (empty($payload['revoke_executor_user_id'])) {
                throw new InvalidArgumentException('revoke_requires_executor');
            }

            return;
        }

        if ($decision === 'exception') {
            $this->requireText($payload, 'reason', 'exception_requires_reason');

            if (empty($payload['valid_until'])) {
                throw new InvalidArgumentException('exception_requires_valid_until');
            }

            if (empty($payload['compensating_controls']) || !is_array($payload['compensating_controls'])) {
                throw new InvalidArgumentException('exception_requires_controls');
            }

            return;
        }

        throw new InvalidArgumentException('decision_type_not_supported');
    }

    private function requireText(array $payload, string $key, string $message): void
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($message);
        }
    }
}
