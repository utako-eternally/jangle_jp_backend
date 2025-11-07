<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShopPlanPayment extends Model
{
    use HasFactory;

    protected $table = 'shop_plan_payments';

    // 支払ステータスの定数
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'shop_id',
        'shop_plan_id',
        'amount',
        'payment_method',
        'payment_status',
        'paid_at',
        'period_start',
        'period_end',
        'transaction_id',
        'memo',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    /**
     * この支払いが属する店舗
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * この支払いが属するプラン
     */
    public function shopPlan(): BelongsTo
    {
        return $this->belongsTo(ShopPlan::class, 'shop_plan_id');
    }

    /**
     * 支払い完了済みかどうか
     */
    public function isCompleted(): bool
    {
        return $this->payment_status === self::STATUS_COMPLETED;
    }

    /**
     * 支払い保留中かどうか
     */
    public function isPending(): bool
    {
        return $this->payment_status === self::STATUS_PENDING;
    }

    /**
     * 支払い失敗かどうか
     */
    public function isFailed(): bool
    {
        return $this->payment_status === self::STATUS_FAILED;
    }

    /**
     * 返金済みかどうか
     */
    public function isRefunded(): bool
    {
        return $this->payment_status === self::STATUS_REFUNDED;
    }

    /**
     * 支払いを完了にする
     */
    public function markAsCompleted(): bool
    {
        return $this->update([
            'payment_status' => self::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);
    }

    /**
     * 支払いを失敗にする
     */
    public function markAsFailed(): bool
    {
        return $this->update([
            'payment_status' => self::STATUS_FAILED,
        ]);
    }

    /**
     * 返金処理
     */
    public function refund(): bool
    {
        return $this->update([
            'payment_status' => self::STATUS_REFUNDED,
        ]);
    }

    /**
     * 完了済み支払いのスコープ
     */
    public function scopeCompleted($query)
    {
        return $query->where('payment_status', self::STATUS_COMPLETED);
    }

    /**
     * 保留中支払いのスコープ
     */
    public function scopePending($query)
    {
        return $query->where('payment_status', self::STATUS_PENDING);
    }

    /**
     * 失敗した支払いのスコープ
     */
    public function scopeFailed($query)
    {
        return $query->where('payment_status', self::STATUS_FAILED);
    }
}