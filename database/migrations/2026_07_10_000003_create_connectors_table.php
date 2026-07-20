<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->default('other');   // ecommerce, business_system, marketing, platform, social, other
            $table->string('provider')->nullable();
            $table->string('role')->default('none');     // primary_source, secondary_source, action_system, outbound, none
            $table->string('status')->default('disconnected'); // healthy, warning, disconnected
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->string('last_health_status')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connectors');
    }
};
