<x-email-layout title="Приглашение для сотрудничества">
<div>

        <p>Здравствуйте!</p>

        <p>Пользователь <strong>{{ $invitedBy }}</strong> от имени компании <strong>{{ $organizationName }}</strong> направляет вам приглашение для сотрудничества в качестве подрядчика.</p>

        @if($message)
            <div style="padding:16px;background-color:#F4F5F6;margin:20px 0;">
                <h3 style="font-size:16px;margin:0 0 12px;">Сообщение от организации:</h3>
                <p>{{ $message }}</p>
            </div>
        @endif

        <div style="padding:16px;background-color:#F4F5F6;margin:20px 0;">
            <strong>Срок действия:</strong> Приглашение действительно до {{ $expiresAt->format('d.m.Y в H:i') }}
        </div>

        <p>Для принятия или отклонения приглашения перейдите по ссылке:</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $invitationUrl }}" style="display:inline-block;padding:12px 24px;background-color:#B45309;color:#FFFFFF;text-decoration:none;border-radius:4px;font-weight:bold;">Рассмотреть приглашение</a>
        </div>

        <p><strong>Что это означает?</strong></p>
        <ul>
            <li>При принятии приглашения ваша организация будет добавлена в список подрядчиков</li>
            <li>Вы сможете получать заказы и заключать договоры с {{ $organizationName }}</li>
            <li>Ваши данные будут синхронизированы автоматически</li>
            <li>Сотрудничество взаимовыгодное - {{ $organizationName }} также будет добавлена в ваш список контрагентов</li>
        </ul>
    </div>

    <div style="border-top:1px solid #DBDFE2;margin-top:24px;padding-top:16px;color:#58636B;font-size:14px;">
        <p>Если у вас возникли вопросы, обратитесь в службу поддержки или свяжитесь напрямую с {{ $organizationName }}.</p>
        
        <p>Если вы не планируете сотрудничать с данной организацией, просто проигнорируйте это письмо.</p>
        
        <p><small>Это автоматическое сообщение. Пожалуйста, не отвечайте на него.</small></p>
    </div>
</x-email-layout>
