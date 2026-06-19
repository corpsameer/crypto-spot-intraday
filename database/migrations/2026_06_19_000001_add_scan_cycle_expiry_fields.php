<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_watchlists', function (Blueprint $table): void {
            if (! Schema::hasColumn('candidate_watchlists', 'scan_run_id')) { $table->unsignedBigInteger('scan_run_id')->nullable()->index()->after('scanner_metric_id'); }
            if (! Schema::hasColumn('candidate_watchlists', 'scan_result_id')) { $table->unsignedBigInteger('scan_result_id')->nullable()->index()->after('scan_run_id'); }
            if (! Schema::hasColumn('candidate_watchlists', 'expiry_reason')) { $table->string('expiry_reason')->nullable()->index()->after('expires_at'); }
            if (! Schema::hasColumn('candidate_watchlists', 'expired_at')) { $table->timestamp('expired_at')->nullable()->index()->after('expiry_reason'); }
        });

        Schema::table('trade_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('trade_plans', 'expiry_reason')) { $table->string('expiry_reason')->nullable()->index()->after('expires_at'); }
            if (! Schema::hasColumn('trade_plans', 'expired_at')) { $table->timestamp('expired_at')->nullable()->index()->after('expiry_reason'); }
        });
    }

    public function down(): void
    {
        Schema::table('trade_plans', function (Blueprint $table): void {
            foreach (['expired_at', 'expiry_reason'] as $column) { if (Schema::hasColumn('trade_plans', $column)) { $table->dropColumn($column); } }
        });
        Schema::table('candidate_watchlists', function (Blueprint $table): void {
            foreach (['expired_at', 'expiry_reason', 'scan_result_id', 'scan_run_id'] as $column) { if (Schema::hasColumn('candidate_watchlists', $column)) { $table->dropColumn($column); } }
        });
    }
};
