<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domain record of an execution — distinct from Laravel's internal `jobs`
     * queue table. A queued RunExecutionJob updates the matching row here.
     */
    public function up(): void
    {
        Schema::create('execution_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payload_id')->nullable()->constrained('webhook_payloads')->nullOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');                              // module slug / action identifier
            $table->string('status')->default('pending');        // pending, processing, completed, failed, held
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('queue_mode')->default('queue_first');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_jobs');
    }
};
