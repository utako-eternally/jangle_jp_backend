<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\ImageService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ShopController extends Controller
{
    use ApiResponse;
    protected ImageService $imageService;

    public function __construct(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    /**
     * 雀荘一覧を取得（公開）
     */
    public function index(Request $request)
    {
        try {
            $query = Shop::verified()->with(['prefecture', 'city', 'shopStations.station', 'shopStations.stationGroup']);

            // 検索条件の適用
            if ($request->filled('prefecture_id')) {
                $query->where('prefecture_id', $request->prefecture_id);
            }

            if ($request->filled('city_id')) {
                $query->where('city_id', $request->city_id);
            }

            if ($request->filled('keyword')) {
                $keyword = '%' . $request->keyword . '%';
                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', $keyword)
                        ->orWhere('description', 'like', $keyword)
                        ->orWhere('address_pref', 'like', $keyword)
                        ->orWhere('address_city', 'like', $keyword)
                        ->orWhere('address_town', 'like', $keyword);
                });
            }

            // 全自動卓でのフィルタリング
            if ($request->boolean('has_auto_tables')) {
                $query->where('auto_table_count', '>', 0);
            }

            // 手積み卓でのフィルタリング
            if ($request->boolean('has_score_tables')) {
                $query->where('score_table_count', '>', 0);
            }

            // ソート
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);

            // ページネーション
            $perPage = $request->input('per_page', 15);
            $shops = $query->paginate($perPage);

            // レスポンスデータにslug情報を追加
            $shopsData = $shops->getCollection()->map(function ($shop) {
                $shopArray = $shop->toArray();
                $shopArray['prefecture_slug'] = $shop->prefecture->slug ?? null;
                $shopArray['city_slug'] = $shop->city->slug ?? null;
                
                // 最寄り駅のslug情報を追加
                $nearestStation = $shop->shopStations->where('is_nearest', true)->first();
                if ($nearestStation) {
                    $stationSlug = $nearestStation->stationGroup 
                        ? $nearestStation->stationGroup->slug 
                        : $nearestStation->station->slug;
                    $shopArray['nearest_station_slug'] = $stationSlug;
                } else {
                    $shopArray['nearest_station_slug'] = null;
                }
                
                return $shopArray;
            });

            $shops->setCollection($shopsData);

            return $this->successResponse(
                $shops,
                '雀荘一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('雀荘一覧取得エラー: ' . $e->getMessage(), [
                'criteria' => $request->all()
            ]);

            return $this->errorResponse('雀荘一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 雀荘詳細を取得（公開）
     */
    public function show($id)
    {
        try {
            $shop = Shop::with([
                'prefecture', 
                'city',
                'owner',
                'shopStations.station.stationLine',
                'shopStations.station',
                'shopStations.stationGroup',
                'businessHours',
                'frees',
                'set',
                'features',
                'rules',
                'activePlan',
                'services',
                'menus',
            ])->findOrFail($id);

            $shopData = $shop->toArray();
            $shopData['prefecture_slug'] = $shop->prefecture->slug ?? null;
            $shopData['city_slug'] = $shop->city->slug ?? null;
            $shopData['nearest_station'] = null;
            $shopData['sub_stations'] = [];

            foreach ($shop->shopStations as $shopStation) {
                $stationInfo = [
                    'id' => $shopStation->station->id,
                    'name' => $shopStation->stationGroup 
                        ? $shopStation->stationGroup->name 
                        : $shopStation->station->name,
                    'slug' => $shopStation->stationGroup 
                        ? $shopStation->stationGroup->slug 
                        : $shopStation->station->slug,
                    'line_name' => $shopStation->station->stationLine->name,
                    'distance_km' => (float)$shopStation->distance_km,
                    'distance' => (float)$shopStation->distance_km,
                    'walking_minutes' => (int)$shopStation->walking_minutes,
                ];

                if ($shopStation->is_nearest) {
                    $shopData['nearest_station'] = $stationInfo;
                } else {
                    $shopData['sub_stations'][] = $stationInfo;
                }
            }

            $shopData['business_hours'] = $shop->businessHours->map(function ($hour) {
                return [
                    'day_of_week' => $hour->day_of_week,
                    'day_name' => $hour->getDayName(),
                    'is_closed' => $hour->is_closed,
                    'is_24h' => $hour->is_24h,
                    'open_time' => $hour->open_time,
                    'close_time' => $hour->close_time,
                    'note' => $hour->note,
                    'display_text' => $hour->getDisplayText(),
                ];
            })->toArray();

            $todayHour = $shop->getTodayBusinessHour();
            $shopData['today_business_hour'] = $todayHour ? [
                'day_name' => $todayHour->getDayName(),
                'display_text' => $todayHour->getDisplayText(),
                'is_open_now' => $shop->isOpenNow(),
            ] : null;

            $shopData['has_three_player_free'] = $shop->hasThreePlayerFree();
            $shopData['has_four_player_free'] = $shop->hasFourPlayerFree();
            $shopData['has_set'] = $shop->hasSet();

            return $this->successResponse(
                $shopData,
                '雀荘詳細を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('雀荘詳細取得エラー: ' . $e->getMessage(), [
                'shop_id' => $id
            ]);

            return $this->errorResponse('雀荘の詳細情報を取得できませんでした。', 500);
        }
    }

    /**
     * 店舗を承認（管理者用）
     */
    public function verify(Request $request, $shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            // 実運用時はコメントアウトを外す
            // if (!Auth::user()->isAdmin()) {
            //     return $this->errorResponse('管理者権限が必要です。', 403);
            // }

            $shop->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);

            Log::info('店舗承認完了', [
                'shop_id' => $shopId,
                'verified_by_user_id' => Auth::id()
            ]);

            return $this->successResponse(
                $shop->load(['prefecture', 'city', 'owner']),
                '店舗を承認しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗承認エラー: ' . $e->getMessage());
            return $this->errorResponse('店舗の承認に失敗しました。', 500);
        }
    }

    /**
     * 未承認店舗一覧を取得（管理者用）
     */
    public function unverifiedShops(Request $request)
    {
        try {
            // 実運用時はコメントアウトを外す
            // if (!Auth::user()->isAdmin()) {
            //     return $this->errorResponse('管理者権限が必要です。', 403);
            // }

            $query = Shop::where('is_verified', false)
                ->with(['prefecture', 'city', 'owner'])
                ->orderBy('created_at', 'desc');

            $shops = $query->paginate($request->input('per_page', 15));

            return $this->successResponse(
                $shops,
                '未承認店舗一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('未承認店舗一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('未承認店舗一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 座標から近隣駅を検索（公開API）
     */
    public function getNearbyStations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'max_stations' => 'nullable|integer|min:1|max:50',
            'max_distance' => 'nullable|numeric|min:0.1|max:10',
        ], [
            'lat.required' => '緯度は必須です。',
            'lng.required' => '経度は必須です。',
            'lat.between' => '緯度は-90度から90度の間で入力してください。',
            'lng.between' => '経度は-180度から180度の間で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '検索条件に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $lat = $request->input('lat');
            $lng = $request->input('lng');
            $maxStations = $request->input('max_stations', 10);
            $maxDistance = $request->input('max_distance', 3.0);

            // Haversine formula for distance calculation
            $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * 
                        cos(radians(longitude) - radians(?)) + sin(radians(?)) * 
                        sin(radians(latitude))))";

            $nearbyStations = DB::table('geo_stations')
                ->select(
                    'geo_stations.id',
                    'geo_stations.name',
                    'geo_stations.name_kana',
                    'geo_stations.latitude',
                    'geo_stations.longitude',
                    'geo_station_lines.id as line_id',
                    'geo_station_lines.name as line_name',
                    'geo_station_groups.id as station_group_id',
                    'geo_station_groups.name as station_group_name',
                    DB::raw("$haversine AS distance")
                )
                ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                ->leftJoin('geo_station_groups', 'geo_stations.station_group_id', '=', 'geo_station_groups.id')
                ->having('distance', '<=', $maxDistance)
                ->orderBy('distance')
                ->limit($maxStations)
                ->setBindings([$lat, $lng, $lat])
                ->get();

            // Group stations by station_group_id
            $groupedStations = [];
            $processedGroups = [];

            foreach ($nearbyStations as $station) {
                if ($station->station_group_id) {
                    $groupKey = $station->station_group_id;
                    
                    if (!isset($processedGroups[$groupKey])) {
                        $processedGroups[$groupKey] = [
                            'station_group_id' => $station->station_group_id,
                            'station_group_name' => $station->station_group_name,
                            'name_kana' => $station->name_kana,
                            'distance' => round($station->distance, 2),
                            'coordinates' => [
                                'lat' => (float)$station->latitude,
                                'lng' => (float)$station->longitude,
                            ],
                            'lines' => []
                        ];
                    }
                    
                    $processedGroups[$groupKey]['lines'][] = [
                        'station_id' => $station->id,
                        'line_id' => $station->line_id,
                        'line_name' => $station->line_name,
                    ];
                } else {
                    $groupedStations[] = [
                        'station_group_id' => null,
                        'station_group_name' => null,
                        'station_id' => $station->id,
                        'station_name' => $station->name,
                        'name_kana' => $station->name_kana,
                        'distance' => round($station->distance, 2),
                        'coordinates' => [
                            'lat' => (float)$station->latitude,
                            'lng' => (float)$station->longitude,
                        ],
                        'lines' => [
                            [
                                'station_id' => $station->id,
                                'line_id' => $station->line_id,
                                'line_name' => $station->line_name,
                            ]
                        ]
                    ];
                }
            }

            $groupedStations = array_merge(array_values($processedGroups), $groupedStations);

            Log::info('近隣駅検索完了', [
                'lat' => $lat,
                'lng' => $lng,
                'found_stations' => count($groupedStations)
            ]);

            return $this->successResponse(
                $groupedStations,
                count($groupedStations) . '件の駅が見つかりました'
            );

        } catch (\Exception $e) {
            Log::error('近隣駅検索エラー: ' . $e->getMessage(), [
                'lat' => $request->input('lat'),
                'lng' => $request->input('lng')
            ]);

            return $this->errorResponse('近隣駅の検索に失敗しました。', 500);
        }
    }

    /**
     * 住所から近隣駅を検索（公開API）
     */
    public function getNearbyStationsByAddress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string|max:500',
            'max_stations' => 'nullable|integer|min:1|max:50',
            'max_distance' => 'nullable|numeric|min:0.1|max:10',
        ], [
            'address.required' => '住所は必須です。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '検索条件に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $address = $request->input('address');
            
            // まず住所をジオコーディング
            $coordinates = $this->geocodeAddress($address);

            if (!$coordinates) {
                return $this->errorResponse('指定された住所の座標を取得できませんでした。', 422);
            }

            // 座標が取得できたら、近隣駅検索を実行
            $request->merge([
                'lat' => $coordinates['lat'],
                'lng' => $coordinates['lng']
            ]);

            return $this->getNearbyStations($request);

        } catch (\Exception $e) {
            Log::error('住所から近隣駅検索エラー: ' . $e->getMessage(), [
                'address' => $request->input('address')
            ]);

            return $this->errorResponse('近隣駅の検索に失敗しました。', 500);
        }
    }

    /**
     * 駅名から駅を検索（公開API）
     */
    public function searchStationsByName(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'keyword' => 'required|string|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'keyword.required' => '駅名は必須です。',
            'keyword.min' => '駅名は1文字以上で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '検索条件に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $keyword = $request->input('keyword');
            $limit = $request->input('limit', 20);

            $stations = DB::table('geo_stations')
                ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                ->leftJoin('geo_station_groups', 'geo_stations.station_group_id', '=', 'geo_station_groups.id')
                ->where(function($query) use ($keyword) {
                    $query->where('geo_stations.name', 'like', "%{$keyword}%")
                        ->orWhere('geo_stations.name_kana', 'like', "%{$keyword}%")
                        ->orWhere('geo_station_groups.name', 'like', "%{$keyword}%");
                })
                ->select(
                    'geo_stations.id',
                    'geo_stations.name',
                    'geo_stations.name_kana',
                    'geo_stations.latitude',
                    'geo_stations.longitude',
                    'geo_station_lines.id as line_id',
                    'geo_station_lines.name as line_name',
                    'geo_station_groups.id as station_group_id',
                    'geo_station_groups.name as station_group_name'
                )
                ->get();

            // 駅グループごとにグループ化
            $groupedStations = [];
            $processedGroups = [];
            $singleStations = [];

            foreach ($stations as $station) {
                if ($station->station_group_id) {
                    // グループ化された駅
                    $groupKey = $station->station_group_id;
                    
                    if (!isset($processedGroups[$groupKey])) {
                        $processedGroups[$groupKey] = [
                            'station_group_id' => $station->station_group_id,
                            'station_group_name' => $station->station_group_name,
                            'name_kana' => $station->name_kana,
                            'coordinates' => [
                                'lat' => (float)$station->latitude,
                                'lng' => (float)$station->longitude,
                            ],
                            'lines' => []
                        ];
                    }
                    
                    $processedGroups[$groupKey]['lines'][] = [
                        'station_id' => $station->id,
                        'line_id' => $station->line_id,
                        'line_name' => $station->line_name,
                    ];
                } else {
                    // グループ化されていない単独駅
                    $singleStations[] = [
                        'station_group_id' => null,
                        'station_group_name' => null,
                        'station_id' => $station->id,
                        'station_name' => $station->name,
                        'name_kana' => $station->name_kana,
                        'coordinates' => [
                            'lat' => (float)$station->latitude,
                            'lng' => (float)$station->longitude,
                        ],
                        'lines' => [
                            [
                                'station_id' => $station->id,
                                'line_id' => $station->line_id,
                                'line_name' => $station->line_name,
                            ]
                        ]
                    ];
                }
            }

            // グループ化された駅と単独駅をマージ
            $groupedStations = array_merge(array_values($processedGroups), $singleStations);

            // limitの制限を適用
            $groupedStations = array_slice($groupedStations, 0, $limit);

            Log::info('駅名検索完了', [
                'keyword' => $keyword,
                'found_stations' => count($groupedStations)
            ]);

            return $this->successResponse(
                $groupedStations,
                count($groupedStations) . '件の駅が見つかりました'
            );

        } catch (\Exception $e) {
            Log::error('駅名検索エラー: ' . $e->getMessage(), [
                'keyword' => $request->input('keyword'),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('駅の検索に失敗しました。', 500);
        }
    }

    /**
     * 店舗のギャラリー画像一覧を取得（公開）
     */
    public function getGalleryImages($shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            $images = $shop->images()->get()->map(function ($image) {
                return [
                    'id' => $image->id,
                    'image_paths' => $image->image_paths,
                    'alt_text' => $image->alt_text,
                    'display_order' => $image->display_order,
                    'thumbnail_url' => $image->getThumbnailUrl(),
                    'medium_url' => $image->getImageUrl('medium'),
                    'large_url' => $image->getImageUrl('large'),
                    'created_at' => $image->created_at,
                ];
            });

            return $this->successResponse($images, 'ギャラリー画像を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ギャラリー画像取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);

            return $this->errorResponse('ギャラリー画像の取得に失敗しました。', 500);
        }
    }

    /**
     * LINE公式アカウント情報を取得（公開）
     */
    public function getLineInfo($id)
    {
        try {
            $shop = Shop::findOrFail($id);

            return $this->successResponse([
                'line_official_id' => $shop->line_official_id,
                'line_add_url' => $shop->line_add_url,
                'line_qr_code_path' => $shop->line_qr_code_path,
                'line_qr_code_url' => $shop->getLineQrCodeUrl(),
                'has_line_account' => $shop->hasLineAccount(),
                'has_line_qr_code' => $shop->hasLineQrCode(),
            ], 'LINE情報を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('LINE情報取得エラー: ' . $e->getMessage(), [
                'shop_id' => $id
            ]);

            return $this->errorResponse('LINE情報の取得に失敗しました。', 500);
        }
    }

    // ========================================
    // 共通プライベートメソッド
    // ========================================

    /**
     * 住所をジオコーディングして座標を取得
     * 
     * @param string $address
     * @return array|null
     */
    public function geocodeAddress(string $address)
    {
        try {
            $nodeApiUrl = env('NODE_ADDRESS_API_URL', 'http://localhost:3001');

            $response = Http::post("{$nodeApiUrl}/api/geo/geocode", [
                'address' => $address,
                'region' => 'JP'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if ($data['success'] ?? false) {
                    return [
                        'lat' => $data['data']['lat'],
                        'lng' => $data['data']['lng']
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('ジオコーディングエラー: ' . $e->getMessage(), [
                'address' => $address
            ]);
            return null;
        }
    }

    /**
     * 雀荘と駅の距離を計算
     * 
     * @param float $shopLat
     * @param float $shopLng
     * @param int $stationId
     * @return float
     */
    public function calculateDistance($shopLat, $shopLng, $stationId)
    {
        $station = DB::table('geo_stations')
            ->where('id', $stationId)
            ->first(['latitude', 'longitude']);

        if (!$station) {
            return 0;
        }

        // Haversine formula
        $earthRadius = 6371; // km

        $latDiff = deg2rad($station->latitude - $shopLat);
        $lngDiff = deg2rad($station->longitude - $shopLng);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos(deg2rad($shopLat)) * cos(deg2rad($station->latitude)) *
             sin($lngDiff / 2) * sin($lngDiff / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return round($earthRadius * $c, 3);
    }
}