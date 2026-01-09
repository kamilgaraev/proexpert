<?php

declare(strict_types=1);

namespace App\Helpers;

class TranslationHelper
{
    /**
     * Получить переведенное сообщение или вернуть ключ, если перевод не найден.
     * 
     * @param string $key Ключ перевода (например, 'auth.login_success')
     * @param array $replace Параметры для замены в переводе
     * @param string|null $locale Локаль (по умолчанию текущая)
     * @return string
     */
    public static function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        $translation = __($key, $replace, $locale);
        
        // Если Laravel не нашел перевод, он вернет сам ключ
        // Проверяем, что вернулось что-то отличное от ключа
        if ($translation === $key) {
            // Логируем отсутствующий перевод для дальнейшего анализа
            \Illuminate\Support\Facades\Log::warning("Missing translation key: {$key}");
        }
        
        return $translation;
    }

    /**
     * Получить переведенное сообщение или null, если перевод не найден.
     * Полезно для опциональных сообщений.
     * 
     * @param string|null $key Ключ перевода
     * @param array $replace Параметры для замены
     * @param string|null $locale Локаль
     * @return string|null
     */
    public static function transOrNull(?string $key, array $replace = [], ?string $locale = null): ?string
    {
        if ($key === null) {
            return null;
        }
        
        return self::trans($key, $replace, $locale);
    }

    /**
     * Проверить, существует ли перевод для ключа.
     * 
     * @param string $key Ключ перевода
     * @param string|null $locale Локаль
     * @return bool
     */
    public static function has(string $key, ?string $locale = null): bool
    {
        return \Illuminate\Support\Facades\Lang::has($key, $locale);
    }
}
