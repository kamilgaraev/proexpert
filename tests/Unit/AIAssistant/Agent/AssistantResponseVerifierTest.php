<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use PHPUnit\Framework\TestCase;

final class AssistantResponseVerifierTest extends TestCase
{
    public function test_replaces_fake_report_completion_without_artifact(): void
    {
        $verifier = new AssistantResponseVerifier;

        foreach ([
            'Готово, отчет сформирован.',
            'Отчет создан.',
            'Файл отчета доступен.',
            'Отчет подготовлен.',
        ] as $claim) {
            $answer = $verifier->verify($claim, [
                'task_id' => 'report.project_timelines',
                'artifacts' => [],
            ]);

            $this->assertSame('Не удалось сформировать файл отчета по текущему запросу.', $answer);
        }
    }

    public function test_allows_report_completion_with_artifact(): void
    {
        $verifier = new AssistantResponseVerifier;
        $answer = 'Готово, отчёт сформирован. [Скачать](https://storage.example.test/report.pdf)';

        $this->assertSame($answer, $verifier->verify($answer, [
            'task_id' => 'report.project_timelines',
            'artifacts' => [
                [
                    'url' => 'https://storage.example.test/report.pdf',
                    'storage_disk' => 's3',
                    'storage_path' => 'org-15/reports/report.pdf',
                ],
            ],
        ]));
    }

    public function test_strips_untrusted_markdown_link_and_keeps_trusted_link(): void
    {
        $verifier = new AssistantResponseVerifier;
        $trustedUrl = 'https://storage.example.test/report.pdf';

        $answer = $verifier->verify(
            "Файл: [поддельный](https://example.test/fake.pdf) и [отчет]({$trustedUrl})",
            [
                'task_id' => 'report.project_timelines',
                'artifacts' => [
                    [
                        'url' => $trustedUrl,
                        'storage_disk' => 's3',
                        'storage_path' => 'org-15/reports/report.pdf',
                    ],
                ],
            ]
        );

        $this->assertSame("Файл: поддельный и [отчет]({$trustedUrl})", $answer);
    }

    public function test_strips_untrusted_raw_and_html_links(): void
    {
        $verifier = new AssistantResponseVerifier;
        $trustedUrl = 'https://storage.example.test/report.pdf';

        $answer = $verifier->verify(
            "Файл: https://example.test/fake.pdf <https://example.test/fake-2.pdf> <a href=\"https://example.test/fake-3.pdf\">поддельный</a> {$trustedUrl}",
            [
                'task_id' => 'report.project_timelines',
                'artifacts' => [
                    [
                        'url' => $trustedUrl,
                        'storage_disk' => 's3',
                        'storage_path' => 'org-15/reports/report.pdf',
                    ],
                ],
            ]
        );

        $this->assertSame("Файл: поддельный {$trustedUrl}", $answer);
    }
}
