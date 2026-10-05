<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time links for a central admin to enter a tenant (App\Services\Impersonation).
        // Only a SHA-256 hash of the token is stored.
        Schema::create('impersonation_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('central_user_id')->index();
            $table->unsignedInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id'); // the tenant user, in the tenant's database
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_tokens');
    }
};
