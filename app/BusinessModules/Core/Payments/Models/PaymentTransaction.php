<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'organization_id',
        'project_id',
        'payer_organization_id',
        'payee_organization_id',
        'payer_contractor_id',
        'payee_contractor_id',
        'amount',
        'currency',
        'payment_method',
        'reference_number',
        'bank_transaction_id',
        'transaction_date',
        'value_date',
        'status',
        'payment_gateway_id',
        'gateway_response',
        'proof_document_url',
        'notes',
        'metadata',
        'created_by_user_id',
        'approved_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'value_date' => 'date',
        'payment_method' => PaymentMethod::class,
        'status' => PaymentTransactionStatus::class,
        'gateway_response' => 'array',
        'metadata' => 'array',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

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

    public function payeeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'payee_organization_id');
    }

    public function payerContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'payer_contractor_id');
    }

    public function payeeContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'payee_contractor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForInvoice($query, int $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', PaymentTransactionStatus::COMPLETED);
    }

    public function scopeByMethod($query, PaymentMethod $method)
    {
        return $query->where('payment_method', $method);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Успешная ли транзакция
     */
    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    /**
     * Финальный ли статус
     */
    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * Может ли быть возвращена
     */
    public function canBeRefunded(): bool
    {
        return $this->status === PaymentTransactionStatus::COMPLETED;
    }
}

