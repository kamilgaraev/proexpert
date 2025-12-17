<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ежедневная выписка из журнала работ</title>
    <style>
        @page { margin: 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; }
        h1 { text-align: center; font-size: 14pt; margin-bottom: 20px; }
        .header-info { margin-bottom: 20px; }
        .header-info p { margin: 5px 0; }
        .section { margin-top: 20px; border-top: 2px solid #000; padding-top: 10px; }
        .section h2 { font-size: 12pt; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: left; }
        th { background-color: #e0e0e0; font-weight: bold; }
        .footer { margin-top: 30px; }
        .signature { margin-top: 15px; }
        .signature-line { border-bottom: 1px solid #000; width: 200px; display: inline-block; }
        .status-badge { padding: 3px 8px; border-radius: 3px; font-size: 9pt; }
        .status-approved { background-color: #d4edda; color: #155724; }
        .status-draft { background-color: #f8f9fa; color: #6c757d; }
        .status-submitted { background-color: #d1ecf1; color: #0c5460; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <h1>ЕЖЕДНЕВНАЯ ВЫПИСКА ИЗ ЖУРНАЛА РАБОТ</h1>
    
    <div class="header-info">
        <p><strong>Объект:</strong> {{ $entry->journal->project->name ?? '-' }}</p>
        <p><strong>Журнал №:</strong> {{ $entry->journal->journal_number ?? $entry->journal->id }}</p>
        <p><strong>Запись №:</strong> {{ $entry->entry_number }}</p>
        <p><strong>Дата:</strong> {{ $entry->entry_date->format('d.m.Y') }}</p>
        <p><strong>Статус:</strong> 
            <span class="status-badge status-{{ $entry->status->value }}">
                {{ $entry->status->label() }}
            </span>
        </p>
    </div>
    
    <div class="section">
        <h2>Описание выполненных работ</h2>
        <p>{{ $entry->work_description }}</p>
    </div>
    
    @if($entry->workVolumes->count() > 0)
    <div class="section">
        <h2>Объемы выполненных работ</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 50%;">Наименование работ</th>
                    <th style="width: 15%;">Количество</th>
                    <th style="width: 15%;">Ед. изм.</th>
                    <th style="width: 20%;">Примечания</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entry->workVolumes as $volume)
                <tr>
                    <td>
                        @if($volume->workType)
                            {{ $volume->workType->name }}
                        @elseif($volume->estimateItem)
                            {{ $volume->estimateItem->name }}
                        @else
                            -
                        @endif
                    </td>
                    <td style="text-align: right;">{{ $volume->quantity }}</td>
                    <td>{{ $volume->measurementUnit?->short_name ?? '-' }}</td>
                    <td>{{ $volume->notes ?? '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    
    @if($entry->workers->count() > 0)
    <div class="section">
        <h2>Рабочие</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 50%;">Специальность</th>
                    <th style="width: 25%;">Количество</th>
                    <th style="width: 25%;">Отработано часов</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entry->workers as $worker)
                <tr>
                    <td>{{ $worker->specialty }}</td>
                    <td style="text-align: center;">{{ $worker->workers_count }} чел.</td>
                    <td style="text-align: center;">{{ $worker->hours_worked ?? '-' }}</td>
                </tr>
                @endforeach
                <tr style="font-weight: bold;">
                    <td>ИТОГО:</td>
                    <td style="text-align: center;">{{ $entry->workers->sum('workers_count') }} чел.</td>
                    <td style="text-align: center;">{{ $entry->workers->sum('hours_worked') ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    @endif
    
    @if($entry->equipment->count() > 0)
    <div class="section">
        <h2>Используемое оборудование</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 40%;">Наименование</th>
                    <th style="width: 30%;">Тип</th>
                    <th style="width: 15%;">Количество</th>
                    <th style="width: 15%;">Часов работы</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entry->equipment as $item)
                <tr>
                    <td>{{ $item->equipment_name }}</td>
                    <td>{{ $item->equipment_type ?? '-' }}</td>
                    <td style="text-align: center;">{{ $item->quantity }}</td>
                    <td style="text-align: center;">{{ $item->hours_used ?? '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    
    @if($entry->materials->count() > 0)
    <div class="section">
        <h2>Израсходованные материалы</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 50%;">Наименование</th>
                    <th style="width: 20%;">Количество</th>
                    <th style="width: 15%;">Ед. изм.</th>
                    <th style="width: 15%;">Примечания</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entry->materials as $material)
                <tr>
                    <td>{{ $material->material_name }}</td>
                    <td style="text-align: right;">{{ $material->quantity }}</td>
                    <td>{{ $material->measurement_unit }}</td>
                    <td>{{ $material->notes ?? '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    
    @if($entry->weather_conditions)
    <div class="section">
        <h2>Погодные условия</h2>
        <p>
            <strong>Температура:</strong> {{ $entry->weather_conditions['temperature'] ?? '-' }}°C<br>
            @if(isset($entry->weather_conditions['precipitation']))
            <strong>Осадки:</strong> {{ $entry->weather_conditions['precipitation'] }}<br>
            @endif
            @if(isset($entry->weather_conditions['wind_speed']))
            <strong>Ветер:</strong> {{ $entry->weather_conditions['wind_speed'] }} м/с<br>
            @endif
        </p>
    </div>
    @endif
    
    @if($entry->problems_description)
    <div class="section">
        <h2>Проблемы и задержки</h2>
        <p>{{ $entry->problems_description }}</p>
    </div>
    @endif
    
    @if($entry->safety_notes)
    <div class="section">
        <h2>Вопросы техники безопасности</h2>
        <p>{{ $entry->safety_notes }}</p>
    </div>
    @endif
    
    @if($entry->quality_notes)
    <div class="section">
        <h2>Контроль качества</h2>
        <p>{{ $entry->quality_notes }}</p>
    </div>
    @endif
    
    <div class="footer">
        <div class="signature">
            <p><strong>Составил:</strong> {{ $entry->createdBy->name ?? '-' }} 
                <span class="signature-line"></span>
                <small>(подпись)</small>
            </p>
        </div>
        
        @if($entry->approvedBy)
        <div class="signature">
            <p><strong>Утвердил:</strong> {{ $entry->approvedBy->name }} 
                <span class="signature-line"></span>
                <small>(подпись)</small>
            </p>
            <p><small>Дата утверждения: {{ $entry->approved_at->format('d.m.Y H:i') }}</small></p>
        </div>
        @endif
    </div>
</body>
</html>

