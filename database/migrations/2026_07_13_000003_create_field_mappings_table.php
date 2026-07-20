<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity');            // orders, customers, ...
            $table->string('source_field');      // dot-path into the incoming payload
            $table->string('target_field');      // canonical / action-input field (dot-path)
            $table->json('transform')->nullable();
            $table->string('status')->default('review'); // validated, review, warning
            $table->timestamps();

            $table->index(['workspace_id', 'connector_id', 'entity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_mappings');
    }
};
