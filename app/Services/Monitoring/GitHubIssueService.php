<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubIssueService
{
    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->repository() !== '';
    }

    public function createFromIncident(array $incident): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('GitHub issue integration is not configured.');
        }

        $response = Http::baseUrl('https://api.github.com')
            ->withToken($this->token())
            ->acceptJson()
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(20)
            ->post(sprintf('/repos/%s/issues', $this->repository()), [
                'title' => $this->buildTitle($incident),
                'body' => $this->buildBody($incident),
                'labels' => config('glitchtip.github.labels', []),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Unable to create GitHub issue from GlitchTip incident.');
        }

        return [
            'number' => $response->json('number'),
            'url' => $response->json('html_url'),
            'title' => $response->json('title'),
        ];
    }

    private function buildTitle(array $incident): string
    {
        $prefix = sprintf('[GlitchTip][%s]', strtoupper((string) ($incident['environment'] ?? 'unknown')));
        $module = $incident['module'] ? sprintf('[%s]', $incident['module']) : '';
        $title = trim((string) ($incident['title'] ?? 'Unknown incident'));

        return trim(sprintf('%s%s %s', $prefix, $module, $title));
    }

    private function buildBody(array $incident): string
    {
        $lines = [
            '# Инцидент из GlitchTip',
            '',
            sprintf('- Issue ID: `%s`', $incident['issue_id'] ?? 'unknown'),
            sprintf('- Event ID: `%s`', $incident['event_id'] ?? 'unknown'),
            sprintf('- Project: `%s`', $incident['project'] ?? 'unknown'),
            sprintf('- Environment: `%s`', $incident['environment'] ?? 'unknown'),
            sprintf('- Release: `%s`', $incident['release'] ?? 'unknown'),
            sprintf('- Level: `%s`', $incident['level'] ?? 'unknown'),
            sprintf('- Module: `%s`', $incident['module'] ?? 'unknown'),
            sprintf('- Interface: `%s`', $incident['interface'] ?? 'unknown'),
            sprintf('- Route: `%s`', $incident['route_name'] ?? 'unknown'),
            sprintf('- Method: `%s`', $incident['method'] ?? 'unknown'),
            sprintf('- Path: `%s`', $incident['path'] ?? 'unknown'),
            sprintf('- User ID: `%s`', $incident['user_id'] ?? 'unknown'),
            sprintf('- Organization ID: `%s`', $incident['organization_id'] ?? 'unknown'),
            sprintf('- Correlation ID: `%s`', $incident['correlation_id'] ?? 'unknown'),
            '',
            '## Сообщение',
            '',
            (string) ($incident['message'] ?? $incident['title'] ?? 'No message'),
            '',
            '## Top Frames',
            '',
        ];

        foreach (array_slice((array) ($incident['top_frames'] ?? []), 0, 5) as $frame) {
            $lines[] = sprintf(
                '- `%s:%s` `%s`',
                Arr::get($frame, 'file', 'unknown'),
                Arr::get($frame, 'line', '?'),
                Arr::get($frame, 'function', 'unknown')
            );
        }

        $webUrl = Arr::get($incident, 'raw.web_url');
        if (is_string($webUrl) && $webUrl !== '') {
            $lines[] = '';
            $lines[] = '## Ссылка';
            $lines[] = '';
            $lines[] = $webUrl;
        }

        return implode("\n", $lines);
    }

    private function token(): string
    {
        return (string) config('glitchtip.github.token', '');
    }

    private function repository(): string
    {
        return (string) config('glitchtip.github.repository', '');
    }
}
