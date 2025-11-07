<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'nick_name',
        'avatar_paths',
        'system_role',
        'status',
        'last_login_at',
        'reset_token',
        'reset_token_expires_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'reset_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'reset_token_expires_at' => 'datetime',
        'password' => 'hashed',
        'avatar_paths' => 'array',
    ];

    /**
     * ユーザーのステータス定数
     */
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_SUSPENDED = 'suspended';

    /**
     * システムロール定数
     */
    const ROLE_ADMIN = 'ADMIN';
    const ROLE_BUSINESS = 'BUSINESS';

    /**
     * フルネームを取得
     */
    public function getFullNameAttribute(): string
    {
        if ($this->first_name && $this->last_name) {
            return $this->first_name . ' ' . $this->last_name;
        }

        return $this->nick_name ?? $this->email;
    }

    /**
     * 表示名を取得（ニックネーム優先）
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->nick_name ?? $this->getFullNameAttribute();
    }

    /**
     * システム管理者かどうかを確認
     */
    public function isAdmin(): bool
    {
        return $this->system_role === self::ROLE_ADMIN;
    }

    /**
     * アクティブなユーザーかどうかを確認
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * ログインを記録
     */
    public function recordLogin(): void
    {
        $this->last_login_at = now();
        $this->save();
    }

    /**
     * パスワードリセットトークンを生成
     */
    public function generatePasswordResetToken(): string
    {
        $token = Str::random(60);
        
        $this->reset_token = hash('sha256', $token);
        $this->reset_token_expires_at = now()->addHours(24);
        $this->save();
        
        return $token;
    }

    /**
     * パスワードリセットトークンが有効かどうか
     */
    public function isValidPasswordResetToken(string $token): bool
    {
        if (!$this->reset_token || !$this->reset_token_expires_at) {
            return false;
        }

        return hash('sha256', $token) === $this->reset_token 
            && $this->reset_token_expires_at->isFuture();
    }

    /**
     * パスワードリセットトークンをクリア
     */
    public function clearPasswordResetToken(): void
    {
        $this->reset_token = null;
        $this->reset_token_expires_at = null;
        $this->save();
    }

    /**
     * このユーザーが所有する雀荘を取得
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class, 'user_id');
    }

    /**
     * アバター画像のURLを取得（指定サイズ）
     */
    public function getAvatarUrl(string $size = 'medium'): ?string
    {
        if (!$this->avatar_paths || !isset($this->avatar_paths[$size])) {
            return null;
        }

        return asset('storage/' . $this->avatar_paths[$size]);
    }

    /**
     * アバター画像を持っているか
     */
    public function hasAvatar(): bool
    {
        return !empty($this->avatar_paths);
    }
}