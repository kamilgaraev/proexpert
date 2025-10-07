<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
                $table->json('channels')->after('priority');
            }
            
            if (!Schema::hasColumn('notifications', 'delivery_status')) {
                $table->json('delivery_status')->nullable()->after('channels');
            }
            
            if (!Schema::hasColumn('notifications', 'metadata')) {
                $table->json('metadata')->nullable()->after('delivery_status');
            }
        });

        if (!Schema::hasIndex('notifications', ['organization_id'])) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('organization_id');
            });
        }
        
        if (!Schema::hasIndex('notifications', ['notification_type'])) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('notification_type');
            });
        }
        
        if (!Schema::hasIndex('notifications', ['priority'])) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('priority');
            });
        }
        
        if (!Schema::hasIndex('notifications', ['read_at'])) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('read_at');
            });
        }
        
        if (!Schema::hasIndex('notifications', ['created_at'])) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index('created_at');
            });
        }

        if (Schema::hasColumn('notifications', 'organization_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                if (!$this->hasForeignKey('notifications', 'notifications_organization_id_foreign')) {
                    $table->foreign('organization_id')
                        ->references('id')
                        ->on('organizations')
                        ->onDelete('cascade');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
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

    protected function hasForeignKey(string $table, string $foreignKey): bool
    {
        $conn = Schema::getConnection();
        $dbSchemaManager = $conn->getDoctrineSchemaManager();
        $foreignKeys = $dbSchemaManager->listTableForeignKeys($table);
        
        foreach ($foreignKeys as $fk) {
            if ($fk->getName() === $foreignKey) {
                return true;
            }
        }
        
        return false;
    }
};

