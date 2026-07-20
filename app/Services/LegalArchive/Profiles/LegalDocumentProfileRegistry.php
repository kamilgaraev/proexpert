<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Profiles;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentTypeProfile;
use Closure;
use Illuminate\Container\Container;
use InvalidArgumentException;

use function trans_message;

final class LegalDocumentProfileRegistry
{
    /** @var Closure(int, string): ?array<string, mixed> */
    private Closure $organizationProfileLoader;

    /** @var array<string, array<string, mixed>> */
    private array $standardProfiles;

    /**
     * @param  null|callable(int, string): ?array<string, mixed>  $organizationProfileLoader
     * @param  null|array<string, array<string, mixed>>  $standardProfiles
     */
    public function __construct(?callable $organizationProfileLoader = null, ?array $standardProfiles = null)
    {
        $this->organizationProfileLoader = $organizationProfileLoader !== null
            ? Closure::fromCallable($organizationProfileLoader)
            : static function (int $organizationId, string $code): ?array {
                $profile = LegalArchiveDocumentTypeProfile::query()
                    ->forOrganization($organizationId)
                    ->active()
                    ->where('code', $code)
                    ->first();

                return $profile?->toArray();
            };

        $configuredProfiles = $standardProfiles;
        if ($configuredProfiles === null) {
            $container = Container::getInstance();
            $configuredProfiles = $container->bound('config')
                ? config('legal-document-profiles', [])
                : require dirname(__DIR__, 4).'/config/legal-document-profiles.php';
            if (! is_array($configuredProfiles) || $configuredProfiles === []) {
                $configuredProfiles = require dirname(__DIR__, 4).'/config/legal-document-profiles.php';
            }
        }
        $this->standardProfiles = is_array($configuredProfiles) ? $configuredProfiles : [];
    }

    public function find(int $organizationId, string $code): LegalDocumentProfile
    {
        if ($organizationId <= 0 || trim($code) === '') {
            throw $this->notFound();
        }

        if (isset($this->standardProfiles[$code])) {
            return $this->fromStandardProfile($code, $this->standardProfiles[$code]);
        }

        $custom = ($this->organizationProfileLoader)($organizationId, $code);

        if (
            $custom === null
            || (int) ($custom['organization_id'] ?? 0) !== $organizationId
            || (string) ($custom['code'] ?? '') !== $code
            || ($custom['is_active'] ?? false) !== true
        ) {
            throw $this->notFound();
        }

        $baseCode = (string) ($custom['base_code'] ?? '');
        $base = $this->standardProfiles[$baseCode] ?? null;

        if (! is_array($base)) {
            throw new InvalidArgumentException(trans_message('legal_archive.profiles.base_not_found'));
        }

        $baseProfile = $this->fromStandardProfile($baseCode, $base);
        $customSchema = $this->arrayValue($custom, 'schema');

        if (array_intersect_key($customSchema, $baseProfile->schema) !== []) {
            throw new InvalidArgumentException(trans_message('legal_archive.profiles.base_field_override_forbidden'));
        }

        return new LegalDocumentProfile(
            code: $code,
            baseCode: $baseCode,
            label: (string) ($custom['name'] ?? $baseProfile->label),
            category: $baseProfile->category,
            schema: [...$baseProfile->schema, ...$customSchema],
            requiredFileRoles: $this->uniqueStrings([
                ...$baseProfile->requiredFileRoles,
                ...$this->stringList($custom, 'required_file_roles'),
            ]),
            requiredFields: $this->uniqueStrings([
                ...$baseProfile->requiredFields,
                ...$this->stringList($custom, 'required_fields'),
            ]),
            requiresSignature: array_key_exists('requires_signature', $custom) && $custom['requires_signature'] !== null
                ? (bool) $custom['requires_signature']
                : $baseProfile->requiresSignature,
            workflowTemplateId: $this->nullablePositiveInteger($custom['workflow_template_id'] ?? null),
            retentionPolicy: $this->nullableString($custom['retention_policy'] ?? null)
                ?? $baseProfile->retentionPolicy,
            confidentialityLevel: $this->nullableString($custom['confidentiality_level'] ?? null)
                ?? $baseProfile->confidentialityLevel,
            isActive: true,
            lockVersion: max(0, (int) ($custom['lock_version'] ?? 0)),
            allowedSignatureKinds: array_key_exists('allowed_signature_kinds', $custom) && $custom['allowed_signature_kinds'] !== null
                ? $this->signatureKinds($custom, 'allowed_signature_kinds')
                : $baseProfile->allowedSignatureKinds,
            requiredSignatureKinds: array_key_exists('required_signature_kinds', $custom) && $custom['required_signature_kinds'] !== null
                ? $this->signatureKinds($custom, 'required_signature_kinds')
                : $baseProfile->requiredSignatureKinds,
            allowedSignatureFormats: array_key_exists('allowed_signature_formats', $custom) && $custom['allowed_signature_formats'] !== null
                ? $this->signatureFormats($custom, 'allowed_signature_formats')
                : $baseProfile->allowedSignatureFormats,
        );
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, LegalDocumentProfile>
     */
    public function findMany(int $organizationId, array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
        if ($organizationId <= 0 || $codes === []) {
            return [];
        }
        $customCodes = array_values(array_filter(
            $codes,
            fn (string $code): bool => ! isset($this->standardProfiles[$code]),
        ));
        $custom = $customCodes === []
            ? collect()
            : LegalArchiveDocumentTypeProfile::query()->forOrganization($organizationId)->active()
                ->whereIn('code', $customCodes)->get()->keyBy('code');
        $bulkRegistry = new self(
            static fn (int $requestedOrganizationId, string $code): ?array => $requestedOrganizationId === $organizationId
                ? $custom->get($code)?->toArray()
                : null,
            $this->standardProfiles,
        );
        $resolved = [];
        foreach ($codes as $code) {
            try {
                $resolved[$code] = $bulkRegistry->find($organizationId, $code);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $resolved;
    }

    /** @param array<string, mixed> $definition */
    private function fromStandardProfile(string $code, array $definition): LegalDocumentProfile
    {
        return new LegalDocumentProfile(
            code: $code,
            baseCode: $code,
            label: (string) ($definition['label'] ?? $code),
            category: (string) ($definition['category'] ?? 'other'),
            schema: $this->arrayValue($definition, 'schema'),
            requiredFileRoles: $this->stringList($definition, 'required_file_roles'),
            requiredFields: $this->stringList($definition, 'required_fields'),
            requiresSignature: (bool) ($definition['requires_signature'] ?? false),
            workflowTemplateId: $this->nullablePositiveInteger($definition['workflow_template_id'] ?? null),
            retentionPolicy: $this->nullableString($definition['retention_policy'] ?? null),
            confidentialityLevel: $this->nullableString($definition['confidentiality_level'] ?? null) ?? 'internal',
            isActive: true,
            lockVersion: 0,
            allowedSignatureKinds: $this->signatureKinds($definition, 'allowed_signature_kinds', ['paper_original', 'external_electronic', 'provider_electronic']),
            requiredSignatureKinds: $this->signatureKinds($definition, 'required_signature_kinds'),
            allowedSignatureFormats: $this->signatureFormats($definition, 'allowed_signature_formats', ['detached_cades', 'embedded_cades', 'xml_dsig']),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function arrayValue(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        return is_array($value) ? $this->uniqueStrings($value) : [];
    }

    /** @param array<string, mixed> $values @param list<string> $default @return list<string> */
    private function signatureKinds(array $values, string $key, array $default = []): array
    {
        $kinds = array_key_exists($key, $values) ? $this->stringList($values, $key) : $default;
        $allowed = ['paper_original', 'external_electronic', 'provider_electronic'];
        if (array_diff($kinds, $allowed) !== []) {
            throw new InvalidArgumentException(trans_message('legal_archive.profiles.schema_invalid'));
        }

        return $kinds;
    }

    /** @param array<string, mixed> $values @param list<string> $default @return list<string> */
    private function signatureFormats(array $values, string $key, array $default = []): array
    {
        $formats = array_key_exists($key, $values) ? $this->stringList($values, $key) : $default;
        if (array_diff($formats, ['detached_cades', 'embedded_cades', 'xml_dsig']) !== []) {
            throw new InvalidArgumentException(trans_message('legal_archive.profiles.schema_invalid'));
        }

        return $formats;
    }

    /** @param array<mixed> $values @return list<string> */
    private function uniqueStrings(array $values): array
    {
        $strings = array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '');

        return array_values(array_unique($strings));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        return $normalized !== false && $normalized > 0 ? $normalized : null;
    }

    private function notFound(): InvalidArgumentException
    {
        return new InvalidArgumentException(trans_message('legal_archive.profiles.not_found'));
    }
}
