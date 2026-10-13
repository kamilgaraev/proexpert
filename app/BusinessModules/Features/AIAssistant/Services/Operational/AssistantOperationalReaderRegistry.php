<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

final class AssistantOperationalReaderRegistry
{
    private const TOPICS = ['/журнал/iu', '/безопасност|охран[а-яё]*\s+труд|происшеств|нарушен/iu',
        '/rfi|вопрос[а-яё]*\s+проектировщик|изменен[а-яё]*.*(?:согласован|решени|влияни)/iu',
        '/проектн[а-яё]*\s+комплект|комплект[а-яё]*.*(?:выпуск|выпущ|пир)|нормконтрол/iu',
        '/коммерческ[а-яё]*\s+предложен/iu', '/исполнительн[а-яё]*\s+(?:документ|комплект)|комплект[а-яё]*\s+ид\b/iu',
        '/производствен[а-яё]*\s+наряд|наряд[а-яё]*.*(?:принят|при[её]мк|закрыти|представлен)/iu', '/crm|сделк/iu'];
    public const TOOLS = [
        'get_project_journal_readiness' => AssistantProjectJournalReader::class,
        'get_project_safety_summary' => AssistantProjectSafetyReader::class,
        'get_project_change_readiness' => AssistantProjectChangeReader::class,
        'get_project_design_readiness' => AssistantProjectDesignReader::class,
        'get_project_proposal_readiness' => AssistantProjectProposalReader::class,
        'get_project_executive_readiness' => AssistantProjectExecutiveReader::class,
        'get_project_production_readiness' => AssistantProjectProductionReader::class,
        'get_project_crm_readiness' => AssistantProjectCrmReader::class,
    ];

    public static function forReference(string $type): ?string
    {
        foreach (self::TOOLS as $class) { if ($class::TYPE === $type) { return $class; } }
        return null;
    }

    public static function intent(string $query): ?array
    {
        if (preg_match('/что\s+написано|выдержк|цитат|содержани|текст[а-яё]*\s+(?:документ|предложен)|объясни[а-яё]*\s+правил/iu', $query) === 1) { return null; }
        if (preg_match('/расход|потрач|прибыл|выручк|рентабел|марж|бюджет|затрат|сч[её]т|оплач|оплат|плат[её]ж|смет|договор|поставщик|поставк|закуп|снабжен|склад|табел|техник|при[её]мк/iu', $query) === 1) { return null; }
        $topics = 0;
        foreach (self::TOPICS as $pattern) {
            if (preg_match($pattern, $query) === 1) { $topics++; }
        }
        if ($topics !== 1) { return null; }
        $matches = [];
        foreach (self::TOOLS as $name => $class) { if ($class::matches($query)) { $matches[] = ['name' => $name, 'reader' => $class]; } }
        if (preg_match('/стоим/iu', $query) === 1 && ($matches[0]['name'] ?? null) !== 'get_project_change_readiness') { return null; }
        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function hasIntent(string $query): bool
    {
        foreach (self::TOPICS as $pattern) { if (preg_match($pattern, $query) === 1) { return true; } }
        return false;
    }
}
