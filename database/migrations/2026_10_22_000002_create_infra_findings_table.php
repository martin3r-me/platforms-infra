<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->foreignId('infra_server_id')->constrained('infra_servers')->cascadeOnDelete();

            // Identity of a finding: (server, check, subject). The same problem seen again
            // updates the row instead of creating a new one.
            $table->string('check_key', 64);
            $table->string('subject')->default('');
            $table->char('fingerprint', 64);

            $table->string('severity', 16)->default('warning');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->json('sample')->nullable();

            $table->string('status', 16)->default('open');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['infra_server_id', 'fingerprint'], 'infra_findings_server_fp_uq');
            $table->index(['team_id', 'status', 'severity'], 'infra_findings_team_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_findings');
    }
};
