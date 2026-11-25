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
        Schema::table('tenant_telegram_bots', function (Blueprint $table): void {
            $table->string('company_description', 512)->nullable();
            $table->string('contact_url', 2048)->nullable();
        });

        Schema::create('telegram_marketing_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chat_id');
            $table->timestampTz('subscribed_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'chat_id'], 'telegram_marketing_tenant_chat_unique');
        });

        DB::statement('ALTER TABLE telegram_marketing_subscriptions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE telegram_marketing_subscriptions FORCE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation_telegram_marketing_subscriptions ON telegram_marketing_subscriptions
             USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
             WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE telegram_marketing_subscriptions NO FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_telegram_marketing_subscriptions ON telegram_marketing_subscriptions');
        DB::statement('ALTER TABLE telegram_marketing_subscriptions DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('telegram_marketing_subscriptions');

        Schema::table('tenant_telegram_bots', function (Blueprint $table): void {
            $table->dropColumn(['company_description', 'contact_url']);
        });
    }
};
