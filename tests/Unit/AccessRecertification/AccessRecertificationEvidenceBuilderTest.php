<?php

declare(strict_types=1);

namespace Tests\Unit\AccessRecertification;

use App\BusinessModules\Core\AccessRecertification\Services\AccessRecertificationEvidenceBuilder;
use PHPUnit\Framework\TestCase;

final class AccessRecertificationEvidenceBuilderTest extends TestCase
{
    public function test_evidence_snapshot_redacts_sensitive_identity_fields(): void
    {
        $snapshot = (new AccessRecertificationEvidenceBuilder())->assignmentSnapshot([
            'assignment_id' => 91,
            'user_id' => 17,
            'user_name' => 'Иван Петров',
            'user_email' => 'ivan.petrov@example.test',
            'role_slug' => 'finance_admin',
            'role_label' => 'Финансовый администратор',
            'permissions' => ['payments.invoice.view', 'payments.transaction.approve'],
        ]);

        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('finance_admin', $encoded);
        $this->assertStringContainsString('Финансовый администратор', $encoded);
        $this->assertStringNotContainsString('ivan.petrov@example.test', $encoded);
        $this->assertStringNotContainsString('Иван Петров', $encoded);
        $this->assertSame('[скрыто]', $snapshot['user_name']);
        $this->assertSame('[скрыто]', $snapshot['user_email']);
    }

    public function test_audit_event_context_uses_rbac_domain_and_access_recertification_prefix(): void
    {
        $event = (new AccessRecertificationEvidenceBuilder())->auditEventData(
            organizationId: 3,
            actorUserId: 8,
            eventType: 'access_recertification.decision.approved',
            action: 'approve',
            subjectType: 'access_recertification_item',
            subjectId: 'item-1',
            subjectLabel: 'Финансовый администратор',
            correlationId: 'arc-123',
            sourceEventId: 'arc:item-1:approve',
            reason: 'Доступ подтвержден',
            afterState: ['status' => 'approved'],
        );

        $this->assertSame('rbac', $event->domain);
        $this->assertSame('access_recertification.decision.approved', $event->eventType);
        $this->assertSame('access_recertification', $event->source);
        $this->assertSame('arc-123', $event->correlationId);
    }
}
