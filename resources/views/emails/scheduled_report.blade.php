<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $report_name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #3b82f6;
            color: white;
            padding: 20px;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #f9fafb;
            padding: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 0 0 5px 5px;
        }
        .footer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
        }
        h1 {
            margin: 0;
            font-size: 24px;
        }
        .info {
            margin: 15px 0;
        }
        .label {
            font-weight: bold;
            color: #374151;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Автоматический отчет</h1>
    </div>
    
    <div class="content">
        <div class="info">
            <span class="label">Название отчета:</span> {{ $report_name }}
        </div>
        
        @if($report_description)
        <div class="info">
            <span class="label">Описание:</span> {{ $report_description }}
        </div>
        @endif
        
        <div class="info">
            <span class="label">Дата и время генерации:</span> {{ $generated_at }}
        </div>
        
        <p>
            Отчет прикреплен к этому письму. Если у вас возникли вопросы или нужна дополнительная информация, 
            пожалуйста, свяжитесь с администратором системы.
        </p>
    </div>
    
    <div class="footer">
        <p>Это автоматически сгенерированное письмо. Пожалуйста, не отвечайте на него.</p>
        <p>&copy; {{ date('Y') }} ProHelper. Все права защищены.</p>
    </div>
</body>
</html>

