<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopPlan;
use App\Models\ShopPlanPayment;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ShopPlanController extends Controller
{
    use ApiResponse;

    /**
     * 店舗の現在のプラン情報を取得
     */
    public function getCurrentPlan($shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            // オーナーまたは管理者のみ閲覧可能
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このプラン情報を閲覧する権限がありません。', 403);
            }

            $activePlan = $shop->activePlan;

            if (!$activePlan) {
                return $this->successResponse([
                    'has_plan' => false,
                    'plan_type' => 'free',
                    'status' => null,
                    'is_paid_plan' => false,
                    'can_use_line' => false,
                    'can_use_gallery' => false,
                    'can_use_blog' => false,
                ], '現在プランはありません（無料プラン）');
            }

            return $this->successResponse([
                'has_plan' => true,
                'plan' => [
                    'id' => $activePlan->id,
                    'plan_type' => $activePlan->plan_type,
                    'status' => $activePlan->status,
                    'started_at' => $activePlan->started_at,
                    'expires_at' => $activePlan->expires_at,
                    'remaining_days' => $activePlan->getRemainingDays(),
                    'expires_in_human' => $activePlan->getExpiresInHuman(),
                    'auto_renew' => $activePlan->auto_renew,
                    'is_valid' => $activePlan->isValid(),
                ],
                'is_paid_plan' => $shop->isPaidPlan(),
                'can_use_line' => $shop->canUseLine(),
                'can_use_gallery' => $shop->canUseGallery(),
                'can_use_blog' => $shop->canUseBlog(),
            ], 'プラン情報を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('プラン情報取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);
            return $this->errorResponse('プラン情報の取得に失敗しました。', 500);
        }
    }

    /**
     * 店舗のプラン履歴を取得
     */
    public function getPlanHistory($shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            // オーナーまたは管理者のみ閲覧可能
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このプラン履歴を閲覧する権限がありません。', 403);
            }

            $plans = $shop->plans()
                ->orderBy('started_at', 'desc')
                ->get()
                ->map(function ($plan) {
                    return [
                        'id' => $plan->id,
                        'plan_type' => $plan->plan_type,
                        'status' => $plan->status,
                        'started_at' => $plan->started_at,
                        'expires_at' => $plan->expires_at,
                        'cancelled_at' => $plan->cancelled_at,
                        'auto_renew' => $plan->auto_renew,
                        'remaining_days' => $plan->getRemainingDays(),
                    ];
                });

            return $this->successResponse(
                $plans,
                'プラン履歴を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('プラン履歴取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);
            return $this->errorResponse('プラン履歴の取得に失敗しました。', 500);
        }
    }

    /**
     * 有料プランを開始（管理者用・開発段階）
     * 本番では決済システムと連携
     */
    public function startPaidPlan(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'duration_months' => 'nullable|integer|min:1|max:12',
        ], [
            'duration_months.integer' => '期間は整数で指定してください。',
            'duration_months.min' => '期間は最低1ヶ月です。',
            'duration_months.max' => '期間は最大12ヶ月です。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'プラン開始情報に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);

            // 権限チェック（開発段階では緩和）
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このプランを開始する権限がありません。', 403);
            }

            DB::beginTransaction();
            try {
                // 既存のアクティブなプランがあればキャンセル
                $existingPlan = $shop->activePlan;
                if ($existingPlan) {
                    $existingPlan->cancel();
                }

                // 新しいプランを作成
                $durationMonths = $request->input('duration_months', 1);
                $startedAt = now();
                $expiresAt = now()->addMonths($durationMonths);

                $plan = ShopPlan::create([
                    'shop_id' => $shopId,
                    'plan_type' => ShopPlan::PLAN_TYPE_PAID,
                    'status' => ShopPlan::STATUS_ACTIVE,
                    'started_at' => $startedAt,
                    'expires_at' => $expiresAt,
                    'auto_renew' => true,
                ]);

                // 支払い記録を作成（開発段階ではダミー）
                $payment = ShopPlanPayment::create([
                    'shop_id' => $shopId,
                    'shop_plan_id' => $plan->id,
                    'amount' => 0, // 本番では実際の金額
                    'payment_method' => 'manual',
                    'payment_status' => ShopPlanPayment::STATUS_COMPLETED,
                    'paid_at' => now(),
                    'period_start' => $startedAt,
                    'period_end' => $expiresAt,
                    'memo' => '開発段階での手動有効化',
                ]);

                DB::commit();

                Log::info('有料プラン開始成功', [
                    'shop_id' => $shopId,
                    'plan_id' => $plan->id,
                    'user_id' => Auth::id(),
                    'duration_months' => $durationMonths,
                ]);

                return $this->successResponse([
                    'plan' => [
                        'id' => $plan->id,
                        'plan_type' => $plan->plan_type,
                        'status' => $plan->status,
                        'started_at' => $plan->started_at,
                        'expires_at' => $plan->expires_at,
                        'remaining_days' => $plan->getRemainingDays(),
                    ],
                    'payment' => [
                        'id' => $payment->id,
                        'amount' => $payment->amount,
                        'paid_at' => $payment->paid_at,
                    ],
                ], '有料プランを開始しました', 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('有料プラン開始エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse('有料プランの開始に失敗しました。', 500);
        }
    }

    /**
     * プランをキャンセル（次回更新を停止）
     */
    public function cancelPlan($shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このプランをキャンセルする権限がありません。', 403);
            }

            $activePlan = $shop->activePlan;

            if (!$activePlan) {
                return $this->errorResponse('キャンセルするプランがありません。', 404);
            }

            if (!$activePlan->isActive()) {
                return $this->errorResponse('アクティブなプランではありません。', 400);
            }

            DB::beginTransaction();
            try {
                $activePlan->cancel();

                DB::commit();

                Log::info('プランキャンセル成功', [
                    'shop_id' => $shopId,
                    'plan_id' => $activePlan->id,
                    'user_id' => Auth::id(),
                ]);

                return $this->successResponse([
                    'plan' => [
                        'id' => $activePlan->id,
                        'status' => $activePlan->status,
                        'cancelled_at' => $activePlan->cancelled_at,
                        'expires_at' => $activePlan->expires_at,
                        'remaining_days' => $activePlan->getRemainingDays(),
                    ],
                    'message' => 'プランをキャンセルしました。' . $activePlan->expires_at->format('Y年m月d日') . 'まで利用可能です。',
                ], 'プランをキャンセルしました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('プランキャンセルエラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse('プランのキャンセルに失敗しました。', 500);
        }
    }

    /**
     * 支払い履歴を取得
     */
    public function getPaymentHistory($shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この支払い履歴を閲覧する権限がありません。', 403);
            }

            $payments = $shop->planPayments()
                ->with('shopPlan')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'amount' => $payment->amount,
                        'payment_method' => $payment->payment_method,
                        'payment_status' => $payment->payment_status,
                        'paid_at' => $payment->paid_at,
                        'period_start' => $payment->period_start,
                        'period_end' => $payment->period_end,
                        'transaction_id' => $payment->transaction_id,
                        'plan_type' => $payment->shopPlan->plan_type ?? null,
                        'created_at' => $payment->created_at,
                    ];
                });

            return $this->successResponse(
                $payments,
                '支払い履歴を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('支払い履歴取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);
            return $this->errorResponse('支払い履歴の取得に失敗しました。', 500);
        }
    }

    /**
     * 期限切れプランを一括処理（バッチ処理用）
     */
    public function expireExpiredPlans()
    {
        try {
            // 管理者のみ実行可能
            // 実運用時はコメントアウトを外す
            // if (!Auth::user()->isAdmin()) {
            //     return $this->errorResponse('管理者権限が必要です。', 403);
            // }

            DB::beginTransaction();
            try {
                // 期限切れプランを取得
                $expiredPlans = ShopPlan::expired()->get();

                $count = 0;
                foreach ($expiredPlans as $plan) {
                    if ($plan->markAsExpired()) {
                        $count++;
                        Log::info('プラン期限切れ処理', [
                            'shop_id' => $plan->shop_id,
                            'plan_id' => $plan->id,
                        ]);
                    }
                }

                DB::commit();

                return $this->successResponse([
                    'expired_count' => $count,
                ], "{$count}件のプランを期限切れにしました");

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('期限切れプラン処理エラー: ' . $e->getMessage());
            return $this->errorResponse('期限切れプランの処理に失敗しました。', 500);
        }
    }
}