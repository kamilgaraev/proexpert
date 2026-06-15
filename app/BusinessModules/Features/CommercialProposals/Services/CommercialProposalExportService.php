<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Services;

use App\BusinessModules\Features\CommercialProposals\Exceptions\CommercialProposalWorkflowException;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalExport;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function e;
use function trans_message;

final class CommercialProposalExportService
{
    public function __construct(private readonly FileService $fileService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(CommercialProposal $proposal, ?string $versionId, bool $canViewAmounts): array
    {
        $version = $this->resolveVersion($proposal, $versionId);

        return [
            'proposal_id' => $proposal->id,
            'version_id' => $version->id,
            'format' => 'html',
            'amounts_visible' => $canViewAmounts,
            'html' => $this->renderHtml($proposal, $version, $canViewAmounts),
        ];
    }

    public function export(CommercialProposal $proposal, array $input, bool $canViewAmounts, ?int $actorId): CommercialProposalExport
    {
        $version = $this->resolveVersion($proposal, $input['version_id'] ?? null);
        $format = (string) ($input['format'] ?? 'pdf');
        $options = $input['options'] ?? [];
        $templateHash = $version->template_version_hash ?? 'default';
        $contentHash = hash('sha256', json_encode([
            'content_hash' => $version->content_hash,
            'format' => $format,
            'amounts_visible' => $canViewAmounts,
            'options' => $options,
        ], JSON_THROW_ON_ERROR));

        [$export, $shouldGenerate] = DB::transaction(function () use ($proposal, $version, $format, $options, $templateHash, $contentHash, $actorId): array {
            $existing = CommercialProposalExport::query()
                ->where('commercial_proposal_version_id', $version->id)
                ->where('format', $format)
                ->where('content_hash', $contentHash)
                ->where('template_version_hash', $templateHash)
                ->first();

            if ($existing instanceof CommercialProposalExport && in_array($existing->status, ['processing', 'ready'], true)) {
                return [$existing, false];
            }

            $export = $existing instanceof CommercialProposalExport
                ? $existing->forceFill([
                    'requested_by_user_id' => $actorId,
                    'status' => 'processing',
                    'options' => $options,
                    'error_message' => null,
                    'storage_path' => null,
                    'generated_at' => null,
                ])
                : CommercialProposalExport::query()->make([
                    'organization_id' => $proposal->organization_id,
                    'commercial_proposal_id' => $proposal->id,
                    'commercial_proposal_version_id' => $version->id,
                    'requested_by_user_id' => $actorId,
                    'format' => $format,
                    'status' => 'processing',
                    'content_hash' => $contentHash,
                    'template_version_hash' => $templateHash,
                    'options' => $options,
                ]);
            $export->save();

            return [$export->refresh(), true];
        });

        if (!$shouldGenerate) {
            return $export;
        }

        try {
            $html = $this->renderHtml($proposal, $version, $canViewAmounts);
            $content = $format === 'pdf'
                ? Pdf::loadHTML($html)->setPaper('a4')->output()
                : $html;
            $extension = $format === 'pdf' ? 'pdf' : 'html';
            $filename = sprintf(
                '%s-v%s-%s.%s',
                Str::slug((string) $proposal->number),
                $version->version_number,
                substr($contentHash, 0, 12),
                $extension
            );
            $organization = Organization::query()->findOrFail($proposal->organization_id);
            $path = $this->fileService->putContent(
                $content,
                "commercial-proposals/{$proposal->id}/versions/{$version->id}/generated_export",
                $filename,
                'private',
                $organization
            );

            if ($path === false) {
                $this->block('export_store_failed');
            }

            $export->forceFill([
                'status' => 'ready',
                'storage_path' => $path,
                'generated_at' => now(),
            ])->save();

            return $export->refresh();
        } catch (CommercialProposalWorkflowException $e) {
            $export->forceFill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ])->save();

            throw $e;
        } catch (\Throwable $e) {
            $export->forceFill([
                'status' => 'failed',
                'error_message' => trans_message('commercial_proposals.export.failed'),
            ])->save();

            throw $e;
        }
    }

    private function resolveVersion(CommercialProposal $proposal, mixed $versionId): CommercialProposalVersion
    {
        if ($versionId !== null && $versionId !== '') {
            return $proposal->versions()->whereKey($versionId)->firstOrFail();
        }

        $version = $proposal->currentVersion;
        if (!$version instanceof CommercialProposalVersion) {
            $this->block('version_missing');
        }

        return $version;
    }

    private function renderHtml(CommercialProposal $proposal, CommercialProposalVersion $version, bool $canViewAmounts): string
    {
        $sections = $version->sections_snapshot ?? [];
        $totals = $version->totals_snapshot ?? [];
        $amount = $canViewAmounts
            ? number_format((float) ($totals['total_amount'] ?? 0), 2, ',', ' ') . ' ' . e((string) ($totals['currency'] ?? $proposal->currency))
            : trans_message('commercial_proposals.amounts.hidden');
        $sectionsHtml = collect($sections)->map(static function (array $section) use ($canViewAmounts, $proposal): string {
            $lines = collect($section['line_items'] ?? [])->map(static function (array $item) use ($canViewAmounts, $proposal): string {
                $price = $canViewAmounts
                    ? e(number_format((float) ($item['unit_price'] ?? 0), 2, ',', ' ') . ' ' . (string) $proposal->currency)
                    : e(trans_message('commercial_proposals.amounts.hidden'));
                $total = $canViewAmounts
                    ? e(number_format((float) ($item['total_amount'] ?? 0), 2, ',', ' ') . ' ' . (string) $proposal->currency)
                    : e(trans_message('commercial_proposals.amounts.hidden'));

                return '<tr><td>' . e((string) ($item['title'] ?? '')) . '</td><td>' . e((string) ($item['quantity'] ?? '')) . '</td><td>' . e((string) ($item['unit'] ?? '')) . '</td><td>' . $price . '</td><td>' . $total . '</td></tr>';
            })->implode('');

            return '<section><h2>' . e((string) $section['title']) . '</h2><p>' . nl2br(e((string) ($section['body'] ?? ''))) . '</p>'
                . ($lines === '' ? '' : '<table><thead><tr><th>Позиция</th><th>Кол-во</th><th>Ед.</th><th>Цена</th><th>Итого</th></tr></thead><tbody>' . $lines . '</tbody></table>')
                . '</section>';
        })->implode('');

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;color:#111827;font-size:13px;line-height:1.5}'
            . 'h1{font-size:24px;margin:0 0 8px}h2{font-size:16px;margin:18px 0 6px}'
            . '.meta{color:#4b5563;margin-bottom:18px}.total{margin-top:24px;font-size:18px;font-weight:700}'
            . 'table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border-bottom:1px solid #e5e7eb;padding:6px 4px;text-align:left}th{font-weight:700;background:#f9fafb}'
            . '</style></head><body>'
            . '<h1>' . e($proposal->title) . '</h1>'
            . '<div class="meta">' . e($proposal->number) . ' - ' . e((string) $proposal->customer_name) . ' - v' . e((string) $version->version_number) . '</div>'
            . $sectionsHtml
            . '<div class="total">' . e(trans_message('commercial_proposals.export.total')) . ': ' . $amount . '</div>'
            . '</body></html>';
    }

    private function block(string $code): never
    {
        throw new CommercialProposalWorkflowException([
            [
                'code' => $code,
                'message' => trans_message("commercial_proposals.blockers.{$code}"),
            ],
        ], trans_message("commercial_proposals.blockers.{$code}"));
    }
}
