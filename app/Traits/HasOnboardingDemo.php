<?php

namespace App\Traits;

trait HasOnboardingDemo
{
    /**
     * Scope для исключения демо-данных обучающего тура
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExcludingDemo($query)
    {
        return $query->where('is_onboarding_demo', false);
    }

    /**
     * Scope для получения только демо-данных
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnlyDemo($query)
    {
        return $query->where('is_onboarding_demo', true);
    }

    /**
     * Проверка, является ли запись демо-данными
     * 
     * @return bool
     */
    public function isDemo(): bool
    {
        return (bool) $this->is_onboarding_demo;
    }
}

