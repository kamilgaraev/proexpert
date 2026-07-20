<?php
declare(strict_types=1); namespace App\BusinessModules\Features\LegalArchive\Models; use Illuminate\Database\Eloquent\Model;
final class LegalDocumentNotificationDelivery extends Model { protected $fillable=['document_id','recipient_user_id','delivery_key','status','lease_expires_at','delivered_at']; protected $casts=['lease_expires_at'=>'datetime','delivered_at'=>'datetime']; }
