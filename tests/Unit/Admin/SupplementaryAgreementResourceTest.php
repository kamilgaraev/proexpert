<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Http\Resources\Api\V1\Admin\Contract\Agreement\SupplementaryAgreementResource;
use App\Models\SupplementaryAgreement;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class SupplementaryAgreementResourceTest extends TestCase
{
    public function test_application_state_is_exposed_for_user_actions(): void
    {
        $draft = $this->agreement();
        $draft->forceFill(['financial_applied_at' => null]);

        $applied = $this->agreement();
        $applied->forceFill(['financial_applied_at' => '2026-08-29 19:00:00']);

        $request = Request::create('/');

        $this->assertFalse((new SupplementaryAgreementResource($draft))->toArray($request)['is_applied']);
        $this->assertTrue((new SupplementaryAgreementResource($applied))->toArray($request)['is_applied']);
    }

    private function agreement(): SupplementaryAgreement
    {
        return new class extends SupplementaryAgreement
        {
            public function getDateFormat(): string
            {
                return 'Y-m-d H:i:s';
            }
        };
    }
}
