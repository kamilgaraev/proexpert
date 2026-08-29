<?php

declare(strict_types=1);

namespace Tests\Unit\AccessRecertification;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationException;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationRevocation;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationExceptionResource;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationRevocationResource;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccessRecertificationWorkflowResourceTest extends TestCase
{
    #[Test]
    public function revocation_exposes_human_role_and_assigned_executor(): void
    {
        $item = new AccessRecertificationItem;
        $item->forceFill(['role_label' => 'Бухгалтер']);

        $executor = new User;
        $executor->forceFill(['id' => 44, 'name' => 'Камиль Гараев']);

        $revocation = new AccessRecertificationRevocation;
        $revocation->forceFill([
            'id' => 'revocation-1',
            'role_slug' => 'accountant',
            'role_type' => 'system',
            'status' => 'pending',
            'reason' => 'Доступ больше не требуется',
        ]);
        $revocation->setRelation('item', $item);
        $revocation->setRelation('executor', $executor);

        $payload = (new AccessRecertificationRevocationResource($revocation))->resolve(Request::create('/'));

        $this->assertSame('Бухгалтер', $payload['role_label']);
        $this->assertSame(['id' => 44, 'name' => 'Камиль Гараев'], $payload['executor']);
    }

    #[Test]
    public function exception_exposes_subject_role_and_requester(): void
    {
        $subject = new User;
        $subject->forceFill(['id' => 46, 'name' => 'Сергей Петров']);

        $requester = new User;
        $requester->forceFill(['id' => 44, 'name' => 'Камиль Гараев']);

        $item = new AccessRecertificationItem;
        $item->forceFill([
            'subject_user_id' => 46,
            'role_slug' => 'accountant',
            'role_label' => 'Бухгалтер',
        ]);
        $item->setRelation('subject', $subject);

        $exception = new AccessRecertificationException;
        $exception->forceFill([
            'id' => 'exception-1',
            'status' => 'requested',
            'reason' => 'До закрытия отчетного периода',
        ]);
        $exception->setRelation('item', $item);
        $exception->setRelation('requestedBy', $requester);

        $payload = (new AccessRecertificationExceptionResource($exception))->resolve(Request::create('/'));

        $this->assertSame(['id' => 46, 'name' => 'Сергей Петров'], $payload['subject']);
        $this->assertSame('accountant', $payload['role_slug']);
        $this->assertSame('Бухгалтер', $payload['role_label']);
        $this->assertSame(['id' => 44, 'name' => 'Камиль Гараев'], $payload['requested_by']);
    }
}
