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
        Schema::create('tenant_telegram_bots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bot_id')->unique();
            $table->string('bot_username', 64);
            $table->text('bot_token');
            $table->string('webhook_secret_hash', 64);
            $table->boolean('webhook_ready')->default(false);
            $table->timestampsTz();
        });

        Schema::create('telegram_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chat_id');
            $table->boolean('enabled')->default(true);
            $table->boolean('remind_24h')->default(true);
            $table->boolean('remind_2h')->default(true);
            $table->timestampTz('connected_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id'], 'telegram_subscriptions_tenant_user_unique');
            $table->unique(['tenant_id', 'chat_id'], 'telegram_subscriptions_tenant_chat_unique');
        });

        Schema::create('telegram_subscription_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'user_id', 'expires_at']);
        });

        foreach (['tenant_telegram_bots', 'telegram_subscriptions', 'telegram_subscription_tokens'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement(
                "CREATE POLICY tenant_isolation_{$table} ON {$table}
                 USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
                 WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
            );
        }

        DB::statement(
            "CREATE POLICY telegram_identity_lookup ON user_identities
             FOR SELECT
             USING (
                provider = 'telegram'
                AND current_setting('app.telegram_identity_lookup', true) = 'on'
             )"
        );
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS telegram_identity_lookup ON user_identities');

        foreach (['telegram_subscription_tokens', 'telegram_subscriptions', 'tenant_telegram_bots'] as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            Schema::dropIfExists($table);
        }
    }
};
