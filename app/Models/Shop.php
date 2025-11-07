<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    use HasFactory;

    protected $table = 'shops';

    protected $fillable = [
        'name',
        'user_id',
        'description',
        'main_image_paths',
        'logo_image_paths',
        'phone',
        'website_url',
        'open_hours_text',
        'table_count',
        'score_table_count',
        'auto_table_count',
        'prefecture_id',
        'city_id',
        'address_pref',
        'address_city',
        'address_town',
        'address_street',
        'address_building',
        'lat',
        'lng',
        'is_verified',
        'verified_at',
        'line_official_id',
        'line_add_url',
        'line_qr_code_path',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'is_verified' => 'boolean',
        'table_count' => 'integer',
        'score_table_count' => 'integer',
        'auto_table_count' => 'integer',
        'main_image_paths' => 'array',
        'logo_image_paths' => 'array',
        'verified_at' => 'datetime',
    ];

    /**
     * この店舗が属する都道府県を取得
     */
    public function prefecture(): BelongsTo
    {
        return $this->belongsTo(GeoPrefecture::class, 'prefecture_id');
    }

    /**
     * この店舗が属する市区町村を取得
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(GeoCity::class, 'city_id');
    }

    /**
     * この店舗の駅情報を取得
     */
    public function shopStations(): HasMany
    {
        return $this->hasMany(ShopStation::class, 'shop_id');
    }

    /**
     * この店舗のギャラリー画像を取得
     */
    public function images(): HasMany
    {
        return $this->hasMany(ShopImage::class, 'shop_id')->orderBy('display_order');
    }

    /**
     * この店舗の営業時間を取得
     */
    public function businessHours(): HasMany
    {
        return $this->hasMany(ShopBusinessHour::class, 'shop_id')->orderBy('day_of_week');
    }

    /**
     * 特定曜日の営業時間を取得
     */
    public function getBusinessHourByDay(int $dayOfWeek): ?ShopBusinessHour
    {
        return $this->businessHours()
            ->where('day_of_week', $dayOfWeek)
            ->first();
    }

    /**
     * 本日の営業時間を取得
     */
    public function getTodayBusinessHour(): ?ShopBusinessHour
    {
        $today = now()->dayOfWeek; // 0=日曜, 6=土曜
        
        // 祝日判定（簡易版 - 必要に応じて祝日判定ロジックを実装）
        // $isHoliday = $this->isHoliday(now());
        // if ($isHoliday) {
        //     return $this->getBusinessHourByDay(ShopBusinessHour::DAY_HOLIDAY);
        // }
        
        return $this->getBusinessHourByDay($today);
    }

    /**
     * 営業時間の表示テキストを取得（全曜日）
     */
    public function getBusinessHoursDisplayText(): array
    {
        $result = [];
        
        foreach ($this->businessHours as $hour) {
            $result[$hour->day_of_week] = [
                'day_name' => $hour->getDayName(),
                'display_text' => $hour->getDisplayText(),
            ];
        }
        
        return $result;
    }

    /**
     * 現在営業中かどうか
     */
    public function isOpenNow(): bool
    {
        $todayHour = $this->getTodayBusinessHour();
        
        if (!$todayHour || $todayHour->is_closed) {
            return false;
        }
        
        if ($todayHour->is_24h) {
            return true;
        }
        
        $now = now()->format('H:i:s');
        return $todayHour->open_time <= $now && $now <= $todayHour->close_time;
    }

    /**
     * この店舗の最寄り駅を取得
     */
    public function nearestStation()
    {
        return $this->shopStations()
            ->where('is_nearest', true)
            ->with(['station', 'station.stationLine'])
            ->first();
    }

    /**
     * この店舗のサブ駅を取得
     */
    public function subStations()
    {
        return $this->shopStations()
            ->where('is_nearest', false)
            ->with(['station', 'station.stationLine'])
            ->orderBy('distance_km')
            ->get();
    }

    /**
     * 完全な住所を取得
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_pref,
            $this->address_city,
            $this->address_town,
            $this->address_street,
            $this->address_building,
        ]);

        return implode('', $parts);
    }

    /**
     * 座標を持っているかを確認
     */
    public function hasCoordinates(): bool
    {
        return !is_null($this->lat) && !is_null($this->lng);
    }

    /**
     * オートテーブルを持っているかを確認
     */
    public function hasAutoTables(): bool
    {
        return $this->auto_table_count > 0;
    }

    /**
     * スコアテーブルを持っているかを確認
     */
    public function hasScoreTables(): bool
    {
        return $this->score_table_count > 0;
    }

    /**
     * この雀荘のオーナーを取得
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 指定ユーザーがオーナーかどうかを確認
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * この店舗の特徴を取得
     */
    public function features(): HasMany
    {
        return $this->hasMany(ShopFeature::class, 'shop_id');
    }

    /**
     * 健康麻雀店舗かどうか
     */
    public function isHealthMahjong(): bool
    {
        return $this->features()
            ->where('feature', ShopFeature::FEATURE_HEALTH)
            ->exists();
    }

    /**
     * ノーレート店舗かどうか
     */
    public function isNoRate(): bool
    {
        return $this->features()
            ->where('feature', ShopFeature::FEATURE_NO_RATE)
            ->exists();
    }

    /**
     * プロ雀士在籍店舗かどうか
     */
    public function hasProStaff(): bool
    {
        return $this->features()
            ->whereIn('feature', [
                ShopFeature::FEATURE_MALE_PRO,
                ShopFeature::FEATURE_FEMALE_PRO
            ])
            ->exists();
    }

    /**
     * 女性麻雀店舗かどうか
     */
    public function isGirlMahjong(): bool
    {
        return $this->features()
            ->where('feature', ShopFeature::FEATURE_GIRL_MAHJONG)
            ->exists();
    }

    /**
     * 女性プロ在籍店舗かどうか
     */
    public function hasFemaleProStaff(): bool
    {
        return $this->features()
            ->where('feature', ShopFeature::FEATURE_FEMALE_PRO)
            ->exists();
    }

    /**
     * 男性プロ在籍店舗かどうか
     */
    public function hasMaleProStaff(): bool
    {
        return $this->features()
            ->where('feature', ShopFeature::FEATURE_MALE_PRO)
            ->exists();
    }

    /**
     * 特定の特徴を持っているか
     */
    public function hasFeature(string $feature): bool
    {
        return $this->features()
            ->where('feature', $feature)
            ->exists();
    }

    /**
     * この店舗のルールを取得
     */
    public function rules(): HasMany
    {
        return $this->hasMany(ShopRule::class, 'shop_id');
    }

    /**
     * 特定のルールを持っているか
     */
    public function hasRule(string $rule): bool
    {
        return $this->rules()
            ->where('rule', $rule)
            ->exists();
    }

    /**
     * クイタンありかどうか
     */
    public function hasKuitanAllowed(): bool
    {
        return $this->hasRule(ShopRule::RULE_KUITAN_ALLOWED);
    }

    /**
     * 赤牌ありかどうか
     */
    public function hasRedTiles(): bool
    {
        return $this->hasRule(ShopRule::RULE_RED_TILES);
    }

    /**
     * 東風戦かどうか
     */
    public function isTonpu(): bool
    {
        return $this->hasRule(ShopRule::RULE_TONPU);
    }

    /**
     * 東南戦かどうか
     */
    public function isTonnan(): bool
    {
        return $this->hasRule(ShopRule::RULE_TONNAN);
    }

    /**
     * この店舗のメニューを取得
     */
    public function menus(): HasMany
    {
        return $this->hasMany(ShopMenu::class, 'shop_id');
    }

    /**
     * 利用可能なメニューを取得
     */
    public function availableMenus(): HasMany
    {
        return $this->hasMany(ShopMenu::class, 'shop_id')
            ->where('is_available', true);
    }

    /**
     * カテゴリ別メニューを取得
     */
    public function getMenusByCategory(string $category)
    {
        return $this->menus()
            ->where('category', $category)
            ->where('is_available', true)
            ->orderBy('item_name')
            ->get();
    }

    /**
     * この店舗のフリー設定を取得
     */
    public function frees(): HasMany
    {
        return $this->hasMany(ShopFree::class, 'shop_id');
    }

    /**
     * 3人打ちフリーを取得
     */
    public function threePlayerFree(): ?ShopFree
    {
        return $this->frees()
            ->where('game_format', ShopFree::GAME_FORMAT_THREE_PLAYER)
            ->first();
    }

    /**
     * 4人打ちフリーを取得
     */
    public function fourPlayerFree(): ?ShopFree
    {
        return $this->frees()
            ->where('game_format', ShopFree::GAME_FORMAT_FOUR_PLAYER)
            ->first();
    }

    /**
     * 3人打ちフリーがあるか
     */
    public function hasThreePlayerFree(): bool
    {
        return $this->threePlayerFree() !== null;
    }

    /**
     * 4人打ちフリーがあるか
     */
    public function hasFourPlayerFree(): bool
    {
        return $this->fourPlayerFree() !== null;
    }

    /**
     * この店舗のセット設定を取得
     */
    public function set(): HasOne
    {
        return $this->hasOne(ShopSet::class, 'shop_id');
    }

    /**
     * セット雀荘を提供しているか
     */
    public function hasSet(): bool
    {
        return $this->set !== null;
    }

    /**
     * 運営確認済みかどうか
     */
    public function isVerified(): bool
    {
        return $this->is_verified;
    }

    /**
     * 確認済み店舗のスコープ
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * メイン画像のURLを取得（指定サイズ）
     */
    public function getMainImageUrl(string $size = 'medium'): ?string
    {
        if (!$this->main_image_paths || !isset($this->main_image_paths[$size])) {
            return null;
        }

        return asset('storage/' . $this->main_image_paths[$size]);
    }

    /**
     * メイン画像を持っているか
     */
    public function hasMainImage(): bool
    {
        return !empty($this->main_image_paths);
    }

    /**
     * ギャラリー画像を持っているか
     */
    public function hasGalleryImages(): bool
    {
        return $this->images()->exists();
    }

    /**
     * ロゴ画像のURLを取得（指定サイズ）
     */
    public function getLogoImageUrl(string $size = 'medium'): ?string
    {
        if (!$this->logo_image_paths || !isset($this->logo_image_paths[$size])) {
            return null;
        }

        return asset('storage/' . $this->logo_image_paths[$size]);
    }

    /**
     * ロゴ画像を持っているか
     */
    public function hasLogoImage(): bool
    {
        return !empty($this->logo_image_paths);
    }

    // ========================================
    // LINE公式アカウント関連のメソッド
    // ========================================

    /**
     * LINE公式アカウント登録済みかチェック
     */
    public function hasLineAccount(): bool
    {
        return !empty($this->line_official_id);
    }

    /**
     * 友だち追加URLを取得
     * line_add_urlが未設定の場合はline_official_idから自動生成
     */
    public function getLineAddUrlAttribute($value): ?string
    {
        if ($value) {
            return $value;
        }

        if ($this->line_official_id) {
            return "https://line.me/R/ti/p/{$this->line_official_id}";
        }

        return null;
    }

    /**
     * QRコード画像のURLを取得
     */
    public function getLineQrCodeUrl(): ?string
    {
        if (!$this->line_qr_code_path) {
            return null;
        }

        return asset('storage/' . $this->line_qr_code_path);
    }

    /**
     * QRコード画像を持っているか
     */
    public function hasLineQrCode(): bool
    {
        return !empty($this->line_qr_code_path);
    }

    /**
     * この店舗のプラン
     */
    public function plans(): HasMany
    {
        return $this->hasMany(ShopPlan::class, 'shop_id');
    }

    /**
     * この店舗のアクティブなプラン
     */
    public function activePlan(): HasOne
    {
        return $this->hasOne(ShopPlan::class, 'shop_id')
            ->where('status', ShopPlan::STATUS_ACTIVE)
            ->latest('started_at');
    }

    /**
     * この店舗のプラン支払履歴
     */
    public function planPayments(): HasMany
    {
        return $this->hasMany(ShopPlanPayment::class, 'shop_id');
    }

    /**
     * 有料プランかどうか
     */
    public function isPaidPlan(): bool
    {
        $plan = $this->activePlan;
        
        if (!$plan) {
            return false;
        }
        
        return $plan->isPaid() && $plan->isValid();
    }

    /**
     * 無料プランかどうか
     */
    public function isFreePlan(): bool
    {
        return !$this->isPaidPlan();
    }

    /**
     * LINE機能が使えるかどうか
     */
    public function canUseLine(): bool
    {
        return $this->isPaidPlan();
    }

    /**
     * ギャラリー画像が使えるかどうか
     */
    public function canUseGallery(): bool
    {
        return $this->isPaidPlan();
    }

    /**
     * ブログ機能が使えるかどうか
     */
    public function canUseBlog(): bool
    {
        return $this->isPaidPlan();
    }

    /**
     * プランの残り日数を取得
     */
    public function getPlanRemainingDays(): ?int
    {
        $plan = $this->activePlan;
        
        if (!$plan) {
            return null;
        }
        
        return $plan->getRemainingDays();
    }

    public function services()
    {
        return $this->hasMany(ShopService::class);
    }
}