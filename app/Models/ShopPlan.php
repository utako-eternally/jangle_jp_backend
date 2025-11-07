<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShopPlan extends Model
{
    use HasFactory;

    protected $table = 'shop_plans';

    // プランタイプの定数
    const PLAN_TYPE_FREE = 'free';
    const PLAN_TYPE_PAID = 'paid';

    // ステータスの定数
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shop_id',
        'plan_type',
        'status',
        'started_at',
        'expires_at',
        'cancelled_at',
        'auto_renew',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew' => 'boolean',
    ];

    /**
     * このプランが属する店舗
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * このプランの支払履歴
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ShopPlanPayment::class, 'shop_plan_id');
    }

    /**
     * 有料プランかどうか
     */
    public function isPaid(): bool
    {
        return $this->plan_type === self::PLAN_TYPE_PAID;
    }

    /**
     * 無料プランかどうか
     */
    public function isFree(): bool
    {
        return $this->plan_type === self::PLAN_TYPE_FREE;
    }

    /**
     * アクティブなプランかどうか
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * 期限切れかどうか
     */
    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        if ($this->expires_at && $this->expires_at < now()) {
            return true;
        }

        return false;
    }

    /**
     * キャンセル済みかどうか
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * プランが有効かどうか（アクティブかつ期限内）
     */
    public function isValid(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        return true;
    }

    /**
     * 残り日数を取得
     */
    public function getRemainingDays(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }

        $diff = now()->diffInDays($this->expires_at, false);
        return $diff > 0 ? (int)$diff : 0;
    }

    /**
     * 期限切れまでの時間を人間が読める形式で取得
     */
    public function getExpiresInHuman(): ?string
    {
        if (!$this->expires_at) {
            return null;
        }

        if ($this->isExpired()) {
            return '期限切れ';
        }

        return $this->expires_at->diffForHumans();
    }

    /**
     * プランを期限切れにする
     */
    public function markAsExpired(): bool
    {
        return $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }

    /**
     * プランをキャンセルする
     */
    public function cancel(): bool
    {
        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);
    }

    /**
     * プランを更新（1ヶ月延長）
     */
    public function renew(): bool
    {
        $newExpiresAt = $this->expires_at 
            ? $this->expires_at->addMonth() 
            : now()->addMonth();

        return $this->update([
            'expires_at' => $newExpiresAt,
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * アクティブなプランのスコープ
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 有料プランのスコープ
     */
    public function scopePaid($query)
    {
        return $query->where('plan_type', self::PLAN_TYPE_PAID);
    }

    /**
     * 期限切れが近いプランのスコープ（指定日数以内）
     */
    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    /**
     * 期限切れプランのスコープ
     */
    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_EXPIRED)
              ->orWhere(function ($q2) {
                  $q2->where('status', self::STATUS_ACTIVE)
                     ->whereNotNull('expires_at')
                     ->where('expires_at', '<', now());
              });
        });
    }
}