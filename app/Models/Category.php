<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A request category (Billing, Technical Support, ...).
 *
 * Categories optionally carry an SLA: `sla_first_response_minutes` is the
 * window within which staff should post a first public reply. Null means
 * the category has no SLA at all. Phase 8 reporting measures breaches by
 * comparing requests' `first_responded_at` against this target.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property int|null $sla_first_response_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Request> $requests
 */
#[Fillable(['team_id', 'name', 'sla_first_response_minutes'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * Get the team (installation) this category belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the requests filed under this category.
     *
     * @return HasMany<Request, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(Request::class);
    }
}
