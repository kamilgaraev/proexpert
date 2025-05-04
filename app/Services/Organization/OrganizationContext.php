<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\Log;

/**
 * Класс для хранения контекста организации.
 * Используется как singleton в приложении.
 */
class OrganizationContext 
{
    /**
     * Хранилище данных
     */
    private static ?int $organizationId = null;
    private static $organization = null;
    
    /**
     * Установить ID организации
     */
    public static function setOrganizationId(?int $id): void 
    {
        self::$organizationId = $id;
        Log::debug("[OrganizationContext] ID организации установлен", ['id' => $id]);
    }
    
    /**
     * Установить объект организации
     */
    public static function setOrganization($organization): void 
    {
        self::$organization = $organization;
        Log::debug("[OrganizationContext] Объект организации установлен", [
            'id' => $organization ? $organization->id : null
        ]);
    }
    
    /**
     * Получить ID текущей организации
     */
    public static function getOrganizationId(): ?int 
    {
        return self::$organizationId;
    }
    
    /**
     * Получить объект текущей организации
     */
    public static function getOrganization() 
    {
        return self::$organization;
    }
    
    /**
     * Очистить контекст организации
     */
    public static function clear(): void 
    {
        self::$organizationId = null;
        self::$organization = null;
        Log::debug("[OrganizationContext] Контекст очищен");
    }
} 