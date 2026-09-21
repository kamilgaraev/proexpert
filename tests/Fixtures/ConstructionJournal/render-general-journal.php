<?php

declare(strict_types=1);

use App\Services\ConstructionJournal\GeneralJournalDocumentDefinition;
use App\Services\ConstructionJournal\GeneralJournalDocumentRenderService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = dirname(__DIR__, 3).'/tmp/pdfs/general-journal';
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}
foreach (['empty', 'filled'] as $mode) {
    $snapshot = [
        'template_version' => GeneralJournalDocumentDefinition::TEMPLATE_VERSION,
        'revision' => 1, 'correction_reason' => null,
        'journal' => ['number' => 'ОЖР-2026/017', 'project_name' => 'Производственный корпус с административными помещениями и наружными инженерными сетями',
            'project_address' => 'Московская область, строительная площадка, участок 17', 'start_date' => '01.09.2026', 'end_date' => null],
        'profile' => ['header' => [], 'representatives' => [], 'title_changes' => []],
        'sections' => array_fill_keys(range(1, 6), []),
    ];
    if ($mode === 'filled') {
        foreach (GeneralJournalDocumentDefinition::headerFields() as $key => $label) {
            $snapshot['profile']['header'][$key] = $label.': сведения для проверки переноса и полноты реквизитов';
        }
        foreach (GeneralJournalDocumentDefinition::representativeGroups() as $key => $label) {
            $snapshot['profile']['representatives'][$key] = [[
                'name' => 'Иванов Иван Иванович', 'position' => 'Главный инженер',
                'authority' => 'Приказ № 17 от 01.09.2026', 'nrs_number' => 'С-000000',
            ]];
        }
        $snapshot['profile']['title_changes'][] = ['date' => '10.09.2026', 'change' => 'Замена представителя по приказу № 27', 'representative' => 'Петров Пётр Петрович', 'authority' => 'Доверенность № 3 от 09.09.2026'];
        foreach (GeneralJournalDocumentDefinition::sections() as $number => $section) {
            $row = [];
            foreach ($section['columns'] as $key => $label) {
                $row[$key] = $label.': '.str_repeat('Полные сведения о выполнении и проверке работ на участке в осях А–Г/1–6. ', $key === 'works' ? 35 : 1);
            }
            $snapshot['sections'][$number][] = $row;
        }
    }
    $bytes = $app->make(GeneralJournalDocumentRenderService::class)->renderSnapshot($snapshot);
    file_put_contents($directory.'/'.$mode.'.pdf', $bytes);
    echo $mode.': '.strlen($bytes)." bytes\n";
}
