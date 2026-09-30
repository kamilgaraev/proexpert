<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantLegalBusinessMetadata as Metadata;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AssistantLegalBusinessMetadataTest extends TestCase
{
    public function test_every_model_is_existing_typed_business_new_safe_business_or_explicitly_excluded(): void
    {
        $root = dirname(__DIR__, 3);
        $existing = ['ChangeApproval','ChangeClaim','ChangeImpact','ChangeManagementRfi','ChangeRequest','VariationOrder',
            'ExecutiveDocument','ExecutiveDocumentSet','AcceptanceChecklist','AcceptanceChecklistItem','AcceptanceFinding',
            'AcceptanceScope','AcceptanceSession','AcceptanceSignoff','HandoverPackage','HandoverPackageDocument','ProjectLocation'];
        $mapped = array_map(static fn (array $record): string => class_basename($record[1]), Metadata::entityDefinitions());
        $expected = [];
        foreach (['LegalArchive','ExecutiveDocumentation','HandoverAcceptance','ChangeManagement'] as $module) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/BusinessModules/Features/'.$module));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && basename(dirname($file->getPathname())) === 'Models') {
                    $name = $file->getBasename('.php');
                    if (! in_array($name, $existing, true) && ! isset(Metadata::excludedModels()[$name])) { $expected[] = $name; }
                }
            }
        }
        sort($expected); sort($mapped);
        self::assertSame($expected, $mapped);
        self::assertCount(40, $mapped);
        self::assertCount(7, Metadata::excludedModels());
        self::assertCount(40, Metadata::entityLabels());
        $canonical = json_decode((string) file_get_contents($root.'/config/RoleDefinitions/lk/organization_owner.json'), true, 512, JSON_THROW_ON_ERROR);
        $permissions = $canonical['system_permissions'];
        foreach ($canonical['module_permissions'] as $keys) { $permissions = array_merge($permissions,$keys); }
        $finance = json_decode((string) file_get_contents($root.'/config/RoleDefinitions/admin/finance_admin.json'),true,512,JSON_THROW_ON_ERROR);
        foreach ($finance['module_permissions'] as $keys) { $permissions = array_merge($permissions,$keys); }
        foreach (['executive-documentation','handover-acceptance','change-management'] as $module) {
            $manifest = json_decode((string) file_get_contents($root.'/config/ModuleList/features/'.$module.'.json'),true,512,JSON_THROW_ON_ERROR);
            $permissions = array_merge($permissions,$manifest['permissions']);
        }
        foreach (Metadata::entityDefinitions() as $type => [$source,$class,$domain]) {
            self::assertTrue(is_subclass_of($class,Model::class),$type);
            self::assertArrayHasKey($domain,Metadata::domainGates());
            self::assertSame([],array_intersect(Metadata::fields()[$type],Metadata::technicalFields()),$type);
            self::assertSame([],array_intersect(Metadata::safeSelectColumns()[$type],Metadata::technicalFields()),$type);
            foreach (Metadata::entityPermissions()[$type] as $permission) { self::assertContains($permission,$permissions,$type); }
            self::assertSame(['finance.view'],Metadata::sourcePermissions()[$source]);
        }
        $visit = function (string $type,array $path = []) use (&$visit): void {
            self::assertNotContains($type,$path);
            foreach (Metadata::parentColumns()[$type] ?? [] as $parent) {
                if (isset(Metadata::entityDefinitions()[$parent['type']])) { $visit($parent['type'],[...$path,$type]); }
            }
        };
        foreach (array_keys(Metadata::records()) as $type) { $visit($type); }
        self::assertSame(['executive_import_item'=>null,'change_rfi_history'=>null],Metadata::organizationColumns());
        self::assertSame(['document_id'=>'document_id'],Metadata::parentColumns()['executive_remark']['version_id']['matches']);
        foreach (Metadata::domainDefinitions() as $domain) {
            foreach (array_intersect($domain->fields,Metadata::moneyFields()) as $field) {
                self::assertSame('finance.view',$domain->fieldPermissions[$field]);
            }
        }
    }
}
