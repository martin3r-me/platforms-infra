<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('infra_server_id')->constrained('infra_servers')->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('load1', 8, 2)->nullable();
            $table->unsignedSmallInteger('cpu_count')->nullable();
            $table->decimal('mem_used_pct', 5, 2)->nullable();
            $table->decimal('swap_used_pct', 5, 2)->nullable();
            $table->decimal('disk_used_pct', 5, 2)->nullable();
            $table->unsignedBigInteger('uptime_s')->nullable();

            $table->index(['infra_server_id', 'recorded_at'], 'infra_metrics_server_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_metrics');
    }
};
