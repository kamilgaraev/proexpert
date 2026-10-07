<?php

declare(strict_types=1);

namespace App\Services\Legal;

use Illuminate\Validation\ValidationException;

final class LegalDocumentService
{
    public function bundle(): array
    {
        return json_decode((string) file_get_contents(resource_path('legal/2026-10-07.json')), true, 512, JSON_THROW_ON_ERROR);
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
            'privacy_ready' => true,
            'commercial_ready' => true,
            'analytics_ready' => true,
            'provider' => config('legal.provider', []),
            'subprocessors' => config('legal.subprocessors', []),
            'documents' => $documents,
        ];
    }

    public function assertAccepted(array $input, array $keys): void
    {
        foreach ($keys as $key) {
            $submitted = $input['legal_documents'][$key] ?? null;
            if (! is_string($submitted) || ! hash_equals($this->hash($key), $submitted)) {
                throw ValidationException::withMessages(['legal_documents' => trans_message('legal.stale')]);
            }
        }
    }
}
