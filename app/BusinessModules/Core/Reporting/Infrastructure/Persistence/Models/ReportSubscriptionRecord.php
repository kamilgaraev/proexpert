<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\SoftDeletes;
final class ReportSubscriptionRecord extends Model { use SoftDeletes; protected $table='report_subscriptions'; public $incrementing=false; protected $keyType='string'; protected $guarded=[]; protected $casts=['organization_id'=>'integer','owner_id'=>'integer','period_policy_json'=>'array','next_run_at'=>'immutable_datetime','created_at'=>'immutable_datetime','updated_at'=>'immutable_datetime','deleted_at'=>'immutable_datetime']; }
