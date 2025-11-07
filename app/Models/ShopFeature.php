<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopFeature extends Model
{
    use HasFactory;

    protected $table = 'shop_features';

    protected $fillable = [
        'shop_id',
        'feature',
    ];

    protected $casts = [
        'shop_id' => 'integer',
    ];

    // ===== JSONに自動的に追加するアクセサ =====
    protected $appends = [
        'display_name',
    ];

    // 特徴定数
    const FEATURE_HEALTH = 'HEALTH';
    const FEATURE_NO_RATE = 'NO_RATE';
    const FEATURE_GIRL_MAHJONG = 'GIRL_MAHJONG';
    const FEATURE_MALE_PRO = 'MALE_PRO';
    const FEATURE_FEMALE_PRO = 'FEMALE_PRO';

    /**
     * 特徴一覧を取得
     *
     * @return array
     */
    public static function getFeatures(): array
    {
        return [
            self::FEATURE_HEALTH => '健康麻雀',
            self::FEATURE_NO_RATE => 'ノーレート',
            self::FEATURE_GIRL_MAHJONG => '女性麻雀',
            self::FEATURE_MALE_PRO => '男性プロ在籍',
            self::FEATURE_FEMALE_PRO => '女性プロ在籍',
        ];
    }

    /**
     * 特徴カテゴリを取得
     *
     * @return array
     */
    public static function getFeatureCategories(): array
    {
        return [
            'game_style' => [
                'name' => 'ゲームスタイル',
                'features' => [
                    self::FEATURE_HEALTH,
                    self::FEATURE_NO_RATE,
                ]
            ],
            'staff' => [
                'name' => 'スタッフ・プロ',
                'features' => [
                    self::FEATURE_MALE_PRO,
                    self::FEATURE_FEMALE_PRO,
                    self::FEATURE_GIRL_MAHJONG,
                ]
            ],
        ];
    }

    /**
     * 特徴の詳細説明を取得
     *
     * @return array
     */
    public static function getFeatureDescriptions(): array
    {
        return [
            self::FEATURE_HEALTH => '賭博性を排除し、健康的に麻雀を楽しみたい年配の方にもおすすめです',
            self::FEATURE_NO_RATE => '賭博性を排除し、ゲーム代だけでプレイできます',
            self::FEATURE_GIRL_MAHJONG => '女性スタッフが多く在籍しています',
            self::FEATURE_MALE_PRO => '男性のプロ雀士が在籍しています',
            self::FEATURE_FEMALE_PRO => '女性のプロ雀士が在籍しています',
        ];
    }

    /*
     * リレーション
     */

    /**
     * この特徴が属する店舗
     *
     * @return BelongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /*
     * アクセサ
     */

    /**
     * 特徴の表示名を取得
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $features = self::getFeatures();
        return $features[$this->feature] ?? $this->feature;
    }

    /**
     * 特徴の説明を取得
     *
     * @return string|null
     */
    public function getDescriptionAttribute(): ?string
    {
        $descriptions = self::getFeatureDescriptions();
        return $descriptions[$this->feature] ?? null;
    }

    /**
     * 特徴のカテゴリを取得
     *
     * @return string|null
     */
    public function getCategoryAttribute(): ?string
    {
        $categories = self::getFeatureCategories();
        
        foreach ($categories as $categoryKey => $category) {
            if (in_array($this->feature, $category['features'])) {
                return $categoryKey;
            }
        }
        
        return null;
    }

    /**
     * 特徴のカテゴリ名を取得
     *
     * @return string|null
     */
    public function getCategoryNameAttribute(): ?string
    {
        $category = $this->category;
        if (!$category) {
            return null;
        }
        
        $categories = self::getFeatureCategories();
        return $categories[$category]['name'] ?? null;
    }

    /**
     * API レスポンス用にカテゴリをフォーマット
     */
    public static function formatCategoriesForApi(): array
    {
        $categories = self::getFeatureCategories();
        $features = self::getFeatures();
        $descriptions = self::getFeatureDescriptions();
        
        $formatted = [];
        foreach ($categories as $categoryKey => $category) {
            $formatted[$categoryKey] = [
                'name' => $category['name'],
                'features' => []
            ];
            
            foreach ($category['features'] as $featureKey) {
                $formatted[$categoryKey]['features'][] = [
                    'key' => $featureKey,
                    'name' => $features[$featureKey] ?? $featureKey,
                    'description' => $descriptions[$featureKey] ?? null,
                ];
            }
        }
        
        return $formatted;
    }

    /**
     * クエリに特徴フィルターを適用（カテゴリ内 AND）
     */
    public static function applyFeatureFilters($query, array $features): void
    {
        $categories = self::getFeatureCategories();
        
        // カテゴリ別に分類
        $featuresByCategory = [];
        foreach ($features as $feature) {
            foreach ($categories as $categoryKey => $category) {
                if (in_array($feature, $category['features'])) {
                    if (!isset($featuresByCategory[$categoryKey])) {
                        $featuresByCategory[$categoryKey] = [];
                    }
                    $featuresByCategory[$categoryKey][] = $feature;
                    break;
                }
            }
        }
        
        // カテゴリ内の各特徴を AND 検索
        foreach ($featuresByCategory as $categoryKey => $categoryFeatures) {
            foreach ($categoryFeatures as $feature) {
                $query->whereExists(function ($subQ) use ($feature) {
                    $subQ->select(DB::raw(1))
                        ->from('shop_features')
                        ->whereColumn('shop_features.shop_id', 'shops.id')
                        ->where('shop_features.feature', $feature);
                });
            }
        }
    }

    /*
     * スコープ
     */

    /**
     * 特定の店舗の特徴を取得するスコープ
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $shopId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForShop($query, int $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    /**
     * 特定の特徴を取得するスコープ
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $feature
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithFeature($query, string $feature)
    {
        return $query->where('feature', $feature);
    }

    /**
     * ゲームスタイル特徴のみを取得するスコープ
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGameStyleFeatures($query)
    {
        $gameStyleFeatures = self::getFeatureCategories()['game_style']['features'];
        return $query->whereIn('feature', $gameStyleFeatures);
    }

    /**
     * スタッフ・プロ特徴のみを取得するスコープ
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStaffFeatures($query)
    {
        $staffFeatures = self::getFeatureCategories()['staff']['features'];
        return $query->whereIn('feature', $staffFeatures);
    }

    /**
     * 健康麻雀・ノーレート店舗を取得するスコープ
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHealthyMahjong($query)
    {
        return $query->whereIn('feature', [self::FEATURE_HEALTH, self::FEATURE_NO_RATE]);
    }

    /**
     * プロ在籍店舗を取得するスコープ
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithPro($query)
    {
        return $query->whereIn('feature', [self::FEATURE_MALE_PRO, self::FEATURE_FEMALE_PRO]);
    }
}