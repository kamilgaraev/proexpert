<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\System;

class GetHelpAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $capabilities = require __DIR__ . '/../../Config/ai-capabilities.php';

        return [
            'version' => $capabilities['version'],
            'capabilities' => $capabilities['categories'],
            'examples' => $capabilities['examples'],
            'tips' => $capabilities['tips'],
            'limitations' => $capabilities['limitations'] ?? [],
            'contacts' => $capabilities['contacts'] ?? [],
        ];
    }
}

