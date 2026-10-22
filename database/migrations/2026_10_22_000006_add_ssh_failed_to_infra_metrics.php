<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('infra_metrics', function (Blueprint $table) {
            // Failed SSH logins since the previous observation (time series for "who wanted in").
            $table->unsignedInteger('ssh_failed')->nullable()->after('uptime_s');
        });
    }

    public function down(): void
    {
        Schema::table('infra_metrics', function (Blueprint $table) {
            $table->dropColumn('ssh_failed');
        });
    }
};
