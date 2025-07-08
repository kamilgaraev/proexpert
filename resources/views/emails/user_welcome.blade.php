@component('mail::message')
# Добро пожаловать, {{ $user->name }}!

Спасибо за регистрацию в ProHelper. Мы рады видеть вас среди пользователей нашей системы.

@component('mail::button', ['url' => config('app.url').'/login'])
Перейти к входу
@endcomponent

Если у вас есть вопросы — отвечайте на это письмо.

С уважением,
Команда ProHelper
@endcomponent 