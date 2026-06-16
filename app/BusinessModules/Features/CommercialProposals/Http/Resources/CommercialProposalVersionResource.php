<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Resources;

use App\BusinessModules\Features\CommercialProposals\Enums\CommercialProposalStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function trans_message;

final class CommercialProposalVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewAmounts = $this->canViewAmounts($request);
        $status = is_string($this->status) ? $this->status : (string) $this->status;
        $workflowStatus = CommercialProposalStatus::tryFrom($status);

        return [
            'id' => $this->id,
            'commercial_proposal_id' => $this->commercial_proposal_id,
            'version_number' => $this->version_number,
            'title' => $this->title,
            'status' => $status,
            'status_label' => $workflowStatus === null ? null : trans_message($workflowStatus->labelKey()),
            'sections' => $this->sectionsSnapshot($canViewAmounts),
            'sections_snapshot' => $this->sectionsSnapshot($canViewAmounts),
            'source_links' => $this->source_links_snapshot ?? [],
            'terms' => $this->terms_snapshot ?? [],
            'amounts_visible' => $canViewAmounts,
            'totals' => $canViewAmounts ? ($this->totals_snapshot ?? []) : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'customer_decision_at' => $this->customer_decision_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdBy', fn (): ?array => $this->createdBy === null ? null : [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sectionsSnapshot(bool $canViewAmounts): array
    {
        $sections = $this->sections_snapshot ?? [];

        if ($canViewAmounts) {
            return is_array($sections) ? array_values($sections) : [];
        }

        return collect(is_array($sections) ? $sections : [])
            ->map(static function (array $section): array {
                foreach (['subtotal_amount', 'total_amount', 'total_amount_with_tax'] as $key) {
                    unset($section[$key]);
                }

                $section['line_items'] = collect($section['line_items'] ?? [])
                    ->map(static function (array $item): array {
                        foreach ([
                            'unit_price',
                            'discount_amount',
                            'vat_rate',
                            'vat_amount',
                            'subtotal_amount',
                            'total_amount',
                            'total_amount_with_tax',
                        ] as $key) {
                            unset($item[$key]);
                        }

                        return $item;
                    })
                    ->values()
                    ->all();

                return $section;
            })
            ->values()
            ->all();
    }

    private function canViewAmounts(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $organizationId = (int) $request->attributes->get('current_organization_id');

        return $organizationId > 0 && $user->can('commercial_proposals.amounts.view', [
            'organization_id' => $organizationId,
        ]);
    }
}
