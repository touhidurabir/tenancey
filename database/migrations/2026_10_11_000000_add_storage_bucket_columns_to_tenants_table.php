<?php

use App\Tenancy\TenantResourceGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Recorded at provisioning: FILESYSTEM_DISK only decides where NEW tenants go.
            $table->string('storage_disk', 32)->nullable()->after('storage_path');
            $table->string('storage_bucket', 63)->nullable()->unique()->after('storage_disk');
        });

        // Existing tenants only ever had local folders. Their bucket folders are created by
        // `php artisan tenants:create-buckets`.
        DB::table('tenants')->whereNull('storage_bucket')->orderBy('id')->each(function (object $tenant) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'storage_disk' => 'local',
                'storage_bucket' => TenantResourceGuard::bucketNameFor($tenant->uuid),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'storage_bucket']);
        });
    }
};
