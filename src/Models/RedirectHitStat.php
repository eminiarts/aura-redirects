<?php

namespace Aura\Redirects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedirectHitStat extends Model
{
    protected $table = 'aura_redirect_hit_stats';

    protected $fillable = [
        'redirect_id',
        'team_id',
        'site_key',
        'host',
        'hit_count',
        'last_hit_at',
    ];

    protected function casts(): array
    {
        return [
            'hit_count' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    public function redirect(): BelongsTo
    {
        return $this->belongsTo(Redirect::class);
    }
}
