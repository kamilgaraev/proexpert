<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

final class AssistantResponseVerifier
{
    public function verify(string $answer, array $agentResult): string
    {
        $trustedUrls = $this->trustedArtifactUrls($agentResult);
        $verifiedAnswer = $this->stripUntrustedLinks($answer, $trustedUrls);
        $verifiedAnswer = $this->guardRagSourceClaims($verifiedAnswer, $agentResult);

        if ($this->isReportTask($agentResult) && $this->claimsReportCompletion($verifiedAnswer) && $trustedUrls === []) {
            return $this->assistantMessage(
                'ai_assistant.report_download_missing_short',
                'Не удалось сформировать файл отчета по текущему запросу.'
            );
        }

        return $verifiedAnswer;
    }

    private function guardRagSourceClaims(string $answer, array $agentResult): string
    {
        $ragContext = $agentResult['rag_context'] ?? null;
        $sources = is_array($ragContext) && is_array($ragContext['sources'] ?? null)
            ? array_values($ragContext['sources'])
            : [];
        $used = is_array($ragContext) && ($ragContext['used'] ?? false) === true && $sources !== [];

        if (! $used && $this->claimsProjectContextUsage($answer)) {
            return $this->assistantMessage(
                'ai_assistant.rag_no_relevant_context',
                'Не нашел достаточно надежного контекста по этому вопросу.'
            );
        }

        return $this->stripMissingSourceReferences($answer, count($sources));
    }

    private function claimsProjectContextUsage(string $answer): bool
    {
        $normalized = mb_strtolower($answer);

        return preg_match(
            '/(использовал\w*|опира[а-я]*|согласно|по данным|на основе).{0,48}(проектн[а-я]* контекст[а-я]*|rag|источник[а-я]*|данн[а-я]* проекта)/u',
            $normalized
        ) === 1;
    }

    private function stripMissingSourceReferences(string $answer, int $sourceCount): string
    {
        $result = preg_replace_callback(
            '/\s*\[(\d+)\]/u',
            static fn (array $matches): string => (int) $matches[1] >= 1 && (int) $matches[1] <= $sourceCount
                ? $matches[0]
                : '',
            $answer
        );

        return is_string($result) ? trim(preg_replace('/[ \t]{2,}/u', ' ', $result) ?? $result) : $answer;
    }

    private function assistantMessage(string $key, string $fallback): string
    {
        try {
            return trans_message($key);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function stripUntrustedLinks(string $answer, array $trustedUrls): string
    {
        $answer = $this->stripUntrustedHtmlLinks($answer, $trustedUrls);
        $answer = $this->stripUntrustedMarkdownLinks($answer, $trustedUrls);

        return $this->stripUntrustedRawUrls($answer, $trustedUrls);
    }

    /**
     * @param  string[]  $trustedUrls
     */
    private function stripUntrustedMarkdownLinks(string $answer, array $trustedUrls): string
    {
        $trusted = array_fill_keys($trustedUrls, true);

        $result = preg_replace_callback(
            '/\[([^\]\r\n]+)\]\(([^)\s]+)\)/u',
            static function (array $matches) use ($trusted): string {
                $url = (string) $matches[2];

                if (isset($trusted[$url])) {
                    return $matches[0];
                }

                return (string) $matches[1];
            },
            $answer
        );

        return is_string($result) ? $result : $answer;
    }

    /**
     * @param  string[]  $trustedUrls
     */
    private function stripUntrustedHtmlLinks(string $answer, array $trustedUrls): string
    {
        $trusted = array_fill_keys($trustedUrls, true);

        $result = preg_replace_callback(
            '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/uis',
            static function (array $matches) use ($trusted): string {
                $url = (string) $matches[1];
                $label = trim(strip_tags((string) $matches[2]));

                if (isset($trusted[$url])) {
                    return $matches[0];
                }

                return $label !== '' ? $label : '';
            },
            $answer
        );

        return is_string($result) ? $result : $answer;
    }

    /**
     * @param  string[]  $trustedUrls
     */
    private function stripUntrustedRawUrls(string $answer, array $trustedUrls): string
    {
        $trusted = array_fill_keys($trustedUrls, true);

        $result = preg_replace_callback(
            '/<?https?:\/\/[^\s<>)]+>?/u',
            static function (array $matches) use ($trusted): string {
                $matched = (string) $matches[0];
                $url = trim($matched, '<>');

                return isset($trusted[$url]) ? $matched : '';
            },
            $answer
        );

        return is_string($result) ? trim(preg_replace('/[ \t]{2,}/u', ' ', $result) ?? $result) : $answer;
    }

    private function trustedArtifactUrls(array $agentResult): array
    {
        $urls = [];

        foreach (($agentResult['artifacts'] ?? []) as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $url = $artifact['url'] ?? null;
            $storageDisk = $artifact['storage_disk'] ?? null;
            $storagePath = $artifact['storage_path'] ?? null;

            if (
                is_string($url)
                && $url !== ''
                && $storageDisk === 's3'
                && is_string($storagePath)
                && str_starts_with($storagePath, 'org-')
                && str_contains($storagePath, '/reports/')
            ) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function isReportTask(array $agentResult): bool
    {
        $taskId = $agentResult['task_id']
            ?? $agentResult['task']['id']
            ?? $agentResult['state']['id']
            ?? $agentResult['capability']['id']
            ?? null;

        return is_string($taskId) && str_starts_with($taskId, 'report.');
    }

    private function claimsReportCompletion(string $answer): bool
    {
        $normalized = mb_strtolower($answer);
        $mentionsReport = preg_match('/\bотч[её]т\w*/u', $normalized) === 1;
        $claimsCompletion = preg_match('/\b(готов\w*|сформирован\w*|сгенерирован\w*|создан\w*|подготовлен\w*|доступен\w*)/u', $normalized) === 1;

        return $mentionsReport && $claimsCompletion;
    }
}
