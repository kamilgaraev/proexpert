<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('notifiable_id');
            }
            
            if (!Schema::hasColumn('notifications', 'notification_type')) {
                $table->string('notification_type', 50)->default('system')->after('organization_id');
            }
            
            if (!Schema::hasColumn('notifications', 'priority')) {
                $table->enum('priority', ['critical', 'high', 'normal', 'low'])->default('normal')->after('notification_type');
            }
            
            if (!Schema::hasColumn('notifications', 'channels')) {
                $table->json('channels')->nullable()->after('priority');
            }
            
            if (!Schema::hasColumn('notifications', 'delivery_status')) {
                $table->json('delivery_status')->nullable()->after('channels');
            }
            
            if (!Schema::hasColumn('notifications', 'metadata')) {
                $table->json('metadata')->nullable()->after('delivery_status');
            }
        });

        $indexes = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'notifications'");
        $existingIndexes = array_column($indexes, 'indexname');

        if (!in_array('notifications_organization_id_index', $existingIndexes)) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('organization_id');
            });
        }
        
        if (!in_array('notifications_notification_type_index', $existingIndexes)) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('notification_type');
            });
        }
        
        if (!in_array('notifications_priority_index', $existingIndexes)) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('priority');
            });
        }
        
        if (!in_array('notifications_read_at_index', $existingIndexes)) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('read_at');
            });
        }
        
        if (!in_array('notifications_created_at_index', $existingIndexes)) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('created_at');
            });
        }

        if (Schema::hasColumn('notifications', 'organization_id')) {
            $foreignKeys = DB::select("
                SELECT constraint_name 
                FROM information_schema.table_constraints 
                WHERE table_name = 'notifications' 
                AND constraint_type = 'FOREIGN KEY'
                AND constraint_name = 'notifications_organization_id_foreign'
            ");

            if (empty($foreignKeys)) {
                try {
                    Schema::table('notifications', function (Blueprint $table) {
                        $table->foreign('organization_id')
                            ->references('id')
                            ->on('organizations')
                            ->onDelete('cascade');
                    });
                } catch (\Exception $e) {
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });
        
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['notification_type']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['read_at']);
            $table->dropIndex(['created_at']);
            
            $table->dropColumn([
                'organization_id',
                'notification_type',
                'priority',
                'channels',
                'delivery_status',
                'metadata',
            ]);
        });
    }
};
