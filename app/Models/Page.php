<?php

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * A knowledge base article — the leaf of Book → Chapter → Page.
 *
 * `body` is raw Markdown. It is rendered at display time by
 * `renderedBody()`, which passes `html_input => escape` so any literal
 * HTML an author (or attacker) types is shown as text instead of being
 * executed — the stored-XSS lesson of this phase.
 *
 * @property int $id
 * @property int $chapter_id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property bool $is_published
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Chapter $chapter
 */
#[Fillable(['chapter_id', 'title', 'slug', 'body', 'is_published', 'position'])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, HasSlug;

    /**
     * Bootstrap the model: position defaults to max+1 within the chapter.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Page $page) {
            if (! array_key_exists('position', $page->getAttributes())) {
                $page->position = static::query()
                    ->where('chapter_id', $page->chapter_id)
                    ->max('position') + 1;
            }
        });
    }

    /**
     * Slug from the title, unique within the chapter — pages titled
     * "Introduction" in two different books never collide because each
     * uniqueness check is scoped to its own chapter.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->extraScope(fn (Builder $builder) => $builder->where('chapter_id', $this->chapter_id));
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
     * Scope to pages whose own flag allows portal display.
     *
     * Necessary but not sufficient: portal queries must ALSO check the
     * book is published — a published page inside a draft book stays
     * hidden. See the visibility matrix test in PortalBrowsingTest.
     *
     * @param  Builder<Page>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Get the chapter this page belongs to.
     *
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * Render the Markdown body to safe HTML.
     *
     * `html_input => escape` neutralizes raw HTML in the source: a stored
     * `<script>` tag comes out as visible text, never as executing
     * markup. HtmlString tells Blade the `{!! !!}` output is intentional.
     */
    public function renderedBody(): HtmlString
    {
        return new HtmlString(
            Str::markdown($this->body, ['html_input' => 'escape'])
        );
    }
}
