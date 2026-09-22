<?php

declare(strict_types=1);

namespace Tests\Unit\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementRuleFactory;
use PHPUnit\Framework\TestCase;

final class ExecutiveDocumentRequirementRuleFactoryTest extends TestCase
{
    public function test_external_profile_has_no_invented_act_roles_but_supports_explicit_attachments(): void
    {
        $profile = ['type' => 'system_test_act', 'relations' => [['key' => 'protocols', 'target' => 'test_result', 'multiple' => true]]];
        $snapshot = (new ExecutiveDocumentRequirementRuleFactory())->snapshot($profile, ['required_relations' => ['protocols']]);
        self::assertSame([], $snapshot['required_signatories']);
        self::assertSame([], $snapshot['unresolved_conditions']);
        self::assertSame(['protocols'], $snapshot['required_relations']);
        self::assertSame($profile, $snapshot['profile']);
    }

    public function test_five_act_profiles_have_three_base_signatories_and_explicit_conditional_roles(): void
    {
        $factory = new ExecutiveDocumentRequirementRuleFactory();
        $profiles = [
            'hidden_work_act' => 'direct_work_executor',
            'axis_layout_act' => 'axis_layout_executor',
            'geodetic_base_acceptance_act' => 'geodetic_base_executor',
            'responsible_structure_act' => 'structure_executor',
            'engineering_network_section_act' => 'network_executor',
        ];

        foreach ($profiles as $type => $executor) {
            $conditions = [
                'designer_supervision' => true,
                'separate_executor' => true,
            ];
            $snapshot = $factory->snapshot(['type' => $type, 'regulatory_basis' => ['344/пр']], $conditions);

            self::assertSame(['344/пр'], $snapshot['normative_source']);
            self::assertArrayHasKey('developer_control_representative', $snapshot['required_signatories']);
            self::assertArrayHasKey('construction_representative', $snapshot['required_signatories']);
            self::assertArrayHasKey('contractor_control_representative', $snapshot['required_signatories']);
            self::assertArrayHasKey('designer_representative', $snapshot['required_signatories']);
            self::assertArrayHasKey($executor, $snapshot['required_signatories']);
            self::assertSame([], $snapshot['unresolved_conditions']);

            if ($type === 'engineering_network_section_act') {
                self::assertArrayHasKey('operating_company_representative', $snapshot['required_signatories']);
            } else {
                self::assertArrayNotHasKey('operating_company_representative', $snapshot['required_signatories']);
            }
        }
    }

    public function test_missing_conditions_are_unresolved_and_do_not_become_required_signatories(): void
    {
        $snapshot = (new ExecutiveDocumentRequirementRuleFactory())->snapshot([
            'type' => 'engineering_network_section_act',
            'regulatory_basis' => ['344/пр'],
        ], []);

        self::assertSame(['designer_supervision', 'separate_executor'], $snapshot['unresolved_conditions']);
        self::assertCount(3, $snapshot['required_signatories']);
    }

    public function test_required_relations_accept_only_declared_profile_keys(): void
    {
        $factory = new ExecutiveDocumentRequirementRuleFactory();
        $profile = [
            'type' => 'hidden_work_act',
            'regulatory_basis' => [],
            'relations' => [
                ['key' => 'journal_entry', 'target' => 'journal_entry', 'multiple' => true],
                ['key' => 'executive_schemes', 'target' => 'geodetic_scheme', 'multiple' => true],
            ],
        ];

        $snapshot = $factory->snapshot($profile, [
            'designer_supervision' => false,
            'separate_executor' => false,
            'required_relations' => ['journal_entry'],
        ]);
        self::assertSame(['journal_entry'], $snapshot['required_relations']);
        self::assertSame([], $snapshot['unresolved_conditions']);

        $this->expectException(\InvalidArgumentException::class);
        $factory->snapshot($profile, ['required_relations' => ['missing_relation']]);
    }
}
