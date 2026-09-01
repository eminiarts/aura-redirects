<?php

namespace Aura\Redirects\Commands;

use Aura\Redirects\Services\RedirectCacheStore;
use Aura\Redirects\Services\RedirectDiagnostics;
use Illuminate\Console\Command;

class ValidateRedirectsCommand extends Command
{
    protected $signature = 'aura-redirects:validate {--warm-cache : Warm the scoped redirect cache after validation}';

    protected $description = 'Validate Aura Redirect definitions and optionally warm their cache.';

    public function handle(RedirectDiagnostics $diagnostics, RedirectCacheStore $cacheStore): int
    {
        $issues = $diagnostics->scan();

        if ($issues === []) {
            $this->components->info('No Aura Redirect issues detected.');
        } else {
            foreach ($issues as $issue) {
                $prefix = strtoupper($issue->level);
                $ids = $issue->redirectIds === [] ? '' : ' [redirects: '.implode(', ', $issue->redirectIds).']';
                $line = "[{$prefix}] {$issue->code}{$ids} {$issue->message}";

                if ($issue->level === 'error') {
                    $this->components->error($line);
                } else {
                    $this->components->warn($line);
                }
            }
        }

        if ($this->option('warm-cache')) {
            $warmed = $cacheStore->warm();
            $this->components->info("Warmed {$warmed} redirect cache entries.");
        }

        return collect($issues)->contains(fn ($issue): bool => $issue->level === 'error') ? self::FAILURE : self::SUCCESS;
    }
}
