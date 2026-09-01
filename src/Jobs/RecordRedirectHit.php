<?php

namespace Aura\Redirects\Jobs;

use Aura\Redirects\Models\RedirectHitStat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordRedirectHit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $redirectId,
        public readonly ?int $teamId,
        public readonly string $siteKey,
        public readonly string $host,
    ) {}

    public function handle(): void
    {
        $timestamp = Carbon::now();

        DB::transaction(function () use ($timestamp): void {
            RedirectHitStat::query()->updateOrCreate(
                ['redirect_id' => $this->redirectId],
                [
                    'team_id' => $this->teamId,
                    'site_key' => $this->siteKey,
                    'host' => $this->host,
                    'hit_count' => 0,
                    'last_hit_at' => $timestamp,
                ],
            );

            RedirectHitStat::query()
                ->where('redirect_id', $this->redirectId)
                ->update([
                    'team_id' => $this->teamId,
                    'site_key' => $this->siteKey,
                    'host' => $this->host,
                    'hit_count' => DB::raw('hit_count + 1'),
                    'last_hit_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        });
    }
}
