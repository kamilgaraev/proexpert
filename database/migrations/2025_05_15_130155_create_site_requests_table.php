<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\SiteRequestTypeEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('user_id')->comment('ID пользователя (прораба), создавшего заявку')->constrained('users')->onDelete('cascade');
            
            $table->string('title');
            $table->text('description')->nullable();
            
            $table->string('status')->default(SiteRequestStatusEnum::DRAFT->value);
            $table->string('priority')->default(SiteRequestPriorityEnum::MEDIUM->value);
            $table->string('request_type')->default(SiteRequestTypeEnum::OTHER->value);
            
            $table->date('required_date')->nullable()->comment('Желаемая дата исполнения/получения');
            $table->text('notes')->nullable()->comment('Дополнительные примечания или комментарии к заявке');
            
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('priority');
            $table->index('request_type');
            $table->index('required_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_requests');
    }
};
