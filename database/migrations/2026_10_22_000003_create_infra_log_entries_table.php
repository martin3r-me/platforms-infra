<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->foreignId('infra_server_id')->constrained('infra_servers')->cascadeOnDelete();
            $table->string('level', 16)->default('info');
            $table->string('source', 64)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['infra_server_id', 'occurred_at'], 'infra_logs_server_time_idx');
            $table->index(['team_id', 'level', 'occurred_at'], 'infra_logs_team_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_log_entries');
    }
};
