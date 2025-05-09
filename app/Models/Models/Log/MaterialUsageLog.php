<?php

namespace App\Models\Models\Log;

use Illuminate\Database\Eloquent\Model;
use App\Models\Project;
use App\Models\User;
use App\Models\Material;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialUsageLog extends Model
{
    protected $table = 'material_usage_logs'; // Явно укажем имя таблицы, на всякий случай

    protected $fillable = [
        'project_id',
        'material_id',
        'user_id',
        'quantity',
        'unit_price',       // Добавлено
        'total_price',      // Добавлено
        'supplier_id',      // Добавлено
        'document_number',  // Добавлено
        'operation_type',   // Добавлено ('receipt', 'write_off')
        'usage_date',       // или 'operation_date'
        'notes',
        'organization_id',  // Важно для привязки к организации
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'usage_date' => 'date',
        'quantity' => 'decimal:3', // Пример каста для точности
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    /**
     * Получить проект, к которому относится лог.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Получить материал, к которому относится лог.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    /**
     * Получить пользователя (прораба), который создал лог.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    /**
     * Получить поставщика (если применимо, например для 'receipt').
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Supplier::class, 'supplier_id');
    }
}
