<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShopServiceController extends Controller
{
    /**
     * 店舗のサービス一覧を取得
     */
    public function index(Request $request, Shop $shop): JsonResponse
    {
        $services = ShopService::where('shop_id', $shop->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // カテゴリー別にグループ化
        $servicesByCategory = $services->groupBy(function ($service) {
            return $this->getCategoryForService($service->service_type);
        })->map(function ($categoryServices, $category) {
            return [
                'name' => $this->getCategoryName($category),
                'services' => $categoryServices->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'service_type' => $service->service_type,
                        'display_name' => $service->service_type_name,
                        'category' => $this->getCategoryForService($service->service_type),
                        'category_name' => $this->getCategoryName($this->getCategoryForService($service->service_type)),
                        'created_at' => $service->created_at->toISOString(),
                        'updated_at' => $service->updated_at->toISOString(),
                    ];
                })->values(),
            ];
        });

        // 統計情報を計算
        $serviceTypes = $services->pluck('service_type')->toArray();
        
        return response()->json([
            'success' => true,
            'data' => [
                'shop_info' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                ],
                'summary' => [
                    'total_services' => $services->count(),
                    'categories_count' => $servicesByCategory->count(),
                    'has_parking' => in_array('PARKING_AVAILABLE', $serviceTypes) || in_array('PARKING_SUBSIDY', $serviceTypes),
                    'has_food' => in_array('FOOD_AVAILABLE', $serviceTypes),
                    'has_free_wifi' => in_array('FREE_WIFI', $serviceTypes),
                ],
                'services_by_category' => $servicesByCategory,
                'all_services' => $services->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'service_type' => $service->service_type,
                        'display_name' => $service->service_type_name,
                        'category' => $this->getCategoryForService($service->service_type),
                        'category_name' => $this->getCategoryName($this->getCategoryForService($service->service_type)),
                        'created_at' => $service->created_at->toISOString(),
                        'updated_at' => $service->updated_at->toISOString(),
                    ];
                })->values(),
            ],
        ]);
    }

    /**
     * 利用可能なサービスタイプ一覧を取得
     */
    public function getAvailableServices(): JsonResponse
    {
        $categorizedServices = [];
        
        foreach (ShopService::SERVICE_TYPES as $key => $name) {
            $category = $this->getCategoryForService($key);
            
            if (!isset($categorizedServices[$category])) {
                $categorizedServices[$category] = [
                    'name' => $this->getCategoryName($category),
                    'services' => [],
                ];
            }
            
            $categorizedServices[$category]['services'][] = [
                'key' => $key,
                'name' => $name,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'all_services' => ShopService::SERVICE_TYPES,
                'categorized_services' => $categorizedServices,
            ],
        ]);
    }

    /**
     * 店舗のサービスを一括更新
     */
    public function update(Request $request, Shop $shop): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_types' => 'required|array',
            'service_types.*' => 'in:' . implode(',', array_keys(ShopService::SERVICE_TYPES)),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'バリデーションエラー',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 既存のサービスを削除
            ShopService::where('shop_id', $shop->id)->delete();

            // 新しいサービスを登録
            $services = [];
            foreach ($request->service_types as $serviceType) {
                $services[] = ShopService::create([
                    'shop_id' => $shop->id,
                    'service_type' => $serviceType,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'サービスを更新しました',
                'data' => $services,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'サービスの更新に失敗しました',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * サービスタイプからカテゴリーを取得
     */
    private function getCategoryForService(string $serviceType): string
    {
        $categoryMap = [
            'FREE_DRINK' => 'DRINK',
            'FREE_DRINK_SET' => 'DRINK',
            'STUDENT_DISCOUNT' => 'DISCOUNT',
            'FEMALE_DISCOUNT' => 'DISCOUNT',
            'SENIOR_DISCOUNT' => 'DISCOUNT',
            'PARKING_AVAILABLE' => 'PARKING',
            'PARKING_SUBSIDY' => 'PARKING',
            'NON_SMOKING' => 'SMOKING',
            'HEATED_TOBACCO_ALLOWED' => 'SMOKING',
            'SMOKING_ALLOWED' => 'SMOKING',
            'FOOD_AVAILABLE' => 'FOOD',
            'ALCOHOL_AVAILABLE' => 'FOOD',
            'DELIVERY_MENU' => 'FOOD',
            'OUTSIDE_FOOD_ALLOWED' => 'FOOD',
            'PRIVATE_ROOM' => 'FACILITY',
            'FEMALE_TOILET' => 'FACILITY',
            'AUTO_TABLE' => 'FACILITY',
            'SCORE_MANAGEMENT' => 'FACILITY',
            'FREE_WIFI' => 'FACILITY',
        ];

        return $categoryMap[$serviceType] ?? 'OTHER';
    }

    /**
     * カテゴリー名を取得
     */
    private function getCategoryName(string $category): string
    {
        $categoryNames = [
            'DRINK' => 'ドリンク・割引',
            'DISCOUNT' => '割引',
            'PARKING' => '駐車場',
            'SMOKING' => '喫煙',
            'FOOD' => '飲食',
            'FACILITY' => '設備',
            'OTHER' => 'その他',
        ];

        return $categoryNames[$category] ?? $category;
    }
}