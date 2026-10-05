<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only (App\Models\AuditLog refuses updates and deletes). Actor and subject are
        // snapshotted as text so the entry still reads correctly if they change or disappear.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 64)->index(); // App\Enums\AuditAction
            $table->foreignId('actor_id')->nullable()->index(); // central users.id, null = system/unknown
            $table->string('actor_email')->nullable();
            $table->unsignedInteger('tenant_id')->nullable()->index(); // tenants.id (rows are never hard deleted)
            $table->string('subject_type', 64)->nullable(); // e.g. tenant_user
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_label')->nullable(); // e.g. the tenant user's email
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
