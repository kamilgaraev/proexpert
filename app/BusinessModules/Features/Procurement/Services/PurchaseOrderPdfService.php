<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class PurchaseOrderPdfService
{
    public function __construct(
        private readonly FileService $files,
    ) {}

    /**
     * Сгенерировать PDF заказа поставщику
     */
    public function generate(PurchaseOrder $order): string
    {
        if (!$order->relationLoaded('organization')) {
            $order->load('organization');
        }
        if (!$order->relationLoaded('supplier')) {
            $order->load('supplier');
        }
        
        $organization = $order->organization;
        $supplier = $order->supplier;

        // Формируем строку организации (Заказчик)
        $orgParts = [];
        $orgParts[] = $organization->legal_name ?: $organization->name;

        $orgInn = $organization->tax_number ?: ($organization->inn ?? null);
        if ($orgInn) $orgParts[] = "ИНН " . $orgInn;
        
        $orgOgrn = $organization->registration_number ?: ($organization->ogrn ?? null);
        if ($orgOgrn) $orgParts[] = "ОГРН " . $orgOgrn;
        
        if ($organization->address) $orgParts[] = $organization->address;
        if ($organization->phone) $orgParts[] = "тел.: " . $organization->phone;
        
        $orgString = implode(', ', array_filter($orgParts));

        // Формируем строку поставщика (Исполнитель)
        $supParts = [];
        $supParts[] = $supplier->name ?: 'Не указано';

        $supInn = $supplier->tax_number ?: ($supplier->inn ?? null);
        if ($supInn) $supParts[] = "ИНН " . $supInn;
        
        if ($supplier->address) $supParts[] = $supplier->address;
        if ($supplier->phone) $supParts[] = "тел.: " . $supplier->phone;
        
        $supplierString = implode(', ', array_filter($supParts));

        $data = [
            'order' => $order,
            'organization' => $organization,
            'supplier' => $supplier,
            'organization_string' => $orgString,
            'supplier_string' => $supplierString,
            'items' => $order->items ?? [],
            'total_amount_words' => $this->num2str($order->total_amount),
            'date_formatted' => Carbon::parse($order->order_date)->translatedFormat('d F Y'),
        ];

        $pdf = Pdf::loadView('procurement.purchase-order', $data);
        $pdf->setPaper('a4', 'landscape');

        return $pdf->output();
    }

    /**
     * Сохранить PDF в S3 и вернуть путь
     */
    public function store(PurchaseOrder $order, ?int $actorId = null): CurrentStoredFile
    {
        $content = $this->generate($order);
        $organizationId = (int) $order->organization_id;
        $orderId = (int) $order->getKey();
        $actorScope = $actorId !== null && $actorId > 0 ? "user-{$actorId}" : 'system';
        $path = OrganizationStoragePath::forDomain(
            $organizationId,
            'procurement',
            "purchase-orders/{$actorScope}/order-{$orderId}",
            Str::uuid()->toString(),
            'pdf',
        );

        return $this->files->putPrivate(
            $path,
            $content,
            'application/pdf',
            hash('sha256', $content),
        );
    }

    public function read(PurchaseOrder $order, string $path): string
    {
        $this->assertOwnedPath($order, $path);

        $stream = $this->files->readCurrent($path);

        try {
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new RuntimeException('purchase_order_pdf_read_failed');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    public function remove(PurchaseOrder $order, string $path): void
    {
        $this->assertOwnedPath($order, $path);
        $this->files->deleteCurrent($path);
    }

    private function assertOwnedPath(PurchaseOrder $order, string $path): void
    {
        $pattern = sprintf(
            '#^org-%d/procurement/purchase-orders/(?:user-[1-9][0-9]*|system)/order-%d/[^/]+\.pdf$#D',
            (int) $order->organization_id,
            (int) $order->getKey(),
        );

        if (preg_match($pattern, $path) !== 1) {
            throw new InvalidArgumentException('purchase_order_pdf_path_invalid');
        }
    }

    /**
     * Конвертация суммы прописью
     */
    private function num2str($num): string
    {
        $nul='ноль';
        $ten=array(
            array('','один','два','три','четыре','пять','шесть','семь','восемь','девять'),
            array('','одна','две','три','четыре','пять','шесть','семь','восемь','девять'),
        );
        $a20=array('десять','одиннадцать','двенадцать','тринадцать','четырнадцать' ,'пятнадцать','шестнадцать','семнадцать','восемьнадцать','девятнадцать');
        $tens=array(2=>'двадцать','тридцать','сорок','пятьдесят','шестьдесят','семьдесят' ,'восемьдесят','девяносто');
        $hundred=array('','сто','двести','триста','четыреста','пятьсот','шестьсот', 'семьсот','восемьсот','девятьсот');
        $unit=array( 
            array('копейка' ,'копейки' ,'копеек',	 1),
            array('рубль'   ,'рубля'   ,'рублей'   ,  0),
            array('тысяча'  ,'тысячи'  ,'тысяч'    ,  1),
            array('миллион' ,'миллиона','миллионов' ,  0),
            array('миллиард','милиарда','миллиардов',  0),
        );

        list($rub,$kop) = explode('.',sprintf("%015.2f", floatval($num)));
        $out = array();
        if (intval($rub)>0) {
            foreach(str_split($rub,3) as $uk=>$v) { 
                if (!intval($v)) continue;
                $uk = sizeof($unit)-$uk-1; 
                $gender = $unit[$uk][3];
                list($i1,$i2,$i3) = array_map('intval',str_split($v,1));
                $out[] = $hundred[$i1]; 
                if ($i2>1) $out[]= $tens[$i2].' '.$ten[$gender][$i3]; 
                else $out[]= ($i2>0) ? $a20[$i3] : $ten[$gender][$i3]; 
                if ($uk>1) $out[]= $this->morph($v,$unit[$uk][0],$unit[$uk][1],$unit[$uk][2]);
            }
        }
        else $out[] = $nul;
        $out[] = $this->morph(intval($rub),$unit[1][0],$unit[1][1],$unit[1][2]); 
        $out[] = $kop.' '.$this->morph($kop,$unit[0][0],$unit[0][1],$unit[0][2]);
        $res = trim(preg_replace('/ {2,}/', ' ', join(' ',$out)));
        return mb_strtoupper(mb_substr($res, 0, 1)) . mb_substr($res, 1);
    }

    private function morph($n,$f1,$f2,$f5) {
        $n = abs(intval($n)) % 100;
        if ($n>10 && $n<20) return $f5;
        $n = $n % 10;
        if ($n>1 && $n<5) return $f2;
        if ($n==1) return $f1;
        return $f5;
    }
}
