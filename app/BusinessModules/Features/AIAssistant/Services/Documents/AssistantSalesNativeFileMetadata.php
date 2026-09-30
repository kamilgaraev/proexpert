<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalFile;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalExport;
use App\BusinessModules\Features\Crm\Models\CrmImportBatch;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptDocument;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantSalesNativeFileMetadata
{
    public const SOURCE = 'sales_native';
    public const MAX_BYTES = 25_000_000;

    public static function definitions(): array
    {
        return [
            'commercial_proposal_file' => ['model' => CommercialProposalFile::class, 'table' => 'commercial_proposal_files', 'path' => 'storage_path', 'hash' => 'checksum', 'size' => 'size_bytes', 'actors' => ['uploaded_by_user_id'], 'permissions' => ['commercial_proposals.view', 'commercial_proposals.amounts.view']],
            'commercial_proposal_export' => ['model' => CommercialProposalExport::class, 'table' => 'commercial_proposal_exports', 'path' => 'storage_path', 'hash' => null, 'size' => null, 'actors' => ['requested_by_user_id'], 'permissions' => ['commercial_proposals.view', 'commercial_proposals.export', 'commercial_proposals.amounts.view']],
            'crm_import_batch' => ['model' => CrmImportBatch::class, 'table' => 'crm_import_batches', 'path' => 'stored_path', 'hash' => null, 'size' => null, 'actors' => ['uploaded_by_user_id', 'confirmed_by_user_id'], 'permissions' => ['crm.import.preview', 'finance.view']],
            'purchase_receipt_document' => ['model' => PurchaseReceiptDocument::class, 'table' => 'purchase_receipt_documents', 'path' => 'storage_key', 'hash' => 'sha256', 'size' => 'size_bytes', 'actors' => ['uploaded_by_user_id'], 'permissions' => ['procurement.purchase_orders.view', 'finance.view']],
            'purchase_order' => ['model' => PurchaseOrder::class, 'table' => 'purchase_orders', 'path' => 'storage_path', 'hash' => 'sha256', 'size' => 'size_bytes', 'actors' => ['sent_by_user_id'], 'permissions' => ['procurement.purchase_orders.view', 'finance.view']],
        ];
    }

    public static function types(): array { return array_keys(self::definitions()); }

    public static function formats(): array
    {
        return AssistantLegalNativeFileMetadata::formats() + ['html' => 'text/html', 'xml' => 'application/xml'];
    }

    public static function sourceQuery(string $type, int $organizationId): QueryBuilder
    {
        $record = self::definitions()[$type] ?? throw new RuntimeException('ai_assistant_document_native_source_invalid');
        $query = DB::table($record['table'].' as native_source');
        if ($organizationId > 0) { $query->where('native_source.organization_id', $organizationId); }
        if (str_starts_with($type, 'commercial_proposal_')) {
            $query->join('commercial_proposals as native_parent', 'native_parent.id', '=', 'native_source.commercial_proposal_id')
                ->whereColumn('native_parent.organization_id', 'native_source.organization_id')->whereNull('native_parent.deleted_at')
                ->leftJoin('commercial_proposal_versions as native_version', 'native_version.id', '=', 'native_source.commercial_proposal_version_id')
                ->where(static function (QueryBuilder $version): void {
                    $version->whereNull('native_source.commercial_proposal_version_id')->orWhere(static function (QueryBuilder $present): void {
                        $present->whereColumn('native_version.organization_id', 'native_source.organization_id')->whereColumn('native_version.commercial_proposal_id', 'native_parent.id');
                    });
                });
            if ($type === 'commercial_proposal_export') { $query->where('native_source.status', 'ready')->whereNotNull('native_version.id'); }
        } elseif (in_array($type, ['purchase_order', 'purchase_receipt_document'], true)) {
            if ($type === 'purchase_receipt_document') {
                $query->join('purchase_orders as native_parent', 'native_parent.id', '=', 'native_source.purchase_order_id')->whereColumn('native_parent.organization_id', 'native_source.organization_id')->whereNull('native_parent.deleted_at');
            } else { $query->whereNull('native_source.deleted_at'); }
            $order = $type === 'purchase_order' ? 'native_source' : 'native_parent';
            $query->leftJoin('contracts as native_contract', 'native_contract.id', '=', $order.'.contract_id')
                ->leftJoin('purchase_requests as native_request', 'native_request.id', '=', $order.'.purchase_request_id')
                ->leftJoin('site_requests as native_site', 'native_site.id', '=', 'native_request.site_request_id')
                ->where(static fn (QueryBuilder $q) => $q->whereNull($order.'.contract_id')->orWhereColumn('native_contract.organization_id', 'native_source.organization_id'))
                ->where(static fn (QueryBuilder $q) => $q->whereNull($order.'.purchase_request_id')->orWhereColumn('native_request.organization_id', 'native_source.organization_id'));
        }
        $path = self::versionExpressions($type)[$record['path']];
        return $query->whereRaw($path.' IS NOT NULL')->whereRaw($path." <> ''");
    }

    public static function versionExpressions(string $type): array
    {
        self::definitions()[$type] ?? throw new RuntimeException('ai_assistant_document_native_source_invalid');
        $fields = ['id', 'organization_id', 'created_at', 'updated_at', ...match ($type) {
            'commercial_proposal_file' => ['commercial_proposal_id', 'commercial_proposal_version_id', 'category', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'checksum'],
            'commercial_proposal_export' => ['commercial_proposal_id', 'commercial_proposal_version_id', 'format', 'status', 'content_hash', 'template_version_hash', 'storage_path', 'generated_at'],
            'crm_import_batch' => ['entity_type', 'source_format', 'status', 'stored_path', 'original_filename', 'cancelled_at'],
            'purchase_receipt_document' => ['purchase_order_id', 'purchase_receipt_id', 'document_type', 'status', 'storage_key', 'storage_etag', 'mime_type', 'size_bytes', 'sha256', 'validated_at'],
            'purchase_order' => ['contract_id', 'purchase_request_id', 'status'],
        }];
        $expressions = array_combine($fields, array_map(static fn (string $field): string => 'native_source.'.$field, $fields));
        foreach (self::definitions()[$type]['actors'] as $field) { $expressions[$field] = $type === 'purchase_order' ? "native_source.metadata->>'$field'" : 'native_source.'.$field; }
        if (str_starts_with($type, 'commercial_proposal_')) {
            $expressions += ['parent_project_id' => 'native_parent.project_id', 'parent_updated_at' => 'native_parent.updated_at', 'version_updated_at' => 'native_version.updated_at'];
        } elseif (in_array($type, ['purchase_order', 'purchase_receipt_document'], true)) {
            $expressions += ['parent_project_id' => 'COALESCE(native_contract.project_id, native_site.project_id)', 'contract_updated_at' => 'native_contract.updated_at', 'request_updated_at' => 'native_request.updated_at', 'site_updated_at' => 'native_site.updated_at'];
            if ($type === 'purchase_receipt_document') { $expressions['parent_updated_at'] = 'native_parent.updated_at'; }
        }
        if ($type === 'purchase_order') {
            foreach (['storage_path' => 'pdf_path', 'sha256' => 'pdf_sha256', 'storage_etag' => 'pdf_etag', 'size_bytes' => 'pdf_size_bytes', 'mime_type' => 'pdf_mime'] as $alias => $field) {
                $expressions[$alias] = "native_source.metadata->>'$field'";
            }
        }
        return $expressions;
    }

    public static function sourceColumns(string $type): array
    {
        $columns = [];
        foreach (self::versionExpressions($type) as $field => $expression) { $columns[] = DB::raw($expression.' as '.$field); }
        return $columns;
    }

    public static function versionData(string $type, array $source): array
    {
        $data = ['native_entity_type' => $type];
        foreach (array_keys(self::versionExpressions($type)) as $field) {
            $value = $source[$field] ?? null;
            if (! is_scalar($value) && $value !== null) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
            $data[$field] = $value === null ? null : (string) $value;
        }
        return $data;
    }

    public static function fingerprint(array $version): string { return hash('sha256', json_encode($version, JSON_THROW_ON_ERROR)); }

    public static function assertSource(string $type, array $source): void
    {
        $record = self::definitions()[$type] ?? throw new RuntimeException('ai_assistant_document_native_source_invalid');
        $org = (int) ($source['organization_id'] ?? 0);
        $id = (string) ($source['id'] ?? '');
        $path = (string) ($source[$record['path']] ?? '');
        if ($org < 1 || ! preg_match('/^(?:[1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/Di', $id)
            || strlen($path) > 1024 || str_contains($path, '..') || str_contains($path, '\\') || ! str_starts_with($path, 'org-'.$org.'/')) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
        $proposal = preg_quote((string) ($source['commercial_proposal_id'] ?? ''), '#');
        $version = preg_quote((string) ($source['commercial_proposal_version_id'] ?? ''), '#');
        $uuid = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}';
        $pattern = match ($type) {
            'commercial_proposal_file' => '#^org-'.$org.'/commercial-proposals/'.$proposal.'/'.($version !== '' ? 'versions/'.$version : 'files').'/'.preg_quote((string) ($source['category'] ?? ''), '#').'/'.$uuid.'\.[A-Za-z0-9]{1,8}$#Di',
            'commercial_proposal_export' => '#^org-'.$org.'/commercial-proposals/'.$proposal.'/versions/'.$version.'/generated_export/[A-Za-z0-9][A-Za-z0-9_-]{0,180}\.(?:pdf|html)$#D',
            'crm_import_batch' => '#^org-'.$org.'/crm/imports/'.$uuid.'\.(?:csv|xlsx|xls)$#Di',
            'purchase_receipt_document' => '#^org-'.$org.'/procurement/receipt-documents/'.(int) ($source['purchase_order_id'] ?? 0).'/'.$uuid.'\.xml$#Di',
            'purchase_order' => '#^org-'.$org.'/procurement/purchase-orders/(?:user-[1-9][0-9]*|system)/order-'.(int) $id.'/'.$uuid.'\.pdf$#Di',
        };
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $size = $record['size'] === null ? null : ($source[$record['size']] ?? null);
        $hash = $record['hash'] === null ? null : ($source[$record['hash']] ?? null);
        if (preg_match($pattern, $path) !== 1 || ! isset(self::formats()[$extension])
            || ($size !== null && (! preg_match('/^[1-9][0-9]*$/D', (string) $size) || (int) $size > self::MAX_BYTES))
            || ($hash !== null && ! preg_match('/^[a-f0-9]{64}$/D', (string) $hash))
            || (in_array($type, ['purchase_order', 'purchase_receipt_document'], true) && ($size === null || $hash === null))
            || ($type === 'commercial_proposal_export' && ($source['status'] ?? null) !== 'ready')
            || ($type === 'purchase_receipt_document' && (($source['document_type'] ?? null) !== 'upd_xml' || ! in_array($source['status'] ?? null, ['validated', 'attached'], true)))) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
    }

    public static function filename(string $type, array $source): string { return basename((string) $source[self::definitions()[$type]['path']]); }
    public static function mime(string $type, array $source): string { return self::formats()[strtolower(pathinfo(self::filename($type, $source), PATHINFO_EXTENSION))]; }
    public static function projectId(array $source): ?int { return isset($source['parent_project_id']) && (int) $source['parent_project_id'] > 0 ? (int) $source['parent_project_id'] : null; }

    public static function constrainDocuments(Builder|QueryBuilder $query): void
    {
        $query->whereNull('ai_assistant_documents.file_id')->where('ai_assistant_documents.metadata->assistant_native_source', self::SOURCE)
            ->whereBetween('ai_assistant_documents.size_bytes', [1, self::MAX_BYTES])
            ->whereRaw("(ai_assistant_documents.metadata->>'native_source_sha256') ~ '^[a-f0-9]{64}$'")
            ->whereRaw("ai_assistant_documents.checksum = ai_assistant_documents.metadata->>'native_source_sha256'")
            ->where(static function (Builder|QueryBuilder $types): void {
                $types->whereRaw('1 = 0');
                foreach (self::definitions() as $type => $record) {
                    $types->orWhere(static function (Builder|QueryBuilder $branch) use ($type, $record): void {
                        $native = self::sourceQuery($type, 0)->selectRaw('1')
                            ->whereRaw('CAST(native_source.id AS TEXT) = ai_assistant_documents.parent_entity_id')
                            ->whereColumn('native_source.organization_id', 'ai_assistant_documents.organization_id')
                            ->whereRaw(self::versionExpressions($type)[$record['path']].' = ai_assistant_documents.storage_path');
                        foreach (self::versionExpressions($type) as $field => $expression) {
                            $native->whereRaw("CAST($expression AS TEXT) IS NOT DISTINCT FROM ai_assistant_documents.metadata->'native_source_fields'->>'$field'");
                        }
                        $branch->where('ai_assistant_documents.parent_entity_type', $type)
                            ->where('ai_assistant_documents.metadata->native_source_type', $type)
                            ->whereRaw("ai_assistant_documents.parent_entity_id = ai_assistant_documents.metadata->>'native_source_id'")
                            ->whereExists($native);
                    });
                }
            });
    }
}
