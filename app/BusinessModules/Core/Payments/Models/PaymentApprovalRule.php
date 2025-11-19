<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentApprovalRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'priority',
        'conditions',
        'approval_chain',
        'is_active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'approval_chain' => 'array',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'priority' => 0,
        'is_active' => true,
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Проверить, применимо ли правило к документу
     */
    public function matches(PaymentDocument $document): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $conditions = $this->conditions;

        // Проверка суммы
        if (isset($conditions['amount_from']) && $document->amount < $conditions['amount_from']) {
            return false;
        }

        if (isset($conditions['amount_to']) && $document->amount > $conditions['amount_to']) {
            return false;
        }

        // Проверка типов документов
        if (isset($conditions['document_types']) && !empty($conditions['document_types'])) {
            if (!in_array($document->document_type->value, $conditions['document_types'])) {
                return false;
            }
        }

        // Проверка проектов
        if (isset($conditions['projects']) && !empty($conditions['projects'])) {
            if (!in_array($document->project_id, $conditions['projects'])) {
                return false;
            }
        }

        // Проверка контрагентов
        if (isset($conditions['contractors']) && !empty($conditions['contractors'])) {
            $documentContractors = array_filter([
                $document->payer_contractor_id,
                $document->payee_contractor_id
            ]);
            
            if (empty(array_intersect($documentContractors, $conditions['contractors']))) {
                return false;
            }
        }

        // Проверка дня недели (опционально)
        if (isset($conditions['weekdays']) && !empty($conditions['weekdays'])) {
            $currentWeekday = now()->dayOfWeek;
            if (!in_array($currentWeekday, $conditions['weekdays'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Получить цепочку утверждений
     */
    public function getApprovalChain(): array
    {
        return $this->approval_chain ?? [];
    }

    /**
     * Получить количество уровней утверждения
     */
    public function getApprovalLevelsCount(): int
    {
        $chain = $this->getApprovalChain();
        
        if (empty($chain)) {
            return 0;
        }

        return max(array_column($chain, 'level'));
    }

    /**
     * Получить утверждающих для уровня
     */
    public function getApproversForLevel(int $level): array
    {
        $chain = $this->getApprovalChain();
        
        return array_filter($chain, fn($item) => $item['level'] === $level);
    }

    /**
     * Требуется ли утверждение данной роли
     */
    public function requiresRole(string $role): bool
    {
        $chain = $this->getApprovalChain();
        
        foreach ($chain as $item) {
            if ($item['role'] === $role && ($item['required'] ?? true)) {
                return true;
            }
        }

        return false;
    }
}

