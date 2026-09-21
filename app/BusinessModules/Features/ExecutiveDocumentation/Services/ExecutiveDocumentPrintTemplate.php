<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use DomainException;

final class ExecutiveDocumentPrintTemplate
{
    private const VERSION = '344-369-v1';

    private const TYPES = [
        'hidden_work_act',
        'axis_layout_act',
        'geodetic_base_acceptance_act',
        'responsible_structure_act',
        'engineering_network_section_act',
    ];

    public function html(array $snapshot, string $templateVersion): string
    {
        if ($templateVersion !== self::VERSION) {
            throw new DomainException('unsupported_executive_document_print_template');
        }

        $type = (string) ($snapshot['document_type'] ?? '');
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('unsupported_executive_document_type');
        }

        $profile = (array) ($snapshot['profile_data'] ?? $snapshot['profile'] ?? []);
        $document = (array) ($snapshot['document'] ?? []);
        $profile = $this->withReferences($profile, (array) ($snapshot['relations'] ?? []));
        $sections = $this->sections($type, $profile);
        $form = self::form($type);

        return view('executive-documentation.print.'.$type, [
            'type' => $type,
            'title' => (string) ($document['title'] ?? ''),
            'officialTitle' => $form['title'],
            'document' => $document,
            'profile' => $profile,
            'project' => (array) ($snapshot['project'] ?? []),
            'participants' => array_values(array_filter((array) ($document['participants'] ?? []), 'is_array')),
            'signatories' => array_values(array_filter((array) ($document['signatories'] ?? []), 'is_array')),
            'relations' => array_values(array_filter((array) ($snapshot['relations'] ?? []), 'is_array')),
            'sections' => $sections,
            'sourceVersionId' => $snapshot['source_version_id'] ?? null,
            'sourceVersionNumber' => $snapshot['source_version_number'] ?? null,
            'templateVersion' => $templateVersion,
        ])->render();
    }

    private function sections(string $type, array $profile): array
    {
        return array_map(
            static function (array $field) use ($profile): array {
                $value = $profile[$field['key']] ?? null;
                if ($field['key'] === 'acceptance_decision') {
                    $value = match ($value) {
                        'accepted' => 'Принято',
                        'next_works_allowed' => 'Разрешено производство последующих работ',
                        'remarks_required' => 'Требуются замечания',
                        default => $value,
                    };
                }

                return ['number' => $field['number'], 'label' => $field['label'], 'value' => $value];
            },
            self::form($type)['fields'],
        );
    }

    /** @return array{title:string,fields:list<array{number:string,key:string,label:string}>} */
    private static function form(string $type): array
    {
        return match ($type) {
            'hidden_work_act' => ['title' => 'АКТ освидетельствования скрытых работ', 'fields' => [
                ['number' => '1', 'key' => 'presented_works', 'label' => 'К освидетельствованию предъявлены следующие работы'],
                ['number' => '2', 'key' => 'project_documentation', 'label' => 'Работы выполнены по проектной документации'],
                ['number' => '3', 'key' => 'materials_quality_documents', 'label' => 'При выполнении работ применены материалы и документы о качестве и безопасности'],
                ['number' => '4', 'key' => 'compliance_documents', 'label' => 'Предъявлены документы, подтверждающие соответствие работ предъявляемым требованиям'],
                ['number' => '5', 'key' => 'started_at', 'label' => 'Дата начала работ'],
                ['number' => '5', 'key' => 'finished_at', 'label' => 'Дата окончания работ'],
                ['number' => '6', 'key' => 'normative_basis', 'label' => 'Работы выполнены в соответствии с нормативными актами и проектной документацией'],
                ['number' => '7', 'key' => 'next_works_permission', 'label' => 'Разрешается производство последующих работ'],
                ['number' => '', 'key' => 'additional_information', 'label' => 'Дополнительные сведения'],
                ['number' => '', 'key' => 'appendices', 'label' => 'Приложения'],
            ]],
            'axis_layout_act' => ['title' => 'АКТ разбивки осей объекта капитального строительства на местности', 'fields' => [
                ['number' => '1', 'key' => 'project_documentation', 'label' => 'Разбивка произведена по данным проектной документации'],
                ['number' => '2', 'key' => 'axis_fixing_text', 'label' => 'Закрепление осей произведено'],
                ['number' => '3', 'key' => 'axis_layout_text', 'label' => 'Обозначение осей, нумерация и расположение точек'],
                ['number' => '', 'key' => 'normative_basis', 'label' => 'Соответствие проектной документации, техническим регламентам и заданной точности'],
                ['number' => '', 'key' => 'additional_information', 'label' => 'Дополнительные сведения'],
                ['number' => '', 'key' => 'appendices', 'label' => 'Приложения (схема закрепления осей)'],
            ]],
            'geodetic_base_acceptance_act' => ['title' => 'АКТ освидетельствования геодезической разбивочной основы объекта капитального строительства', 'fields' => [
                ['number' => '1', 'key' => 'geodetic_base_description', 'label' => 'Предъявленные знаки геодезической разбивочной основы, координаты, отметки, места установки и способы закрепления'],
                ['number' => '2', 'key' => 'base_acceptance_documents', 'label' => 'Соответствие проектной документации, техническим регламентам и заданной точности'],
                ['number' => '', 'key' => 'additional_information', 'label' => 'Дополнительные сведения'],
                ['number' => '', 'key' => 'appendices', 'label' => 'Приложения (чертежи, схемы, ведомости)'],
            ]],
            'responsible_structure_act' => ['title' => 'АКТ освидетельствования строительных конструкций, устранение недостатков в которых невозможно без разборки или повреждения других строительных конструкций, и участков сетей инженерно-технического обеспечения (ответственных конструкций)', 'fields' => [
                ['number' => '1', 'key' => 'presented_structures', 'label' => 'К освидетельствованию предъявлены строительные конструкции и участки сетей'],
                ['number' => '2', 'key' => 'project_documentation', 'label' => 'Конструкции выполнены по проектной документации'],
                ['number' => '3', 'key' => 'hidden_work_acts', 'label' => 'Освидетельствованы скрытые работы, влияющие на безопасность конструкций'],
                ['number' => '4', 'key' => 'materials_quality_documents', 'label' => 'При выполнении строительных конструкций применены материалы и документы о качестве'],
                ['number' => '5', 'key' => 'compliance_documents', 'label' => 'Предъявлены документы, подтверждающие соответствие конструкций требованиям'],
                ['number' => '6', 'key' => 'inspection_results', 'label' => 'Проведены необходимые испытания и опробования'],
                ['number' => '7', 'key' => 'started_at', 'label' => 'Дата начала работ'],
                ['number' => '7', 'key' => 'finished_at', 'label' => 'Дата окончания работ'],
                ['number' => '8', 'key' => 'normative_basis', 'label' => 'Конструкции выполнены в соответствии с техническими регламентами и проектной документацией'],
                ['number' => '9', 'key' => 'acceptance_decision', 'label' => 'Решение по результатам освидетельствования'],
                ['number' => '9а', 'key' => 'usage_by_purpose', 'label' => 'Разрешается использование конструкций по назначению'],
                ['number' => '9б', 'key' => 'usage_load', 'label' => 'Разрешается использование конструкций с нагрузкой в размере проектной нагрузки'],
                ['number' => '9в', 'key' => 'full_load_conditions', 'label' => 'Разрешается полное нагружение при выполнении условий'],
                ['number' => '9г', 'key' => 'next_works_permission', 'label' => 'Разрешается производство последующих работ'],
                ['number' => '', 'key' => 'additional_information', 'label' => 'Дополнительные сведения'],
                ['number' => '', 'key' => 'appendices', 'label' => 'Приложения'],
            ]],
            default => ['title' => 'АКТ освидетельствования участков сетей инженерно-технического обеспечения', 'fields' => [
                ['number' => '', 'key' => 'network_type', 'label' => 'Вид сети инженерно-технического обеспечения'],
                ['number' => '1', 'key' => 'network_section_boundaries', 'label' => 'К освидетельствованию предъявлены участки сети инженерно-технического обеспечения'],
                ['number' => '2', 'key' => 'project_documentation', 'label' => 'Участки сетей выполнены по проектной документации'],
                ['number' => '3', 'key' => 'technical_conditions', 'label' => 'Технические условия подключения предоставлены'],
                ['number' => '4', 'key' => 'hidden_work_acts', 'label' => 'Освидетельствованы скрытые работы, влияющие на безопасность участков сетей'],
                ['number' => '5', 'key' => 'materials_quality_documents', 'label' => 'При выполнении участков сетей применены материалы и документы о качестве'],
                ['number' => '6а', 'key' => 'compliance_documents', 'label' => 'Исполнительные геодезические схемы положения сетей'],
                ['number' => '6б', 'key' => 'inspection_results', 'label' => 'Результаты экспертиз, обследований, лабораторных и иных испытаний'],
                ['number' => '6в', 'key' => 'network_technical_conditions', 'label' => 'Технические условия в составе документов соответствия'],
                ['number' => '7', 'key' => 'tests', 'label' => 'Проведены необходимые испытания и опробования'],
                ['number' => '8', 'key' => 'started_at', 'label' => 'Дата начала работ'],
                ['number' => '8', 'key' => 'finished_at', 'label' => 'Дата окончания работ'],
                ['number' => '9', 'key' => 'normative_basis', 'label' => 'Участки сетей выполнены в соответствии с техническими условиями, регламентами и проектной документацией'],
                ['number' => '', 'key' => 'additional_information', 'label' => 'Дополнительные сведения'],
                ['number' => '', 'key' => 'appendices', 'label' => 'Приложения'],
            ]],
        };
    }

    private function withReferences(array $profile, array $relations): array
    {
        $groups = [];
        foreach ($relations as $relation) {
            if (! is_array($relation)) continue;
            $reference = $relation['document_snapshot'] ?? $relation['domain_snapshot'] ?? [];
            $title = $reference['title'] ?? $reference['name'] ?? $relation['label'] ?? null;
            if (! is_string($title) || trim($title) === '') continue;
            $line = $title;
            if (! empty($reference['number'])) $line .= ' № '.$reference['number'];
            if (! empty($reference['document_date'])) $line .= ' от '.substr((string) $reference['document_date'], 0, 10);
            $field = match ($relation['relation_type'] ?? '') {
                'quality_documents' => 'materials_quality_documents',
                'executive_schemes', 'geodetic_schemes', 'network_schemes' => 'compliance_documents',
                'hidden_work_acts' => 'hidden_work_acts',
                'inspection_results' => 'inspection_results',
                'tests' => 'tests',
                default => 'appendices',
            };
            $groups[$field][] = $line;
            if ($field !== 'appendices') $groups['appendices'][] = $line;
        }
        foreach ($groups as $key => $lines) {
            if (empty($profile[$key]) || is_array($profile[$key])) $profile[$key] = implode("\n", array_unique($lines));
        }
        return $profile;
    }
}
