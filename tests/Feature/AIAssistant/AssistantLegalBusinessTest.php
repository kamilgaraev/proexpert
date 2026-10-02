<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\LegalBusinessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ExecutiveBusinessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\HandoverBusinessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ChangeBusinessRagSource;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentImport;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentImportItem;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentComment;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentObligation;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantLegalBusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_grants_filter_candidates_before_limit_and_revoke_current_references(): void
    {
        $fixture = $this->fixture();
        for ($i=0;$i<33;$i++) { $this->document($fixture,['confidentiality_level'=>'secret']); }
        $document = $this->document($fixture,['confidentiality_level'=>'restricted']);
        $grant = LegalDocumentAccessGrant::query()->create(['organization_id'=>$fixture->organization->id,'document_id'=>$document->id,
            'subject_kind'=>'internal_user','subject_organization_id'=>$fixture->organization->id,'subject_user_id'=>$fixture->member->id,
            'abilities'=>['view'],'granted_by_user_id'=>$fixture->owner->id]);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertSame([$document->id],$policy->entityQuery($fixture->member,$fixture->organization->id,'legal_document')->orderBy('id')->limit(32)->pluck('id')->all());
        $reference = ['type'=>'legal_document','id'=>$document->id];
        self::assertTrue($policy->canReadReference($fixture->member,$fixture->organization->id,$reference));
        app(AuthorizationService::class)->can($fixture->member,'legal_archive.view',['organization_id'=>$fixture->organization->id]);
        $grant->update(['revoked_at'=>now(),'revoked_by_user_id'=>$fixture->owner->id,'revocation_reason'=>'Регрессия отзыва']);
        self::assertFalse($policy->canReadReference($fixture->member,$fixture->organization->id,$reference));
        self::assertFalse($policy->canReadEntity($fixture->foreignOwner,$fixture->organization->id,'legal_document',$document->id));
    }

    public function test_private_comment_audience_and_current_version_parent_are_enforced_before_limit(): void
    {
        $fixture = $this->fixture();
        $document = $this->document($fixture,['responsible_user_id'=>$fixture->owner->id]);
        $version = $this->version($fixture,$document);
        foreach (['author_and_responsible','author_and_responsible','internal'] as $visibility) {
            $comment = LegalDocumentComment::query()->create(['organization_id'=>$fixture->organization->id,'document_id'=>$document->id,
                'document_version_id'=>$version->id,'author_user_id'=>$fixture->owner->id,'body'=>'Замечание '.$visibility,'visibility'=>$visibility]);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertSame([$comment->id],$policy->entityQuery($fixture->member,$fixture->organization->id,'legal_document_comment')->orderBy('id')->limit(1)->pluck('id')->all());
        $document->updateQuietly(['responsible_user_id'=>$fixture->member->id]);
        self::assertSame(3,$policy->entityQuery($fixture->member,$fixture->organization->id,'legal_document_comment')->count());
        $fixture->memberRole->update(['system_permissions'=>['legal_archive.view','finance.view']]);
        self::assertFalse($policy->canReadEntity($fixture->member,$fixture->organization->id,'legal_document_comment',$comment->id));
    }

    public function test_all_import_form_rows_inherit_assigned_project_and_current_parent_before_limit(): void
    {
        $fixture = $this->fixture();
        $visible = Project::withoutEvents(fn () => Project::factory()->create(['organization_id'=>$fixture->organization->id]));
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id'=>$fixture->organization->id]));
        $fixture->member->assignedProjects()->attach($visible->id,['is_active'=>true,'role'=>'member']);
        $visibleIds = [];
        foreach ([$hidden,$hidden,$hidden,$visible,$visible] as $index=>$project) {
            $set = ExecutiveDocumentSet::withoutEvents(fn () => ExecutiveDocumentSet::query()->create(['organization_id'=>$fixture->organization->id,
                'project_id'=>$project->id,'created_by'=>$fixture->owner->id,'set_number'=>'AI-'.$index,'title'=>'Комплект '.$index,'status'=>'draft']));
            $batch = ExecutiveDocumentImport::query()->create(['organization_id'=>$fixture->organization->id,'document_set_id'=>$set->id,
                'created_by'=>$fixture->owner->id,'operation_key'=>'import-'.$index,'manifest_hash'=>str_repeat('a',64)]);
            $item = ExecutiveDocumentImportItem::query()->create(['import_id'=>$batch->id,'client_key'=>'file-'.$index,
                'original_name'=>'Строка '.$index.'.pdf','size'=>12,'content_hash'=>str_repeat('b',64),'staged_path'=>'private-secret-path',
                'mapping'=>['token'=>'private-secret'],'errors'=>['secret'=>'private-secret']]);
            if ($project->id === $visible->id) { $visibleIds[] = $item->id; }
        }
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertSame($visibleIds,$policy->entityQuery($fixture->member,$fixture->organization->id,'executive_import_item')->orderBy('id')->limit(2)->pluck('id')->all());
        $chunks = iterator_to_array((function () use ($fixture) { yield from (new ExecutiveBusinessRagSource)->collectForOrganization($fixture->organization->id); })());
        $items = array_values(array_filter($chunks,static fn ($chunk): bool => $chunk->entityType === 'executive_import_item'));
        self::assertCount(5,$items);
        foreach ($items as $chunk) { self::assertStringNotContainsString('private-secret',$chunk->content); }
        $set->delete();
        self::assertFalse($policy->canReadEntity($fixture->member,$fixture->organization->id,'executive_import_item',$item->id));
        self::assertFalse($policy->canReadEntity($fixture->foreignOwner,$fixture->organization->id,'executive_import_item',$visibleIds[0]));
    }

    public function test_exact_financial_values_and_all_four_actual_collectors_with_current_finance_revocation(): void
    {
        $fixture = $this->fixture();
        $document = $this->document($fixture,['metadata'=>['token'=>'private-secret'],'structured_fields'=>['bank'=>'private-secret']]);
        $obligation = LegalDocumentObligation::query()->create(['organization_id'=>$fixture->organization->id,'document_id'=>$document->id,
            'title'=>'Оплата','amount'=>'12345678901234.57','volume'=>'9999999999.127','unit'=>'м3','metadata'=>['private'=>'private-secret']]);
        $chunks = [];
        foreach ([new LegalBusinessRagSource,new ExecutiveBusinessRagSource,new HandoverBusinessRagSource,new ChangeBusinessRagSource] as $source) {
            foreach ($source->collectForOrganization($fixture->organization->id) as $chunk) { $chunks[] = $chunk; }
        }
        $checkpoint = array_values(array_filter($chunks,static fn ($chunk): bool => $chunk->entityType === 'change_history_checkpoint'))[0];
        self::assertInstanceOf(\DateTimeInterface::class,$checkpoint->updatedAt);
        self::assertSame($fixture->organization->id,$checkpoint->organizationId);
        $chunk = array_values(array_filter($chunks,static fn ($chunk): bool => $chunk->entityType === 'legal_document_obligation' && (int)$chunk->entityId === $obligation->id))[0];
        self::assertSame('12345678901234.57',$chunk->metadata['amount']);
        self::assertSame('9999999999.127',$chunk->metadata['volume']);
        foreach ($chunks as $current) { self::assertStringNotContainsString('private-secret',$current->content); }
        $policy = app(AssistantDataAccessPolicy::class);
        $reference = ['type'=>'legal_document_obligation','id'=>$obligation->id];
        self::assertTrue($policy->canReadReference($fixture->member,$fixture->organization->id,$reference));
        app(AuthorizationService::class)->can($fixture->member,'finance.view',['organization_id'=>$fixture->organization->id]);
        $fixture->memberRole->update(['system_permissions'=>['legal_archive.view','legal_archive.files.view']]);
        self::assertTrue($policy->canReadEntity($fixture->member,$fixture->organization->id,'legal_document_obligation',$obligation->id));
        self::assertFalse($policy->canReadReference($fixture->member,$fixture->organization->id,$reference));
    }

    public function test_archive_reference_requires_current_executive_artifact_and_project_despite_internal_grant(): void
    {
        $fixture = $this->fixture();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id'=>$fixture->organization->id]));
        $fixture->member->assignedProjects()->attach($project->id,['is_active'=>true,'role'=>'member']);
        $set = ExecutiveDocumentSet::withoutEvents(fn () => ExecutiveDocumentSet::query()->create(['organization_id'=>$fixture->organization->id,
            'project_id'=>$project->id,'set_number'=>'AI-source','title'=>'Комплект','status'=>'draft']));
        $artifact = ExecutiveDocument::withoutEvents(fn () => ExecutiveDocument::query()->create(['organization_id'=>$fixture->organization->id,
            'project_id'=>$project->id,'document_set_id'=>$set->id,'document_type'=>'hidden_work_act','title'=>'Акт','status'=>'draft']));
        $document = $this->document($fixture,['primary_project_id'=>$project->id,'source_type'=>'executive_document','source_id'=>(string)$artifact->id,
            'confidentiality_level'=>'restricted']);
        LegalDocumentAccessGrant::query()->create(['organization_id'=>$fixture->organization->id,'document_id'=>$document->id,'subject_kind'=>'internal_user',
            'subject_organization_id'=>$fixture->organization->id,'subject_user_id'=>$fixture->member->id,'abilities'=>['view'],'granted_by_user_id'=>$fixture->owner->id]);
        $policy = app(AssistantDataAccessPolicy::class);
        $reference = ['type'=>'legal_document','id'=>$document->id];
        self::assertTrue($policy->canReadReference($fixture->member,$fixture->organization->id,$reference));
        $fixture->member->assignedProjects()->detach($project->id);
        self::assertFalse($policy->canReadReference($fixture->member,$fixture->organization->id,$reference));
        $fixture->member->assignedProjects()->attach($project->id,['is_active'=>true,'role'=>'member']);
        self::assertTrue($policy->canReadReference($fixture->member,$fixture->organization->id,$reference));
        $artifact->delete();
        self::assertFalse($policy->canReadReference($fixture->member,$fixture->organization->id,$reference));
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['system_permissions'=>['legal_archive.view','legal_archive.files.view','finance.view'],
            'module_permissions'=>['ai-assistant'=>['ai_assistant.chat'],'project-management'=>['projects.view'],
                'executive-documentation'=>['executive-documentation.view']]]);
        return $fixture;
    }

    private function document(AssistantRealAuthorizationFixture $fixture,array $fields = []): LegalArchiveDocument
    {
        return LegalArchiveDocument::withoutEvents(fn () => LegalArchiveDocument::query()->create($fields + ['organization_id'=>$fixture->organization->id,
            'title'=>'Юридический документ','document_type'=>'contract','status'=>'draft','lifecycle_status'=>'draft','approval_status'=>'not_started',
            'signature_status'=>'unsigned','confidentiality_level'=>'internal','lock_version'=>0]));
    }

    private function version(AssistantRealAuthorizationFixture $fixture,LegalArchiveDocument $document): LegalArchiveDocumentVersion
    {
        $file = LegalArchiveDocumentFile::query()->create(['organization_id'=>$fixture->organization->id,'document_id'=>$document->id,
            'role'=>'primary','title'=>'Основной документ','sort_order'=>0,'is_required'=>true]);
        return LegalArchiveDocumentVersion::query()->create(['organization_id'=>$fixture->organization->id,'document_id'=>$document->id,'document_file_id'=>$file->id,
            'version_number'=>'1','status'=>'uploaded','processing_status'=>'ready','file_path'=>'private-legal-source','original_filename'=>'source.pdf',
            'mime_type'=>'application/pdf','size_bytes'=>12,'content_hash'=>str_repeat('a',64)]);
    }
}
