<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopRuleText;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopRuleTextController extends Controller
{
    use ApiResponse;

    /**
     * 店舗のルールテキスト一覧を取得
     */
    public function index(Request $request, $shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);
            
            // active()スコープを削除
            $ruleTexts = ShopRuleText::where('shop_id', $shopId)
                ->ordered()
                ->get();
            
            // 存在しないカテゴリのデフォルトを作成
            $existingCategories = $ruleTexts->pluck('category')->toArray();
            $allCategories = ShopRuleText::CATEGORIES;
            
            $result = [];
            foreach ($allCategories as $index => $category) {
                $existing = $ruleTexts->firstWhere('category', $category);
                
                if ($existing) {
                    $result[] = [
                        'id' => $existing->id,
                        'category' => $existing->category,
                        'category_label' => $existing->category_label,
                        'content' => $existing->content,
                        'display_order' => $existing->display_order,
                        'created_at' => $existing->created_at,
                        'updated_at' => $existing->updated_at,
                    ];
                } else {
                    // まだ作成されていないカテゴリ
                    $result[] = [
                        'id' => null,
                        'category' => $category,
                        'category_label' => ShopRuleText::CATEGORY_LABELS[$category],
                        'content' => '',
                        'display_order' => $index,
                        'created_at' => null,
                        'updated_at' => null,
                    ];
                }
            }
            
            return $this->successResponse([
                'shop_info' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                ],
                'rule_texts' => $result,
                'categories' => ShopRuleText::CATEGORY_LABELS,
            ], '店舗ルールテキストを取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ルールテキスト取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);
            
            return $this->errorResponse('店舗ルールテキストの取得に失敗しました。', 500);
        }
    }

    /**
     * 特定カテゴリのルールテキストを取得
     */
    public function show(Request $request, $shopId, $category)
    {
        try {
            $shop = Shop::findOrFail($shopId);
            
            if (!in_array($category, ShopRuleText::CATEGORIES)) {
                return $this->errorResponse('無効なカテゴリです。', 422);
            }
            
            $ruleText = ShopRuleText::where('shop_id', $shopId)
                ->where('category', $category)
                ->first();
            
            if (!$ruleText) {
                return $this->successResponse([
                    'id' => null,
                    'category' => $category,
                    'category_label' => ShopRuleText::CATEGORY_LABELS[$category],
                    'content' => '',
                    'display_order' => array_search($category, ShopRuleText::CATEGORIES),
                ], 'ルールテキストはまだ作成されていません');
            }
            
            return $this->successResponse([
                'id' => $ruleText->id,
                'category' => $ruleText->category,
                'category_label' => $ruleText->category_label,
                'content' => $ruleText->content,
                'display_order' => $ruleText->display_order,
                'created_at' => $ruleText->created_at,
                'updated_at' => $ruleText->updated_at,
            ], 'ルールテキストを取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ルールテキスト取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'category' => $category
            ]);
            
            return $this->errorResponse('ルールテキストの取得に失敗しました。', 500);
        }
    }

    /**
     * 特定カテゴリのルールテキストを更新
     */
    public function update(Request $request, $shopId, $category)
    {
        // カテゴリの検証
        if (!in_array($category, ShopRuleText::CATEGORIES)) {
            return $this->errorResponse('無効なカテゴリです。', 422);
        }
        
        $validator = Validator::make($request->all(), [
            'content' => 'nullable|string|max:10000',
        ], [
            'content.string' => 'コンテンツは文字列である必要があります。',
            'content.max' => 'コンテンツは10000文字以内で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'ルールテキストデータに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);
            
            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗のルールテキストを更新する権限がありません。', 403);
            }
            
            $ruleText = DB::transaction(function () use ($shopId, $category, $request) {
                $displayOrder = array_search($category, ShopRuleText::CATEGORIES);
                
                // contentが送信されていない場合は空文字列として扱う
                $content = $request->has('content') ? $request->input('content') : '';
                
                $ruleText = ShopRuleText::updateOrCreate(
                    [
                        'shop_id' => $shopId,
                        'category' => $category,
                    ],
                    [
                        'content' => $content,
                        'display_order' => $displayOrder,
                    ]
                );
                
                return $ruleText;
            });
            
            Log::info('店舗ルールテキストが更新されました', [
                'shop_id' => $shopId,
                'category' => $category,
                'content_length' => strlen($ruleText->content),
                'operator_id' => Auth::id()
            ]);
            
            return $this->successResponse([
                'id' => $ruleText->id,
                'category' => $ruleText->category,
                'category_label' => $ruleText->category_label,
                'content' => $ruleText->content,
                'display_order' => $ruleText->display_order,
                'created_at' => $ruleText->created_at,
                'updated_at' => $ruleText->updated_at,
            ], 'ルールテキストを更新しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ルールテキスト更新エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'category' => $category,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('ルールテキストの更新に失敗しました。', 500);
        }
    }

    /**
     * 利用可能なカテゴリ一覧を取得
     */
    public function getAvailableCategories(Request $request)
    {
        try {
            return $this->successResponse([
                'categories' => ShopRuleText::CATEGORY_LABELS,
                'category_keys' => ShopRuleText::CATEGORIES,
            ], '利用可能なカテゴリ一覧を取得しました');

        } catch (\Exception $e) {
            Log::error('カテゴリ一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('カテゴリ一覧の取得に失敗しました。', 500);
        }
    }
}