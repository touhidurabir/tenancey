<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Recorded only: may the master .env database user also use this tenant's database?
            // The app never grants or revokes anything on the master user.
            $table->boolean('db_master_access')->default(true)->after('db_password');

            // Set once provisioning has created the dedicated database user. Proof that the user
            // is ours: retry and teardown never drop a user without it.
            $table->timestamp('db_user_created_at')->nullable()->after('db_master_access');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['db_master_access', 'db_user_created_at']);
        });
    }
};
