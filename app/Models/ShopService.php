<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopService extends Model
{
    use HasFactory;

    protected $table = 'shop_services';

    protected $fillable = [
        'shop_id',
        'service_type',
    ];

    // ===== JSONに自動的に追加するアクセサ =====
    protected $appends = [
        'service_type_name',
        'display_name',
        'category',
        'category_name',
    ];

    /**
     * サービスタイプの定義
     */
    public const SERVICE_TYPES = [
        'FREE_DRINK' => 'フリードリンク',
        'FREE_DRINK_SET' => 'セット飲み放題',
        'STUDENT_DISCOUNT' => '学割あり',
        'FEMALE_DISCOUNT' => '女性割引あり',
        'SENIOR_DISCOUNT' => 'シニア割引あり',
        'PARKING_AVAILABLE' => '駐車場あり',
        'PARKING_SUBSIDY' => '駐車料金補助',
        'NON_SMOKING' => '禁煙',
        'HEATED_TOBACCO_ALLOWED' => '加熱式タバコ可',
        'SMOKING_ALLOWED' => '喫煙可',
        'FOOD_AVAILABLE' => '食事あり',
        'ALCOHOL_AVAILABLE' => 'アルコールあり',
        'DELIVERY_MENU' => 'デリバリーメニューあり',
        'OUTSIDE_FOOD_ALLOWED' => '持ち込み可',
        'PRIVATE_ROOM' => '個室あり',
        'FEMALE_TOILET' => '女性専用トイレあり',
        'AUTO_TABLE' => '全自動卓あり',
        'SCORE_MANAGEMENT' => '成績管理あり',
        'FREE_WIFI' => 'Wi-Fi無料',
    ];

    /**
     * カテゴリ定義（types/models.ts のSERVICE_TYPESに対応）
     */
    public const CATEGORIES = [
        // ドリンク・割引
        'FREE_DRINK' => 'DRINK',
        'FREE_DRINK_SET' => 'DRINK',
        'STUDENT_DISCOUNT' => 'DISCOUNT',
        'FEMALE_DISCOUNT' => 'DISCOUNT',
        'SENIOR_DISCOUNT' => 'DISCOUNT',
        
        // 駐車場
        'PARKING_AVAILABLE' => 'PARKING',
        'PARKING_SUBSIDY' => 'PARKING',
        
        // 喫煙
        'NON_SMOKING' => 'SMOKING',
        'HEATED_TOBACCO_ALLOWED' => 'SMOKING',
        'SMOKING_ALLOWED' => 'SMOKING',
        
        // 飲食
        'FOOD_AVAILABLE' => 'FOOD',
        'ALCOHOL_AVAILABLE' => 'FOOD',
        'DELIVERY_MENU' => 'FOOD',
        'OUTSIDE_FOOD_ALLOWED' => 'FOOD',
        
        // 設備
        'PRIVATE_ROOM' => 'FACILITY',
        'FEMALE_TOILET' => 'FACILITY',
        'AUTO_TABLE' => 'FACILITY',
        'SCORE_MANAGEMENT' => 'FACILITY',
        'FREE_WIFI' => 'FACILITY',
    ];

    /**
     * カテゴリ名の定義
     */
    public const CATEGORY_NAMES = [
        'DRINK' => 'ドリンク・割引',
        'DISCOUNT' => '割引',
        'PARKING' => '駐車場',
        'SMOKING' => '喫煙',
        'FOOD' => '飲食',
        'FACILITY' => '設備',
    ];

    /**
     * Shopモデルとのリレーション
     */
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * サービスタイプの日本語名を取得
     */
    public function getServiceTypeNameAttribute(): string
    {
        return self::SERVICE_TYPES[$this->service_type] ?? $this->service_type;
    }

    /**
     * ===== 追加: 表示名アクセサ =====
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->service_type_name;
    }

    /**
     * ===== 追加: カテゴリアクセサ =====
     */
    public function getCategoryAttribute(): ?string
    {
        return self::CATEGORIES[$this->service_type] ?? null;
    }

    /**
     * ===== 追加: カテゴリ名アクセサ =====
     */
    public function getCategoryNameAttribute(): ?string
    {
        $category = $this->category;
        return $category ? (self::CATEGORY_NAMES[$category] ?? null) : null;
    }
}