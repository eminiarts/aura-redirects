<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aura_redirects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('source_path', 2048);
            $table->string('normalized_source', 2048);
            $table->text('destination');
            $table->string('destination_type', 24);
            $table->string('destination_host')->nullable();
            $table->string('destination_path', 2048)->nullable();
            $table->text('destination_query')->nullable();
            $table->string('destination_fragment')->nullable();
            $table->string('normalized_destination', 2048)->nullable();
            $table->unsignedSmallInteger('redirect_status');
            $table->boolean('enabled')->default(true)->index();
            $table->boolean('preserve_query')->default(false);
            $table->string('host');
            $table->string('site_key', 120);
            $table->string('scope_hash', 64)->index();
            $table->string('active_source_key', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('create index aura_redirect_scope_source_index on aura_redirects (scope_hash, normalized_source(191))');
            DB::statement('create index aura_redirect_scope_active_source_index on aura_redirects (scope_hash, active_source_key(191))');

            return;
        }

        Schema::table('aura_redirects', function (Blueprint $table): void {
            $table->index(['scope_hash', 'normalized_source'], 'aura_redirect_scope_source_index');
            $table->index(['scope_hash', 'active_source_key'], 'aura_redirect_scope_active_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aura_redirects');
    }
};
