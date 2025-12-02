<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class PaymentDocument extends Model
{
    use HasFactory, SoftDeletes;
    
    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();
        
        // Перехватываем загрузку invoiceable для защиты от удаленного класса Invoice
        static::retrieved(function ($document) {
            if ($document->invoiceable_type && 
                str_contains($document->invoiceable_type, 'Invoice') && 
                str_contains($document->invoiceable_type, 'Payments\\Models')) {
                // Очищаем invoiceable_type для старых записей
                $document->setAttribute('invoiceable_type', null);
                $document->setAttribute('invoiceable_id', null);
                $document->setRelation('invoiceable', null);
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'project_id',
        'document_type',
        'document_number',
        'document_date',
        'direction',
        'invoice_type',
        'invoiceable_type',
        'invoiceable_id',
        'payer_organization_id',
        'payer_contractor_id',
        'payee_organization_id',
        'payee_contractor_id',
        'counterparty_organization_id',
        'contractor_id',
        'amount',
        'currency',
        'vat_amount',
        'vat_rate',
        'amount_without_vat',
        'paid_amount',
        'remaining_amount',
        'status',
        'workflow_stage',
        'source_type',
        'source_id',
        'due_date',
        'payment_terms_days',
        'description',
        'payment_purpose',
        'payment_terms',
        'attached_documents',
        'bank_account',
        'bank_bik',
        'bank_correspondent_account',
        'bank_name',
        'metadata',
        'notes',
        'created_by_user_id',
        'approved_by_user_id',
        'submitted_at',
        'approved_at',
        'issued_at',
        'scheduled_at',
        'paid_at',
        'overdue_since',
        'recipient_organization_id',
        'recipient_notified_at',
        'recipient_viewed_at',
        'recipient_confirmed_at',
        'recipient_confirmation_comment',
        'recipient_confirmed_by_user_id',
    ];

    protected $casts = [
        'document_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'amount_without_vat' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'document_type' => PaymentDocumentType::class,
        'direction' => InvoiceDirection::class,
        'invoice_type' => InvoiceType::class,
        'status' => PaymentDocumentStatus::class,
        'attached_documents' => 'array',
        'metadata' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'paid_at' => 'datetime',
        'overdue_since' => 'datetime',
        'recipient_notified_at' => 'datetime',
        'recipient_viewed_at' => 'datetime',
        'recipient_confirmed_at' => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'RUB',
        'vat_rate' => 20,
        'paid_amount' => 0,
        'status' => 'draft',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function payerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'payer_organization_id');
    }

    public function payerContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'payer_contractor_id');
    }

    public function payeeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'payee_organization_id');
    }

    public function payeeContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'payee_contractor_id');
    }

    public function counterpartyOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'counterparty_organization_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function recipientOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'recipient_organization_id');
    }

    public function recipientConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_confirmed_by_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function invoiceable(): MorphTo
    {
        return $this->morphTo('invoiceable');
    }
    
    /**
     * Переопределяем getAttribute для безопасной загрузки invoiceable
     * Защита от удаленного класса Invoice
     */
    public function getAttribute($key)
    {
        if ($key === 'invoiceable' && $this->relationLoaded('invoiceable')) {
            $type = $this->attributes['invoiceable_type'] ?? null;
            
            // Пропускаем старые ссылки на удаленный класс Invoice
            if ($type && str_contains($type, 'Invoice') && str_contains($type, 'Payments\\Models')) {
                return null;
            }
        }
        
        return parent::getAttribute($key);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PaymentApproval::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_document_id');
    }

    /**
     * Заявки, связанные с этим платежом
     */
    public function siteRequests(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\BusinessModules\Features\SiteRequests\Models\SiteRequest::class,
            'payment_document_site_requests',
            'payment_document_id',
            'site_request_id'
        )->withPivot('amount')->withTimestamps();
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByType($query, PaymentDocumentType $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeByStatus($query, PaymentDocumentStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['submitted', 'pending_approval', 'approved', 'scheduled', 'partially_paid']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', Carbon::now())
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid']);
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->whereBetween('due_date', [Carbon::now(), Carbon::now()->addDays($days)])
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid']);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', PaymentDocumentStatus::PENDING_APPROVAL);
    }

    public function scopeAwaitingPayment($query)
    {
        return $query->whereIn('status', ['approved', 'scheduled', 'partially_paid']);
    }

    public function scopeIncoming($query)
    {
        return $query->where('direction', InvoiceDirection::INCOMING);
    }

    public function scopeOutgoing($query)
    {
        return $query->where('direction', InvoiceDirection::OUTGOING);
    }

    public function scopeByInvoiceType($query, InvoiceType $type)
    {
        return $query->where('invoice_type', $type);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Рассчитать оставшуюся сумму к оплате
     */
    public function calculateRemainingAmount(): float
    {
        return (float) ($this->amount - $this->paid_amount);
    }

    /**
     * Получить процент оплаты
     */
    public function getPaymentPercentage(): float
    {
        if ($this->amount == 0) {
            return 0;
        }

        return round(($this->paid_amount / $this->amount) * 100, 2);
    }

    /**
     * Количество дней до срока оплаты
     */
    public function getDaysUntilDue(): int
    {
        if (!$this->due_date) {
            return 0;
        }

        return Carbon::now()->diffInDays($this->due_date, false);
    }

    /**
     * Количество дней просрочки
     */
    public function getOverdueDays(): int
    {
        if (!$this->due_date || $this->due_date >= Carbon::now()) {
            return 0;
        }

        return Carbon::now()->diffInDays($this->due_date);
    }

    /**
     * Просрочен ли документ
     */
    public function isOverdue(): bool
    {
        if ($this->overdue_since) {
            return true;
        }
        
        return $this->due_date && $this->due_date < Carbon::now() 
            && !in_array($this->status->value, ['paid', 'cancelled', 'rejected']);
    }

    /**
     * Получить название плательщика
     */
    public function getPayerName(): string
    {
        if ($this->payerOrganization) {
            return $this->payerOrganization->name;
        }

        if ($this->payerContractor) {
            return $this->payerContractor->name;
        }

        return 'Не указан';
    }

    /**
     * Получить название получателя
     */
    public function getPayeeName(): string
    {
        if ($this->payeeOrganization) {
            return $this->payeeOrganization->name;
        }

        if ($this->payeeContractor) {
            return $this->payeeContractor->name;
        }

        return 'Не указан';
    }

    /**
     * Определить ID организации-получателя (если зарегистрирована)
     * 
     * Проверяет прямую связь через payee_organization_id или через подрядчика
     * 
     * @return int|null ID организации-получателя или null если не зарегистрирована
     */
    public function getRecipientOrganizationId(): ?int
    {
        // 1. Прямая связь через payee_organization_id
        if ($this->payee_organization_id) {
            return $this->payee_organization_id;
        }

        // 2. Через подрядчика (если подрядчик связан с организацией)
        if ($this->payee_contractor_id && $this->payeeContractor) {
            return $this->payeeContractor->source_organization_id;
        }

        // 3. Через contractor_id (для обратной совместимости)
        if ($this->contractor_id && $this->contractor) {
            return $this->contractor->source_organization_id;
        }

        // 4. Не зарегистрирован
        return null;
    }

    /**
     * Проверить, зарегистрирован ли получатель в системе
     * 
     * @return bool true если получатель зарегистрирован как организация
     */
    public function hasRegisteredRecipient(): bool
    {
        return $this->getRecipientOrganizationId() !== null;
    }

    /**
     * Отметить документ как уведомленный получателю
     * 
     * Работает только если получатель зарегистрирован
     * 
     * @return bool true если успешно отмечено, false если получатель не зарегистрирован
     */
    public function markAsNotifiedToRecipient(): bool
    {
        if (!$this->hasRegisteredRecipient()) {
            return false;
        }

        $this->recipient_notified_at = now();
        return $this->save();
    }

    /**
     * Отметить документ как просмотренный получателем
     * 
     * Работает только если получатель зарегистрирован
     * 
     * @param int $userId ID пользователя, который просмотрел
     * @return bool true если успешно отмечено, false если получатель не зарегистрирован
     */
    public function markAsViewedByRecipient(int $userId): bool
    {
        if (!$this->hasRegisteredRecipient()) {
            return false;
        }

        $this->recipient_viewed_at = now();
        return $this->save();
    }

    /**
     * Подтвердить получение платежа получателем
     * 
     * Работает только если получатель зарегистрирован и документ в подходящем статусе
     * 
     * @param int $userId ID пользователя, который подтверждает
     * @param string|null $comment Комментарий получателя
     * @return bool true если успешно подтверждено
     * @throws \DomainException если получатель не зарегистрирован или статус не подходит
     */
    public function confirmByRecipient(int $userId, ?string $comment = null): bool
    {
        if (!$this->hasRegisteredRecipient()) {
            throw new \DomainException('Получатель не зарегистрирован в системе');
        }

        // Можно подтверждать только документы в статусах approved, scheduled, paid
        $allowedStatuses = [
            PaymentDocumentStatus::APPROVED,
            PaymentDocumentStatus::SCHEDULED,
            PaymentDocumentStatus::PAID,
            PaymentDocumentStatus::PARTIALLY_PAID,
        ];

        if (!in_array($this->status, $allowedStatuses)) {
            throw new \DomainException(
                "Документ в статусе '{$this->status->label()}' не может быть подтвержден получателем"
            );
        }

        $this->recipient_confirmed_at = now();
        $this->recipient_confirmation_comment = $comment;
        $this->recipient_confirmed_by_user_id = $userId;

        return $this->save();
    }

    /**
     * Форматированная сумма
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2, '.', ' ') . ' ' . $this->currency;
    }

    /**
     * Может ли документ быть оплачен
     */
    public function canBePaid(): bool
    {
        return $this->status->canBePaid() && $this->remaining_amount > 0;
    }

    /**
     * Может ли документ быть отменен
     */
    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled();
    }

    /**
     * Может ли документ быть редактирован
     */
    public function canBeEdited(): bool
    {
        return $this->status->canBeEdited();
    }

    /**
     * Требует ли документ утверждения
     */
    public function requiresApproval(): bool
    {
        return $this->document_type->requiresApproval();
    }

    /**
     * Проверить наличие связанных заявок
     */
    public function hasSiteRequests(): bool
    {
        return $this->siteRequests()->exists();
    }

    /**
     * Получить количество связанных заявок
     */
    public function getSiteRequestsCount(): int
    {
        return $this->siteRequests()->count();
    }
}

