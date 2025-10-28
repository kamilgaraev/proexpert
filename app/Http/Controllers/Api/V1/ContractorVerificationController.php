<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContractorVerification;
use App\Models\OrganizationAccessRestriction;
use App\Models\OrganizationDispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractorVerificationController extends Controller
{
    public function confirm(string $token): JsonResponse
    {
        try {
            $verification = ContractorVerification::where('verification_token', $token)
                ->with(['contractor', 'registeredOrganization', 'customerOrganization'])
                ->firstOrFail();

            if ($verification->isConfirmed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Подрядчик уже подтвержден'
                ], 400);
            }

            if ($verification->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Срок подтверждения истек'
                ], 400);
            }

            DB::transaction(function () use ($verification) {
                $verification->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'confirmed_by_user_id' => auth()->id(),
                ]);

                $this->liftRestrictions($verification->registeredOrganization);

                Log::channel('security')->info('Contractor verification confirmed', [
                    'verification_id' => $verification->id,
                    'contractor_id' => $verification->contractor_id,
                    'registered_org_id' => $verification->registered_organization_id,
                    'confirmed_by' => auth()->id()
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Подрядчик успешно подтвержден. Ограничения доступа сняты.',
                'data' => [
                    'contractor' => [
                        'id' => $verification->contractor->id,
                        'name' => $verification->contractor->name,
                    ],
                    'organization' => [
                        'id' => $verification->registeredOrganization->id,
                        'name' => $verification->registeredOrganization->name,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Contractor verification confirmation failed', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при подтверждении подрядчика'
            ], 500);
        }
    }

    public function reject(Request $request, string $token): JsonResponse
    {
        try {
            $verification = ContractorVerification::where('verification_token', $token)
                ->with(['contractor', 'registeredOrganization', 'customerOrganization'])
                ->firstOrFail();

            if ($verification->isRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Подрядчик уже отклонен'
                ], 400);
            }

            $reason = $request->input('reason', 'Заказчик указал, что это не его подрядчик');

            DB::transaction(function () use ($verification, $reason) {
                $verification->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'confirmed_by_user_id' => auth()->id(),
                ]);

                $this->blockOrganizationAccess($verification->registeredOrganization, $reason);

                $this->createDispute($verification, $reason);

                Log::channel('security')->critical('Contractor verification rejected', [
                    'verification_id' => $verification->id,
                    'contractor_id' => $verification->contractor_id,
                    'registered_org_id' => $verification->registered_organization_id,
                    'rejected_by' => auth()->id(),
                    'reason' => $reason
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Доступ заблокирован. Мы начали расследование.',
                'data' => [
                    'contractor' => [
                        'id' => $verification->contractor->id,
                        'name' => $verification->contractor->name,
                    ],
                    'organization' => [
                        'id' => $verification->registeredOrganization->id,
                        'name' => $verification->registeredOrganization->name,
                        'status' => 'blocked'
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Contractor verification rejection failed', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отклонении подрядчика'
            ], 500);
        }
    }

    public function dispute(Request $request, string $token): JsonResponse
    {
        try {
            $verification = ContractorVerification::where('verification_token', $token)
                ->with(['contractor', 'registeredOrganization', 'customerOrganization'])
                ->firstOrFail();

            $reason = $request->input('reason', 'Заказчик сообщил о проблеме с подрядчиком');

            $dispute = $this->createDispute($verification, $reason);

            $this->applyTemporaryRestrictions($verification->registeredOrganization);

            Log::channel('security')->warning('Dispute created for contractor', [
                'verification_id' => $verification->id,
                'dispute_id' => $dispute->id,
                'contractor_id' => $verification->contractor_id,
                'registered_org_id' => $verification->registered_organization_id,
                'reported_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Жалоба принята. Мы проверим ситуацию.',
                'data' => [
                    'dispute_id' => $dispute->id,
                    'status' => 'under_investigation'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Dispute creation failed', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании жалобы'
            ], 500);
        }
    }

    private function liftRestrictions($organization): void
    {
        OrganizationAccessRestriction::where('organization_id', $organization->id)
            ->active()
            ->update([
                'expires_at' => now(),
            ]);

        Log::info('Access restrictions lifted', [
            'organization_id' => $organization->id
        ]);
    }

    private function blockOrganizationAccess($organization, string $reason): void
    {
        OrganizationAccessRestriction::where('organization_id', $organization->id)
            ->active()
            ->delete();

        OrganizationAccessRestriction::create([
            'organization_id' => $organization->id,
            'restriction_type' => 'verification_rejected',
            'access_level' => 'blocked',
            'allowed_actions' => [],
            'blocked_actions' => ['*'],
            'reason' => $reason,
            'expires_at' => null,
            'can_be_lifted_early' => false,
            'metadata' => [
                'blocked_at' => now()->toDateTimeString(),
                'blocked_by' => auth()->id(),
            ],
        ]);

        DB::table('project_organization')
            ->where('organization_id', $organization->id)
            ->update([
                'is_active' => false,
                'metadata' => DB::raw("jsonb_set(COALESCE(metadata, '{}'), '{suspended_reason}', '\"" . addslashes($reason) . "\"')")
            ]);

        Log::channel('security')->critical('Organization access blocked', [
            'organization_id' => $organization->id,
            'reason' => $reason
        ]);
    }

    private function applyTemporaryRestrictions($organization): void
    {
        $existing = OrganizationAccessRestriction::where('organization_id', $organization->id)
            ->active()
            ->first();

        if ($existing) {
            $existing->update([
                'blocked_actions' => array_merge(
                    $existing->blocked_actions ?? [],
                    ['bulk_export', 'request_payments']
                ),
                'metadata' => array_merge(
                    $existing->metadata ?? [],
                    ['under_investigation' => true, 'investigation_started_at' => now()->toDateTimeString()]
                ),
            ]);
        }
    }

    private function createDispute(ContractorVerification $verification, string $reason): OrganizationDispute
    {
        return OrganizationDispute::create([
            'reporter_user_id' => auth()->id(),
            'reporter_organization_id' => $verification->customer_organization_id,
            'disputed_organization_id' => $verification->registered_organization_id,
            'dispute_type' => 'fraudulent_registration',
            'reason' => $reason,
            'evidence' => [
                'verification_id' => $verification->id,
                'contractor_id' => $verification->contractor_id,
                'verification_score' => $verification->verification_score,
                'reported_at' => now()->toDateTimeString(),
            ],
            'status' => 'under_investigation',
            'priority' => 'high',
        ]);
    }
}

