<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION_STABLE_KEY_INDEX = 'estimate_sections_estimate_stable_key_idx';
    private const ITEM_STABLE_KEY_INDEX = 'estimate_items_estimate_stable_key_idx';

    public function up(): void
    {
        if (Schema::hasTable('estimate_sections')) {
            Schema::table('estimate_sections', function (Blueprint $table) {
                if (!Schema::hasColumn('estimate_sections', 'stable_key')) {
                    $table->uuid('stable_key')->nullable();
                }
            });

            Schema::table('estimate_sections', function (Blueprint $table) {
                if (!Schema::hasIndex('estimate_sections', self::SECTION_STABLE_KEY_INDEX)) {
                    $table->index(['estimate_id', 'stable_key'], self::SECTION_STABLE_KEY_INDEX);
                }
            });
        }

        if (Schema::hasTable('estimate_items')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                if (!Schema::hasColumn('estimate_items', 'stable_key')) {
                    $table->uuid('stable_key')->nullable();
                }
            });

            Schema::table('estimate_items', function (Blueprint $table) {
                if (!Schema::hasIndex('estimate_items', self::ITEM_STABLE_KEY_INDEX)) {
                    $table->index(['estimate_id', 'stable_key'], self::ITEM_STABLE_KEY_INDEX);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('estimate_sections')) {
            Schema::table('estimate_sections', function (Blueprint $table) {
                if (Schema::hasIndex('estimate_sections', self::SECTION_STABLE_KEY_INDEX)) {
                    $table->dropIndex(self::SECTION_STABLE_KEY_INDEX);
                }
            });

            if (Schema::hasColumn('estimate_sections', 'stable_key')) {
                Schema::table('estimate_sections', function (Blueprint $table) {
                    $table->dropColumn('stable_key');
                });
            }
        }

        if (Schema::hasTable('estimate_items')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                if (Schema::hasIndex('estimate_items', self::ITEM_STABLE_KEY_INDEX)) {
                    $table->dropIndex(self::ITEM_STABLE_KEY_INDEX);
                }
            });

            if (Schema::hasColumn('estimate_items', 'stable_key')) {
                Schema::table('estimate_items', function (Blueprint $table) {
                    $table->dropColumn('stable_key');
                });
            }
        }
    }
};
