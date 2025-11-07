<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoStationGroup extends Model
{
    use HasFactory;

    protected $table = 'geo_station_groups';

    protected $fillable = [
        'name',
        'name_kana',
        'slug',
        'prefecture_id',
    ];

    /**
     * このグループが属する都道府県を取得
     */
    public function prefecture(): BelongsTo
    {
        return $this->belongsTo(GeoPrefecture::class, 'prefecture_id');
    }

    /**
     * このグループに属する駅を取得
     */
    public function stations(): HasMany
    {
        return $this->hasMany(GeoStation::class, 'station_group_id');
    }
}
