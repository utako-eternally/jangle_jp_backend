<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoPrefecture extends Model
{
    use HasFactory;
    protected $table = 'geo_prefectures';

    protected $fillable = [
        'name',
        'name_kana',
        'slug',
    ];

    /*
     * この都道府県に属する市区町村を取得
     */
    public function cities(): HasMany
    {
        return $this->hasMany(GeoCity::class, 'prefecture_id');
    }

    /*
     * この都道府県に属する路線を取得
     */
    public function stationLines()
    {
        return $this->belongsToMany(
            GeoStationLine::class,
            'geo_station_prefecture_lines',
            'prefecture_id',
            'station_line_id',
        );
    }
}
