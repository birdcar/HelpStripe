<?php

namespace App\Models;

use App\Enums\RuleLayer;
use App\Support\Automation\Action;
use App\Support\Automation\Condition;
use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One automation rule — a row that backs any of the three layers.
 *
 * The `conditions` and `actions` columns are JSON, cast to `array` (the same
 * pattern as Filter::criteria). On top of the array cast, the accessors below
 * hydrate those arrays into Condition[]/Action[] value objects — the "casts →
 * value object" mapping taught here without a heavyweight library. The engine
 * never touches the raw arrays; it asks for typed objects, so a malformed
 * hand-edited rule throws at hydration (where RuleEngine catches + skips it)
 * rather than mis-evaluating silently.
 *
 * @property int $id
 * @property int $team_id
 * @property RuleLayer $layer
 * @property string|null $event
 * @property string $name
 * @property bool $is_active
 * @property int $position
 * @property array<int, array{field: string, operator: string, value: mixed}> $conditions
 * @property array<int, array{action: string, value: mixed}> $actions
 * @property Carbon|null $last_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable([
    'team_id',
    'layer',
    'event',
    'name',
    'is_active',
    'position',
    'conditions',
    'actions',
    'last_run_at',
])]
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use HasFactory;

    /**
     * Get the team (installation) this rule belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Scope to active rules of a given layer, in evaluation order.
     *
     * This is the engine's single entry point for "which rules run now?" — the
     * mail pipeline, the trigger listener, and the scheduled command all narrow
     * through here, so the active flag and position ordering are honored in
     * exactly one place. Ordering by `position` then `id` keeps two rules with
     * the same position stable.
     *
     * @param  Builder<AutomationRule>  $query
     */
    #[Scope]
    protected function activeLayer(Builder $query, RuleLayer $layer): void
    {
        $query->where('layer', $layer->value)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Hydrate the stored conditions into Condition value objects.
     *
     * Named `hydratedConditions`, not `conditions`, on purpose: a method whose
     * name matches a JSON-cast attribute would shadow it in Eloquent's
     * relation/attribute resolution and confuse readers about whether
     * `$rule->conditions` returns the array or the objects. The raw cast array
     * stays at `$rule->conditions`; the typed objects are an explicit call.
     *
     * @return list<Condition>
     */
    public function hydratedConditions(): array
    {
        return array_values(array_map(
            fn (array $condition) => Condition::fromArray($condition),
            $this->conditions,
        ));
    }

    /**
     * Hydrate the stored actions into Action value objects.
     *
     * @return list<Action>
     */
    public function hydratedActions(): array
    {
        return array_values(array_map(
            fn (array $action) => Action::fromArray($action),
            $this->actions,
        ));
    }

    /**
     * Get the attributes that should be cast.
     *
     * The `array` cast JSON-encodes on write and decodes on read; the layer is
     * a backed enum. PHP code sees arrays and a RuleLayer instance, never raw
     * JSON or a bare string.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layer' => RuleLayer::class,
            'is_active' => 'boolean',
            'position' => 'integer',
            'conditions' => 'array',
            'actions' => 'array',
            'last_run_at' => 'datetime',
        ];
    }
}
