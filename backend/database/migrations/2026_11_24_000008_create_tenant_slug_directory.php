<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_slug_directory', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE tenant_slug_directory ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenant_slug_directory FORCE ROW LEVEL SECURITY');
        DB::statement(
            'CREATE POLICY tenant_slug_directory_public_lookup ON tenant_slug_directory
             FOR SELECT USING (true)'
        );
        DB::statement(
            "CREATE POLICY tenant_slug_directory_insert ON tenant_slug_directory
             FOR INSERT
             WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
        );
        DB::statement(
            "CREATE POLICY tenant_slug_directory_update ON tenant_slug_directory
             FOR UPDATE
             USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
             WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
        );
        DB::statement(
            "CREATE POLICY tenant_slug_directory_delete ON tenant_slug_directory
             FOR DELETE
             USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
        );

        DB::statement(
            'INSERT INTO tenant_slug_directory (tenant_id, slug, created_at, updated_at)
             SELECT id, slug, NOW(), NOW() FROM tenants
             ON CONFLICT (tenant_id) DO NOTHING'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_slug_directory');
    }
};
