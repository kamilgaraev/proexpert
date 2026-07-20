<?php
declare(strict_types=1);
namespace App\Services\LegalArchive;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
final class LegalDocumentNotificationPublisher {
 public function publish(LegalArchiveDocument $document, User $recipient, string $key, Notification $notification): void {
  $token=Str::random(64); try { $delivery = DB::transaction(function () use ($document,$recipient,$key,$token): ?LegalDocumentNotificationDelivery { $row=LegalDocumentNotificationDelivery::query()->where(['document_id'=>$document->id,'recipient_user_id'=>$recipient->id,'delivery_key'=>$key])->lockForUpdate()->first(); if($row?->status==='delivered') return null; if($row!==null && $row->lease_expires_at?->isFuture()) return null; if($row===null) return LegalDocumentNotificationDelivery::query()->create(['document_id'=>$document->id,'recipient_user_id'=>$recipient->id,'delivery_key'=>$key,'status'=>'sending','lease_token'=>hash('sha256',$token),'lease_expires_at'=>now()->addMinutes(5)]); $row->forceFill(['status'=>'sending','lease_token'=>hash('sha256',$token),'lease_expires_at'=>now()->addMinutes(5)])->save(); return $row; }); } catch (QueryException) { return; }
  if($delivery===null) return; try { $recipient->notify($notification); LegalDocumentNotificationDelivery::query()->whereKey($delivery->id)->where('lease_token',hash('sha256',$token))->update(['status'=>'delivered','delivered_at'=>now(),'lease_expires_at'=>null,'lease_token'=>null]); } catch (\Throwable $error) { LegalDocumentNotificationDelivery::query()->whereKey($delivery->id)->where('lease_token',hash('sha256',$token))->update(['status'=>'failed','lease_expires_at'=>null,'lease_token'=>null]); throw $error; }
 }
}
