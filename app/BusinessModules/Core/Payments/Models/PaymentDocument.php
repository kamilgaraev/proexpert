<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class PaymentDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'document_type',
        'document_number',
        'document_date',
        'payer_organization_id',
        'payer_contractor_id',
        'payee_organization_id',
        'payee_contractor_id',
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
        'scheduled_at',
        'paid_at',
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
        'status' => PaymentDocumentStatus::class,
        'attached_documents' => 'array',
        'metadata' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'paid_at' => 'datetime',
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

    public function source(): MorphTo
    {
        return $this->morphTo();
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
        return $this->hasMany(PaymentTransaction::class, 'invoice_id'); // совместимость со старой схемой
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
}

