<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_servers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_in_charge_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('role')->nullable();
            $table->string('environment', 32)->default('production');
            $table->string('provider', 32)->default('other');
            $table->string('provider_ref')->nullable();
            $table->string('ip_public', 64)->nullable();
            $table->string('ip_private', 64)->nullable();
            $table->string('ssh_user', 64)->nullable();
            $table->unsignedSmallInteger('ssh_port')->nullable();
            $table->string('status', 32)->default('active');
            $table->text('notes')->nullable();

            // Latest state as reported by the observer (agent): denormalised for cheap dashboards.
            $table->timestamp('last_seen_at')->nullable();
            $table->string('os')->nullable();
            $table->json('last_report')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'name'], 'infra_servers_team_name_idx');
            $table->index(['team_id', 'status'], 'infra_servers_team_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_servers');
    }
};
