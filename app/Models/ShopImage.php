<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopImage extends Model
{
    use HasFactory;

    protected $table = 'shop_images';

    protected $fillable = [
        'shop_id',
        'image_paths',
        'alt_text',
        'display_order',
    ];

    protected $casts = [
        'image_paths' => 'array',
        'display_order' => 'integer',
    ];

    /**
     * この画像が属する店舗を取得
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * 画像のURLを取得（指定サイズ）
     */
    public function getImageUrl(string $size = 'medium'): ?string
    {
        if (!$this->image_paths || !isset($this->image_paths[$size])) {
            return null;
        }

        return asset('storage/' . $this->image_paths[$size]);
    }

    /**
     * オリジナル画像のURLを取得
     */
    public function getOriginalUrl(): ?string
    {
        return $this->getImageUrl('original');
    }

    /**
     * サムネイル画像のURLを取得
     */
    public function getThumbnailUrl(): ?string
    {
        return $this->getImageUrl('thumb');
    }

    /**
     * 全サイズの画像URLを取得
     */
    public function getAllImageUrls(): array
    {
        if (!$this->image_paths) {
            return [];
        }

        $urls = [];
        foreach ($this->image_paths as $size => $path) {
            $urls[$size] = asset('storage/' . $path);
        }

        return $urls;
    }
}