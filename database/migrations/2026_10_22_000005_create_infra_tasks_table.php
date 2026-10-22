<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            // Both optional: a task can concern one server, stem from one finding, or neither.
            $table->foreignId('infra_server_id')->nullable()->constrained('infra_servers')->nullOnDelete();
            $table->foreignId('infra_finding_id')->nullable()->constrained('infra_findings')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->unsignedInteger('position')->default(0);
            $table->date('due_date')->nullable();

            $table->foreignId('user_in_charge_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->text('done_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status', 'position'], 'infra_tasks_team_status_idx');
            $table->index(['infra_server_id', 'status'], 'infra_tasks_server_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_tasks');
    }
};
