<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ShopController extends Controller
{
    use ApiResponse;

    /**
     * 店舗一覧を取得
     */
    public function index(Request $request)
    {
        try {
            $query = Shop::query();

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

            // テーブル数でのフィルタリング
            if ($request->boolean('has_auto_tables')) {
                $query->where('auto_table_count', '>', 0);
            }

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

            return $this->successResponse(
                $shops,
                '店舗一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('店舗一覧取得エラー: ' . $e->getMessage(), [
                'criteria' => $request->all()
            ]);

            return $this->errorResponse('店舗一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 店舗詳細を取得
     */
    public function show($id)
    {
        try {
            $shop = Shop::with(['prefecture', 'city'])->findOrFail($id);

            return $this->successResponse(
                $shop,
                '店舗詳細を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗詳細取得エラー: ' . $e->getMessage(), [
                'shop_id' => $id
            ]);

            return $this->errorResponse('店舗の詳細情報を取得できませんでした。', 500);
        }
    }

    /**
     * 新しい店舗を作成
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:32',
            'website_url' => 'nullable|url|max:255',
            'open_hours' => 'nullable|string|max:255',
            'table_count' => 'nullable|integer|min:0',
            'score_table_count' => 'nullable|integer|min:0',
            'auto_table_count' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'node_address_result' => 'required|array',
            'node_address_result.lat' => 'required|numeric|between:-90,90',
            'node_address_result.lng' => 'required|numeric|between:-180,180',
            'node_address_result.formatted_address' => 'required|string|max:500',
            'final_address' => 'nullable|string|max:500',
            'final_lat' => 'nullable|numeric|between:-90,90',
            'final_lng' => 'nullable|numeric|between:-180,180',
        ], [
            'name.required' => '店舗名は必須です。',
            'node_address_result.required' => '住所情報が必要です。住所処理を完了してください。',
            'node_address_result.lat.required' => '位置情報（緯度）が必要です。',
            'node_address_result.lng.required' => '位置情報（経度）が必要です。',
            'node_address_result.formatted_address.required' => '住所情報が必要です。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '店舗情報に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        DB::beginTransaction();
        try {
            $nodeResult = $request->input('node_address_result');
            
            // 最終的な住所と座標を決定
            if ($request->has('final_address')) {
                $lat = $request->input('final_lat');
                $lng = $request->input('final_lng');
                $formattedAddress = $request->input('final_address');
            } else {
                $lat = $nodeResult['lat'];
                $lng = $nodeResult['lng'];
                $formattedAddress = $nodeResult['formatted_address'];
            }

            // 住所を分解して保存用データを準備
            $addressParts = $this->parseAddress($formattedAddress);

            $shopData = array_merge(
                $request->only([
                    'name',
                    'phone',
                    'website_url',
                    'open_hours',
                    'table_count',
                    'score_table_count',
                    'auto_table_count',
                    'description',
                ]),
                [
                    'address_pref' => $addressParts['prefecture'] ?? '',
                    'address_city' => $addressParts['city'] ?? '',
                    'address_town' => $addressParts['town'] ?? '',
                    'address_street' => $addressParts['street'] ?? '',
                    'address_building' => $addressParts['building'] ?? '',
                    'lat' => $lat,
                    'lng' => $lng,
                    'prefecture_id' => $addressParts['prefecture_id'] ?? null,
                    'city_id' => $addressParts['city_id'] ?? null,
                ]
            );

            $shop = Shop::create($shopData);

            DB::commit();

            Log::info('店舗作成成功', [
                'shop_id' => $shop->id,
                'user_id' => Auth::id(),
                'coordinates' => [
                    'lat' => $shop->lat,
                    'lng' => $shop->lng
                ]
            ]);

            return $this->successResponse(
                $shop,
                '店舗を作成しました',
                201
            );

        } catch (\Exception $e) {
            DB::rollback();
            
            Log::error('店舗作成エラー: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'request_summary' => [
                    'name' => $request->input('name'),
                    'has_node_result' => $request->has('node_address_result'),
                    'final_address' => $request->input('final_address')
                ],
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('店舗の作成に失敗しました。', 500);
        }
    }

    /**
     * 店舗情報を更新
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'address_pref' => 'sometimes|required|string|max:64',
            'address_city' => 'sometimes|required|string|max:64',
            'address_town' => 'nullable|string|max:64',
            'address_street' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:32',
            'website_url' => 'nullable|url|max:255',
            'open_hours' => 'nullable|string|max:255',
            'table_count' => 'nullable|integer|min:0',
            'score_table_count' => 'nullable|integer|min:0',
            'auto_table_count' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'prefecture_id' => 'nullable|exists:geo_prefectures,id',
            'city_id' => 'nullable|exists:geo_cities,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '店舗更新情報に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($id);

            $shop->update($request->all());

            return $this->successResponse(
                $shop,
                '店舗情報を更新しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗更新エラー: ' . $e->getMessage(), [
                'shop_id' => $id,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('店舗の更新に失敗しました。', 500);
        }
    }

    /**
     * 店舗の駅情報を更新
     */
    public function updateShopStations(Request $request, $shopId)
    {
        try {
            Log::info('=== 駅設定リクエスト受信 ===', [
                'shop_id' => $shopId,
                'main_station_id' => $request->input('main_station_id'),
                'sub_station_ids' => $request->input('sub_station_ids'),
            ]);

            $shop = Shop::findOrFail($shopId);

            // バリデーション
            $validator = Validator::make($request->all(), [
                'main_station_id' => 'nullable|integer|exists:geo_stations,id',
                'sub_station_ids' => 'nullable|array|max:5',
                'sub_station_ids.*' => 'integer|exists:geo_stations,id'
            ], [
                'main_station_id.exists' => '指定されたメイン駅が存在しません。',
                'sub_station_ids.array' => 'サブ駅IDは配列形式で送信してください。',
                'sub_station_ids.max' => 'サブ駅は最大5つまで選択できます。',
                'sub_station_ids.*.exists' => '指定されたサブ駅の中に存在しない駅があります。'
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(
                    '駅設定データに不備があります: ' . $validator->errors()->first(),
                    422
                );
            }

            DB::beginTransaction();
            try {
                // 既存の駅情報を削除
                DB::table('shop_stations')->where('shop_id', $shopId)->delete();

                $mainStationId = $request->input('main_station_id');
                $subStationIds = $request->input('sub_station_ids', []);

                $stationsToInsert = [];

                // メイン駅を追加
                if ($mainStationId) {
                    $distance = $this->calculateDistance(
                        $shop->lat, 
                        $shop->lng, 
                        $mainStationId
                    );

                    $stationsToInsert[] = [
                        'shop_id' => $shopId,
                        'station_id' => $mainStationId,
                        'is_nearest' => true,
                        'distance_km' => $distance,
                        'walking_minutes' => round($distance * 15), // 時速4kmで計算
                        'accuracy' => 'medium',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // サブ駅を追加
                foreach ($subStationIds as $stationId) {
                    if ($stationId != $mainStationId) {
                        $distance = $this->calculateDistance(
                            $shop->lat, 
                            $shop->lng, 
                            $stationId
                        );

                        $stationsToInsert[] = [
                            'shop_id' => $shopId,
                            'station_id' => $stationId,
                            'is_nearest' => false,
                            'distance_km' => $distance,
                            'walking_minutes' => round($distance * 15),
                            'accuracy' => 'medium',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                if (!empty($stationsToInsert)) {
                    DB::table('shop_stations')->insert($stationsToInsert);
                }

                DB::commit();

                return $this->successResponse([
                    'stations_count' => count($stationsToInsert),
                    'message' => '駅情報を更新しました'
                ], '駅情報を更新しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗駅情報更新エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('駅情報の更新に失敗しました。', 500);
        }
    }

    /**
     * 座標から近隣駅を検索
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

            Log::info('近隣駅検索開始', [
                'lat' => $lat,
                'lng' => $lng,
                'max_stations' => $maxStations,
                'max_distance' => $maxDistance
            ]);

            // 距離計算のSQL
            $stations = DB::table('geo_stations')
                ->select(
                    'geo_stations.id',
                    'geo_stations.name',
                    'geo_stations.name_kana',
                    'geo_stations.latitude',
                    'geo_stations.longitude',
                    'geo_station_lines.name as line_name',
                    DB::raw("
                        6371 * acos(
                            cos(radians($lat)) * cos(radians(latitude)) *
                            cos(radians(longitude) - radians($lng)) +
                            sin(radians($lat)) * sin(radians(latitude))
                        ) AS distance
                    ")
                )
                ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                ->having('distance', '<=', $maxDistance)
                ->orderBy('distance')
                ->limit($maxStations)
                ->get();

            $stationsData = $stations->map(function ($station) {
                return [
                    'id' => $station->id,
                    'name' => $station->name,
                    'name_kana' => $station->name_kana,
                    'line_name' => $station->line_name,
                    'distance' => round($station->distance, 3),
                    'walking_time' => round($station->distance * 15),
                    'coordinates' => [
                        'lat' => $station->latitude,
                        'lng' => $station->longitude,
                    ],
                ];
            });

            Log::info('近隣駅検索完了', [
                'stations_count' => $stationsData->count(),
                'first_station' => $stationsData->first()['name'] ?? null
            ]);

            return $this->successResponse(
                $stationsData,
                empty($stationsData) ? '周辺に駅が見つかりませんでした' : '近隣駅を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('近隣駅検索エラー: ' . $e->getMessage(), [
                'lat' => $request->input('lat'),
                'lng' => $request->input('lng'),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('近隣駅の検索に失敗しました。', 500);
        }
    }

    /**
     * 住所から近隣駅を検索
     */
    public function getNearbyStationsByAddress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string|max:500',
        ], [
            'address.required' => '住所は必須です。',
            'address.max' => '住所は500文字以内で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '検索条件に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $address = $request->input('address');

            Log::info('住所から駅検索開始', [
                'address' => $address
            ]);

            // 住所をジオコーディング
            $coordinates = $this->geocodeAddress($address);

            if (!$coordinates) {
                return $this->errorResponse('指定された住所の座標を取得できませんでした。', 400);
            }

            // 座標から駅を検索
            $nearbyRequest = new Request([
                'lat' => $coordinates['lat'],
                'lng' => $coordinates['lng'],
                'max_stations' => 10,
                'max_distance' => 3.0
            ]);

            return $this->getNearbyStations($nearbyRequest);

        } catch (\Exception $e) {
            Log::error('住所から駅検索エラー: ' . $e->getMessage(), [
                'address' => $request->input('address'),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('住所からの駅検索に失敗しました。', 500);
        }
    }

    /**
     * 店舗の駅情報を取得
     */
    public function getShopStations($shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            $stations = DB::table('shop_stations')
                ->join('geo_stations', 'shop_stations.station_id', '=', 'geo_stations.id')
                ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                ->where('shop_stations.shop_id', $shopId)
                ->select(
                    'shop_stations.*',
                    'geo_stations.name',
                    'geo_stations.name_kana',
                    'geo_station_lines.name as line_name'
                )
                ->orderBy('shop_stations.is_nearest', 'desc')
                ->orderBy('shop_stations.distance_km')
                ->get();

            $result = [
                'main_station' => null,
                'sub_stations' => [],
                'total_count' => $stations->count()
            ];

            foreach ($stations as $station) {
                $stationData = [
                    'id' => $station->station_id,
                    'name' => $station->name,
                    'name_kana' => $station->name_kana,
                    'line_name' => $station->line_name,
                    'distance_km' => $station->distance_km,
                    'walking_minutes' => $station->walking_minutes,
                    'is_nearest' => (bool)$station->is_nearest
                ];

                if ($station->is_nearest) {
                    $result['main_station'] = $stationData;
                } else {
                    $result['sub_stations'][] = $stationData;
                }
            }

            return $this->successResponse($result, '店舗の駅情報を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗駅情報取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);

            return $this->errorResponse('駅情報の取得に失敗しました。', 500);
        }
    }

    /**
     * 駅名で検索
     */
    public function searchStationsByName(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'keyword' => 'required|string|min:1|max:50',
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'keyword.required' => '検索キーワードは必須です。',
            'keyword.min' => '検索キーワードは1文字以上入力してください。',
            'keyword.max' => '検索キーワードは50文字以内で入力してください。',
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

            Log::info('駅名検索開始', [
                'keyword' => $keyword,
                'limit' => $limit
            ]);

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
                    'geo_station_lines.name as line_name',
                    'geo_station_groups.id as station_group_id',
                    'geo_station_groups.name as station_group_name'
                )
                ->limit($limit)
                ->get();

            $stationsData = $stations->map(function ($station) {
                return [
                    'id' => $station->id,
                    'name' => $station->station_group_name ?? $station->name,
                    'name_kana' => $station->name_kana,
                    'line_name' => $station->line_name,
                    'coordinates' => [
                        'lat' => $station->latitude,
                        'lng' => $station->longitude,
                    ],
                    'station_group' => $station->station_group_id ? [
                        'id' => $station->station_group_id,
                        'name' => $station->station_group_name,
                    ] : null,
                ];
            });

            Log::info('駅名検索完了', [
                'keyword' => $keyword,
                'found_stations' => $stationsData->count()
            ]);

            return $this->successResponse(
                $stationsData,
                $stationsData->count() . '件の駅が見つかりました'
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
     * 住所を解析して都道府県・市区町村を分解
     * 
     * @param string $address
     * @return array
     */
    private function parseAddress(string $address)
    {
        // シンプルな実装。実際にはもっと高度な解析が必要
        $parts = [
            'prefecture' => '',
            'city' => '',
            'town' => '',
            'street' => '',
            'building' => '',
            'prefecture_id' => null,
            'city_id' => null,
        ];

        // 都道府県を検索
        $prefectures = DB::table('geo_prefectures')->get();
        foreach ($prefectures as $pref) {
            if (strpos($address, $pref->name) !== false) {
                $parts['prefecture'] = $pref->name;
                $parts['prefecture_id'] = $pref->id;
                
                // 市区町村を検索
                $cities = DB::table('geo_cities')
                    ->where('prefecture_id', $pref->id)
                    ->get();
                    
                foreach ($cities as $city) {
                    if (strpos($address, $city->name) !== false) {
                        $parts['city'] = $city->name;
                        $parts['city_id'] = $city->id;
                        break;
                    }
                }
                break;
            }
        }

        // 残りの部分を町名以降として扱う（簡易実装）
        $remaining = str_replace([$parts['prefecture'], $parts['city']], '', $address);
        $parts['town'] = trim($remaining);

        return $parts;
    }

    /**
     * 住所をジオコーディングして座標を取得
     * 
     * @param string $address
     * @return array|null
     */
    private function geocodeAddress(string $address)
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
     * 店舗と駅の距離を計算
     * 
     * @param float $shopLat
     * @param float $shopLng
     * @param int $stationId
     * @return float
     */
    private function calculateDistance($shopLat, $shopLng, $stationId)
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