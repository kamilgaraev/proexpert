<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Приглашение для сотрудничества</title>
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
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 30px;
        }
        .content {
            padding: 20px 0;
        }
        .invitation-details {
            background-color: #e3f2fd;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #2196f3;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin: 10px 5px;
            font-weight: bold;
        }
        .button:hover {
            background-color: #1976d2;
        }
        .button.secondary {
            background-color: #757575;
        }
        .button.secondary:hover {
            background-color: #616161;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 14px;
            color: #666;
        }
        .expires-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Приглашение для сотрудничества</h1>
        <p>Компания <strong>{{ $organizationName }}</strong> приглашает вас к сотрудничеству</p>
    </div>

    <div class="content">
        <p>Здравствуйте!</p>

        <p>Пользователь <strong>{{ $invitedBy }}</strong> от имени компании <strong>{{ $organizationName }}</strong> направляет вам приглашение для сотрудничества в качестве подрядчика.</p>

        @if($message)
            <div class="invitation-details">
                <h3>Сообщение от организации:</h3>
                <p>{{ $message }}</p>
            </div>
        @endif

        <div class="expires-warning">
            <strong>⏰ Важно:</strong> Приглашение действительно до {{ $expiresAt->format('d.m.Y в H:i') }}
        </div>

        <p>Для принятия или отклонения приглашения перейдите по ссылке:</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $invitationUrl }}" class="button">Рассмотреть приглашение</a>
        </div>

        <p><strong>Что это означает?</strong></p>
        <ul>
            <li>При принятии приглашения ваша организация будет добавлена в список подрядчиков</li>
            <li>Вы сможете получать заказы и заключать договоры с {{ $organizationName }}</li>
            <li>Ваши данные будут синхронизированы автоматически</li>
            <li>Сотрудничество взаимовыгодное - {{ $organizationName }} также будет добавлена в ваш список контрагентов</li>
        </ul>
    </div>

    <div class="footer">
        <p>Если у вас возникли вопросы, обратитесь в службу поддержки или свяжитесь напрямую с {{ $organizationName }}.</p>
        
        <p>Если вы не планируете сотрудничать с данной организацией, просто проигнорируйте это письмо.</p>
        
        <p><small>Это автоматическое сообщение. Пожалуйста, не отвечайте на него.</small></p>
    </div>
</body>
</html>