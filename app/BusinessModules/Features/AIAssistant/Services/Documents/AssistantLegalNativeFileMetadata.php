<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantLegalNativeFileMetadata
{
    public const SOURCE = 'legal_executive_native';
    public const MAX_BYTES = 25_000_000;

    public static function definitions(): array
    {
        return ['legal_document_version'=>['model'=>LegalArchiveDocumentVersion::class,'table'=>'legal_archive_document_versions','path'=>'file_path','hash'=>'content_hash',
                'actors'=>['uploaded_by_user_id'],'permissions'=>['legal_archive.files.view'],'deleted'=>false],
            'executive_version'=>['model'=>ExecutiveDocumentVersion::class,'table'=>'executive_document_versions','path'=>'file_url','hash'=>'content_hash',
                'actors'=>['uploaded_by','approved_by'],'permissions'=>['executive-documentation.view'],'deleted'=>true],
            'executive_approved_list'=>['model'=>ExecutiveDocumentApprovedList::class,'table'=>'executive_document_approved_lists','path'=>'file_url','hash'=>'file_hash',
                'actors'=>['uploaded_by'],'permissions'=>['executive-documentation.view'],'deleted'=>false]];
    }

    public static function formats(): array
    {
        return ['pdf'=>'application/pdf','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','doc'=>'application/msword',
            'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','xls'=>'application/vnd.ms-excel','csv'=>'text/csv',
            'txt'=>'text/plain','json'=>'application/json','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp',
            'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','ppt'=>'application/vnd.ms-powerpoint'];
    }

    public static function types(): array { return array_keys(self::definitions()); }
    public static function fileParents(): array
    {
        $result = [];
        foreach (self::definitions() as $type=>$record) {
            $result[$record['model']] = ['entity_type'=>$type,'path'=>$record['path'],'permissions'=>$record['permissions'],
                'native_adapter'=>AssistantLegalNativeFileAdapter::class];
        }
        return $result;
    }

    public static function sourceQuery(string $type,int $organizationId): QueryBuilder
    {
        $definition = self::definitions()[$type] ?? throw new RuntimeException('ai_assistant_document_native_source_invalid');
        $query = DB::table($definition['table'].' as native_source');
        if ($organizationId > 0) { $query->where('native_source.organization_id',$organizationId); }
        if ($definition['deleted']) { $query->whereNull('native_source.deleted_at'); }
        if ($type === 'legal_document_version') {
            $query->join('legal_archive_documents as native_document','native_document.id','=','native_source.document_id')
                ->join('legal_archive_document_files as native_file','native_file.id','=','native_source.document_file_id')
                ->whereColumn('native_document.organization_id','native_source.organization_id')->whereColumn('native_file.organization_id','native_source.organization_id')
                ->whereColumn('native_file.document_id','native_document.id')->whereNull('native_document.deleted_at')->where('native_source.processing_status','ready');
        } elseif ($type === 'executive_version') {
            $query->join('executive_documents as native_document','native_document.id','=','native_source.document_id')
                ->join('executive_document_sets as native_set','native_set.id','=','native_document.document_set_id')
                ->whereColumn('native_document.organization_id','native_source.organization_id')->whereColumn('native_set.organization_id','native_source.organization_id')
                ->whereColumn('native_set.project_id','native_document.project_id')->whereNull('native_document.deleted_at')->whereNull('native_set.deleted_at');
        }
        return $query;
    }

    public static function sourceColumns(string $type): array
    {
        $columns = array_map(static fn (string $expression,string $key): string => $expression.' as '.$key,self::versionExpressions($type),array_keys(self::versionExpressions($type)));
        foreach (self::definitions()[$type]['actors'] as $actorColumn) { $columns[] = 'native_source.'.$actorColumn; }
        return array_values(array_unique($columns));
    }

    public static function versionExpressions(string $type): array
    {
        $definition = self::definitions()[$type];
        $fields = ['id','organization_id',$definition['path'],$definition['hash'],'updated_at',...match ($type) {
            'legal_document_version'=>['document_id','document_file_id','version_number','status','processing_status','is_current','original_filename','mime_type','size_bytes'],
            'executive_version'=>['document_id','version_number','status','uploaded_by','approved_by'],
            'executive_approved_list'=>['project_id','revision','approved_by_party','approved_at','original_name','uploaded_by'],
        }];
        $columns = array_combine($fields,array_map(static fn (string $field): string => 'native_source.'.$field,$fields));
        if ($type === 'legal_document_version') {
            $columns += ['parent_project_id'=>'native_document.primary_project_id','parent_updated_at'=>'native_document.updated_at','parent_file_updated_at'=>'native_file.updated_at'];
        } elseif ($type === 'executive_version') {
            $columns += ['parent_project_id'=>'native_document.project_id','parent_set_id'=>'native_document.document_set_id',
                'parent_updated_at'=>'native_document.updated_at','parent_set_updated_at'=>'native_set.updated_at'];
        }
        return $columns;
    }

    public static function assertSource(string $type,array $source): void
    {
        $definition = self::definitions()[$type] ?? throw new RuntimeException('ai_assistant_document_native_source_invalid');
        $organizationId = (int)($source['organization_id'] ?? 0);
        $path = (string)($source[$definition['path']] ?? '');
        $prefix = self::prefix($type,$source);
        $leaf = str_starts_with($path,$prefix) ? substr($path,strlen($prefix)) : '';
        $extension = strtolower(pathinfo($leaf,PATHINFO_EXTENSION));
        $hash = $source[$definition['hash']] ?? null;
        if ($organizationId < 1 || (int)($source['id'] ?? 0) < 1 || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.[A-Za-z0-9]{1,8}$/D',$leaf)
            || ! isset(self::formats()[$extension]) || strlen($path)>1024 || ($hash !== null && ! preg_match('/^[a-f0-9]{64}$/D',(string)$hash))
            || ($type === 'legal_document_version' && ((int)($source['document_file_id'] ?? 0) < 1 || ($source['processing_status'] ?? null) !== 'ready'
                || (int)($source['size_bytes'] ?? 0) < 1 || (int)$source['size_bytes'] > self::MAX_BYTES))) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
    }

    public static function prefix(string $type,array $source): string
    {
        $organizationId = (int)($source['organization_id'] ?? 0);
        return match ($type) {
            'legal_document_version'=>'org-'.$organizationId.'/legal-archive/files/'.(int)($source['document_file_id'] ?? 0).'/versions/',
            'executive_version'=>'org-'.$organizationId.'/executive-documentation/project-'.(int)($source['parent_project_id'] ?? 0).'/set-'.(int)($source['parent_set_id'] ?? 0).'/',
            'executive_approved_list'=>'org-'.$organizationId.'/executive-documentation/project-'.(int)($source['project_id'] ?? 0).'/approved-lists/',
            default=>throw new RuntimeException('ai_assistant_document_native_source_invalid'),
        };
    }

    public static function versionData(string $type,array $source): array
    {
        $result = ['entity_type'=>$type];
        foreach (array_keys(self::versionExpressions($type)) as $field) {
            $value = $source[$field] ?? null;
            $result[$field] = $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
        }
        return $result;
    }

    public static function fingerprint(array $version): string { return hash('sha256',json_encode($version,JSON_THROW_ON_ERROR)); }
    public static function filename(string $type,array $source): string
    {
        $path = (string)$source[self::definitions()[$type]['path']];
        return basename($path);
    }
    public static function mime(string $type,array $source): string
    {
        return self::formats()[strtolower(pathinfo((string)$source[self::definitions()[$type]['path']],PATHINFO_EXTENSION))];
    }

    public static function constrainMappings(Builder|QueryBuilder $query): void
    {
        $query->where('files.additional_info->assistant_native_source',self::SOURCE)->where('files.disk','s3')->whereBetween('files.size',[1,self::MAX_BYTES])
            ->whereColumn('files.name','files.original_name')
            ->whereRaw("(files.additional_info->>'native_source_sha256') ~ '^[a-f0-9]{64}$'")
            ->where(static function (Builder|QueryBuilder $types): void {
                $types->whereRaw('1 = 0');
                foreach (self::definitions() as $type=>$definition) {
                    $model = new $definition['model'];
                    $types->orWhere(static function (Builder|QueryBuilder $branch) use ($type,$definition,$model): void {
                        $native = self::sourceQuery($type,0)->selectRaw('1')->whereColumn('native_source.id','files.fileable_id')
                            ->whereColumn('native_source.organization_id','files.organization_id')->whereColumn('native_source.'.$definition['path'],'files.path')
                            ->whereRaw("files.original_name = regexp_replace(native_source.".$definition['path'].",'^.*/','')");
                        $native->whereRaw("files.additional_info->'native_source_fields'->>'entity_type' = ?",[$type])
                            ->where(static fn (QueryBuilder $hash): QueryBuilder => $hash->whereNull('native_source.'.$definition['hash'])
                                ->orWhereRaw('native_source.'.$definition['hash']." ~ '^[a-f0-9]{64}$'"));
                        foreach (self::versionExpressions($type) as $field=>$expression) {
                            $native->whereRaw("CAST($expression AS TEXT) IS NOT DISTINCT FROM files.additional_info->'native_source_fields'->>'$field'");
                        }
                        if ($type === 'legal_document_version') { $native->whereColumn('native_source.size_bytes','files.size'); }
                        $prefix = match ($type) {
                            'legal_document_version'=>"'org-' || native_source.organization_id || '/legal-archive/files/' || native_source.document_file_id || '/versions/'",
                            'executive_version'=>"'org-' || native_source.organization_id || '/executive-documentation/project-' || native_document.project_id || '/set-' || native_document.document_set_id || '/'",
                            'executive_approved_list'=>"'org-' || native_source.organization_id || '/executive-documentation/project-' || native_source.project_id || '/approved-lists/'",
                        };
                        $leaf = 'substring(native_source.'.$definition['path'].' FROM length('.$prefix.') + 1)';
                        $native->whereRaw('left(native_source.'.$definition['path'].',length('.$prefix.')) = '.$prefix)
                            ->whereRaw($leaf." ~ '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\\.[A-Za-z0-9]{1,8}$'");
                        $native->where(static function (QueryBuilder $formats) use ($leaf): void {
                            $formats->whereRaw('1 = 0');
                            foreach (self::formats() as $extension=>$mime) {
                                $formats->orWhere(static fn (QueryBuilder $format): QueryBuilder => $format->where('files.mime_type',$mime)->whereRaw('lower('.$leaf.') LIKE ?',['%.'.$extension]));
                            }
                        });
                        $branch->whereIn('files.fileable_type',[$definition['model'],$model->getMorphClass()])->where('files.additional_info->native_entity_type',$type)->whereExists($native);
                    });
                }
            });
    }
}
