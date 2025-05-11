<?php

namespace App\Models\Models\Log;

use Illuminate\Database\Eloquent\Model;
use App\Models\Project;
use App\Models\User;
use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasImages;

class MaterialUsageLog extends Model
{
    use HasImages;

    protected $table = 'material_usage_logs'; // Явно укажем имя таблицы, на всякий случай

    protected $fillable = [
        'project_id',
        'material_id',
        'user_id',
        'organization_id',  // Важно для привязки к организации
        'operation_type',   // Добавлено ('receipt', 'write_off')
        'quantity',
        'unit_price',       // Добавлено
        'total_price',      // Добавлено
        'supplier_id',      // Добавлено
        'document_number',  // Переименовано из invoice_number для общности
        'invoice_date',     // Оставляем для обратной совместимости или специфики приемки
        'usage_date',       // или 'operation_date'
        'photo_path',       // Добавлено
        'notes',
        'work_type_id',     // Добавлено для списания на работы
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'usage_date' => 'date',
        'invoice_date' => 'date',
        'quantity' => 'decimal:3', // Пример каста для точности
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    protected $appends = [
        'photo_url' // <--- Добавляем аксессор в вывод JSON
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
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function workType(): BelongsTo // Для списания
    {
        return $this->belongsTo(\App\Models\WorkType::class, 'work_type_id');
    }

    /**
     * Аксессор для получения URL фото.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        // Предполагаем, что дефолтного изображения для логов нет, или оно другое
        return $this->getImageUrl('photo_path', null); // null если нет дефолтного
    }
}
