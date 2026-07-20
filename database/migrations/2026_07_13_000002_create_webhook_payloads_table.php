<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('endpoint_id')->nullable()->constrained('webhook_endpoints')->nullOnDelete();
            $table->json('headers')->nullable();
            $table->longText('raw_payload');                  // exactly as received
            $table->json('parsed_payload')->nullable();       // decoded JSON
            $table->string('status')->default('received');    // received, valid, invalid, processed, failed
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_payloads');
    }
};
