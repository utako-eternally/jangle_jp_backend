<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoStationLine extends Model
{
    use HasFactory;
    protected $table = 'geo_station_lines';

    protected $fillable = [
        'name',
        'name_kana'
    ];

    /**
     * この路線に属する駅を取得
     */
    public function stations(): HasMany
    {
        return $this->hasMany(GeoStation::class, 'station_line_id');
    }

    /**
     * 特定の都道府県内のこの路線の駅を取得
     */
    public function stationsInPrefecture($prefectureId)
    {
        return $this->stations()
            ->where('prefecture_id', $prefectureId)
            ->orderBy('name_kana')
            ->get();
    }

    /**
     * この路線が通過する都道府県を取得
     */
    public function prefectures()
    {
        return $this->belongsToMany(
            GeoPrefecture::class,
            'geo_station_prefecture_lines',
            'station_line_id',
            'prefecture_id'
        );
    }

}
