<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчет системного анализа - {{ $project->name ?? 'Организация' }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #333;
        }
        
        .header {
            text-align: center;
            padding: 20px 0;
            border-bottom: 3px solid #2563eb;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 20pt;
            color: #1e40af;
            margin-bottom: 10px;
        }
        
        .header .subtitle {
            font-size: 12pt;
            color: #666;
        }
        
        .summary-box {
            background: #f3f4f6;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 5px solid #2563eb;
        }
        
        .score-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 16pt;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .score-good { background: #10b981; color: white; }
        .score-warning { background: #f59e0b; color: white; }
        .score-critical { background: #ef4444; color: white; }
        
        .section {
            margin-bottom: 40px;
            page-break-inside: avoid;
        }
        
        .section-header {
            background: #ede9fe;
            padding: 12px;
            border-left: 5px solid #7c3aed;
            margin-bottom: 15px;
        }
        
        .section-header h2 {
            font-size: 14pt;
            color: #5b21b6;
        }
        
        .section-content {
            padding: 10px;
        }
        
        .section-score {
            font-size: 24pt;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .recommendations {
            background: #fef3c7;
            padding: 15px;
            border-left: 4px solid #f59e0b;
            margin-top: 15px;
        }
        
        .recommendations h3 {
            color: #92400e;
            font-size: 12pt;
            margin-bottom: 10px;
        }
        
        .recommendation-item {
            margin: 8px 0;
            padding-left: 15px;
        }
        
        .priority-high { color: #dc2626; font-weight: bold; }
        .priority-medium { color: #f59e0b; }
        .priority-low { color: #10b981; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        table th, table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        table th {
            background: #f3f4f6;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            color: #666;
            font-size: 9pt;
        }
        
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <!-- Титульная страница -->
    <div class="header">
        <h1>Системный анализ проекта</h1>
        @if($project)
            <div class="subtitle">{{ $project->name }}</div>
        @endif
        <div class="subtitle" style="margin-top: 10px;">{{ $generated_at }}</div>
    </div>

    <!-- Исполнительное резюме -->
    <div class="summary-box">
        <h2 style="margin-bottom: 15px;">Общая оценка</h2>
        
        <div>
            Оценка здоровья проекта: 
            <span class="score-badge score-{{ $report->overall_status }}">
                {{ $report->overall_score }}/100
            </span>
        </div>
        
        <div style="margin-top: 10px;">
            Статус: 
            @if($report->overall_status === 'good')
                <strong style="color: #10b981;">Проект в хорошем состоянии</strong>
            @elseif($report->overall_status === 'warning')
                <strong style="color: #f59e0b;">Требуется внимание</strong>
            @else
                <strong style="color: #ef4444;">Критическое состояние</strong>
            @endif
        </div>
        
        @if($project)
        <table style="margin-top: 15px;">
            <tr>
                <th>Адрес</th>
                <td>{{ $project->address }}</td>
            </tr>
            <tr>
                <th>Бюджет</th>
                <td>{{ number_format($project->budget_amount, 2, ',', ' ') }} руб.</td>
            </tr>
            <tr>
                <th>Сроки</th>
                <td>
                    @if($project->start_date && $project->end_date)
                        {{ $project->start_date->format('d.m.Y') }} - {{ $project->end_date->format('d.m.Y') }}
                    @else
                        Не указаны
                    @endif
                </td>
            </tr>
            <tr>
                <th>Статус</th>
                <td>{{ $project->status }}</td>
            </tr>
        </table>
        @endif
    </div>

    <div class="page-break"></div>

    <!-- Разделы анализа -->
    @foreach($sections as $section)
        <div class="section">
            <div class="section-header">
                <h2>{{ $section->getSectionIcon() }} {{ $section->getSectionName() }}</h2>
            </div>
            
            <div class="section-content">
                <div>
                    <strong>Оценка раздела:</strong> 
                    <span class="section-score" style="color: {{ $section->getStatusColor() }};">
                        {{ $section->score }}/100
                    </span>
                </div>
                
                @if($section->summary)
                    <div style="margin: 15px 0; padding: 10px; background: #f9fafb; border-radius: 4px;">
                        <strong>Резюме:</strong> {{ $section->summary }}
                    </div>
                @endif
                
                @if($section->analysis)
                    <div style="margin: 15px 0;">
                        <strong>Анализ:</strong>
                        <p style="margin-top: 8px;">{{ $section->analysis }}</p>
                    </div>
                @endif
                
                @if($section->recommendations && count($section->recommendations) > 0)
                    <div class="recommendations">
                        <h3>💡 Рекомендации</h3>
                        @foreach($section->recommendations as $index => $recommendation)
                            <div class="recommendation-item">
                                <span class="priority-{{ $recommendation['priority'] ?? 'medium' }}">
                                    {{ $index + 1 }}.
                                </span>
                                {{ $recommendation['action'] ?? $recommendation['recommendation'] ?? 'Рекомендация' }}
                                @if(isset($recommendation['impact']))
                                    <br><em style="color: #666; font-size: 10pt;">Эффект: {{ $recommendation['impact'] }}</em>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        
        @if(!$loop->last)
            <div style="margin: 30px 0; border-top: 2px dashed #e5e7eb;"></div>
        @endif
    @endforeach

    <!-- Подвал -->
    <div class="footer">
        <div>Отчет сгенерирован автоматически системой ProHelper</div>
        <div>{{ $generated_at }}</div>
        <div style="margin-top: 10px;">
            ID отчета: {{ $report->id }} | 
            Токенов использовано: {{ $report->tokens_used }} | 
            Стоимость: {{ number_format($report->cost, 2, ',', ' ') }} руб.
        </div>
    </div>
</body>
</html>

