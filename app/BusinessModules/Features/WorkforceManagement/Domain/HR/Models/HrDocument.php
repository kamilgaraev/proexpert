<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use Illuminate\Database\Eloquent\Model;

final class HrDocument extends Model
{
    protected $table = 'workforce_hr_documents';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'document_type',
        'title',
        'file_id',
        'revision',
        'supersedes_id',
        'retention_class',
        'document_date',
        'case_closed_on',
        'closure_basis_reference',
        'retain_until',
        'retention_policy_metadata',
        'evidence_kind',
        'evidence_file_id',
        'paper_evidence_metadata',
        'acknowledged_at',
        'acknowledged_by',
        'recorded_by',
    ];

    protected $casts = [
        'document_date' => 'date:Y-m-d',
        'case_closed_on' => 'date:Y-m-d',
        'retention_policy_metadata' => 'array',
        'paper_evidence_metadata' => 'array',
        'retain_until' => 'date:Y-m-d',
        'acknowledged_at' => 'datetime',
    ];
}
