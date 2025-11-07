<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\Fluent\Concerns\Has;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoStation extends Model
{
    use HasFactory;
    protected $table = 'geo_stations';

    protected $fillable = [
        'station_group_id',
        'station_line_id',
        'prefecture_id',
        'city_id',
        'name',
        'name_kana',
        'slug',
        'latitude',
        'longitude',
        'is_grouped',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'is_grouped' => 'boolean',
    ];

    /*
     * この駅が属する路線を取得
     */
    public function stationLine(): BelongsTo
    {
        return $this->belongsTo(GeoStationLine::class, 'station_line_id');
    }

    /*
     * この駅が属する都道府県を取得
     */
    public function prefecture(): BelongsTo
    {
        return $this->belongsTo(GeoPrefecture::class, 'prefecture_id');
    }

    /**
     * 所属する市区町村
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(GeoCity::class, 'city_id');
    }

    /*
     * この駅が属するグループを取得
     */
    public function stationGroup(): BelongsTo
    {
        return $this->belongsTo(GeoStationGroup::class, 'station_group_id');
    }

    /*
     * この駅が駅グループに属しているかどうかを取得
     */
    public function hasStationGroup(): bool
    {
        return $this->station_group_id !== null && $this->is_grouped;
    }

    /*
     * 駅グループに属する場合、同じグループの駅を取得
     */
    public function getOtherStationsInGroup()
    {
        if (!$this->hasStationGroup()) {
            return collect([]);
        }

        return GeoStation::where('station_group_id', $this->station_group_id)
            ->where('id', '!=', $this->id)
            ->get();
    }

    /**
     * この駅に関連する店舗情報
     * 
     * @return HasMany
     */
    public function shopStations(): HasMany
    {
        return $this->hasMany(ShopStation::class, 'station_id');
    }

    /**
     * この駅を最寄り駅とする店舗
     * 
     * @return HasMany
     */
    public function nearestShops(): HasMany
    {
        return $this->shopStations()->where('is_nearest', true);
    }

    /**
     * この駅から指定距離以内の店舗を取得
     * 
     * @param float $maxDistance 最大距離（km）
     * @return HasMany
     */
    public function nearbyShops(float $maxDistance = 1.0): HasMany
    {
        return $this->shopStations()
            ->where('distance_km', '<=', $maxDistance)
            ->with('shop')
            ->orderBy('distance_km');
    }

    /**
     * 駅名で検索（あいまい検索）
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $name
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearchByName($query, string $name)
    {
        return $query->where('name', 'LIKE', "%{$name}%")
            ->orWhere('name_kana', 'LIKE', "%{$name}%");
    }

    /**
     * 都道府県で絞り込み
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $prefectureId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByPrefecture($query, int $prefectureId)
    {
        return $query->where('prefecture_id', $prefectureId);
    }

    /**
     * 市区町村で絞り込み
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $cityId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCity($query, int $cityId)
    {
        return $query->where('city_id', $cityId);
    }

    /**
     * 路線で絞り込み
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $lineId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByLine($query, int $lineId)
    {
        return $query->where('station_line_id', $lineId);
    }
}