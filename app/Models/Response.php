<?php

namespace App\Models;

use Database\Factories\ResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Response — HelpSpot's name for a canned reply.
 *
 * Like App\Models\Request, the class name deliberately collides with a
 * framework class (`Illuminate\Http\Response`). Same namespaces lesson:
 * inside App\Models the bare name resolves here; alias the framework
 * class in files that need both.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'name', 'body'])]
class Response extends Model
{
    /** @use HasFactory<ResponseFactory> */
    use HasFactory;

    /**
     * Get the team (installation) this canned reply belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
