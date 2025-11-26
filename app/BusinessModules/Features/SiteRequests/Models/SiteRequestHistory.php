<?php

namespace App\BusinessModules\Features\SiteRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;

/**
 * Модель истории изменений заявки (audit log)
 */
class SiteRequestHistory extends Model
{
    use HasFactory;

    protected $table = 'site_request_history';

    /**
     * Отключаем updated_at так как эта таблица только для чтения
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'site_request_id',
        'user_id',
        'action',
        'old_value',
        'new_value',
        'notes',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    // ============================================
    // КОНСТАНТЫ ДЕЙСТВИЙ
    // ============================================

    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_STATUS_CHANGED = 'status_changed';
    public const ACTION_ASSIGNED = 'assigned';
    public const ACTION_UNASSIGNED = 'unassigned';
    public const ACTION_FILE_UPLOADED = 'file_uploaded';
    public const ACTION_FILE_DELETED = 'file_deleted';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_RESTORED = 'restored';

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Заявка
     */
    public function siteRequest(): BelongsTo
    {
        return $this->belongsTo(SiteRequest::class);
    }

    /**
     * Пользователь, который внес изменение
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Scope для заявки
     */
    public function scopeForRequest($query, int $requestId)
    {
        return $query->where('site_request_id', $requestId);
    }

    /**
     * Scope для пользователя
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope для типа действия
     */
    public function scopeOfAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope для сортировки по времени (последние первые)
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Получить человекочитаемое название действия
     */
    public function getActionLabelAttribute(): string
    {
        return match($this->action) {
            self::ACTION_CREATED => 'Создана',
            self::ACTION_UPDATED => 'Обновлена',
            self::ACTION_STATUS_CHANGED => 'Статус изменен',
            self::ACTION_ASSIGNED => 'Назначен исполнитель',
            self::ACTION_UNASSIGNED => 'Исполнитель снят',
            self::ACTION_FILE_UPLOADED => 'Файл загружен',
            self::ACTION_FILE_DELETED => 'Файл удален',
            self::ACTION_DELETED => 'Удалена',
            self::ACTION_RESTORED => 'Восстановлена',
            default => $this->action,
        };
    }

    /**
     * Создать запись о создании заявки
     */
    public static function logCreated(SiteRequest $request, int $userId, ?string $notes = null): self
    {
        return self::create([
            'site_request_id' => $request->id,
            'user_id' => $userId,
            'action' => self::ACTION_CREATED,
            'new_value' => $request->toArray(),
            'notes' => $notes,
        ]);
    }

    /**
     * Создать запись о смене статуса
     */
    public static function logStatusChanged(
        SiteRequest $request,
        int $userId,
        string $oldStatus,
        string $newStatus,
        ?string $notes = null
    ): self {
        return self::create([
            'site_request_id' => $request->id,
            'user_id' => $userId,
            'action' => self::ACTION_STATUS_CHANGED,
            'old_value' => ['status' => $oldStatus],
            'new_value' => ['status' => $newStatus],
            'notes' => $notes,
        ]);
    }

    /**
     * Создать запись о назначении исполнителя
     */
    public static function logAssigned(
        SiteRequest $request,
        int $userId,
        ?int $oldAssignee,
        int $newAssignee,
        ?string $notes = null
    ): self {
        return self::create([
            'site_request_id' => $request->id,
            'user_id' => $userId,
            'action' => self::ACTION_ASSIGNED,
            'old_value' => ['assigned_to' => $oldAssignee],
            'new_value' => ['assigned_to' => $newAssignee],
            'notes' => $notes,
        ]);
    }

    /**
     * Создать запись об обновлении
     */
    public static function logUpdated(
        SiteRequest $request,
        int $userId,
        array $oldValues,
        array $newValues,
        ?string $notes = null
    ): self {
        return self::create([
            'site_request_id' => $request->id,
            'user_id' => $userId,
            'action' => self::ACTION_UPDATED,
            'old_value' => $oldValues,
            'new_value' => $newValues,
            'notes' => $notes,
        ]);
    }
}

