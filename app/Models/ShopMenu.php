<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopMenu extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'item_name',
        'category',
        'price',
        'description',
        'is_available',
        'display_order',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'price' => 'integer',
        'is_available' => 'boolean',
        'display_order' => 'integer',
    ];

    // ===== JSONに自動的に追加するアクセサ =====
    protected $appends = [
        'category_display',
        'price_display',
    ];

    // カテゴリ定数
    const CATEGORY_FOOD = 'FOOD';
    const CATEGORY_DRINK = 'DRINK';
    const CATEGORY_ALCOHOL = 'ALCOHOL';
    const CATEGORY_OTHER = 'OTHER';

    /**
     * カテゴリの選択肢を取得
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_FOOD => '食べ物',
            self::CATEGORY_DRINK => '飲み物',
            self::CATEGORY_ALCOHOL => 'アルコール',
            self::CATEGORY_OTHER => 'その他',
        ];
    }

    /*
     * リレーション
     */

    /**
     * このメニューが属する店舗
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /*
     * スコープ
     */

    /**
     * 利用可能なメニューのみ
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    /**
     * 特定のカテゴリ
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * 価格範囲で絞り込み
     */
    public function scopePriceBetween($query, int $minPrice, int $maxPrice)
    {
        return $query->whereBetween('price', [$minPrice, $maxPrice]);
    }

    /**
     * アイテム名で検索
     */
    public function scopeSearchByName($query, string $name)
    {
        return $query->where('item_name', 'like', '%' . $name . '%');
    }

    /*
     * アクセサ
     */

    /**
     * カテゴリの表示名を取得
     */
    public function getCategoryDisplayAttribute(): string
    {
        return self::getCategories()[$this->category] ?? $this->category;
    }

    /**
     * 価格の表示形式を取得
     */
    public function getPriceDisplayAttribute(): string
    {
        return '¥' . number_format($this->price);
    }

    /**
     * 食べ物かどうか
     */
    public function isFood(): bool
    {
        return $this->category === self::CATEGORY_FOOD;
    }

    /**
     * 飲み物かどうか
     */
    public function isDrink(): bool
    {
        return $this->category === self::CATEGORY_DRINK;
    }

    /**
     * アルコールかどうか
     */
    public function isAlcohol(): bool
    {
        return $this->category === self::CATEGORY_ALCOHOL;
    }
}