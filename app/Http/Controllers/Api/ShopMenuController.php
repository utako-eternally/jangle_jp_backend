<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopMenu;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopMenuController extends Controller
{
    use ApiResponse;

    /**
     * 店舗のメニュー一覧を取得
     */
    public function index(Request $request, $shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);
            
            $menus = ShopMenu::where('shop_id', $shopId)
                ->orderBy('category', 'asc')
                ->orderBy('item_name', 'asc')
                ->get();
            
            // カテゴリ別サマリー
            $categories = $menus->groupBy('category');
            $summary = [];
            
            foreach (ShopMenu::getCategories() as $categoryKey => $categoryName) {
                $categoryMenus = $categories->get($categoryKey, collect());
                $summary[] = [
                    'category' => $categoryKey,
                    'category_display' => $categoryName,
                    'total_count' => $categoryMenus->count(),
                    'available_count' => $categoryMenus->where('is_available', true)->count(),
                ];
            }
            
            return $this->successResponse([
                'shop_info' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                ],
                'menus' => $menus->map(fn($menu) => $this->formatMenuData($menu)),
                'summary' => $summary,
                'categories' => ShopMenu::getCategories(),
            ], 'メニュー一覧を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('メニュー一覧取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);
            
            return $this->errorResponse('メニュー一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 特定メニューの詳細を取得
     */
    public function show(Request $request, $shopId, $menuId)
    {
        try {
            $menu = ShopMenu::where('shop_id', $shopId)
                ->with('shop')
                ->findOrFail($menuId);
            
            return $this->successResponse(
                $this->formatMenuData($menu, true),
                'メニュー詳細を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたメニューが見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('メニュー詳細取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'menu_id' => $menuId
            ]);
            
            return $this->errorResponse('メニュー詳細の取得に失敗しました。', 500);
        }
    }

    /**
     * 新しいメニューを作成
     */
    public function store(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'item_name' => 'required|string|max:100',
            'category' => 'required|in:FOOD,DRINK,ALCOHOL,OTHER',
            'price' => 'required|integer|min:0|max:999999',
            'description' => 'nullable|string|max:500',
            'is_available' => 'sometimes|boolean',
        ], [
            'item_name.required' => '商品名は必須です。',
            'item_name.max' => '商品名は100文字以内で入力してください。',
            'category.required' => 'カテゴリは必須です。',
            'category.in' => '無効なカテゴリです。',
            'price.required' => '価格は必須です。',
            'price.integer' => '価格は整数で入力してください。',
            'price.min' => '価格は0円以上で入力してください。',
            'price.max' => '価格は999,999円以下で入力してください。',
            'description.max' => '説明は500文字以内で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'メニューデータに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);
            
            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗のメニューを作成する権限がありません。', 403);
            }
            
            // 同名チェック
            $existingMenu = ShopMenu::where('shop_id', $shopId)
                ->where('item_name', $request->item_name)
                ->first();
                
            if ($existingMenu) {
                return $this->errorResponse('同じ名前のメニューが既に存在します。', 422);
            }
            
            $menu = DB::transaction(function () use ($shopId, $request) {
                $menu = ShopMenu::create([
                    'shop_id' => $shopId,
                    'item_name' => $request->item_name,
                    'category' => $request->category,
                    'price' => $request->price,
                    'description' => $request->description,
                    'is_available' => $request->input('is_available', true),
                ]);
                
                return $menu;
            });
            
            Log::info('メニューが作成されました', [
                'shop_id' => $shopId,
                'menu_id' => $menu->id,
                'item_name' => $menu->item_name,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                $this->formatMenuData($menu->load('shop')),
                "メニュー「{$menu->item_name}」を作成しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('メニュー作成エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('メニューの作成に失敗しました。', 500);
        }
    }

    /**
     * メニューを更新
     */
    public function update(Request $request, $shopId, $menuId)
    {
        $validator = Validator::make($request->all(), [
            'item_name' => 'sometimes|required|string|max:100',
            'category' => 'sometimes|required|in:FOOD,DRINK,ALCOHOL,OTHER',
            'price' => 'sometimes|required|integer|min:0|max:999999',
            'description' => 'nullable|string|max:500',
            'is_available' => 'sometimes|boolean',
        ], [
            'item_name.max' => '商品名は100文字以内で入力してください。',
            'category.in' => '無効なカテゴリです。',
            'price.integer' => '価格は整数で入力してください。',
            'price.min' => '価格は0円以上で入力してください。',
            'price.max' => '価格は999,999円以下で入力してください。',
            'description.max' => '説明は500文字以内で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'メニューデータに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $menu = ShopMenu::where('shop_id', $shopId)
                ->with('shop')
                ->findOrFail($menuId);
            
            // 権限チェック
            if (!$menu->shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このメニューを更新する権限がありません。', 403);
            }
            
            // 同名チェック（自分以外）
            if ($request->has('item_name')) {
                $existingMenu = ShopMenu::where('shop_id', $shopId)
                    ->where('item_name', $request->item_name)
                    ->where('id', '!=', $menuId)
                    ->first();
                    
                if ($existingMenu) {
                    return $this->errorResponse('同じ名前のメニューが既に存在します。', 422);
                }
            }
            
            $menu = DB::transaction(function () use ($menu, $request) {
                $menu->update($request->only([
                    'item_name',
                    'category',
                    'price',
                    'description',
                    'is_available',
                ]));
                
                return $menu;
            });
            
            Log::info('メニューが更新されました', [
                'shop_id' => $shopId,
                'menu_id' => $menu->id,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                $this->formatMenuData($menu->fresh()->load('shop')),
                "メニュー「{$menu->item_name}」を更新しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたメニューが見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('メニュー更新エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'menu_id' => $menuId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('メニューの更新に失敗しました。', 500);
        }
    }

    /**
     * メニューを削除（物理削除）
     */
    public function destroy(Request $request, $shopId, $menuId)
    {
        try {
            $menu = ShopMenu::where('shop_id', $shopId)
                ->with('shop')
                ->findOrFail($menuId);
            
            // 権限チェック
            if (!$menu->shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このメニューを削除する権限がありません。', 403);
            }
            
            $menuName = $menu->item_name;
            
            DB::transaction(function () use ($menu) {
                $menu->delete();
            });
            
            Log::info('メニューが削除されました', [
                'shop_id' => $shopId,
                'menu_id' => $menuId,
                'item_name' => $menuName,
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse(
                null,
                "メニュー「{$menuName}」を削除しました。"
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたメニューが見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('メニュー削除エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'menu_id' => $menuId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('メニューの削除に失敗しました。', 500);
        }
    }

    /**
     * 利用可能なカテゴリ一覧を取得
     */
    public function getCategories(Request $request)
    {
        try {
            return $this->successResponse([
                'categories' => ShopMenu::getCategories(),
            ], '利用可能なカテゴリ一覧を取得しました');

        } catch (\Exception $e) {
            Log::error('カテゴリ一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('カテゴリ一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * メニューデータをフォーマット
     */
    private function formatMenuData(ShopMenu $menu, bool $includeShop = false): array
    {
        $data = [
            'id' => $menu->id,
            'shop_id' => $menu->shop_id,
            'item_name' => $menu->item_name,
            'category' => $menu->category,
            'category_display' => $menu->category_display,
            'price' => $menu->price,
            'price_display' => $menu->price_display,
            'description' => $menu->description,
            'is_available' => $menu->is_available,
            'created_at' => $menu->created_at,
            'updated_at' => $menu->updated_at,
        ];
        
        if ($includeShop && $menu->relationLoaded('shop')) {
            $data['shop_name'] = $menu->shop->name ?? null;
        }
        
        return $data;
    }

}