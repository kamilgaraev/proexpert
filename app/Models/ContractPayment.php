<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\Contract\ContractPaymentTypeEnum;

class ContractPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'payment_date',
        'amount',
        'payment_type',
        'reference_document_number',
        'description',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'payment_type' => ContractPaymentTypeEnum::class,
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $payment = static::where($this->getRouteKeyName(), $value)->firstOrFail();
        
        $user = request()->user();
        if ($user && $user->current_organization_id) {
            $contract = $payment->contract;
            if ($contract && $contract->organization_id !== $user->current_organization_id) {
                abort(403, 'У вас нет доступа к этому платежу');
            }
        }
        
        return $payment;
    }
} 