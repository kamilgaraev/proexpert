<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrQualityReport;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

class OcrQualityAnalyzer
{
    public function analyze(OcrRecognitionResult $recognition): OcrQualityReport
    {
        $text = $recognition->text();
        $pageCount = max(count($recognition->pages), 1);
        $emptyPages = 0;
        $confidences = [];

        foreach ($recognition->pages as $page) {
            if (trim($page->text) === '') {
                $emptyPages++;
            }

            if ($page->confidence !== null) {
                $confidences[] = $page->confidence;
            }
        }

        $baseScore = $confidences !== []
            ? array_sum($confidences) / count($confidences)
            : (trim($text) === '' ? 0.0 : 0.7);

        $coveragePenalty = $emptyPages > 0 ? min(0.4, $emptyPages / $pageCount * 0.35) : 0.0;
        $lengthPenalty = mb_strlen($text) < 20 ? 0.2 : 0.0;
        $score = round(max(0.0, min(1.0, $baseScore - $coveragePenalty - $lengthPenalty)), 2);
        $flags = $this->flags($score, $text, $emptyPages);

        return new OcrQualityReport(
            score: $score,
            level: $this->level($score),
            flags: $flags,
            metrics: [
                'text_length' => mb_strlen($text),
                'page_count' => $pageCount,
                'empty_pages' => $emptyPages,
                'average_confidence' => $confidences !== []
                    ? round(array_sum($confidences) / count($confidences), 4)
                    : null,
            ],
        );
    }

    /**
     * @return array<int, string>
     */
    private function flags(float $score, string $text, int $emptyPages): array
    {
        $flags = [];

        if (trim($text) === '') {
            $flags[] = 'no_text_detected';
        }

        if (mb_strlen($text) > 0 && mb_strlen($text) < 20) {
            $flags[] = 'text_too_short';
        }

        if ($emptyPages > 0) {
            $flags[] = 'empty_pages_detected';
        }

        if ($score < (float) config('estimate-generation.ocr.min_usable_quality_score', 0.6)) {
            $flags[] = 'low_quality';
        }

        return array_values(array_unique($flags));
    }

    private function level(float $score): string
    {
        if ($score >= (float) config('estimate-generation.ocr.min_good_quality_score', 0.8)) {
            return 'good';
        }

        if ($score >= (float) config('estimate-generation.ocr.min_usable_quality_score', 0.6)) {
            return 'acceptable';
        }

        if ($score > 0.0) {
            return 'low';
        }

        return 'unusable';
    }
}
