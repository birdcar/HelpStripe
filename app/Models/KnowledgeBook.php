<?php

namespace App\Models;

use Database\Factories\KnowledgeBookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * The top level of the knowledge base: Book → Chapter → Page.
 *
 * Two lessons live here:
 *
 *  - **Scoped slugs** (spatie/laravel-sluggable): `extraScope` limits the
 *    uniqueness check to this team, so two teams can both publish a
 *    "getting-started" book without suffixing. Chapters and pages repeat
 *    the trick one level down.
 *  - **hasManyThrough**: `pages()` reaches the grandchild Page rows
 *    through the intermediate `chapters` table without a `book_id` column
 *    on pages — Eloquent joins through the chapter FK.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_published
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Chapter> $chapters
 * @property-read Collection<int, Page> $pages
 */
#[Fillable(['team_id', 'name', 'slug', 'description', 'is_published', 'position'])]
class KnowledgeBook extends Model
{
    /** @use HasFactory<KnowledgeBookFactory> */
    use HasFactory, HasSlug;

    /**
     * Bootstrap the model.
     *
     * Position defaults to max+1 within the parent scope (the team), so
     * new books land at the end of the list without callers managing
     * ordering. Reordering later is a simple swap of two position values
     * (see the admin UI) — deliberately not drag-and-drop; the lesson is
     * the data model, not the widget.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (KnowledgeBook $book) {
            if (! array_key_exists('position', $book->getAttributes())) {
                $book->position = static::query()
                    ->where('team_id', $book->team_id)
                    ->max('position') + 1;
            }
        });
    }

    /**
     * Slug from the name, unique within the team.
     *
     * Sluggable regenerates the slug when the name changes — the old URL
     * simply 404s afterwards (no redirect; named as a future
     * consideration in docs/tour/05-knowledge-base.md).
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->extraScope(fn (Builder $builder) => $builder->where('team_id', $this->team_id));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    /**
     * Scope to books visible on the public portal.
     *
     * @param  Builder<KnowledgeBook>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Get the team (installation) this book belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the book's chapters in display order.
     *
     * The relationship name matters beyond readability: nested route
     * binding (`{book:slug}/{chapter:slug}` + scopeBindings()) guesses
     * the relation from the route parameter's plural — `chapters`.
     *
     * @return HasMany<Chapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('position');
    }

    /**
     * Get every page in the book, through its chapters.
     *
     * @return HasManyThrough<Page, Chapter, $this>
     */
    public function pages(): HasManyThrough
    {
        return $this->hasManyThrough(Page::class, Chapter::class);
    }
}
