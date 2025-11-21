<?php

namespace App\BusinessModules\Core\Payments\Services\Import\Parsers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OneCClientBankParser
{
    /**
     * Parse 1C Client-Bank exchange file content
     * 
     * @param string $content File content (windows-1251 or utf-8)
     * @return array Structured data
     */
    public function parse(string $content): array
    {
        // Detect encoding and convert to UTF-8 if needed
        if (!$this->isUtf8($content)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $lines = explode("\n", $content);
        $data = [
            'header' => [],
            'documents' => [],
        ];

        $currentDoc = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) continue;

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '1CClientBankExchange') {
                continue;
            }

            if ($key === 'СекцияДокумент') {
                if ($currentDoc) {
                    $data['documents'][] = $currentDoc;
                }
                $currentDoc = ['Type' => $value];
                continue;
            }

            if ($key === 'КонецДокумента') {
                if ($currentDoc) {
                    $data['documents'][] = $currentDoc;
                    $currentDoc = null;
                }
                continue;
            }

            if ($key === 'КонецФайла') {
                break;
            }

            if ($currentDoc) {
                $currentDoc[$key] = $value;
            } else {
                $data['header'][$key] = $value;
            }
        }

        // Add last document if file ended abruptly
        if ($currentDoc) {
            $data['documents'][] = $currentDoc;
        }

        return $data;
    }

    /**
     * Check if string is UTF-8
     */
    private function isUtf8(string $string): bool
    {
        return mb_detect_encoding($string, ['UTF-8', 'Windows-1251'], true) === 'UTF-8';
    }
}

