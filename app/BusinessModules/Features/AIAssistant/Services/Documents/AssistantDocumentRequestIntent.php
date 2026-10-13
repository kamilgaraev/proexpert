<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

final readonly class AssistantDocumentRequestIntent
{
    public static function readyListArguments(string $query): ?array
    {
        if (! self::metadataOnly($query) || preg_match('/готов[а-яё]*\s+текст/iu', $query) !== 1) { return null; }
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($query), $words);
        $allowed = explode(' ', 'какие документов документы файлы файлов с готовым текстом текст доступны доступные покажи покажите имена названия и идентификаторы пожалуйста');
        if ($words[0] === [] || array_diff($words[0], $allowed) !== []) { return null; }
        return ['query' => $query, 'project_id' => null, 'source_types' => ['file_document'], 'limit' => 20,
            'read_mode' => 'metadata', 'filename_query' => null, 'document_status' => 'ready', 'metadata_offset' => 0];
    }

    public static function metadataOnly(string $query): bool
    {
        if (AssistantDocumentReadinessReader::matches($query)) { return true; }
        if (preg_match('/документ|файл/iu', $query) !== 1 || preg_match('/список|названи|имена|идентификатор|готов[а-яё]*\s+текст/iu', $query) !== 1) { return false; }
        $remaining = preg_replace('/без\s+(?:пересказ[а-яё]*(?:\s+содержим[а-яё]*)?|содержим[а-яё]*|текст[а-яё]*)/iu', '', $query);
        $remaining = preg_replace('/(?:с\s+)?готов[а-яё]*\s+текст[а-яё]*/iu', '', $remaining ?? $query);
        return preg_match('/перескаж|пересказ|содержим|цитат|прочитай|текст|выдержк|описани|резюме|что\s+(?:там|в\s+них|написан)|услови|требован|пункт/iu', $remaining ?? $query) !== 1;
    }
}
