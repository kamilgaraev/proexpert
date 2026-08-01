<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;
use Illuminate\Database\Eloquent\Model;
final class ReportSubscriptionDeliveryRecord extends Model { protected $table='report_subscription_deliveries'; public $incrementing=false; protected $keyType='string'; protected $guarded=[]; protected $casts=['organization_id'=>'integer','owner_id'=>'integer','scheduled_for'=>'immutable_datetime','execution_expires_at'=>'immutable_datetime','retention_delete_after'=>'immutable_datetime','retry_at'=>'immutable_datetime','notified_at'=>'immutable_datetime','created_at'=>'immutable_datetime','updated_at'=>'immutable_datetime']; }
