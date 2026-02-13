<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Services\Storage\OrgBucketService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class PurchaseOrderPdfService
{
    public function __construct(
        private readonly OrgBucketService $bucketService
    ) {}

    /**
     * Сгенерировать PDF заказа поставщику
     */
    public function generate(PurchaseOrder $order): string
    {
        $data = [
            'order' => $order,
            'organization' => $order->organization,
            'supplier' => $order->supplier,
            'items' => $order->items ?? [],
            'total_amount_words' => $this->num2str($order->total_amount),
            'date_formatted' => Carbon::parse($order->order_date)->translatedFormat('d F Y'),
        ];

        $pdf = Pdf::loadView('procurement.purchase-order', $data);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    /**
     * Сохранить PDF в S3 и вернуть путь
     */
    public function store(PurchaseOrder $order): string
    {
        $content = $this->generate($order);
        
        $path = "procurement/orders/{$order->order_number}.pdf";
        
        $disk = $this->bucketService->getDisk($order->organization);
        $disk->put($path, $content);
        
        return $path;
    }

    /**
     * Получить публичный URL файла в S3
     */
    public function getUrl(PurchaseOrder $order, string $path): string
    {
        $disk = $this->bucketService->getDisk($order->organization);
        
        return $disk->url($path);
    }

    /**
     * Получить временный URL для скачивания (signed URL)
     */
    public function getTemporaryUrl(PurchaseOrder $order, string $path, int $minutes = 60): string
    {
        $disk = $this->bucketService->getDisk($order->organization);
        
        return $disk->temporaryUrl($path, now()->addMinutes($minutes));
    }

    /**
     * Конвертация суммы прописью
     */
    private function num2str($num): string
    {
        return number_format($num, 2, ',', ' ') . ' руб.';
    }
}

