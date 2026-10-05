<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique(); // stancl's tenant key: lookups, queue payloads, tenants:* commands
            $table->string('name');

            // One tenant, one subdomain. Unique across soft-deleted rows too: a deleted tenant's
            // subdomain stays reserved.
            $table->string('subdomain', 63)->unique();

            $table->boolean('enabled')->default(true); // admin's on/off switch
            $table->unsignedTinyInteger('state')->index(); // App\Enums\TenantState (lifecycle)

            // Read by stancl's DatabaseConfig through the Tenant model (internal prefix is '').
            // Username/password null = connect with the master credentials from .env.
            $table->string('db_name', 64)->unique();
            $table->string('db_username', 32)->nullable()->unique();
            $table->text('db_password')->nullable(); // encrypted cast

            $table->string('storage_path');
            $table->string('cache_prefix')->unique();

            // First user's details until the credentials email is sent, then null (encrypted cast).
            $table->text('pending_admin')->nullable();

            $table->string('current_step')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
