<?php
declare(strict_types=1);
namespace App\Services\LegalArchive;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
final class LegalDocumentNotificationPublisher {
 public function publish(LegalArchiveDocument $document, User $recipient, string $key, Notification $notification): void {
  $published = DB::transaction(function () use ($document,$recipient,$key): bool { $locked=LegalArchiveDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail(); $metadata=is_array($locked->metadata)?$locked->metadata:[]; $keys=(array)($metadata['notification_keys']??[]); if(in_array($key,$keys,true)) return false; $locked->forceFill(['metadata'=>[...$metadata,'notification_keys'=>[...$keys,$key]]])->save(); return true; });
  if($published) $recipient->notify($notification);
 }
}
