<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final readonly class AssistantReportTopicNormalizer
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function topic(array $input, string $query): string
    {
        $topic = $this->scalar($input['topic'] ?? null) ?? $query;
        $topic = $this->normalize($topic);

        return $topic !== '' ? $topic : 'данным базы знаний';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function title(array $input, string $query): string
    {
        $topic = $this->truncate($this->topic($input, $query), 72);

        if (preg_match('/^(по|о|об|обо|про|для)\s+/iu', $topic) === 1) {
            return $this->truncate('Отчет '.$topic, 90);
        }

        return $this->truncate('Отчет: '.$this->ucfirst($topic), 90);
    }

    private function normalize(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B\"'«».,:;-");

        $patterns = [
            '/\b(?:в\s+формате\s+)?(?:pdf|пдф)\b/iu',
            '/^(?:пожалуйста[, ]*)?(?:сформируй|создай|подготовь|сделай|сгенерируй|выгрузи|нужен|нужна|нужно|покажи|дай|составь)\s+/iu',
            '/^(?:подробный|детальный|краткий|управленческий|аналитический|операционный)\s+/iu',
            '/^(?:rag[-\s]*)?(?:отчет|отчёт|сводку|сводка|анализ|обзор)\s*/iu',
            '/^(?:по\s+)?(?:теме|запросу)\s+/iu',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '', $value) ?? $value;
            $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
            $value = trim($value, " \t\n\r\0\x0B\"'«».,:;-");
        }

        return $value;
    }

    private function scalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $short = rtrim(mb_substr($value, 0, $limit - 3));

        foreach (['.', '!', '?', ';', ':', ','] as $delimiter) {
            $position = mb_strrpos($short, $delimiter);
            if ($position !== false && $position >= 30) {
                $short = rtrim(mb_substr($short, 0, $position));
                break;
            }
        }

        return $short.'...';
    }

    private function ucfirst(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
