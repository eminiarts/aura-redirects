<?php

namespace Aura\Redirects\Services;

use Aura\Redirects\Data\RedirectDiagnostic;
use Aura\Redirects\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RedirectDiagnostics
{
    public function __construct(
        private readonly RedirectDestinationNormalizer $destinationNormalizer,
        private readonly ProtectedPathMatcher $protectedPathMatcher,
        private readonly RedirectPathNormalizer $pathNormalizer,
        private readonly RedirectRepository $repository,
        private readonly Router $router,
    ) {}

    /**
     * @return array<int, RedirectDiagnostic>
     */
    public function scan(): array
    {
        $records = Redirect::query()->withoutGlobalScopes()->orderBy('site_key')->orderBy('host')->orderBy('normalized_source')->get();
        $diagnostics = [];

        foreach ($records as $record) {
            try {
                $normalizedSource = $this->pathNormalizer->normalizeSource((string) $record->source_path);
            } catch (\Throwable $exception) {
                $diagnostics[] = new RedirectDiagnostic(
                    code: 'invalid_source',
                    level: 'error',
                    message: "Redirect {$record->getKey()} has an invalid source path: {$exception->getMessage()}",
                    redirectIds: [(int) $record->getKey()],
                );

                continue;
            }

            if ($this->protectedPathMatcher->matches($normalizedSource)) {
                $diagnostics[] = new RedirectDiagnostic(
                    code: 'shadowed_protected',
                    level: 'error',
                    message: "Redirect {$record->getKey()} targets a protected source path {$normalizedSource}.",
                    redirectIds: [(int) $record->getKey()],
                );
            }

            try {
                $destination = $this->destinationNormalizer->normalize(
                    (string) $record->destination,
                    (string) $record->host,
                    (string) $record->site_key,
                );
            } catch (\Throwable $exception) {
                $diagnostics[] = new RedirectDiagnostic(
                    code: 'invalid_destination',
                    level: 'error',
                    message: "Redirect {$record->getKey()} has an invalid destination: {$exception->getMessage()}",
                    redirectIds: [(int) $record->getKey()],
                );

                continue;
            }

            if ($record->enabled && ! $this->repository->isActiveAt($record, $this->timezoneFor($record))) {
                $diagnostics[] = new RedirectDiagnostic(
                    code: 'expired_or_scheduled',
                    level: 'warning',
                    message: "Redirect {$record->getKey()} for {$record->host}{$normalizedSource} is enabled but currently inactive because of its schedule window.",
                    redirectIds: [(int) $record->getKey()],
                );
            }

            if ($destination->internal && $this->shouldCheckInternalTargets() && ! $this->internalTargetExists($destination->path, $destination->host ?: $record->host)) {
                $diagnostics[] = new RedirectDiagnostic(
                    code: 'unreachable_internal',
                    level: 'warning',
                    message: "Redirect {$record->getKey()} for {$record->host}{$normalizedSource} points to an internal destination that is not currently routable: {$destination->path}.",
                    redirectIds: [(int) $record->getKey()],
                );
            }
        }

        foreach ($this->conflicts($records) as $conflict) {
            $diagnostics[] = $conflict;
        }

        foreach ($this->chainsAndCycles($records) as $diagnostic) {
            $diagnostics[] = $diagnostic;
        }

        return $this->uniqueDiagnostics($diagnostics);
    }

    /**
     * @param  Collection<int, Redirect>  $records
     * @return array<int, RedirectDiagnostic>
     */
    private function chainsAndCycles($records): array
    {
        $diagnostics = [];
        $maxChainLength = (int) config('aura-redirects.diagnostics.max_chain_length', 8);

        $groups = $records
            ->where('enabled', true)
            ->groupBy(fn (Redirect $redirect): string => ($redirect->team_id ?? 'global').'|'.$redirect->site_key);

        foreach ($groups as $group) {
            $edges = [];
            $ids = [];

            foreach ($group as $record) {
                if (! in_array($record->destination_type, ['internal_path', 'internal_url'], true) || ! $record->destination_path) {
                    continue;
                }

                $node = $record->host.'|'.$record->normalized_source;
                $edges[$node] = ($record->destination_host ?: $record->host).'|'.$record->destination_path;
                $ids[$node] = (int) $record->getKey();
            }

            foreach ($edges as $sourceNode => $nextNode) {
                $visited = [$sourceNode];
                $chainIds = [$ids[$sourceNode]];
                $cursor = $nextNode;
                $steps = 0;

                while (isset($edges[$cursor]) && $steps < 24) {
                    $steps++;
                    $chainIds[] = $ids[$cursor] ?? 0;

                    if (in_array($cursor, $visited, true)) {
                        $diagnostics[] = new RedirectDiagnostic(
                            code: 'cycle',
                            level: 'error',
                            message: 'Detected a redirect cycle in the same Team/site scope.',
                            redirectIds: array_values(array_filter($chainIds)),
                        );

                        break;
                    }

                    $visited[] = $cursor;
                    $cursor = $edges[$cursor];
                }

                if ($steps >= 1 && count(array_filter($chainIds)) > 1) {
                    $level = count(array_filter($chainIds)) > $maxChainLength ? 'error' : 'warning';

                    $diagnostics[] = new RedirectDiagnostic(
                        code: 'chain',
                        level: $level,
                        message: 'Detected a redirect chain in the same Team/site scope.',
                        redirectIds: array_values(array_filter($chainIds)),
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * @param  Collection<int, Redirect>  $records
     * @return array<int, RedirectDiagnostic>
     */
    private function conflicts($records): array
    {
        $diagnostics = [];

        $duplicates = $records
            ->where('enabled', true)
            ->groupBy(fn (Redirect $redirect): string => $redirect->scope_hash.'|'.$redirect->normalized_source)
            ->filter(fn ($group): bool => $group->count() > 1);

        foreach ($duplicates as $group) {
            /** @var Redirect|null $first */
            $first = $group->first();
            $scopeDescription = $first === null
                ? 'the same normalized source in the same exact host scope'
                : "{$first->host}{$first->normalized_source} in site {$first->site_key}";

            $diagnostics[] = new RedirectDiagnostic(
                code: 'conflict',
                level: 'error',
                message: "Multiple enabled redirects claim {$scopeDescription}.",
                redirectIds: $group->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
        }

        return $diagnostics;
    }

    private function internalTargetExists(string $path, string $host): bool
    {
        try {
            $request = Request::create(
                'http://'.$host.$path,
                'GET',
                server: ['HTTP_HOST' => $host],
            );

            $this->router->getRoutes()->match($request);

            return true;
        } catch (NotFoundHttpException) {
            return false;
        }
    }

    private function shouldCheckInternalTargets(): bool
    {
        return (bool) config('aura-redirects.diagnostics.resolve_internal_destinations', true);
    }

    private function timezoneFor(Redirect $record): string
    {
        $sites = (array) config('aura-redirects.resolver.sites', []);

        return (string) ($sites[$record->site_key]['timezone'] ?? config('app.timezone', 'UTC'));
    }

    /**
     * @param  array<int, RedirectDiagnostic>  $diagnostics
     * @return array<int, RedirectDiagnostic>
     */
    private function uniqueDiagnostics(array $diagnostics): array
    {
        $unique = [];

        foreach ($diagnostics as $diagnostic) {
            $signature = implode('|', [
                $diagnostic->code,
                $diagnostic->level,
                $diagnostic->message,
                implode(',', $diagnostic->redirectIds),
            ]);

            $unique[$signature] = $diagnostic;
        }

        return array_values($unique);
    }
}
