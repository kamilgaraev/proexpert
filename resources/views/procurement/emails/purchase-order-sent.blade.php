<x-email-layout title="Заказ поставщику №{{ $order->order_number }}">
<p>Уважаемый партнер <strong>{{ $supplier->name }}</strong>,</p>
        
        <p>Направляем Вам заказ поставщику от компании <strong>{{ $organization->name }}</strong>.</p>
        
        <div style="background-color: #F4F5F6; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <h3 style="font-size:16px;margin-top: 0; color: #252C32;">Детали заказа:</h3>
            <ul style="list-style: none; padding: 0;">
                <li><strong>Номер заказа:</strong> {{ $order->order_number }}</li>
                <li><strong>Дата заказа:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('d.m.Y') }}</li>
                <li><strong>Сумма заказа:</strong> {{ number_format($order->total_amount, 2, ',', ' ') }} {{ $order->currency }}</li>
                @if($order->delivery_date)
                <li><strong>Желаемая дата поставки:</strong> {{ \Carbon\Carbon::parse($order->delivery_date)->format('d.m.Y') }}</li>
                @endif
            </ul>
        </div>
        
        @if(count($items) > 0)
        <div style="margin: 20px 0;">
            <h3 style="font-size:16px;color: #252C32;">Список материалов:</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="background-color: #F4F5F6;">
                        <th style="border: 1px solid #DBDFE2; padding: 10px; text-align: left; font-size: 14px;">Материал</th>
                        <th style="border: 1px solid #DBDFE2; padding: 10px; text-align: center; font-size: 14px; width: 100px;">Кол-во</th>
                        <th style="border: 1px solid #DBDFE2; padding: 10px; text-align: center; font-size: 14px; width: 60px;">Ед.</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                    <tr>
                        <td style="border: 1px solid #DBDFE2; padding: 10px; font-size: 14px;">{{ $item->material_name }}</td>
                        <td style="border: 1px solid #DBDFE2; padding: 10px; text-align: center; font-size: 14px;">{{ number_format($item->quantity, 2, ',', ' ') }}</td>
                        <td style="border: 1px solid #DBDFE2; padding: 10px; text-align: center; font-size: 14px;">{{ $item->unit }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
        
        @if($order->notes)
        <div style="margin: 20px 0;">
            <h4 style="font-size:16px;color: #252C32;">Примечания:</h4>
            <p style="background-color: #F4F5F6; padding: 10px; border-left: 4px solid #B45309;">
                {{ $order->notes }}
            </p>
        </div>
        @endif
        
        <p>Пожалуйста, подтвердите получение заказа и предоставьте коммерческое предложение в ближайшее время.</p>
        
        <p>Подробная информация и спецификация находятся во вложенном PDF-документе.</p>
        
        <hr style="border: none; border-top: 1px solid #DBDFE2; margin: 30px 0;">
        
        <p style="color: #58636B; font-size: 12px;">
            С уважением,<br>
            <strong>{{ $organization->name }}</strong><br>
            <em>Это письмо сформировано автоматически. Пожалуйста, не отвечайте на него напрямую.</em>
        </p>
</x-email-layout>
