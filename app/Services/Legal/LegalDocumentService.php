<?php

declare(strict_types=1);

namespace App\Services\Legal;

use Illuminate\Validation\ValidationException;

final class LegalDocumentService
{
    public function bundle(): array
    {
        return json_decode((string) file_get_contents(resource_path('legal/2026-10-06.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function privacyReady(): bool
    {
        $provider = config('legal.provider', []);
        $processors = config('legal.subprocessors', []);
        if (! config('legal.reviewed') || ! filter_var($provider['email'] ?? '', FILTER_VALIDATE_EMAIL)
            || trim((string) ($provider['name'] ?? '')) === '' || trim((string) ($provider['address'] ?? '')) === ''
            || ! is_array($processors) || $processors === []) {
            return false;
        }
        foreach ($processors as $processor) {
            foreach (['name', 'address', 'country', 'purpose', 'data', 'role'] as $field) {
                if (! is_array($processor) || trim((string) ($processor[$field] ?? '')) === '') {
                    return false;
                }
            }
        }

        return $this->bundle()['version'] === config('legal.version');
    }

    public function commercialReady(): bool
    {
        if (! $this->privacyReady() || ! config('legal.commercial_enabled')) {
            return false;
        }
        $provider = config('legal.provider', []);
        foreach (['status', 'inn', 'bank_details', 'tax_status'] as $field) {
            if (trim((string) ($provider[$field] ?? '')) === '') {
                return false;
            }
        }
        if (in_array($provider['status'], ['ИП', 'Юридическое лицо'], true)
            && trim((string) ($provider['registration_number'] ?? '')) === '') {
            return false;
        }

        return true;
    }

    public function snapshot(string $key): array
    {
        $bundle = $this->bundle();
        if (! isset($bundle['documents'][$key])) {
            throw ValidationException::withMessages(['legal_documents' => trans_message('legal.stale')]);
        }

        $document = $bundle['documents'][$key];
        $replacements = [];
        foreach (config('legal.provider', []) as $field => $value) {
            $replacements['{{'.$field.'}}'] = trim((string) $value) ?: '__________';
        }
        foreach ($document['sections'] as &$section) {
            $section['paragraphs'] = array_map(static fn (string $text): string => strtr($text, $replacements), $section['paragraphs']);
        }
        unset($section);

        return [
            'version' => $bundle['version'],
            'document' => $document,
            'provider' => config('legal.provider', []),
            'subprocessors' => config('legal.subprocessors', []),
        ];
    }

    public function hash(string $key): string
    {
        return hash('sha256', json_encode($this->snapshot($key), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function manifest(): array
    {
        $documents = [];
        foreach ($this->bundle()['documents'] as $key => $document) {
            $documents[$key] = ['path' => $document['path'], 'sha256' => $this->hash($key)];
        }

        return [
            'version' => $this->bundle()['version'],
            'content_sha256' => hash_file('sha256', resource_path('legal/2026-10-06.json')),
            'privacy_ready' => $this->privacyReady(),
            'commercial_ready' => $this->commercialReady(),
            'analytics_ready' => $this->privacyReady() && (bool) config('legal.analytics_reviewed'),
            'provider' => config('legal.provider', []),
            'subprocessors' => config('legal.subprocessors', []),
            'documents' => $documents,
        ];
    }

    public function assertAccepted(array $input, array $keys, bool $commercial = true): void
    {
        if (! ($commercial ? $this->commercialReady() : $this->privacyReady())) {
            throw ValidationException::withMessages(['legal_documents' => trans_message('legal.unavailable')]);
        }
        foreach ($keys as $key) {
            $submitted = $input['legal_documents'][$key] ?? null;
            if (! is_string($submitted) || ! hash_equals($this->hash($key), $submitted)) {
                throw ValidationException::withMessages(['legal_documents' => trans_message('legal.stale')]);
            }
        }
    }
}
