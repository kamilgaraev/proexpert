<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

final readonly class AssistantProjectMaterialRequestReader extends AssistantProjectSiteRequestReader
{
    public const TYPE = 'live_project_material_request_queue_projection';

    public static function matches(string $query): bool
    {
        $query = self::withoutTypeExclusions($query);
        return parent::matches($query) && self::hasIntent($query)
            && preg_match('/техник|оборудован|персонал|запрос[а-яё]*\s+информац|сообщен[а-яё]*\s+о\s+проблем|(?:и|или)\s+(?:на\s+)?информац/iu', $query) !== 1;
    }

    public static function hasIntent(string $query): bool { return preg_match('/заявк[а-яё]*\s+(?:на|по)\s+материал/iu', $query) === 1; }

    public static function intentQuery(string $query): string
    {
        if (! self::matches($query)) { return $query; }
        return self::withoutTypeExclusions($query);
    }

    private static function withoutTypeExclusions(string $query): string
    {
        $subject = '(?:заявк[а-яё]*\s+на\s+)?техник[уи]';
        $verb = '(?:включ(?:ай(?:те)?|ать)|учитыва(?:й(?:те)?|ть))';
        return preg_replace('/'.$subject.'\s+(?:сюда\s+)?не\s+'.$verb.'(?!\pL)|не\s+'.$verb.'\s+(?:сюда\s+)?'.$subject.'(?!\pL)/iu', '', $query) ?? $query;
    }

    protected function requestTypes(): ?array { return ['material_request', 'material']; }
}
