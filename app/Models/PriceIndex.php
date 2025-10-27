<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PriceIndex extends Model
{
    use HasFactory;

    protected $fillable = [
        'index_type',
        'region_code',
        'region_name',
        'year',
        'quarter',
        'month',
        'index_value',
        'source',
        'publication_date',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'index_value' => 'decimal:4',
        'publication_date' => 'date',
        'metadata' => 'array',
    ];

    public function scopeByType($query, string $type)
    {
        return $query->where('index_type', $type);
    }

    public function scopeByRegion($query, string $regionCode)
    {
        return $query->where('region_code', $regionCode);
    }

    public function scopeByPeriod($query, int $year, ?int $quarter = null, ?int $month = null)
    {
        $query->where('year', $year);
        
        if ($quarter !== null) {
            $query->where('quarter', $quarter);
        }
        
        if ($month !== null) {
            $query->where('month', $month);
        }
        
        return $query;
    }

    public function scopeLatest($query)
    {
        return $query->orderBy('year', 'desc')
            ->orderBy('quarter', 'desc')
            ->orderBy('month', 'desc');
    }

    public function scopeForDate($query, Carbon $date, ?string $regionCode = null)
    {
        $query->where('year', $date->year);
        
        if ($regionCode) {
            $query->where('region_code', $regionCode);
        }
        
        if ($date->month) {
            $query->where(function ($q) use ($date) {
                $q->where('month', $date->month)
                  ->orWhere(function ($sq) use ($date) {
                      $sq->whereNull('month')
                         ->where('quarter', ceil($date->month / 3));
                  })
                  ->orWhere(function ($sq) {
                      $sq->whereNull('month')
                         ->whereNull('quarter');
                  });
            });
        }
        
        return $query;
    }

    public static function getIndexForDate(
        string $indexType,
        Carbon $date,
        ?string $regionCode = null
    ): ?float {
        $index = self::where('index_type', $indexType)
            ->forDate($date, $regionCode)
            ->latest()
            ->first();
        
        return $index ? (float) $index->index_value : null;
    }

    public function getPeriodNameAttribute(): string
    {
        $period = $this->year;
        
        if ($this->month) {
            $period .= '-' . str_pad($this->month, 2, '0', STR_PAD_LEFT);
        } elseif ($this->quarter) {
            $period .= ' кв.' . $this->quarter;
        }
        
        return $period;
    }

    public function getFormattedIndexValueAttribute(): string
    {
        return number_format($this->index_value, 4, '.', '');
    }
}
