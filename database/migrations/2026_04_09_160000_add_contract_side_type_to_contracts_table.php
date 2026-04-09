<?php

use App\Enums\Contract\ContractSideTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('contract_side_type')->nullable()->after('supplier_id');
            $table->boolean('requires_contract_side_review')->default(false)->after('contract_side_type');
            $table->text('contract_side_review_reason')->nullable()->after('requires_contract_side_review');

            $table->index('contract_side_type');
            $table->index('requires_contract_side_review');
        });

        DB::table('contracts')
            ->select(['id', 'organization_id', 'project_id', 'contractor_id', 'supplier_id'])
            ->orderBy('id')
            ->chunkById(200, function ($contracts): void {
                foreach ($contracts as $contract) {
                    $sideType = null;
                    $review = false;
                    $reason = null;

                    if ($contract->supplier_id !== null) {
                        $sideType = ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER->value;
                    } elseif ($contract->project_id === null) {
                        $review = true;
                        $reason = 'Отсутствует project_id, сторону договора нужно проверить вручную.';
                    } else {
                        $project = DB::table('projects')
                            ->select(['id', 'organization_id'])
                            ->where('id', $contract->project_id)
                            ->first();

                        $activeCustomerParticipantId = DB::table('project_organization')
                            ->where('project_id', $contract->project_id)
                            ->where('is_active', true)
                            ->where(function ($query): void {
                                $query
                                    ->where('role_new', 'customer')
                                    ->orWhere(function ($legacy): void {
                                        $legacy
                                            ->whereNull('role_new')
                                            ->where('role', 'customer');
                                    });
                            })
                            ->value('organization_id');

                        $resolvedCustomerId = $activeCustomerParticipantId ?? $project?->organization_id;

                        if ($resolvedCustomerId !== null && (int) $resolvedCustomerId === (int) $contract->organization_id) {
                            $sideType = ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR->value;
                        } else {
                            $organizationRole = DB::table('project_organization')
                                ->where('project_id', $contract->project_id)
                                ->where('organization_id', $contract->organization_id)
                                ->select(['role_new', 'role'])
                                ->first();

                            $effectiveRole = $organizationRole?->role_new ?? $organizationRole?->role;

                            if (in_array($effectiveRole, ['contractor', 'subcontractor'], true)) {
                                $sideType = ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR->value;
                            } elseif (in_array($effectiveRole, ['owner', 'general_contractor', 'customer'], true) || $project?->organization_id === $contract->organization_id) {
                                $sideType = ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value;
                            } else {
                                $review = true;
                                $reason = 'Не удалось однозначно определить сторону договора по проектному контексту.';
                            }
                        }
                    }

                    DB::table('contracts')
                        ->where('id', $contract->id)
                        ->update([
                            'contract_side_type' => $sideType,
                            'requires_contract_side_review' => $review,
                            'contract_side_review_reason' => $reason,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropIndex(['contract_side_type']);
            $table->dropIndex(['requires_contract_side_review']);
            $table->dropColumn([
                'contract_side_type',
                'requires_contract_side_review',
                'contract_side_review_reason',
            ]);
        });
    }
};
