<?php

declare(strict_types=1);

namespace App\Services\Contract;

use Illuminate\Support\Facades\DB;

final class ContractDocumentProfiles
{
    public static function all(): array
    {
        $configured = config('legal-document-profiles');
        $profiles = is_array($configured) ? $configured : require __DIR__.'/../../../config/legal-document-profiles.php';
        return array_filter($profiles, static fn (array $profile): bool => ($profile['category'] ?? null) === 'contract');
    }

    public static function fromVersion(?int $versionId, array $parties): string
    {
        $content = $versionId === null ? null : DB::table('contract_library_versions')->where('id', $versionId)->value('content');
        $template = is_string($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
        return self::fromContent($template, $parties);
    }

    public static function fromContent(array $content, array $parties = []): string
    {
        $code = $content['contract_profile_code'] ?? null;
        return is_string($code) && isset(self::all()[$code]) ? $code : (in_array('subcontractor', array_column($parties, 'role'), true) ? 'contract.subcontract' : 'contract.work');
    }
}
