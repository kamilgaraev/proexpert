@component('mail::message')
# Подтверждение email

Здравствуйте, {{ $user->name }}!

Спасибо за регистрацию в ProHelper. Для завершения регистрации подтвердите ваш email адрес.

@component('mail::button', ['url' => $verificationUrl])
Подтвердить email
@endcomponent

Ссылка действительна в течение 60 минут.

Если вы не регистрировались в нашей системе, просто проигнорируйте это письмо.

С уважением,
Команда ProHelper
@endcomponent

