<?php
declare(strict_types=1);
namespace App\Services\LegalArchive;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
final class LegalDocumentNotificationPublisher {
 public function publish(LegalArchiveDocument $document, User $recipient, string $key, Notification $notification): void {
  $published = DB::transaction(function () use ($document,$key): bool { $locked=LegalArchiveDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail(); $metadata=is_array($locked->metadata)?$locked->metadata:[]; $keys=array_values(array_filter((array)($metadata['notification_keys']??[]),'is_string')); if(in_array($key,$keys,true)) return false; $keys=array_slice([...$keys,$key],-100); $locked->forceFill(['metadata'=>[...$metadata,'notification_keys'=>$keys]])->save(); return true; });
  if(!$published) return;
  try { $recipient->notify($notification); } catch (\Throwable $error) { DB::transaction(function () use ($document,$key): void { $locked=LegalArchiveDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail(); $metadata=is_array($locked->metadata)?$locked->metadata:[]; $locked->forceFill(['metadata'=>[...$metadata,'notification_keys'=>array_values(array_filter((array)($metadata['notification_keys']??[]),static fn($existing): bool => $existing !== $key))]])->save(); }); throw $error; }
 }
}
