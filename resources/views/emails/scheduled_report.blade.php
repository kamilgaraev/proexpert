<x-email-layout title="Автоматический отчёт">
<div style="margin:16px 0;">
            <span style="font-weight:bold;">Название отчета:</span> {{ $report_name }}
        </div>
        
        @if($report_description)
        <div style="margin:16px 0;">
            <span style="font-weight:bold;">Описание:</span> {{ $report_description }}
        </div>
        @endif
        
        <div style="margin:16px 0;">
            <span style="font-weight:bold;">Дата и время генерации:</span> {{ $generated_at }}
        </div>
        
        <p>
            Отчет прикреплен к этому письму. Если у вас возникли вопросы или нужна дополнительная информация, 
            пожалуйста, свяжитесь с администратором системы.
        </p>
</x-email-layout>
