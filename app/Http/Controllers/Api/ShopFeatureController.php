<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopFeature;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopFeatureController extends Controller
{
    use ApiResponse;

    /**
     * 店舗の特徴一覧を取得
     */
    public function index(Request $request, $shopId)
    {
        try {
            $shop = Shop::with('features')->findOrFail($shopId);
            
            $shopFeatures = $shop->features()->orderBy('feature')->get();
            
            $featuresData = $shopFeatures->map(function ($shopFeature) {
                return $this->formatFeatureData($shopFeature);
            });
            
            // カテゴリ別に分類
            $featuresByCategory = $featuresData->groupBy('category');
            
            // 統計情報の計算
            $gameStyleFeatures = ShopFeature::getFeatureCategories()['game_style']['features'];
            $staffFeatures = ShopFeature::getFeatureCategories()['staff']['features'];
            
            // カテゴリ別の特徴をオブジェクト形式に変換（配列ではなく）
            $categorizedFeatures = [];
            foreach ($featuresByCategory as $category => $features) {
                $categorizedFeatures[$category] = [
                    'name' => $features->first()['category_name'],
                    'features' => $features->values()->toArray(),
                ];
            }
            
            $responseData = [
                'shop_info' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                ],
                'summary' => [
                    'total_features' => $featuresData->count(),
                    'categories_count' => $featuresByCategory->count(),
                    'is_healthy_mahjong' => $shop->isHealthMahjong() || $shop->isNoRate(),
                    'has_pro_staff' => $shop->hasProStaff(),
                    'is_female_friendly' => $shop->isGirlMahjong() || $shop->hasFemaleProStaff(),
                ],
                'stats' => [
                    'game_style_features' => $shopFeatures->whereIn('feature', $gameStyleFeatures)->count(),
                    'staff_features' => $shopFeatures->whereIn('feature', $staffFeatures)->count(),
                    'health_related' => $shopFeatures->whereIn('feature', [
                        ShopFeature::FEATURE_HEALTH,
                        ShopFeature::FEATURE_NO_RATE
                    ])->count(),
                    'gender_related' => $shopFeatures->whereIn('feature', [
                        ShopFeature::FEATURE_GIRL_MAHJONG,
                        ShopFeature::FEATURE_FEMALE_PRO
                    ])->count(),
                    'pro_related' => $shopFeatures->whereIn('feature', [
                        ShopFeature::FEATURE_MALE_PRO,
                        ShopFeature::FEATURE_FEMALE_PRO
                    ])->count(),
                ],
                'features_by_category' => $categorizedFeatures,  // ← オブジェクト形式に変更
                'all_features' => $featuresData->toArray(),
                'available_features' => $this->getAvailableFeaturesForShop($shopId),
            ];
            
            return $this->successResponse($responseData, '店舗特徴一覧を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗特徴一覧取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);
            
            return $this->errorResponse('店舗特徴一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 店舗の特徴を更新（一括設定）
     */
    public function update(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'features' => 'required|array',
            'features.*' => 'string|in:HEALTH,NO_RATE,GIRL_MAHJONG,MALE_PRO,FEMALE_PRO',
        ], [
            'features.required' => '特徴データは必須です。',
            'features.array' => '特徴データは配列である必要があります。',
            'features.*.in' => '無効な特徴が含まれています。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '特徴データに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);
            
            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗の特徴を設定する権限がありません。', 403);
            }
            
            $createdFeatures = DB::transaction(function () use ($shopId, $request) {
                // 既存特徴をすべて削除
                ShopFeature::forShop($shopId)->delete();
                
                // 新しい特徴を一括挿入
                $createdFeatures = [];
                foreach ($request->features as $feature) {
                    $shopFeature = ShopFeature::create([
                        'shop_id' => $shopId,
                        'feature' => $feature,
                    ]);
                    
                    $createdFeatures[] = $shopFeature;
                }
                
                return $createdFeatures;
            });
            
            Log::info('店舗特徴が更新されました', [
                'shop_id' => $shopId,
                'features_count' => count($createdFeatures),
                'features' => $request->features,
                'operator_id' => Auth::id()
            ]);
            
            $formattedFeatures = array_map([$this, 'formatFeatureData'], $createdFeatures);
            
            return $this->successResponse(
                $formattedFeatures,
                count($createdFeatures) . '件の特徴を設定しました。'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗特徴更新エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id(),
                'features' => $request->features ?? []
            ]);

            return $this->errorResponse('店舗特徴の更新に失敗しました。', 500);
        }
    }

    /**
     * 利用可能な全特徴の定義を取得
     */
    public function getAvailableFeatures(Request $request)
    {
        try {
            $features = ShopFeature::getFeatures();
            $descriptions = ShopFeature::getFeatureDescriptions();
            $categorizedFeatures = ShopFeature::formatCategoriesForApi();
            
            return $this->successResponse([
                'all_features' => $features,
                'descriptions' => $descriptions,
                'categorized_features' => $categorizedFeatures,
            ], '利用可能な特徴一覧を取得しました');

        } catch (\Exception $e) {
            Log::error('利用可能な特徴一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('利用可能な特徴一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 特徴データをフォーマット
     */
    private function formatFeatureData(ShopFeature $shopFeature): array
    {
        return [
            'id' => $shopFeature->id,
            'feature' => $shopFeature->feature,
            'display_name' => $shopFeature->display_name,
            'description' => $shopFeature->description,
            'category' => $shopFeature->category,
            'category_name' => $shopFeature->category_name,
            'created_at' => $shopFeature->created_at,
            'updated_at' => $shopFeature->updated_at,
        ];
    }

    /**
     * 店舗で利用可能な特徴一覧を取得
     */
    private function getAvailableFeaturesForShop(int $shopId): array
    {
        $existingFeatures = ShopFeature::forShop($shopId)->pluck('feature')->toArray();
        $allFeatures = ShopFeature::getFeatures();
        
        $availableFeatures = [];
        foreach ($allFeatures as $feature => $displayName) {
            if (!in_array($feature, $existingFeatures)) {
                $description = ShopFeature::getFeatureDescriptions()[$feature] ?? null;
                $availableFeatures[] = [
                    'feature' => $feature,
                    'display_name' => $displayName,
                    'description' => $description,
                ];
            }
        }
        
        return $availableFeatures;
    }
}