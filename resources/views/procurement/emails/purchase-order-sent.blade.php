<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Заказ поставщику</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;">
            Заказ поставщику №{{ $order->order_number }}
        </h2>
        
        <p>Уважаемый партнер <strong>{{ $supplier->name }}</strong>,</p>
        
        <p>Направляем Вам заказ поставщику от компании <strong>{{ $organization->name }}</strong>.</p>
        
        <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #495057;">Детали заказа:</h3>
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
            <h3 style="color: #495057;">Список материалов:</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="background-color: #f8f9fa;">
                        <th style="border: 1px solid #dee2e6; padding: 10px; text-align: left; font-size: 14px;">Материал</th>
                        <th style="border: 1px solid #dee2e6; padding: 10px; text-align: center; font-size: 14px; width: 100px;">Кол-во</th>
                        <th style="border: 1px solid #dee2e6; padding: 10px; text-align: center; font-size: 14px; width: 60px;">Ед.</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                    <tr>
                        <td style="border: 1px solid #dee2e6; padding: 10px; font-size: 14px;">{{ $item->material_name }}</td>
                        <td style="border: 1px solid #dee2e6; padding: 10px; text-align: center; font-size: 14px;">{{ number_format($item->quantity, 2, ',', ' ') }}</td>
                        <td style="border: 1px solid #dee2e6; padding: 10px; text-align: center; font-size: 14px;">{{ $item->unit }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
        
        @if($order->notes)
        <div style="margin: 20px 0;">
            <h4 style="color: #495057;">Примечания:</h4>
            <p style="background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffc107;">
                {{ $order->notes }}
            </p>
        </div>
        @endif
        
        <p>Пожалуйста, подтвердите получение заказа и предоставьте коммерческое предложение в ближайшее время.</p>
        
        <p>Подробная информация и спецификация находятся во вложенном PDF-документе.</p>
        
        <hr style="border: none; border-top: 1px solid #dee2e6; margin: 30px 0;">
        
        <p style="color: #6c757d; font-size: 12px;">
            С уважением,<br>
            <strong>{{ $organization->name }}</strong><br>
            <em>Это письмо сформировано автоматически. Пожалуйста, не отвечайте на него напрямую.</em>
        </p>
    </div>
</body>
</html>
