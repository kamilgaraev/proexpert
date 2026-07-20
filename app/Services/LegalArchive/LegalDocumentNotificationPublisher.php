<?php
declare(strict_types=1);
namespace App\Services\LegalArchive;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
final class LegalDocumentNotificationPublisher {
 public function publish(LegalArchiveDocument $document, User $recipient, string $key, Notification $notification): void {
  $delivery = DB::transaction(function () use ($document,$recipient,$key): ?LegalDocumentNotificationDelivery { $row=LegalDocumentNotificationDelivery::query()->where(['document_id'=>$document->id,'recipient_user_id'=>$recipient->id,'delivery_key'=>$key])->lockForUpdate()->first(); if($row?->status==='delivered') return null; if($row!==null && $row->lease_expires_at?->isFuture()) return null; return LegalDocumentNotificationDelivery::query()->updateOrCreate(['document_id'=>$document->id,'recipient_user_id'=>$recipient->id,'delivery_key'=>$key],['status'=>'sending','lease_expires_at'=>now()->addMinutes(5)]); });
  if($delivery===null) return; try { $recipient->notify($notification); $delivery->forceFill(['status'=>'delivered','delivered_at'=>now(),'lease_expires_at'=>null])->save(); } catch (\Throwable $error) { $delivery->forceFill(['status'=>'failed','lease_expires_at'=>null])->save(); throw $error; }
 }
}
