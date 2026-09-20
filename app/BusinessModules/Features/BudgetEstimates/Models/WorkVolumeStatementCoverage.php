<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Models;

use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkVolumeStatementCoverage extends Model
{
    use HasFactory;

    protected $table = 'work_volume_statement_coverages';
    protected $fillable = ['statement_id', 'statement_line_id', 'organization_id', 'project_id', 'contract_id', 'estimate_id', 'estimate_item_id', 'quantity', 'coverage_revision', 'unit_code', 'source_link_id', 'conversion_basis', 'estimate_quantity', 'estimate_unit_code', 'source_snapshot'];
    protected $casts = ['quantity' => 'decimal:6', 'estimate_quantity' => 'decimal:6', 'conversion_basis' => 'array', 'source_snapshot' => 'array'];

    public function statement(): BelongsTo { return $this->belongsTo(WorkVolumeStatement::class, 'statement_id'); }
    public function line(): BelongsTo { return $this->belongsTo(WorkVolumeStatementLine::class, 'statement_line_id'); }
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
}
