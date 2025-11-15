<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationError extends Model
{
    /**
     * Таблица БД
     */
    protected $table = 'application_errors';

    /**
     * Массово заполняемые атрибуты
     */
    protected $fillable = [
        'error_hash',
        'error_group',
        'exception_class',
        'message',
        'file',
        'line',
        'stack_trace',
        'organization_id',
        'user_id',
        'module',
        'url',
        'method',
        'ip',
        'user_agent',
        'context',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'status',
        'severity',
    ];

    /**
     * Приведение типов
     */
    protected $casts = [
        'context' => 'array',
        'occurrences' => 'integer',
        'line' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Организация
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Пользователь
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Только нерешенные ошибки
     */
    public function scopeUnresolved($query)
    {
        return $query->where('status', 'unresolved');
    }

    /**
     * Только решенные ошибки
     */
    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    /**
     * Игнорируемые ошибки
     */
    public function scopeIgnored($query)
    {
        return $query->where('status', 'ignored');
    }

    /**
     * По важности
     */
    public function scopeWithSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Критические ошибки
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * За последние N дней
     */
    public function scopeLastDays($query, int $days = 7)
    {
        return $query->where('last_seen_at', '>=', now()->subDays($days));
    }

    /**
     * По модулю
     */
    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * По организации
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // ============================================
    // МЕТОДЫ
    // ============================================

    /**
     * Отметить как решенную
     */
    public function markAsResolved(): bool
    {
        return $this->update(['status' => 'resolved']);
    }

    /**
     * Игнорировать ошибку
     */
    public function markAsIgnored(): bool
    {
        return $this->update(['status' => 'ignored']);
    }

    /**
     * Вернуть в нерешенные
     */
    public function markAsUnresolved(): bool
    {
        return $this->update(['status' => 'unresolved']);
    }

    /**
     * Короткое сообщение (первые 100 символов)
     */
    public function getShortMessageAttribute(): string
    {
        return mb_substr($this->message, 0, 100) . (mb_strlen($this->message) > 100 ? '...' : '');
    }

    /**
     * Короткий путь к файлу (без базового пути)
     */
    public function getShortFileAttribute(): string
    {
        $basePath = base_path();
        return str_replace($basePath, '', $this->file);
    }
}

