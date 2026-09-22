<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory;

    protected $table = 'article';

    protected $primaryKey = 'id';

    protected $fillable = [
        'title',
        'content',
        'status',
        'password',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y/m/d H:i:s',
        'updated_at' => 'datetime:Y/m/d H:i:s',
    ];

    protected $appends = [
        'first_image',
    ];

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'article_tag', 'article_id', 'tag_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [1, 2]);
    }

    public function toListingArray(bool $isPasswordVerified = false, bool $showFirstImage = true): array
    {
        $canRead = (int) $this->status !== 2 || $isPasswordVerified;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'created_at' => $this->created_at?->format('Y/m/d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y/m/d H:i:s'),
            'tags' => $this->tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name])->values()->all(),
            'is_password_verified' => $isPasswordVerified,
            'first_image' => $showFirstImage && $canRead ? $this->first_image : null,
        ];
    }

    public function getFirstImageAttribute(): ?string
    {
        preg_match('/<img[^>]+src=["\'](.*?)["\']/', (string) $this->content, $matches);

        return $matches[1] ?? null;
    }

    public function getExcerptAttribute(): string
    {
        $content = html_entity_decode(strip_tags((string) $this->content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace('/\s+/u', ' ', trim($content)) ?? '';
        $excerpt = mb_substr($content, 0, 150);

        if (mb_strlen($content) > 150) {
            $excerpt .= '...';
        }

        return $excerpt;
    }
}
