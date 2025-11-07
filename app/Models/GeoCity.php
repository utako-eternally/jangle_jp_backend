<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GeoCity extends Model
{
    use HasFactory;
    protected $table = 'geo_cities';

    protected $fillable = [
        'prefecture_id',
        'name',
        'name_kana',
        'slug',
    ];

    /*
     * この市区町村が属する都道府県を取得
     */
    public function prefecture(): BelongsTo
    {
        return $this->belongsTo(GeoPrefecture::class, 'prefecture_id');
    }

    /**
     * 市区町村内の駅一覧
     */
    public function stations(): HasMany
    {
        return $this->hasMany(GeoStation::class, 'city_id');
    }

    /**
     * 市区町村内の雀荘一覧
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class, 'city_id');
    }
}