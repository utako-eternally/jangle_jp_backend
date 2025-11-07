<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopRuleText extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'category',
        'content',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    /**
     * カテゴリの定数
     */
    const CATEGORY_MAIN_RULES = 'MAIN_RULES';
    const CATEGORY_PENALTY_RULES = 'PENALTY_RULES';
    const CATEGORY_MANNER_RULES = 'MANNER_RULES';

    /**
     * 利用可能なカテゴリ
     */
    const CATEGORIES = [
        self::CATEGORY_MAIN_RULES,
        self::CATEGORY_PENALTY_RULES,
        self::CATEGORY_MANNER_RULES,
    ];

    /**
     * カテゴリの表示名
     */
    const CATEGORY_LABELS = [
        self::CATEGORY_MAIN_RULES => '主なルール',
        self::CATEGORY_PENALTY_RULES => 'アガリ放棄・チョンボ',
        self::CATEGORY_MANNER_RULES => 'マナー・禁止事項',
    ];

    /**
     * Get the shop that owns the rule text.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Scope a query to order by display order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order', 'asc');
    }

    /**
     * Get the category label.
     */
    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }
}