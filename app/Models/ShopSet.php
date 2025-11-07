<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSet extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'price',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'price' => 'integer',
    ];

    // ===== JSONに自動的に追加するアクセサ =====
    protected $appends = [
        'display_name',
        'display_name_with_price',
        'price_summary',
    ];

    /**
     * リレーション: Shop（親）
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * 表示名アクセサ（固定で「セット雀荘」）
     */
    public function getDisplayNameAttribute(): string
    {
        return 'セット雀荘';
    }

    /**
     * 料金を含む表示名
     */
    public function getDisplayNameWithPriceAttribute(): string
    {
        return "{$this->display_name} ({$this->formatted_price}/時間)";
    }

    /**
     * 料金を円表示で取得
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price) . '円';
    }

    /**
     * ===== 追加: 料金サマリーアクセサ =====
     * フロントエンド用に単純な価格を price_summary 形式で返す
     */
    public function getPriceSummaryAttribute(): array
    {
        return [
            'base_prices_count' => 1,
            'packages_count' => 0,
            'min_base_price' => $this->price,
            'max_base_price' => $this->price,
            'min_package_price' => null,
            'overall_min_price' => $this->price,
        ];
    }

    /**
     * スコープ: 特定の店舗に属するセット設定
     */
    public function scopeForShop($query, $shopId)
    {
        return $query->where('shop_id', $shopId);
    }
}