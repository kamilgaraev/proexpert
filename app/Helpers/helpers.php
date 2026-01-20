<?php

declare(strict_types=1);

if (!function_exists('trans_message')) {
    /**
     * Хелпер для получения переведенного сообщения.
     * Короткая альтернатива __() с дополнительной логикой.
     * 
     * @param string $key Ключ перевода (например, 'auth.login_success')
     * @param array $replace Параметры для замены
     * @param string|null $locale Локаль
     * @return string
     */
    function trans_message(string $key, array $replace = [], ?string $locale = null): string
    {
        return \App\Helpers\TranslationHelper::trans($key, $replace, $locale);
    }
}

if (!function_exists('trans_or_null')) {
    /**
     * Получить переведенное сообщение или null.
     * 
     * @param string|null $key Ключ перевода
     * @param array $replace Параметры для замены
     * @param string|null $locale Локаль
     * @return string|null
     */
    function trans_or_null(?string $key, array $replace = [], ?string $locale = null): ?string
    {
        return \App\Helpers\TranslationHelper::transOrNull($key, $replace, $locale);
    }
}

if (!function_exists('convert_ini_size_to_bytes')) {
    /**
     * Конвертирует строку размера PHP (например, "64M", "2G") в байты.
     * 
     * @param string $size Размер в формате PHP ini (например, "64M", "2G", "128K")
     * @return int Размер в байтах
     */
    function convert_ini_size_to_bytes(string $size): int
    {
        $size = trim($size);
        if (empty($size)) {
            return 0;
        }
        
        $last = strtolower($size[strlen($size) - 1]);
        $value = (int)$size;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}

if (!function_exists('is_api_request')) {
    /**
     * Проверяет, является ли запрос API запросом.
     * 
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    function is_api_request(\Illuminate\Http\Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }
}
