<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopFree;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopFreeController extends Controller
{
    use ApiResponse;

    /**
     * 店舗のフリー設定一覧を取得
     */
    public function index(Request $request, $shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);
            
            $frees = ShopFree::where('shop_id', $shopId)
                ->orderBy('game_format', 'asc')
                ->get();
            
            // ゲーム形式別に分類
            $threePlayerFree = $frees->firstWhere('game_format', ShopFree::GAME_FORMAT_THREE_PLAYER);
            $fourPlayerFree = $frees->firstWhere('game_format', ShopFree::GAME_FORMAT_FOUR_PLAYER);
            
            return $this->successResponse([
                'shop_info' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                ],
                'frees' => $frees->map(fn($free) => $this->formatFreeData($free)),
                'by_format' => [
                    'three_player' => $threePlayerFree ? $this->formatFreeData($threePlayerFree) : null,
                    'four_player' => $fourPlayerFree ? $this->formatFreeData($fourPlayerFree) : null,
                ],
                'summary' => [
                    'has_three_player' => $threePlayerFree !== null,
                    'has_four_player' => $fourPlayerFree !== null,
                    'total_count' => $frees->count(),
                ],
                'game_formats' => ShopFree::getGameFormats(),
            ], 'フリー設定一覧を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('フリー設定一覧取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);
            
            return $this->errorResponse('フリー設定一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 特定フリー設定の詳細を取得
     */
    public function show(Request $request, $shopId, $freeId)
    {
        try {
            $free = ShopFree::where('shop_id', $shopId)
                ->with('shop')
                ->findOrFail($freeId);
            
            return $this->successResponse(
                $this->formatFreeData($free, true),
                'フリー設定詳細を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたフリー設定が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('フリー設定詳細取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'free_id' => $freeId
            ]);
            
            return $this->errorResponse('フリー設定詳細の取得に失敗しました。', 500);
        }
    }

    /**
     * 新しいフリー設定を作成
     */
    public function store(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'game_format' => 'required|in:THREE_PLAYER,FOUR_PLAYER',
            'price' => 'required|integer|min:0|max:999999',
        ], [
            'game_format.required' => 'ゲーム形式は必須です。',
            'game_format.in' => 'ゲーム形式はTHREE_PLAYERまたはFOUR_PLAYERを指定してください。',
            'price.required' => '料金は必須です。',
            'price.integer' => '料金は整数で入力してください。',
            'price.min' => '料金は0円以上で入力してください。',
            'price.max' => '料金は999,999円以下で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'フリー設定データに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);
            
            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗のフリー設定を作成する権限がありません。', 403);
            }
            
            // 同一ゲーム形式の重複チェック
            $existingFree = ShopFree::where('shop_id', $shopId)
                ->where('game_format', $request->game_format)
                ->first();
                
            if ($existingFree) {
                $formatName = ShopFree::getGameFormats()[$request->game_format];
                return $this->errorResponse("この店舗では既に{$formatName}のフリー設定が登録されています。", 422);
            }
            
            $free = DB::transaction(function () use ($shopId, $request) {
                $free = ShopFree::create([
                    'shop_id' => $shopId,
                    'game_format' => $request->game_format,
                    'price' => $request->price,
                ]);
                
                return $free;
            });
            
            Log::info('フリー設定が作成されました', [
                'shop_id' => $shopId,
                'free_id' => $free->id,
                'game_format' => $free->game_format,
                'price' => $free->price,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                $this->formatFreeData($free->load('shop')),
                "フリー設定「{$free->display_name}」を作成しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('フリー設定作成エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('フリー設定の作成に失敗しました。', 500);
        }
    }

    /**
     * フリー設定を更新
     */
    public function update(Request $request, $shopId, $freeId)
    {
        $validator = Validator::make($request->all(), [
            'game_format' => 'sometimes|required|in:THREE_PLAYER,FOUR_PLAYER',
            'price' => 'sometimes|required|integer|min:0|max:999999',
        ], [
            'game_format.in' => 'ゲーム形式はTHREE_PLAYERまたはFOUR_PLAYERを指定してください。',
            'price.integer' => '料金は整数で入力してください。',
            'price.min' => '料金は0円以上で入力してください。',
            'price.max' => '料金は999,999円以下で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'フリー設定データに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $free = ShopFree::where('shop_id', $shopId)
                ->with('shop')
                ->findOrFail($freeId);
            
            // 権限チェック
            if (!$free->shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このフリー設定を更新する権限がありません。', 403);
            }
            
            // ゲーム形式の重複チェック（自分以外）
            if ($request->has('game_format')) {
                $existingFree = ShopFree::where('shop_id', $shopId)
                    ->where('game_format', $request->game_format)
                    ->where('id', '!=', $freeId)
                    ->first();
                    
                if ($existingFree) {
                    $formatName = ShopFree::getGameFormats()[$request->game_format];
                    return $this->errorResponse("この店舗では既に{$formatName}のフリー設定が登録されています。", 422);
                }
            }
            
            $free = DB::transaction(function () use ($free, $request) {
                $free->update($request->only([
                    'game_format',
                    'price',
                ]));
                
                return $free;
            });
            
            Log::info('フリー設定が更新されました', [
                'shop_id' => $shopId,
                'free_id' => $free->id,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                $this->formatFreeData($free->fresh()->load('shop')),
                "フリー設定「{$free->display_name}」を更新しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたフリー設定が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('フリー設定更新エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'free_id' => $freeId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('フリー設定の更新に失敗しました。', 500);
        }
    }

    /**
     * フリー設定を削除（物理削除）
     */
    public function destroy(Request $request, $shopId, $freeId)
    {
        try {
            $free = ShopFree::where('shop_id', $shopId)
                ->with('shop')
                ->findOrFail($freeId);
            
            // 権限チェック
            if (!$free->shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このフリー設定を削除する権限がありません。', 403);
            }
            
            $displayName = $free->display_name;
            
            DB::transaction(function () use ($free) {
                $free->delete();
            });
            
            Log::info('フリー設定が削除されました', [
                'shop_id' => $shopId,
                'free_id' => $freeId,
                'display_name' => $displayName,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                null,
                "フリー設定「{$displayName}」を削除しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたフリー設定が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('フリー設定削除エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'free_id' => $freeId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('フリー設定の削除に失敗しました。', 500);
        }
    }

    /**
     * 利用可能なゲーム形式一覧を取得
     */
    public function getGameFormats(Request $request)
    {
        try {
            return $this->successResponse([
                'game_formats' => ShopFree::getGameFormats(),
            ], '利用可能なゲーム形式一覧を取得しました');

        } catch (\Exception $e) {
            Log::error('ゲーム形式一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('ゲーム形式一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * フリー設定データをフォーマット
     */
    private function formatFreeData(ShopFree $free, bool $includeShop = false): array
    {
        $data = [
            'id' => $free->id,
            'shop_id' => $free->shop_id,
            'game_format' => $free->game_format,
            'game_format_display' => ShopFree::getGameFormats()[$free->game_format] ?? $free->game_format,
            'display_name' => $free->display_name,
            'price' => $free->price,
            'formatted_price' => $free->formatted_price,
            'display_name_with_price' => $free->display_name_with_price,
            'created_at' => $free->created_at,
            'updated_at' => $free->updated_at,
        ];
        
        if ($includeShop && $free->relationLoaded('shop')) {
            $data['shop_name'] = $free->shop->name ?? null;
        }
        
        return $data;
    }
}