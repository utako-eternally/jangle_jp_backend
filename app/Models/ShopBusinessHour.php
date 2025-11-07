<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopBusinessHour extends Model
{
    protected $table = 'shop_business_hours';

    protected $fillable = [
        'shop_id',
        'day_of_week',
        'is_closed',
        'is_24h',
        'open_time',
        'close_time',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
        'is_24h' => 'boolean',
    ];

    // 曜日の定数
    public const DAY_SUNDAY = 0;
    public const DAY_MONDAY = 1;
    public const DAY_TUESDAY = 2;
    public const DAY_WEDNESDAY = 3;
    public const DAY_THURSDAY = 4;
    public const DAY_FRIDAY = 5;
    public const DAY_SATURDAY = 6;
    public const DAY_HOLIDAY = 7;

    // 曜日名マップ
    public const DAY_NAMES = [
        self::DAY_SUNDAY => '日曜日',
        self::DAY_MONDAY => '月曜日',
        self::DAY_TUESDAY => '火曜日',
        self::DAY_WEDNESDAY => '水曜日',
        self::DAY_THURSDAY => '木曜日',
        self::DAY_FRIDAY => '金曜日',
        self::DAY_SATURDAY => '土曜日',
        self::DAY_HOLIDAY => '祝日',
    ];

    /**
     * この営業時間が属する店舗
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * 曜日名を取得
     */
    public function getDayName(): string
    {
        return self::DAY_NAMES[$this->day_of_week] ?? '';
    }

    /**
     * 営業時間の表示テキストを取得
     */
    public function getDisplayText(): string
    {
        if ($this->is_closed) {
            return '定休日';
        }

        if ($this->is_24h) {
            return '24時間営業';
        }

        if ($this->open_time && $this->close_time) {
            return substr($this->open_time, 0, 5) . ' - ' . substr($this->close_time, 0, 5);
        }

        return '営業';
    }
}