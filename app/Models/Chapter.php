<?php

namespace App\Models;

use Database\Factories\ChapterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * The middle level of the knowledge base: an ordered, named grouping of
 * pages within a book. Chapters carry no published flag — visibility is
 * decided by the book above and each page below.
 *
 * @property int $id
 * @property int $knowledge_book_id
 * @property string $name
 * @property string $slug
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read KnowledgeBook $book
 * @property-read Collection<int, Page> $pages
 */
#[Fillable(['knowledge_book_id', 'name', 'slug', 'position'])]
class Chapter extends Model
{
    /** @use HasFactory<ChapterFactory> */
    use HasFactory, HasSlug;

    /**
     * Bootstrap the model: position defaults to max+1 within the book.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Chapter $chapter) {
            if (! array_key_exists('position', $chapter->getAttributes())) {
                $chapter->position = static::query()
                    ->where('knowledge_book_id', $chapter->knowledge_book_id)
                    ->max('position') + 1;
            }
        });
    }

    /**
     * Slug from the name, unique within the book — so every book can have
     * its own "introduction" chapter without suffix collisions.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->extraScope(fn (Builder $builder) => $builder->where('knowledge_book_id', $this->knowledge_book_id));
    }

    /**
     * Get the book this chapter belongs to.
     *
     * @return BelongsTo<KnowledgeBook, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBook::class, 'knowledge_book_id');
    }

    /**
     * Get the chapter's pages in display order.
     *
     * Named `pages` so nested route binding (`{chapter:slug}/{page:slug}`
     * + scopeBindings()) can resolve the child within this chapter.
     *
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('position');
    }
}
