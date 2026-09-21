<?php

declare(strict_types=1);

use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRenderService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = dirname(__DIR__, 3).'/tmp/pdfs/executive-print';
if (! is_dir($directory)) mkdir($directory, 0777, true);
$profiles = [
    'hidden_work_act' => ['presented_works' => 'Армирование фундаментной плиты в осях А–Г / 1–6, отметка −3,200', 'next_works_permission' => 'Бетонирование плиты после подписания акта'],
    'axis_layout_act' => ['axis_layout_text' => 'Оси А–Г / 1–6', 'axis_fixing_text' => 'Выносные створные знаки, металлические марки'],
    'geodetic_base_acceptance_act' => ['geodetic_base_description' => 'Реперы Р1, Р2 и опорные пункты Г1–Г4; ведомость координат прилагается.', 'base_acceptance_documents' => 'Чертёж ГРО-01, ведомость координат ВК-03'],
    'responsible_structure_act' => ['presented_structures' => 'Фундаментная плита корпуса А', 'structure_location' => 'Оси А–Г / 1–6', 'acceptance_decision' => 'next_works_allowed', 'next_works_permission' => 'Монтаж стен подвала'],
    'engineering_network_section_act' => ['network_type' => 'Наружное водоснабжение', 'network_section_boundaries' => 'Колодцы В1–В4', 'technical_conditions' => 'ТУ № 27 от 20.01.2026', 'tests' => 'Протокол гидравлических испытаний № 19'],
];
foreach ($profiles as $type => $profile) {
    $profile += ['act_number' => 'ИД-2026/017', 'started_at' => '2026-09-01', 'finished_at' => '2026-09-03', 'project_documentation' => 'МОСТ-2026-КЖ, листы 7–12, изменение 2', 'normative_basis' => 'Основания и результаты проверяются ответственными представителями'];
    $profile['appendices'] = implode("\n", array_map(static fn ($i) => "Приложение {$i}. ".str_repeat('Исполнительная схема и протокол контроля с полными реквизитами. ', $type === 'hidden_work_act' ? 7 : 1), range(1, $type === 'hidden_work_act' ? 22 : 3)));
    $snapshot = [
        'document_type' => $type, 'profile_data' => $profile, 'source_version_id' => 17, 'source_version_number' => '1.0',
        'project' => ['name' => 'Многофункциональный производственно-складской комплекс с административно-бытовым корпусом и наружными инженерными сетями', 'address' => 'Московская область, строительная площадка, участок 17'],
        'document' => ['document_date' => '2026-09-04', 'copies_count' => 3,
            'participants' => [['role' => 'construction', 'role_label' => 'Лицо, осуществляющее строительство', 'name' => 'ООО «Строительная организация с длинным наименованием для проверки переноса реквизитов»', 'inn' => '7700000000', 'ogrn' => '1007700000000', 'address' => 'Москва, примерный адрес объекта', 'sro_name' => 'Ассоциация строителей']],
            'signatories' => [['role' => 'construction_representative', 'position' => 'Главный инженер проекта', 'name' => 'Иванов Иван Иванович', 'organization' => 'ООО «Строительная организация»', 'authority_document' => 'Приказ № 173/26 от 01.08.2026, доверенность № 22 от 15.08.2026', 'nrs_number' => 'С-000000']]],
        'relations' => [['relation_type' => 'quality_documents', 'document_snapshot' => ['title' => 'Паспорт бетона', 'number' => 'П-017', 'document_date' => '2026-09-02', 'version_number' => '1'], 'target_version' => ['version_id' => 13]]],
    ];
    $pdf = $app->make(ExecutiveDocumentRenderService::class)->renderSnapshot($snapshot, '344-369-v1');
    file_put_contents($directory.'/'.$type.'.pdf', $pdf);
    echo $type.': '.strlen($pdf)." bytes\n";
}
