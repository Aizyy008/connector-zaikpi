<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete(); // null = global module
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('action'); // trigger, action, transform
            $table->text('description')->nullable();
            $table->json('actions')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->string('execution_method')->default('queue'); // sync, queue, webhook
            $table->json('scopes')->nullable();
            $table->string('health_status')->default('healthy'); // healthy, warning, unavailable
            $table->string('version')->default('1.0.0');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
