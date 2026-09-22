<?php

declare(strict_types=1);

namespace Tests\Unit\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementEvidenceValidator;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;

final class ExecutiveDocumentRequirementEvidenceValidatorTest extends TestCase
{
    public function test_required_document_relation_needs_an_approved_version_snapshot_not_only_an_id(): void
    {
        $requirement = $this->requirement('working_drawing_set', [
            'profile' => $this->profile('working_drawing_set', []),
            'required_relations' => ['related_acts'],
        ]);
        $relation = ['relation_type' => 'related_acts', 'target_type' => 'executive_document', 'target_id' => 9];
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        foreach ([null, ['version_id' => 1, 'document_id' => 9, 'content_hash' => 'hash', 'status' => 'draft'], ['version_id' => 1, 'document_id' => 10, 'content_hash' => 'hash', 'status' => 'approved']] as $snapshot) {
            $version = $this->version(['profile' => $this->profile('working_drawing_set', []), 'relations' => [array_replace($relation, ['target_version' => $snapshot])]], [], true);
            self::assertContains('required_relation_missing', array_column($validator->violations($requirement, $version), 'code'));
        }
        $version = $this->version(['profile' => $this->profile('working_drawing_set', []), 'relations' => [array_replace($relation, ['target_version' => ['version_id' => 1, 'document_id' => 9, 'content_hash' => 'hash', 'status' => 'approved']])]], [], true);
        self::assertSame([], $validator->violations($requirement, $version));
    }

    public function test_blank_or_non_text_authority_details_do_not_confirm_a_signatory(): void
    {
        $requirement = $this->requirement('hidden_work_act', ['required_signatories' => ['construction_representative' => ['authority_required' => true]]]);
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        foreach (['   ', true, ['text' => 'Приказ']] as $authority) {
            $version = $this->version([
                'profile' => $this->profile('hidden_work_act', []),
                'document' => ['signatories' => [[
                    'role' => 'construction_representative', 'name' => 'Иванов', 'organization' => 'Подрядчик', 'authority_document' => $authority,
                ]]],
            ], [], true);
            self::assertContains('authority_details_missing', array_column($validator->violations($requirement, $version), 'code'));
        }
    }

    public function test_unresolved_conditions_block_even_an_otherwise_complete_version(): void
    {
        $requirement = $this->requirement('working_drawing_set', ['profile' => $this->profile('working_drawing_set', []), 'unresolved_conditions' => ['designer_supervision']]);
        $version = $this->version(['profile' => $this->profile('working_drawing_set', [])], [], true);
        self::assertContains('normative_conditions_unresolved', array_column((new ExecutiveDocumentRequirementEvidenceValidator())->violations($requirement, $version), 'code'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $loader = new ArrayLoader();
        $loader->addMessages('ru', 'executive_requirements', require dirname(__DIR__, 3).'/lang/ru/executive_requirements.php');
        $app->instance('translator', new Translator($loader, 'ru'));
        $app->instance('log', new Logger(new MonologLogger('pure-test')));
        Facade::setFacadeApplication($app);
    }

    public function test_validates_required_fields_and_immutable_profile(): void
    {
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        $requirement = $this->requirement('working_drawing_set', ['profile' => $this->profile('working_drawing_set', [['key' => 'drawing_set_code', 'required' => true]])]);
        $version = $this->version([
            'profile' => $this->profile('working_drawing_set', [['key' => 'drawing_set_code', 'required' => false]]),
            'document' => ['document_type' => 'working_drawing_set'],
        ], [], true);

        self::assertSame(['required_field_missing'], array_column($validator->violations($requirement, $version), 'code'));
    }

    public function test_relation_multiple_does_not_make_attachment_required(): void
    {
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        $requirement = $this->requirement('responsible_structure_act');
        $version = $this->version([
            'profile' => $this->profile('responsible_structure_act', []),
            'relations' => [],
            'document' => ['document_type' => 'responsible_structure_act'],
        ], [], true);

        self::assertSame([], $validator->violations($requirement, $version));
    }

    public function test_explicit_required_relation_and_signatory_authority_are_checked(): void
    {
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        $requirement = $this->requirement('incoming_batch_control', [
            'relations' => [['key' => 'material_delivery', 'target' => 'project_material_delivery', 'required' => true]],
            'required_signatories' => ['contractor_control_representative' => ['authority_required' => true]],
        ]);
        $version = $this->version([
            'profile' => $this->profile('incoming_batch_control', []),
            'document' => ['document_type' => 'incoming_batch_control', 'signatories' => [['role' => 'contractor_control_representative']]],
        ], [], true);

        self::assertSame(['required_relation_missing', 'authority_details_missing'], array_column($validator->violations($requirement, $version), 'code'));
    }

    public function test_not_applicable_requirement_has_no_violations(): void
    {
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        $requirement = $this->requirement('system_test_act');
        $requirement->applicability = 'not_applicable';

        self::assertSame([], $validator->violations($requirement, $this->version([], [], true)));
    }

    private function requirement(string $type, array $ruleSnapshot = []): ExecutiveDocumentRequirement
    {
        return new ExecutiveDocumentRequirement(['profile_type' => $type, 'applicability' => 'required', 'rule_snapshot' => $ruleSnapshot]);
    }

    private function version(array $basis, array $profile, bool $complete = false): ExecutiveDocumentVersion
    {
        return new ExecutiveDocumentVersion([
            'basis_snapshot' => $basis,
            'profile_snapshot' => $profile,
            'file_url' => $complete ? 's3://evidence.pdf' : null,
            'content_hash' => $complete ? str_repeat('a', 64) : null,
        ]);
    }

    private function profile(string $type, array $fields): array
    {
        return ['type' => $type, 'fields' => $fields, 'relations' => [], 'requires_work_type' => false, 'requires_journal_entry' => false];
    }

    public function test_version_without_file_or_hash_is_not_evidence(): void
    {
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        $requirement = $this->requirement('working_drawing_set');
        $version = $this->version(['profile' => $this->profile('working_drawing_set', [])], []);

        self::assertSame(['file_missing', 'content_hash_missing'], array_column($validator->violations($requirement, $version), 'code'));
    }

    public function test_missing_immutable_coverage_does_not_satisfy_scoped_requirement(): void
    {
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        $requirement = $this->requirement('working_drawing_set');
        $requirement->coverage_scope = ['project_location_id' => 17];
        $version = $this->version(['profile' => $this->profile('working_drawing_set', [])], [], true);

        self::assertSame(['coverage_missing'], array_column($validator->violations($requirement, $version), 'code'));
    }

    public function test_authoritative_rules_accept_complete_immutable_version(): void
    {
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        $requirement = $this->requirement('incoming_batch_control', [
            'profile' => $this->profile('incoming_batch_control', [['key' => 'batch_details', 'required' => true]]),
            'relations' => [['key' => 'material_delivery', 'target' => 'project_material_delivery', 'required' => true]],
            'required_signatories' => ['contractor_control_representative' => ['authority_required' => true]],
        ]);
        $version = $this->version([
            'profile' => $this->profile('incoming_batch_control', []),
            'coverage' => ['project_location_id' => 17],
            'relations' => [['relation_type' => 'material_delivery', 'target_type' => 'project_material_delivery', 'target_id' => 9, 'domain_snapshot' => ['id' => 9, 'material_id' => 2, 'status' => 'accepted']]],
            'document' => ['document_type' => 'incoming_batch_control', 'signatories' => [[
                'role' => 'contractor_control_representative', 'name' => 'Иванов И.И.', 'organization' => 'ООО Строй', 'authority_document' => 'Доверенность 1',
            ]]],
        ], ['batch_details' => 'Партия 1'], true);
        $requirement->coverage_scope = ['project_location_id' => 17];

        self::assertSame([], $validator->violations($requirement, $version));
    }

    public function test_signatory_from_mutable_profile_snapshot_is_not_evidence(): void
    {
        $validator = new ExecutiveDocumentRequirementEvidenceValidator();
        $requirement = $this->requirement('incoming_batch_control', [
            'required_signatories' => ['contractor_control_representative' => ['authority_required' => true]],
        ]);
        $version = $this->version([
            'profile' => $this->profile('incoming_batch_control', []),
            'document' => ['document_type' => 'incoming_batch_control'],
        ], ['signatories' => [['role' => 'contractor_control_representative', 'name' => 'Иванов И.И.']], 'batch_details' => 'Партия'], true);

        self::assertSame(['required_signatory_missing'], array_column($validator->violations($requirement, $version), 'code'));
    }
}
