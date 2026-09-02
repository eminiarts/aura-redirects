<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aura_redirect_hit_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('redirect_id')->constrained('aura_redirects')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('site_key', 120);
            $table->string('host');
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();

            $table->unique('redirect_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aura_redirect_hit_stats');
    }
};
