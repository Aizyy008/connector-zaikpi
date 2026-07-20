<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connector_id')->constrained()->cascadeOnDelete();
            $table->string('key');                 // e.g. api_token, client_secret
            $table->text('value');                 // encrypted cast — never stored/rendered in plaintext
            $table->string('type')->default('custom'); // bearer, hmac, oauth, basic, custom
            $table->string('last_four', 8)->nullable(); // for masked display without decrypting
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['connector_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_credentials');
    }
};
