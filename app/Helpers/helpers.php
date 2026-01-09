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
