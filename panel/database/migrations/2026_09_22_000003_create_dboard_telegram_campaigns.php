<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dboard_telegram_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 16);
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->json('user_ids')->nullable();
            $table->text('message');
            $table->unsignedBigInteger('scheduled_at')->index();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('queued_count')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('started_at')->nullable();
            $table->unsignedBigInteger('finished_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dboard_telegram_campaigns');
    }
};
