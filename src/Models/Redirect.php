<?php

namespace Aura\Redirects\Models;

use Aura\Base\Fields\Boolean;
use Aura\Base\Fields\Datetime;
use Aura\Base\Fields\Number;
use Aura\Base\Fields\Select;
use Aura\Base\Fields\Text;
use Aura\Base\Fields\Textarea;
use Aura\Base\Resource;
use Aura\Redirects\Services\RedirectCacheStore;
use Aura\Redirects\Services\RedirectValidator;
use Aura\Redirects\Support\RedirectScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int|null $team_id
 * @property int|null $user_id
 * @property string $source_path
 * @property string $normalized_source
 * @property string $destination
 * @property string $destination_type
 * @property string|null $destination_host
 * @property string|null $destination_path
 * @property string|null $destination_query
 * @property string|null $destination_fragment
 * @property string|null $normalized_destination
 * @property int $redirect_status
 * @property bool $enabled
 * @property bool $preserve_query
 * @property string $host
 * @property string $site_key
 * @property string $scope_hash
 * @property string|null $active_source_key
 * @property string|null $notes
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read int $hit_count
 * @property-read CarbonInterface|null $last_hit_at
 */
class Redirect extends Resource
{
    public static $customTable = true;

    public static $globalSearch = false;

    public static $singularName = 'Redirect';

    public static bool $usesMeta = false;

    protected static ?string $group = 'Aura';

    protected static ?string $icon = null;

    public static ?string $slug = 'aura-redirect';

    protected static ?int $sort = 68;

    protected static string $type = 'AuraRedirect';

    protected array $scopeHashesToInvalidate = [];

    protected $fillable = [
        'user_id',
        'team_id',
        'source_path',
        'normalized_source',
        'destination',
        'destination_type',
        'destination_host',
        'destination_path',
        'destination_query',
        'destination_fragment',
        'normalized_destination',
        'redirect_status',
        'enabled',
        'preserve_query',
        'host',
        'site_key',
        'scope_hash',
        'active_source_key',
        'notes',
        'starts_at',
        'ends_at',
    ];

    protected $table = 'aura_redirects';

    protected $with = ['stat'];

    public static function getFields(): array
    {
        $defaultSiteKey = (string) config('aura-redirects.resolver.default_site_key', 'default');
        $defaultHost = RedirectScope::normalizeHost(parse_url((string) config('app.url'), PHP_URL_HOST));

        return [
            self::field('Source Path', 'source_path', Text::class, 'required|max:2048', true, true, true, true),
            self::field('Destination', 'destination', Text::class, 'required|max:4096', true, true, true, true),
            self::field('HTTP Status', 'redirect_status', Select::class, 'required|integer|in:301,302,307,308', true, true, true, false, [
                'options' => [
                    '301' => '301 Permanent',
                    '302' => '302 Temporary',
                    '307' => '307 Temporary, method-preserving',
                    '308' => '308 Permanent, method-preserving',
                ],
                'default' => '302',
            ]),
            self::field('Enabled', 'enabled', Boolean::class, 'boolean', true, true, true, false, [
                'default' => true,
            ]),
            self::field('Preserve Query', 'preserve_query', Boolean::class, 'boolean', true, true, true, false, [
                'default' => false,
            ]),
            self::field('Hostname', 'host', Text::class, 'required|max:255', true, true, true, true, [
                'default' => $defaultHost !== '' ? $defaultHost : null,
            ]),
            self::field('Site Key', 'site_key', Text::class, 'required|max:120', true, true, true, true, [
                'default' => $defaultSiteKey,
            ]),
            self::field('Starts At', 'starts_at', Datetime::class, 'nullable|date', true, true, true, false),
            self::field('Ends At', 'ends_at', Datetime::class, 'nullable|date|after_or_equal:starts_at', true, true, true, false),
            self::field('Notes', 'notes', Textarea::class, 'nullable|string|max:5000', false, true, true, false),
            self::field('Hit Count', 'hit_count', Number::class, '', true, false, true, false),
            self::field('Last Hit At', 'last_hit_at', Datetime::class, '', true, false, true, false),
            self::field('Created At', 'created_at', Datetime::class, '', true, false, true, false),
            self::field('Updated At', 'updated_at', Datetime::class, '', true, false, true, false),
        ];
    }

    public function stat(): HasOne
    {
        return $this->hasOne(RedirectHitStat::class);
    }

    public function getHitCountAttribute(): int
    {
        $stat = $this->relationLoaded('stat') ? $this->getRelation('stat') : $this->stat()->first();

        return (int) ($stat?->hit_count ?? 0);
    }

    public function getLastHitAtAttribute(): ?CarbonInterface
    {
        $stat = $this->relationLoaded('stat') ? $this->getRelation('stat') : $this->stat()->first();

        return $stat?->last_hit_at;
    }

    public function getIcon()
    {
        return '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M5 12h14M13 6l6 6-6 6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    public function indexQuery($query): Builder
    {
        return $query
            ->orderBy('site_key')
            ->orderBy('host')
            ->orderBy('normalized_source');
    }

    public function currentScopeHash(): ?string
    {
        $host = RedirectScope::normalizeHost($this->host);
        $siteKey = trim((string) $this->site_key);

        if ($host === '' || $siteKey === '') {
            return null;
        }

        return RedirectScope::hash($this->team_id, $siteKey, $host);
    }

    /**
     * @return array<int, string>
     */
    public function scopeHashesForInvalidation(): array
    {
        $current = $this->currentScopeHash();

        return array_values(array_unique(array_filter([
            ...$this->scopeHashesToInvalidate,
            $current,
        ])));
    }

    public function inputFields()
    {
        return parent::inputFields()
            ->reject(fn (array $field): bool => in_array($field['slug'] ?? null, [
                'hit_count',
                'last_hit_at',
                'created_at',
                'updated_at',
            ], true))
            ->values();
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (self $redirect): void {
            $redirect->captureOriginalScopeHash();
            app(RedirectValidator::class)->prepareAndValidate($redirect);
        });

        static::saved(function (self $redirect): void {
            app(RedirectCacheStore::class)->invalidateScopeHashes($redirect->scopeHashesForInvalidation());
        });

        static::deleted(function (self $redirect): void {
            $redirect->captureOriginalScopeHash();
            app(RedirectCacheStore::class)->invalidateScopeHashes($redirect->scopeHashesForInvalidation());
        });
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'enabled' => 'boolean',
            'ends_at' => 'datetime',
            'last_hit_at' => 'datetime',
            'preserve_query' => 'boolean',
            'redirect_status' => 'integer',
            'starts_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    private function captureOriginalScopeHash(): void
    {
        $originalSiteKey = trim((string) ($this->getOriginal('site_key') ?? ''));
        $originalHost = RedirectScope::normalizeHost($this->getOriginal('host'));

        if ($originalSiteKey === '' || $originalHost === '') {
            return;
        }

        $this->scopeHashesToInvalidate[] = RedirectScope::hash(
            $this->getOriginal('team_id') === null ? null : (int) $this->getOriginal('team_id'),
            $originalSiteKey,
            $originalHost,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function field(
        string $name,
        string $slug,
        string $type,
        string $validation,
        bool $onIndex,
        bool $onForms,
        bool $onView,
        bool $searchable,
        array $extra = [],
    ): array {
        return array_merge([
            'conditional_logic' => [],
            'name' => $name,
            'on_forms' => $onForms,
            'on_index' => $onIndex,
            'on_view' => $onView,
            'searchable' => $searchable,
            'slug' => $slug,
            'type' => $type,
            'validation' => $validation,
        ], $extra);
    }
}
