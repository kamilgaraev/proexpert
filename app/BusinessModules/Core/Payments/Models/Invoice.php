<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceStatus;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'counterparty_organization_id',
        'contractor_id',
        'project_id',
        'invoiceable_type',
        'invoiceable_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'direction',
        'invoice_type',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'currency',
        'vat_rate',
        'vat_amount',
        'amount_without_vat',
        'status',
        'description',
        'payment_terms',
        'bank_account',
        'bank_bik',
        'bank_name',
        'bank_correspondent_account',
        'metadata',
        'notes',
        'issued_at',
        'paid_at',
        'overdue_since',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'amount_without_vat' => 'decimal:2',
        'direction' => InvoiceDirection::class,
        'invoice_type' => InvoiceType::class,
        'status' => InvoiceStatus::class,
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'overdue_since' => 'datetime',
    ];

    /**
     * Значения по умолчанию
     */
    protected $attributes = [
        'currency' => 'RUB',
        'vat_rate' => 20,
        'paid_amount' => 0,
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function counterpartyOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'counterparty_organization_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }

    /**
     * PaymentDocuments созданные из этого счета
     */
    public function paymentDocuments(): HasMany
    {
        return $this->hasMany(PaymentDocument::class, 'source_id')
            ->where('source_type', self::class);
    }

    /**
     * Получить основной PaymentDocument для этого счета
     */
    public function primaryPaymentDocument()
    {
        return $this->hasOne(PaymentDocument::class, 'source_id')
            ->where('source_type', self::class)
            ->latest();
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', InvoiceStatus::OVERDUE)
            ->orWhere(function ($q) {
                $q->whereIn('status', [InvoiceStatus::ISSUED, InvoiceStatus::PARTIALLY_PAID])
                    ->where('due_date', '<', Carbon::now());
            });
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', [
            InvoiceStatus::ISSUED,
            InvoiceStatus::PARTIALLY_PAID,
            InvoiceStatus::OVERDUE,
        ]);
    }

    public function scopeIncoming($query)
    {
        return $query->where('direction', InvoiceDirection::INCOMING);
    }

    public function scopeOutgoing($query)
    {
        return $query->where('direction', InvoiceDirection::OUTGOING);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Проверка просрочки счёта
     */
    public function isOverdue(): bool
    {
        if ($this->status === InvoiceStatus::PAID) {
            return false;
        }

        return $this->due_date < Carbon::now();
    }

    /**
     * Может ли счёт быть оплачен
     */
    public function canBePaid(): bool
    {
        return $this->status->canBePaid();
    }

    /**
     * Может ли счёт быть отменён
     */
    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled();
    }

    /**
     * Рассчитать остаток к оплате
     */
    public function calculateRemainingAmount(): float
    {
        return (float) ($this->total_amount - $this->paid_amount);
    }

    /**
     * Получить процент оплаты
     */
    public function getPaymentPercentage(): float
    {
        if ($this->total_amount == 0) {
            return 0;
        }

        return round(($this->paid_amount / $this->total_amount) * 100, 2);
    }

    /**
     * Количество дней просрочки
     */
    public function getOverdueDays(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return Carbon::now()->diffInDays($this->due_date);
    }
}

