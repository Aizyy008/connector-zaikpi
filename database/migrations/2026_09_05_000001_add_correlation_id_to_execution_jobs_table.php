<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-requested fix (2026-09-05 review): "RunExecutionJob needs to propagate the correlation
 * ID into the ExecutionContext, and the correlation ID must continue through the ZaiKPI
 * measurement request so that a run can be traced from source → Connector → ZaiKPI." There was
 * previously nowhere on `ExecutionJob` to hold one — this adds it. Logged in
 * `project_2_v1_files/docs/07-modification-register.md` as a client-requested, client-approved
 * touch to `RunExecutionJob.php`/`ExecutionJob.php` (core platform files, not adapter code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_jobs', function (Blueprint $table) {
            $table->string('correlation_id')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('execution_jobs', function (Blueprint $table) {
            $table->dropColumn('correlation_id');
        });
    }
};
