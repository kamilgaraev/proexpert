<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

final class AssistantDocumentOcrRenderer
{
    public function pages(string $content, string $mimeType, int $pageCount, array $completedPages = []): iterable
    {
        if ($pageCount < 1 || $pageCount > AssistantDocumentOcrClient::MAX_PAGES) {
            throw new RuntimeException('ai_assistant_document_ocr_page_limit');
        }
        if ($mimeType !== 'application/pdf') {
            if (in_array(1, $completedPages, true)) return;
            $info = @getimagesizefromstring($content);
            if ($info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                || $info[0] * $info[1] > 25_000_000) {
                throw new RuntimeException('ai_assistant_document_image_invalid');
            }
            yield 1 => ['content' => $content, 'mime' => $info['mime']];
            return;
        }
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-ocr-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('ai_assistant_document_temp_unavailable');
        }
        $input = $directory.DIRECTORY_SEPARATOR.'source.pdf';
        try {
            if (file_put_contents($input, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('ai_assistant_document_temp_unavailable');
            }
            for ($page = 1; $page <= $pageCount; $page++) {
                if (in_array($page, $completedPages, true)) continue;
                $prefix = $directory.DIRECTORY_SEPARATOR.'page';
                $process = new Process(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', '1800', '-png', $input, $prefix]);
                $process->setTimeout(15);
                $process->run();
                if (! $process->isSuccessful()) {
                    throw new RuntimeException('ai_assistant_document_pdf_render_failed');
                }
                $image = file_get_contents($prefix.'.png');
                if (! is_string($image) || strlen($image) > 8_000_000 || @getimagesizefromstring($image) === false) {
                    throw new RuntimeException('ai_assistant_document_pdf_render_failed');
                }
                yield $page => ['content' => $image, 'mime' => 'image/png'];
                unlink($prefix.'.png');
            }
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
