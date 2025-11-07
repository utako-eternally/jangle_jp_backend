<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogPost extends Model
{
    use HasFactory;

    protected $table = 'blog_posts';

    protected $fillable = [
        'shop_id',
        'user_id',
        'title',
        'content',
        'thumbnail_paths',
        'status',
        'published_at',
    ];

    protected $casts = [
        'thumbnail_paths' => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * ステータス定数
     */
    const STATUS_DRAFT = 'DRAFT';
    const STATUS_PUBLISHED = 'PUBLISHED';
    const STATUS_ARCHIVED = 'ARCHIVED';

    /**
     * この投稿が属する店舗を取得
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * この投稿の投稿者を取得
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * この投稿の画像を取得
     */
    public function images(): HasMany
    {
        return $this->hasMany(BlogImage::class, 'blog_post_id')->orderBy('display_order');
    }

    /**
     * サムネイル画像のURLを取得（指定サイズ）
     */
    public function getThumbnailUrl(string $size = 'medium'): ?string
    {
        if (!$this->thumbnail_paths || !isset($this->thumbnail_paths[$size])) {
            return null;
        }

        return asset('storage/' . $this->thumbnail_paths[$size]);
    }

    /**
     * サムネイル画像を持っているか
     */
    public function hasThumbnail(): bool
    {
        return !empty($this->thumbnail_paths);
    }

    /**
     * 公開済みかどうか
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED 
            && $this->published_at 
            && $this->published_at->isPast();
    }

    /**
     * 下書きかどうか
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * アーカイブ済みかどうか
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * 指定ユーザーが投稿者かどうか
     */
    public function isAuthoredBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * 公開済み投稿のスコープ
     */
    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where('published_at', '<=', now());
    }

    /**
     * 下書き投稿のスコープ
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * 特定店舗の投稿のスコープ
     */
    public function scopeForShop($query, int $shopId)
    {
        return $query->where('shop_id', $shopId);
    }
}