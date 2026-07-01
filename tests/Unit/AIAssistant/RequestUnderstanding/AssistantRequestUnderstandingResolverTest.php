<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantRequestUnderstandingResolverTest extends TestCase
{
    public function test_negative_pdf_report_request_is_text_rag_read_only(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'По проекту «Кирпичный дом "Лесной двор"» перечисли 5 фактов из базы знаний. Только текст. Не создавай PDF, файл или отчет.',
            []
        );

        $this->assertSame('search_knowledge', $result->primaryIntent);
        $this->assertSame('text', $result->outputFormat);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('no_pdf'));
        $this->assertTrue($result->hasConstraint('no_file'));
        $this->assertTrue($result->hasConstraint('no_report'));
        $this->assertTrue($result->hasConstraint('text_only'));
        $this->assertTrue($result->hasConstraint('sources_required'));
        $this->assertContains('project', $result->requestedEntities);
        $this->assertGreaterThanOrEqual(0.7, $result->confidence);
        $this->assertNotSame([], $result->evidence);
    }

    public function test_knowledge_facts_without_actions_stays_read_only(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Найди в базе знаний 3-5 фактов по проекту. Не выполняй действий, не создавай отчет, просто перечисли факты.',
            []
        );

        $this->assertSame('search_knowledge', $result->primaryIntent);
        $this->assertSame('text', $result->outputFormat);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('no_actions'));
        $this->assertTrue($result->hasConstraint('no_report'));
        $this->assertTrue($result->hasConstraint('sources_required'));
        $this->assertContains('project', $result->requestedEntities);
    }

    public function test_explicit_pdf_report_request_allows_file_generation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Сформируй PDF-отчет по проекту «Кирпичный дом "Лесной двор"».',
            []
        );

        $this->assertSame('generate_report', $result->primaryIntent);
        $this->assertSame('pdf', $result->outputFormat);
        $this->assertSame('allow_file_generation', $result->actionPolicy);
        $this->assertFalse($result->hasConstraint('no_pdf'));
        $this->assertFalse($result->hasConstraint('no_file'));
        $this->assertContains('project', $result->requestedEntities);
    }

    public function test_human_file_request_allows_file_generation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Сделай мне файл по Лесному двору, чтобы я мог быстро показать руководителю: текущее состояние, деньги, риски и ближайшие шаги.',
            []
        );

        $this->assertSame('generate_report', $result->primaryIntent);
        $this->assertSame('file', $result->outputFormat);
        $this->assertSame('allow_file_generation', $result->actionPolicy);
        $this->assertFalse($result->hasConstraint('no_file'));
        $this->assertFalse($result->hasConstraint('no_report'));
    }

    public function test_project_summary_without_changes_and_files_is_read_only(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Покажи краткую сводку по проекту без изменений и без файлов.',
            []
        );

        $this->assertSame('summarize', $result->primaryIntent);
        $this->assertSame('text', $result->outputFormat);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('no_file'));
        $this->assertTrue($result->hasConstraint('no_actions'));
        $this->assertContains('project', $result->requestedEntities);
    }

    public function test_strict_json_without_actions_and_navigation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Ответь строго JSON без markdown. Без действий и без навигации.',
            []
        );

        $this->assertSame('json', $result->outputFormat);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('json_only'));
        $this->assertTrue($result->hasConstraint('no_actions'));
        $this->assertTrue($result->hasConstraint('no_navigation'));
    }

    public function test_payment_review_does_not_allow_mutation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Покажи, какие платежи требуют согласования, но ничего не утверждай.',
            []
        );

        $this->assertSame('analyze', $result->primaryIntent);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('no_actions'));
        $this->assertContains('payment', $result->requestedEntities);
    }

    public function test_approval_request_requires_confirmation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve('Утверди платеж', []);

        $this->assertSame('approve', $result->primaryIntent);
        $this->assertSame('requires_confirmation', $result->actionPolicy);
        $this->assertContains('payment', $result->requestedEntities);
    }

    public function test_navigation_is_allowed_only_for_explicit_navigation_request(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Открой проект «Кирпичный дом "Лесной двор"».',
            []
        );

        $this->assertSame('navigate', $result->primaryIntent);
        $this->assertSame('allow_navigation', $result->actionPolicy);
        $this->assertContains('project', $result->requestedEntities);
    }

    #[DataProvider('negativeReportProvider')]
    public function test_negative_report_and_file_words_do_not_create_generation_intent(string $message, string $constraint): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve($message, []);

        $this->assertNotSame('generate_report', $result->primaryIntent);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint($constraint));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function negativeReportProvider(): array
    {
        return [
            'negative report' => ['Без отчета расскажи, какие риски по проекту.', 'no_report'],
            'negative file' => ['Не нужен файл, просто напиши текстом.', 'no_file'],
        ];
    }
}
