<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryCoverage;
use Brick\Math\BigDecimal;

/** Lexical mentions require an explicit target and confirmation; they are not measurements. */
final class DescriptionNumericProposals
{
    public static function sourceFacts(ProjectModelSnapshot $snapshot, string $description): array
    {
        return array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'evaluation_answer' && is_array($fact->value)
            && ($fact->value['kind'] ?? null) === 'initial_description'
            && ($fact->value['description'] ?? null) === trim($description)));
    }

    public static function fromSnapshot(ProjectModelSnapshot $snapshot, string $description, array $decisions): array
    {
        $sources = self::sourceFacts($snapshot, $description);
        if (count($sources) !== 1 || ! GeometryCoverage::isUserConfirmed($sources[0], $snapshot, $decisions)) {
            return [];
        }
        $source = $sources[0];
        $hash = hash('sha256', CanonicalPipelineJson::encode($source->value));
        $proofs = array_column($snapshot->evidence, null, 'id');
        foreach ($source->evidenceIds as $id) {
            $proof = $proofs[$id];
            if ($proof->producerName !== EvidenceProducer::UserInputNormalizer->value
                || $proof->nativeReference !== 'input_payload.description'
                || $proof->sourceReference !== 'input:'.$source->sessionId
                || $proof->choiceHash === null || ! hash_equals($hash, $proof->choiceHash)) {
                return [];
            }
        }

        return self::parse($description, $source->id, $source->version, $hash);
    }

    public static function parse(string $description, string $factId, int $factVersion, string $valueHash): array
    {
        $description = trim($description);
        if (mb_strlen($description) > 10000) {
            return [];
        }
        preg_match_all('/(?<![\p{L}\p{N}])(?<parameter>площадь|высота|длина|ширина|глубина|толщина)\s*(?:[:=]\s*)?(?<value>[0-9]{1,12}(?:[.,][0-9]{1,4})?)\s*(?<unit>м²|м2|m2|см|cm|мм|mm|м|m)(?![\p{L}\p{N}²³^])/iu', $description, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $parameters = ['площадь' => 'area', 'высота' => 'height', 'длина' => 'length', 'ширина' => 'width', 'глубина' => 'depth', 'толщина' => 'thickness'];
        $units = ['м²' => 'm2', 'м2' => 'm2', 'm2' => 'm2', 'м' => 'm', 'm' => 'm', 'см' => 'cm', 'cm' => 'cm', 'мм' => 'mm', 'mm' => 'mm'];
        $result = [];
        foreach ($matches as $match) {
            if (strlen($match[0][0]) > 120) {
                continue;
            }
            $parameter = $parameters[mb_strtolower($match['parameter'][0])];
            $unit = $units[mb_strtolower($match['unit'][0])];
            $number = BigDecimal::of(str_replace(',', '.', $match['value'][0]));
            if (! $number->isGreaterThan(0) || ($parameter === 'area') !== ($unit === 'm2')) {
                continue;
            }
            $value = (string) $number->strippedOfTrailingZeros();
            $reference = [$factId, $factVersion, $valueHash, $match[0][1], $match[0][0], $parameter, $value, $unit];
            $result[] = ['proposal_id' => hash('sha256', CanonicalPipelineJson::encode($reference)),
                'source_fact_id' => $factId, 'source_fact_version' => $factVersion, 'source_value_hash' => $valueHash,
                'parameter' => $parameter, 'value' => $value, 'unit' => $unit, 'quote' => $match[0][0], 'status' => 'needs_confirmation'];
            if (count($result) >= 30) {
                break;
            }
        }

        return $result;
    }
}
