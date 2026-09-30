<?php

declare(strict_types=1);

if (getenv('APP_ENV') !== 'testing' || ! is_string(getenv('BIM_DEVICE_ACCEPTANCE_DESCRIPTOR'))) {
    throw new RuntimeException('bim_acceptance_provider_context_invalid');
}

return array_values(array_diff(require dirname(__DIR__, 3).'/bootstrap/providers.php', [
    App\Providers\Filament\AdminPanelProvider::class,
]));
