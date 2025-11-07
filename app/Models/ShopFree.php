<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopFree extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'game_format',
        'price',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'price' => 'integer',
    ];

    // ===== JSONに自動的に追加するアクセサ =====
    protected $appends = [
        'game_format_display',
        'display_name',
        'display_name_with_price',
        'summary',
    ];

    // ゲームフォーマット定数
    const GAME_FORMAT_THREE_PLAYER = 'THREE_PLAYER';
    const GAME_FORMAT_FOUR_PLAYER = 'FOUR_PLAYER';

    /**
     * ゲーム形式の選択肢を取得
     */
    public static function getGameFormats(): array
    {
        return [
            self::GAME_FORMAT_THREE_PLAYER => '3人打ち',
            self::GAME_FORMAT_FOUR_PLAYER => '4人打ち',
        ];
    }

    /**
     * リレーション: Shop（親）
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * スコープ: 特定の店舗に属するフリー設定
     */
    public function scopeForShop($query, $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    /**
     * スコープ: 特定のゲーム形式
     */
    public function scopeGameFormat($query, $format)
    {
        return $query->where('game_format', $format);
    }

    /**
     * ゲーム形式の表示名アクセサ
     */
    public function getGameFormatDisplayAttribute(): string
    {
        return $this->isThreePlayer() ? '三人麻雀' : '四人麻雀';
    }

    /**
     * 表示名アクセサ（動的に生成）
     */
    public function getDisplayNameAttribute(): string
    {
        $formatName = self::getGameFormats()[$this->game_format] ?? $this->game_format;
        return "フリー{$formatName}";
    }

    /**
     * 料金情報を含む詳細表示名
     */
    public function getDisplayNameWithPriceAttribute(): string
    {
        return "{$this->display_name} ({$this->formatted_price})";
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
     * フロントエンド用に単純な価格を summary 形式で返す
     */
    public function getSummaryAttribute(): array
    {
        return [
            'total_rates' => 1,
            'public_rates' => 1,
            'private_rates' => 0,
            'min_price' => $this->price,
            'max_price' => $this->price,
        ];
    }

    /**
     * 3人打ちかどうか
     */
    public function isThreePlayer(): bool
    {
        return $this->game_format === self::GAME_FORMAT_THREE_PLAYER;
    }

    /**
     * 4人打ちかどうか
     */
    public function isFourPlayer(): bool
    {
        return $this->game_format === self::GAME_FORMAT_FOUR_PLAYER;
    }
}