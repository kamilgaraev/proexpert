<x-email-layout title="Новая заявка с сайта МОСТ">
<p><strong>Имя:</strong> {{ $contactForm->name }}</p>
    <p><strong>Email:</strong> {{ $contactForm->email }}</p>
    <p><strong>Телефон:</strong> {{ $contactForm->phone ?: 'Не указан' }}</p>
    <p><strong>Компания:</strong> {{ $contactForm->company ?: 'Не указана' }}</p>
    <p><strong>Роль компании:</strong> {{ $contactForm->company_role ?: 'Не указана' }}</p>
    <p><strong>Размер компании:</strong> {{ $contactForm->company_size ?: 'Не указан' }}</p>
    <p><strong>Тема:</strong> {{ $contactForm->subject }}</p>
    <p><strong>Страница:</strong> {{ $contactForm->page_source }}</p>

    <h2 style="font-size:16px;margin:24px 0 12px;">Сообщение</h2>
    <p>{!! nl2br(e($contactForm->message)) !!}</p>

    <h2 style="font-size:16px;margin:24px 0 12px;">UTM</h2>
    <p><strong>utm_source:</strong> {{ $contactForm->utm_source ?: 'Не указан' }}</p>
    <p><strong>utm_medium:</strong> {{ $contactForm->utm_medium ?: 'Не указан' }}</p>
    <p><strong>utm_campaign:</strong> {{ $contactForm->utm_campaign ?: 'Не указан' }}</p>
    <p><strong>utm_term:</strong> {{ $contactForm->utm_term ?: 'Не указан' }}</p>
    <p><strong>utm_content:</strong> {{ $contactForm->utm_content ?: 'Не указан' }}</p>
</x-email-layout>
