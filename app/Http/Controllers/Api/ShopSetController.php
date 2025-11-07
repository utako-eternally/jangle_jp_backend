<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopSet;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopSetController extends Controller
{
    use ApiResponse;

    /**
     * 店舗のセット設定を取得
     */
    public function show(Request $request, $shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);
            
            $shopSet = ShopSet::where('shop_id', $shopId)->first();
            
            return $this->successResponse([
                'shop_info' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                ],
                'has_set' => $shopSet !== null,
                'set_data' => $shopSet ? $this->formatSetData($shopSet) : null,
            ], 'セット設定を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('セット設定取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);
            
            return $this->errorResponse('セット設定の取得に失敗しました。', 500);
        }
    }

    /**
     * セット設定を作成
     */
    public function store(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'price' => 'required|integer|min:0|max:999999',
        ], [
            'price.required' => '料金は必須です。',
            'price.integer' => '料金は整数で入力してください。',
            'price.min' => '料金は0円以上で入力してください。',
            'price.max' => '料金は999,999円以下で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'セット設定データに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);
            
            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗のセット設定を作成する権限がありません。', 403);
            }
            
            // 既存チェック
            $existingSet = ShopSet::where('shop_id', $shopId)->first();
                
            if ($existingSet) {
                return $this->errorResponse('この店舗では既にセット設定が登録されています。', 422);
            }
            
            $shopSet = DB::transaction(function () use ($shopId, $request) {
                $shopSet = ShopSet::create([
                    'shop_id' => $shopId,
                    'price' => $request->price,
                ]);
                
                return $shopSet;
            });
            
            Log::info('セット設定が作成されました', [
                'shop_id' => $shopId,
                'set_id' => $shopSet->id,
                'price' => $shopSet->price,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                $this->formatSetData($shopSet->load('shop')),
                "セット設定「{$shopSet->display_name}」を作成しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('セット設定作成エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('セット設定の作成に失敗しました。', 500);
        }
    }

    /**
     * セット設定を更新
     */
    public function update(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'price' => 'required|integer|min:0|max:999999',
        ], [
            'price.required' => '料金は必須です。',
            'price.integer' => '料金は整数で入力してください。',
            'price.min' => '料金は0円以上で入力してください。',
            'price.max' => '料金は999,999円以下で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'セット設定データに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shopSet = ShopSet::where('shop_id', $shopId)
                ->with('shop')
                ->firstOrFail();
            
            // 権限チェック
            if (!$shopSet->shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このセット設定を更新する権限がありません。', 403);
            }
            
            $shopSet = DB::transaction(function () use ($shopSet, $request) {
                $shopSet->update([
                    'price' => $request->price,
                ]);
                
                return $shopSet;
            });
            
            Log::info('セット設定が更新されました', [
                'shop_id' => $shopId,
                'set_id' => $shopSet->id,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                $this->formatSetData($shopSet->fresh()->load('shop')),
                "セット設定「{$shopSet->display_name}」を更新しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたセット設定が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('セット設定更新エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('セット設定の更新に失敗しました。', 500);
        }
    }

    /**
     * セット設定を削除（物理削除）
     */
    public function destroy(Request $request, $shopId)
    {
        try {
            $shopSet = ShopSet::where('shop_id', $shopId)
                ->with('shop')
                ->firstOrFail();
            
            // 権限チェック
            if (!$shopSet->shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このセット設定を削除する権限がありません。', 403);
            }
            
            $displayName = $shopSet->display_name;
            
            DB::transaction(function () use ($shopSet) {
                $shopSet->delete();
            });
            
            Log::info('セット設定が削除されました', [
                'shop_id' => $shopId,
                'set_id' => $shopSet->id,
                'display_name' => $displayName,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                null,
                "セット設定「{$displayName}」を削除しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたセット設定が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('セット設定削除エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('セット設定の削除に失敗しました。', 500);
        }
    }

    /**
     * セット設定データをフォーマット
     */
    private function formatSetData(ShopSet $shopSet, bool $includeShop = false): array
    {
        $data = [
            'id' => $shopSet->id,
            'shop_id' => $shopSet->shop_id,
            'display_name' => $shopSet->display_name,
            'price' => $shopSet->price,
            'formatted_price' => $shopSet->formatted_price,
            'display_name_with_price' => $shopSet->display_name_with_price,
            'created_at' => $shopSet->created_at,
            'updated_at' => $shopSet->updated_at,
        ];
        
        if ($includeShop && $shopSet->relationLoaded('shop')) {
            $data['shop_name'] = $shopSet->shop->name ?? null;
        }
        
        return $data;
    }
}