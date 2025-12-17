<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Общий журнал работ КС-6</title>
    <style>
        @page { margin: 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; }
        h1 { text-align: center; font-size: 14pt; margin-bottom: 20px; }
        .header-info { margin-bottom: 15px; }
        .header-info p { margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: left; }
        th { background-color: #e0e0e0; font-weight: bold; text-align: center; }
        .footer { margin-top: 30px; }
        .signature { margin-top: 10px; display: flex; justify-content: space-between; }
        .signature-line { border-bottom: 1px solid #000; width: 200px; display: inline-block; }
        .small-text { font-size: 8pt; }
    </style>
</head>
<body>
    <h1>ОБЩИЙ ЖУРНАЛ РАБОТ</h1>
    <p class="small-text" style="text-align: center;">Форма по ОКУД 0322006 (форма КС-6)</p>
    <p class="small-text" style="text-align: center;">Утверждена постановлением Госкомстата России от 11.11.99 № 100</p>
    
    <div class="header-info">
        <p><strong>Объект:</strong> {{ $journal->project->name ?? '-' }}</p>
        <p><strong>Журнал №:</strong> {{ $journal->journal_number ?? $journal->id }}</p>
        <p><strong>Период:</strong> с {{ $period_from->format('d.m.Y') }} по {{ $period_to->format('d.m.Y') }}</p>
        @if($journal->contract)
        <p><strong>Договор:</strong> № {{ $journal->contract->number }} от {{ $journal->contract->date->format('d.m.Y') }}</p>
        @endif
    </div>
    
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">№</th>
                <th style="width: 8%;">Дата</th>
                <th style="width: 35%;">Описание работ</th>
                <th style="width: 12%;">Объем работ</th>
                <th style="width: 15%;">Рабочие</th>
                <th style="width: 10%;">Погода</th>
                <th style="width: 10%;">Статус</th>
                <th style="width: 5%;">Подпись</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
            <tr>
                <td style="text-align: center;">{{ $entry->entry_number }}</td>
                <td style="text-align: center;">{{ $entry->entry_date->format('d.m.Y') }}</td>
                <td>
                    {{ $entry->work_description }}
                    @if($entry->quality_notes)
                    <br><small><em>Качество: {{ $entry->quality_notes }}</em></small>
                    @endif
                </td>
                <td>
                    @foreach($entry->workVolumes as $volume)
                        {{ $volume->quantity }} {{ $volume->measurementUnit?->short_name ?? '' }}
                        @if($volume->workType)
                            ({{ $volume->workType->name }})
                        @endif
                        <br>
                    @endforeach
                </td>
                <td>
                    @foreach($entry->workers as $worker)
                        {{ $worker->specialty }}: {{ $worker->workers_count }} чел.
                        @if($worker->hours_worked)
                            ({{ $worker->hours_worked }} ч.)
                        @endif
                        <br>
                    @endforeach
                </td>
                <td style="font-size: 8pt;">
                    @if($entry->weather_conditions)
                        {{ $entry->weather_conditions['temperature'] ?? '' }}°C
                        @if(isset($entry->weather_conditions['precipitation']))
                            <br>{{ $entry->weather_conditions['precipitation'] }}
                        @endif
                    @endif
                </td>
                <td style="text-align: center; font-size: 8pt;">{{ $entry->status->label() }}</td>
                <td></td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="text-align: center;">Нет записей за указанный период</td>
            </tr>
            @endforelse
        </tbody>
    </table>
    
    <div class="footer">
        <p><strong>Ответственный за ведение журнала:</strong></p>
        <div class="signature">
            <div>
                {{ $journal->createdBy?->name ?? '' }} 
                <span class="signature-line"></span>
                <small>(подпись)</small>
            </div>
            <div>
                Дата: <span class="signature-line"></span>
            </div>
        </div>
    </div>
</body>
</html>

