<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSignup extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'role',
        'email_verified',
        'completed',
        'completed_at',
        'verification_token',
        'token_expires_at'
    ];

    protected $casts = [
        'email_verified' => 'boolean',
        'completed' => 'boolean',
        'completed_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'type' => 'string'
    ];

    /**
     * ユーザーリレーション
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * トークンが有効かチェック
     */
    public function isTokenValid(): bool
    {
        return $this->verification_token && 
               $this->token_expires_at && 
               $this->token_expires_at->isFuture();
    }

    /**
     * 有効なトークンを持つレコードを取得するスコープ
     */
    public function scopeValidToken($query, string $token)
    {
        return $query->where('verification_token', $token)
                    ->where('token_expires_at', '>', now())
                    ->where('completed', false);
    }

}