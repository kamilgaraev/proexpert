<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiWireNotStarted;

/** Encoded files are transport data, not textual tokens; media uses the same admitted ceiling as Vision. */
final class OcrWireReservationTokens
{
    public static function inputUpper(array $messages, int $inputCeiling): int
    {
        if ($inputCeiling < 1 || $inputCeiling > 1000000) {
            throw new AiWireNotStarted('ocr_input_budget_invalid');
        }
        $textBytes = 0;
        foreach ($messages as $message) {
            $textBytes += 64;
            $content = $message['content'] ?? null;
            if (is_string($content)) {
                $textBytes += strlen($content);

                continue;
            }
            if (! is_array($content)) {
                throw new AiWireNotStarted('ocr_input_contract_invalid');
            }
            foreach ($content as $part) {
                if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $textBytes += strlen($part['text']);
                } elseif (! in_array($part['type'] ?? null, ['image_url', 'input_file'], true)) {
                    throw new AiWireNotStarted('ocr_input_contract_invalid');
                }
            }
        }
        if ($textBytes > $inputCeiling) {
            throw new AiWireNotStarted('ocr_input_requires_chunking');
        }

        return $inputCeiling + 1024;
    }
}
