<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopStation extends Model
{
    use HasFactory;

    protected $table = 'shop_stations';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'shop_id',
        'station_id',
        'station_group_id',
        'distance_km',
        'is_nearest',
        'walking_minutes',
        'accuracy',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'distance_km' => 'float',
        'is_nearest' => 'boolean',
        'walking_minutes' => 'integer',
    ];

    /*
     * リレーション
     */

    /**
     * この関係に対応する店舗
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * この関係に対応する駅
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(GeoStation::class, 'station_id');
    }

    /**
     * この関係に対応する駅グループ
     */
    public function stationGroup(): BelongsTo
    {
        return $this->belongsTo(GeoStationGroup::class, 'station_group_id');
    }

    /**
     * モデルの「起動」メソッド
     */
    protected static function boot()
    {
        parent::boot();

        // 保存前の処理
        static::saving(function ($model) {
            // station_idが設定されていて、station_group_idが未設定の場合
            if ($model->station_id && !$model->station_group_id) {
                $station = GeoStation::find($model->station_id);
                if ($station && $station->station_group_id) {
                    $model->station_group_id = $station->station_group_id;
                }
            }

            // 徒歩時間の自動計算（未設定の場合）
            if ($model->distance_km && !$model->walking_minutes) {
                $model->walking_minutes = $model->calculateWalkingMinutes();
            }

            // 精度の自動設定（未設定の場合）
            if ($model->distance_km && !$model->accuracy) {
                $model->accuracy = $model->determineAccuracy();
            }
        });
    }

    /*
     * スコープ
     */

    /**
     * 最寄り駅のみを取得するスコープ
     */
    public function scopeNearest($query)
    {
        return $query->where('is_nearest', true);
    }

    /**
     * 距離順に並べるスコープ
     */
    public function scopeOrderByDistance($query, $direction = 'asc')
    {
        return $query->orderBy('distance_km', $direction);
    }

    /**
     * 指定距離以内の駅を取得するスコープ
     */
    public function scopeWithinDistance($query, float $maxDistance)
    {
        return $query->where('distance_km', '<=', $maxDistance);
    }

    /**
     * 特定の店舗の駅情報を取得するスコープ
     */
    public function scopeForShop($query, int $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    /**
     * 駅グループでの検索スコープ
     */
    public function scopeByStationGroup($query, $stationGroupId)
    {
        return $query->where('station_group_id', $stationGroupId);
    }

    /**
     * 駅グループごとにグループ化して取得
     */
    public function scopeGroupedByStation($query)
    {
        return $query->with(['station.line', 'stationGroup'])
            ->orderBy('distance_km')
            ->get()
            ->groupBy('station_group_id');
    }

    /*
     * ヘルパーメソッド
     */

    /**
     * 徒歩時間を自動計算（時速4kmで計算）
     */
    public function calculateWalkingMinutes(): int
    {
        // 徒歩時速4km（分速約67m）で計算
        return (int) round($this->distance_km * 1000 / 67);
    }

    /**
     * 距離の精度を判定
     */
    public function determineAccuracy(): string
    {
        if ($this->distance_km <= 0.1) {
            return 'high';  // 100m以内
        } elseif ($this->distance_km <= 0.5) {
            return 'medium'; // 500m以内
        } else {
            return 'low';    // それ以上
        }
    }

    /**
     * 最寄り駅として設定
     */
    public function setAsNearest(): bool
    {
        // 同じ店舗の他の駅の最寄りフラグをfalseに
        self::where('shop_id', $this->shop_id)
            ->where('id', '!=', $this->id)
            ->update(['is_nearest' => false]);

        // 自分を最寄り駅に設定
        $this->is_nearest = true;
        return $this->save();
    }

    /**
     * 表示用の距離文字列を取得
     */
    public function getFormattedDistanceAttribute(): string
    {
        if ($this->distance_km < 1) {
            return round($this->distance_km * 1000) . 'm';
        } else {
            return round($this->distance_km, 1) . 'km';
        }
    }

    /**
     * 表示用の徒歩時間文字列を取得
     */
    public function getFormattedWalkingTimeAttribute(): string
    {
        $minutes = $this->walking_minutes ?? $this->calculateWalkingMinutes();
        return $minutes . '分';
    }
}
