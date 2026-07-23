<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use function trans_message;

final class LegalArchiveDictionary
{
    public const DOCUMENT_TYPES = [
        'contract',
        'act',
        'commercial_proposal',
        'claim',
        'payment_document',
        'procurement_document',
        'mdm_document',
        'edo_document',
        'other',
    ];

    public const STATUSES = [
        'draft',
        'active',
        'superseded',
        'expired',
        'archived',
    ];

    public const DIRECTIONS = [
        'incoming',
        'outgoing',
        'internal',
    ];

    public const LEGAL_SIGNIFICANCE_STATUSES = [
        'not_confirmed',
        'edo_original',
        'paper_original',
        'copy',
    ];

    public const LINK_TYPES = [
        'project',
        'contract',
        'payment',
        'procurement',
        'act',
        'commercial_proposal',
        'mdm',
        'claim',
        'edo',
        'one_c',
        'other',
    ];

    public const VERSION_STATUSES = [
        'uploaded',
        'reviewed',
        'frozen',
        'signed',
        'superseded',
    ];

    public static function options(string $group): array
    {
        return array_map(
            static fn (string $value): array => [
                'value' => $value,
                'label' => self::label($group, $value),
            ],
            self::values($group)
        );
    }

    public static function values(string $group): array
    {
        return match ($group) {
            'types' => self::DOCUMENT_TYPES,
            'statuses' => self::STATUSES,
            'directions' => self::DIRECTIONS,
            'legal_significance_statuses' => self::LEGAL_SIGNIFICANCE_STATUSES,
            'link_types' => self::LINK_TYPES,
            'version_statuses' => self::VERSION_STATUSES,
            default => [],
        };
    }

    public static function label(string $group, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = "legal_archive.{$group}.{$value}";
        $translated = trans_message($key);

        return $translated === $key ? $value : $translated;
    }
}
