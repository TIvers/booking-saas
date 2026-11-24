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
        Schema::table('tenants', function (Blueprint $table): void {
            $table->char('currency', 3)->default('USD');
        });

        DB::statement(
            "ALTER TABLE tenants ADD CONSTRAINT tenants_currency_check CHECK (currency ~ '^[A-Z]{3}$')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT tenants_currency_check');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
