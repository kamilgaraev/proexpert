<?php

declare(strict_types=1);

namespace Tests\Unit\CommercialProposals;

use App\BusinessModules\Features\CommercialProposals\Http\Resources\CommercialProposalResource;
use App\BusinessModules\Features\CommercialProposals\Http\Resources\CommercialProposalVersionResource;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CommercialProposalResourceContractTest extends TestCase
{
    public function test_version_resource_masks_nested_amounts_without_permission(): void
    {
        $version = new CommercialProposalVersion();
        $version->forceFill([
            'id' => '2cdb4bbd-8bb4-4f70-bf84-4d4d765946e1',
            'commercial_proposal_id' => '74e67088-5021-4ffc-8d65-e86d68ac1277',
            'version_number' => 1,
            'status' => 'draft',
            'title' => 'КП',
            'sections_snapshot' => [
                [
                    'title' => 'Состав',
                    'subtotal_amount' => '1000.00',
                    'line_items' => [
                        [
                            'title' => 'Работы',
                            'quantity' => 1,
                            'unit_price' => '1000.00',
                            'subtotal_amount' => '1000.00',
                            'total_amount' => '1200.00',
                        ],
                    ],
                ],
            ],
            'totals_snapshot' => ['total_amount' => '1200.00'],
        ]);

        $payload = (new CommercialProposalVersionResource($version))->toArray(
            $this->requestWithPermissions([])
        );

        $this->assertNull($payload['totals']);
        $this->assertArrayNotHasKey('subtotal_amount', $payload['sections'][0]);
        $this->assertArrayNotHasKey('unit_price', $payload['sections'][0]['line_items'][0]);
        $this->assertArrayNotHasKey('total_amount', $payload['sections'][0]['line_items'][0]);
    }

    public function test_action_details_are_disabled_without_action_permission(): void
    {
        $proposal = new CommercialProposal();
        $proposal->forceFill([
            'id' => '74e67088-5021-4ffc-8d65-e86d68ac1277',
            'organization_id' => 7,
            'number' => 'КП-2026-0001',
            'title' => 'КП',
            'status' => 'approved',
            'currency' => 'RUB',
        ]);

        $payload = (new CommercialProposalResource($proposal))->toArray(
            $this->requestWithPermissions(['commercial_proposals.view'])
        );

        $sendAction = collect($payload['workflow_summary']['available_action_details'])
            ->firstWhere('action', 'send');

        $this->assertSame('commercial_proposals.send', $sendAction['permission']);
        $this->assertFalse($sendAction['enabled']);
    }

    /**
     * @param list<string> $permissions
     */
    private function requestWithPermissions(array $permissions): Request
    {
        $request = Request::create('/api/v1/admin/commercial-proposals', 'GET');
        $request->attributes->set('current_organization_id', 7);
        $request->setUserResolver(static fn (): Authenticatable => new class ($permissions) implements Authenticatable {
            /**
             * @param list<string> $permissions
             */
            public function __construct(private readonly array $permissions)
            {
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function can(string $permission, array $arguments = []): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): int
            {
                return 10;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken($value): void
            {
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        });

        return $request;
    }
}
