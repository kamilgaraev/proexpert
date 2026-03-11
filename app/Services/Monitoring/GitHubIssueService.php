<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubIssueService
{
    private const GITHUB_API_BASE_URL = 'https://api.github.com';

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->repository() !== '';
    }

    public function createFromIncident(array $incident): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('GitHub issue integration is not configured.');
        }

        $metadata = $this->buildIncidentMetadata($incident);

        $response = $this->githubRequest()
            ->post(sprintf('/repos/%s/issues', $this->repository()), [
                'title' => $metadata['issue_title'],
                'body' => $this->buildIssueBody($incident, $metadata),
                'labels' => config('glitchtip.github.labels', []),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Unable to create GitHub issue from GlitchTip incident.');
        }

        return [
            'number' => $response->json('number'),
            'url' => $response->json('html_url'),
            'title' => $response->json('title'),
            'suggested_branch' => $metadata['suggested_branch'],
            'suggested_commit' => $metadata['suggested_commit'],
            'suggested_pr_title' => $metadata['suggested_pr_title'],
        ];
    }

    public function createPullRequest(array $payload): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('GitHub pull request integration is not configured.');
        }

        $headBranch = trim((string) ($payload['head'] ?? ''));
        if ($headBranch === '') {
            throw new RuntimeException('Pull request head branch is required.');
        }

        $issueNumber = isset($payload['issue_number']) ? (int) $payload['issue_number'] : null;
        $issue = $issueNumber !== null ? $this->fetchIssue($issueNumber) : null;
        $pullRequestPayload = $this->buildPullRequestPayload(
            $headBranch,
            trim((string) ($payload['base'] ?? $this->baseBranch())),
            array_key_exists('draft', $payload) ? (bool) $payload['draft'] : true,
            $issueNumber,
            $issue,
            Arr::get($payload, 'title'),
            Arr::get($payload, 'body')
        );

        $response = $this->githubRequest()
            ->post(sprintf('/repos/%s/pulls', $this->repository()), $pullRequestPayload);

        if ($response->failed()) {
            throw new RuntimeException('Unable to create GitHub pull request.');
        }

        return [
            'number' => $response->json('number'),
            'url' => $response->json('html_url'),
            'title' => $response->json('title'),
            'head' => Arr::get($pullRequestPayload, 'head'),
            'base' => Arr::get($pullRequestPayload, 'base'),
            'draft' => (bool) $response->json('draft', Arr::get($pullRequestPayload, 'draft', true)),
        ];
    }

    public function buildIncidentMetadata(array $incident): array
    {
        $prefix = sprintf('[GlitchTip][%s]', strtoupper((string) ($incident['environment'] ?? 'unknown')));
        $module = !empty($incident['module']) ? sprintf('[%s]', $incident['module']) : '';
        $title = trim((string) ($incident['title'] ?? 'Unknown incident'));
        $issueTitle = trim(sprintf('%s%s %s', $prefix, $module, $title));
        $issueId = (string) ($incident['issue_id'] ?? 'unknown');
        $moduleSlug = $this->slugify((string) ($incident['module'] ?? 'incident'));
        $titleSlug = $this->slugify($title);
        $suggestedBranch = trim(sprintf('codex/fix-incident-%s-%s-%s', $issueId, $moduleSlug, $titleSlug), '-');
        $suggestedBranch = preg_replace('/-+/', '-', $suggestedBranch) ?? $suggestedBranch;

        return [
            'issue_title' => $issueTitle,
            'suggested_branch' => rtrim($suggestedBranch, '-'),
            'suggested_commit' => sprintf('fix: устранен инцидент GlitchTip #%s в модуле %s', $issueId, (string) ($incident['module'] ?? 'unknown')),
            'suggested_pr_title' => sprintf('fix: устранен инцидент GlitchTip #%s', $issueId),
        ];
    }

    public function buildPullRequestPayload(
        string $headBranch,
        string $baseBranch,
        bool $draft = true,
        ?int $issueNumber = null,
        ?array $issue = null,
        mixed $title = null,
        mixed $body = null
    ): array {
        $resolvedTitle = trim((string) ($title ?? ''));
        if ($resolvedTitle === '') {
            $resolvedTitle = $this->resolvePullRequestTitle($issueNumber, $issue);
        }

        $resolvedBody = trim((string) ($body ?? ''));
        if ($resolvedBody === '') {
            $resolvedBody = $this->resolvePullRequestBody($issueNumber, $headBranch, $issue);
        }

        return [
            'title' => $resolvedTitle,
            'body' => $resolvedBody,
            'head' => $headBranch,
            'base' => $baseBranch !== '' ? $baseBranch : $this->baseBranch(),
            'draft' => $draft,
        ];
    }

    private function buildIssueBody(array $incident, array $metadata): string
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
            '## Рекомендуемая ветка',
            '',
            sprintf('`%s`', $metadata['suggested_branch']),
            '',
            '## Рекомендуемый commit',
            '',
            sprintf('`%s`', $metadata['suggested_commit']),
            '',
            '## Рекомендуемый PR title',
            '',
            sprintf('`%s`', $metadata['suggested_pr_title']),
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

        $lines[] = '';
        $lines[] = '## Чеклист фикса';
        $lines[] = '';
        $lines[] = '- [ ] воспроизвести проблему или подтвердить контекст инцидента';
        $lines[] = '- [ ] определить root cause';
        $lines[] = '- [ ] подготовить исправление в рекомендуемой ветке';
        $lines[] = '- [ ] обновить или добавить тесты, если это уместно';
        $lines[] = '- [ ] проверить сценарий после фикса';
        $lines[] = '- [ ] указать в PR ссылку на этот issue и релиз';

        return implode("\n", $lines);
    }

    private function fetchIssue(int $issueNumber): array
    {
        $response = $this->githubRequest()
            ->get(sprintf('/repos/%s/issues/%d', $this->repository(), $issueNumber));

        if ($response->failed()) {
            throw new RuntimeException('Unable to fetch GitHub issue for pull request creation.');
        }

        $issue = $response->json();

        return is_array($issue) ? $issue : [];
    }

    private function resolvePullRequestTitle(?int $issueNumber, ?array $issue = null): string
    {
        $suggestedTitle = $this->extractSuggestedValue((string) Arr::get($issue, 'body', ''), '## Рекомендуемый PR title');
        if ($suggestedTitle !== null) {
            return $suggestedTitle;
        }

        if ($issueNumber !== null) {
            return sprintf('fix: resolve GitHub issue #%d', $issueNumber);
        }

        return 'fix: update incident';
    }

    private function resolvePullRequestBody(?int $issueNumber, string $headBranch, ?array $issue = null): string
    {
        $lines = [];

        if ($issueNumber !== null) {
            $lines[] = sprintf('Closes #%d', $issueNumber);
            $lines[] = '';
        }

        $lines[] = '## Context';
        $lines[] = '';

        $issueTitle = trim((string) Arr::get($issue, 'title', ''));
        if ($issueTitle !== '') {
            $lines[] = sprintf('- Source issue: `%s`', $issueTitle);
        }

        $lines[] = sprintf('- Head branch: `%s`', $headBranch);
        $lines[] = '';
        $lines[] = '## Changes';
        $lines[] = '';
        $lines[] = '- [ ] Describe the implemented fix';
        $lines[] = '';
        $lines[] = '## Verification';
        $lines[] = '';
        $lines[] = '- [ ] Describe how the fix was verified';
        $lines[] = '';
        $lines[] = '## Risks';
        $lines[] = '';
        $lines[] = '- [ ] Describe residual risks or follow-up items';

        return implode("\n", $lines);
    }

    private function extractSuggestedValue(string $body, string $heading): ?string
    {
        $pattern = sprintf('/%s\s+`(?<value>[^`]+)`/u', preg_quote($heading, '/'));

        if (preg_match($pattern, $body, $matches) !== 1) {
            return null;
        }

        $value = trim((string) ($matches['value'] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? 'incident';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'incident';
    }

    private function token(): string
    {
        return (string) config('glitchtip.github.token', '');
    }

    private function repository(): string
    {
        return (string) config('glitchtip.github.repository', '');
    }

    private function baseBranch(): string
    {
        return (string) config('glitchtip.github.base_branch', 'main');
    }

    private function githubRequest(): PendingRequest
    {
        return Http::baseUrl(self::GITHUB_API_BASE_URL)
            ->withToken($this->token())
            ->acceptJson()
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(20);
    }
}
