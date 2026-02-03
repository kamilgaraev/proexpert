<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceAccountTransaction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Атрибуты, которые можно массово присваивать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'organization_id',
        'project_id',
        'type',
        'amount',
        'description',
        'document_number',
        'document_date',
        'balance_after',
        'reporting_status',
        'reported_at',
        'approved_at',
        'created_by_user_id',
        'approved_by_user_id',
        'external_code',
        'accounting_data',
        'cost_category_id',
        'attachment_ids',
    ];

    /**
     * Атрибуты, которые должны быть приведены к native типам.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'document_date' => 'date',
        'reported_at' => 'datetime',
        'approved_at' => 'datetime',
        'accounting_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Константы для типов транзакций.
     */
    public const TYPE_ISSUE = 'issue';    // Выдача средств
    public const TYPE_EXPENSE = 'expense'; // Расход/списание средств
    public const TYPE_RETURN = 'return';   // Возврат неиспользованных средств

    /**
     * Константы для статусов отчетности.
     */
    public const STATUS_PENDING = 'pending';   // В ожидании отчета
    public const STATUS_REPORTED = 'reported'; // Отчет предоставлен
    public const STATUS_APPROVED = 'approved'; // Отчет утвержден

    /**
     * Получить пользователя (прораба), к которому относится транзакция.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить организацию, к которой относится транзакция.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить проект, к которому относится транзакция.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Получить пользователя, создавшего транзакцию.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Получить пользователя, утвердившего транзакцию.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }


    /**
     * Получить категорию затрат.
     */
    public function costCategory(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }

    /**
     * Получить прикрепленные файлы.
     */
    public function getAttachments()
    {
        if (empty($this->attachment_ids)) {
            return [];
        }

        $fileIds = explode(',', $this->attachment_ids);
        return File::whereIn('id', $fileIds)->get();
    }

    /**
     * Получить транзакции по указанному пользователю.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Получить транзакции по указанной организации.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Получить транзакции по указанному проекту.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $projectId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Получить транзакции указанного типа.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Получить транзакции с указанным статусом отчетности.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('reporting_status', $status);
    }

    /**
     * Получить транзакции за указанный период.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('document_date', [$startDate, $endDate]);
    }

    /**
     * Получить транзакции, не имеющие отчета.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('reporting_status', self::STATUS_PENDING);
    }

    /**
     * Получить транзакции с созданным отчетом, но без утверждения.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReported($query)
    {
        return $query->where('reporting_status', self::STATUS_REPORTED);
    }

    /**
     * Получить транзакции с утвержденным отчетом.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('reporting_status', self::STATUS_APPROVED);
    }
}
