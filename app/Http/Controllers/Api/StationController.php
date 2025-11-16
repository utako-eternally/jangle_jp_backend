<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoPrefecture;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StationController extends Controller
{
    use ApiResponse;

    /**
     * 駅詳細を取得(公開)
     * 駅グループまたは単独駅に対応
     * 
     * @param string $prefectureSlug
     * @param string $stationSlug
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($prefectureSlug, $stationSlug)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $prefectureSlug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            // まず駅グループを検索
            $stationGroup = DB::table('geo_station_groups')
                ->where('slug', $stationSlug)
                ->where('prefecture_id', $prefecture->id)
                ->select('id', 'name', 'name_kana', 'slug', 'primary_city_id')  // ← primary_city_id を追加
                ->first();

            if ($stationGroup) {
                // 駅グループの場合
                $lines = DB::table('geo_stations')
                    ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                    ->where('geo_stations.station_group_id', $stationGroup->id)
                    ->select(
                        'geo_stations.id as station_id',
                        'geo_station_lines.id as line_id',
                        'geo_station_lines.name as line_name'
                    )
                    ->get();

                // primary_city_id を使用して市区町村情報を取得
                $city = null;
                if ($stationGroup->primary_city_id) {
                    $cityData = DB::table('geo_cities')
                        ->where('id', $stationGroup->primary_city_id)
                        ->select('id', 'name', 'slug')
                        ->first();
                    
                    if ($cityData) {
                        $city = [
                            'id' => $cityData->id,
                            'name' => $cityData->name,
                            'slug' => $cityData->slug,
                        ];
                    }
                }

                $shopCount = DB::table('shop_stations')
                    ->join('shops', 'shop_stations.shop_id', '=', 'shops.id')
                    ->whereIn('shop_stations.station_id', $lines->pluck('station_id'))
                    ->where('shops.is_verified', true)
                    ->distinct('shop_stations.shop_id')
                    ->count('shop_stations.shop_id');

                $stationData = [
                    'type' => 'group',
                    'station_group_id' => $stationGroup->id,
                    'name' => $stationGroup->name,
                    'name_kana' => $stationGroup->name_kana,
                    'slug' => $stationGroup->slug,
                    'prefecture' => [
                        'id' => $prefecture->id,
                        'name' => $prefecture->name,
                        'slug' => $prefecture->slug,
                    ],
                    'city' => $city,
                    'lines' => $lines->map(function($line) {
                        return [
                            'station_id' => $line->station_id,
                            'line_id' => $line->line_id,
                            'line_name' => $line->line_name,
                        ];
                    }),
                    'shop_count' => $shopCount,
                ];

                return $this->successResponse(
                    $stationData,
                    '駅詳細を取得しました'
                );
            }

            // 駅グループが見つからない場合、単独駅を検索
            $station = DB::table('geo_stations')
                ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                ->leftJoin('geo_cities', 'geo_stations.city_id', '=', 'geo_cities.id')
                ->where('geo_stations.slug', $stationSlug)
                ->where('geo_stations.prefecture_id', $prefecture->id)
                ->whereNull('geo_stations.station_group_id')
                ->select(
                    'geo_stations.id',
                    'geo_stations.name',
                    'geo_stations.name_kana',
                    'geo_stations.slug',
                    'geo_stations.city_id',
                    'geo_station_lines.id as line_id',
                    'geo_station_lines.name as line_name',
                    'geo_cities.id as city_id',
                    'geo_cities.name as city_name',
                    'geo_cities.slug as city_slug'
                )
                ->first();

            if (!$station) {
                return $this->errorResponse('指定された駅が見つかりません。', 404);
            }

            $shopCount = DB::table('shop_stations')
                ->join('shops', 'shop_stations.shop_id', '=', 'shops.id')
                ->where('shop_stations.station_id', $station->id)
                ->where('shops.is_verified', true)
                ->distinct('shop_stations.shop_id')
                ->count('shop_stations.shop_id');

            $city = null;
            if ($station->city_id) {
                $city = [
                    'id' => $station->city_id,
                    'name' => $station->city_name,
                    'slug' => $station->city_slug,
                ];
            }

            $stationData = [
                'type' => 'single',
                'station_id' => $station->id,
                'name' => $station->name,
                'name_kana' => $station->name_kana,
                'slug' => $station->slug,
                'prefecture' => [
                    'id' => $prefecture->id,
                    'name' => $prefecture->name,
                    'slug' => $prefecture->slug,
                ],
                'city' => $city,
                'line' => [
                    'line_id' => $station->line_id,
                    'line_name' => $station->line_name,
                ],
                'shop_count' => $shopCount,
            ];

            return $this->successResponse(
                $stationData,
                '駅詳細を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('駅詳細取得エラー: ' . $e->getMessage(), [
                'prefecture_slug' => $prefectureSlug,
                'station_slug' => $stationSlug
            ]);
            return $this->errorResponse('駅の詳細情報を取得できませんでした。', 500);
        }
    }

    /**
     * 周辺駅を取得(公開)
     * 
     * @param string $prefectureSlug
     * @param string $stationSlug
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNearby($prefectureSlug, $stationSlug, Request $request)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $prefectureSlug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            // 現在の駅情報を取得
            $currentStation = null;
            $currentStationLatLng = null;

            // まず駅グループを検索
            $stationGroup = DB::table('geo_station_groups')
                ->where('slug', $stationSlug)
                ->where('prefecture_id', $prefecture->id)
                ->first();

            if ($stationGroup) {
                // 駅グループの場合、代表駅の座標を取得
                $representativeStation = DB::table('geo_stations')
                    ->where('station_group_id', $stationGroup->id)
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->first();

                if ($representativeStation) {
                    $currentStationLatLng = [
                        'lat' => $representativeStation->latitude,
                        'lng' => $representativeStation->longitude
                    ];
                }

                $currentStation = [
                    'id' => $stationGroup->id,
                    'name' => $stationGroup->name,
                    'slug' => $stationGroup->slug,
                ];
            } else {
                // 単独駅の場合
                $station = DB::table('geo_stations')
                    ->where('slug', $stationSlug)
                    ->where('prefecture_id', $prefecture->id)
                    ->whereNull('station_group_id')
                    ->first();

                if (!$station) {
                    return $this->errorResponse('指定された駅が見つかりません。', 404);
                }

                $currentStationLatLng = [
                    'lat' => $station->latitude,
                    'lng' => $station->longitude
                ];

                $currentStation = [
                    'id' => $station->id,
                    'name' => $station->name,
                    'slug' => $station->slug,
                ];
            }

            if (!$currentStationLatLng) {
                return $this->errorResponse('駅の位置情報が取得できません。', 404);
            }

            // パラメータ取得
            $maxDistanceKm = $request->input('max_distance_km', 10.0);
            $limit = $request->input('limit', 20);

            // 距離計算のためのSQL(Haversine formula)
            $lat = $currentStationLatLng['lat'];
            $lng = $currentStationLatLng['lng'];
            
            // グループ化されている駅は代表として取得（雀荘がある駅のみ）
            $nearbyStations = DB::table('geo_stations')
                ->leftJoin('geo_station_groups', 'geo_stations.station_group_id', '=', 'geo_station_groups.id')
                ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                ->leftJoin('geo_cities as station_city', 'geo_stations.city_id', '=', 'station_city.id')
                ->leftJoin('geo_cities as group_city', 'geo_station_groups.primary_city_id', '=', 'group_city.id')
                ->whereNotNull('geo_stations.latitude')
                ->whereNotNull('geo_stations.longitude')
                ->where('geo_stations.prefecture_id', $prefecture->id)
                ->whereRaw("
                    (6371 * acos(
                        cos(radians({$lat})) * 
                        cos(radians(geo_stations.latitude)) * 
                        cos(radians(geo_stations.longitude) - radians({$lng})) + 
                        sin(radians({$lat})) * 
                        sin(radians(geo_stations.latitude))
                    )) <= ?
                ", [$maxDistanceKm])
                ->where(function($query) use ($stationSlug) {
                    $query->where('geo_stations.slug', '!=', $stationSlug)
                        ->where(function($q) use ($stationSlug) {
                            $q->where('geo_station_groups.slug', '!=', $stationSlug)
                                ->orWhereNull('geo_station_groups.slug');
                        });
                })
                ->select(
                    DB::raw("COALESCE(geo_station_groups.id, geo_stations.id) as id"),
                    DB::raw("COALESCE(geo_station_groups.name, geo_stations.name) as name"),
                    DB::raw("COALESCE(geo_station_groups.name_kana, geo_stations.name_kana) as name_kana"),
                    DB::raw("COALESCE(geo_station_groups.slug, geo_stations.slug) as slug"),
                    DB::raw("COALESCE(group_city.slug, station_city.slug) as city_slug"),
                    'geo_station_lines.name as line_name',
                    'geo_stations.latitude',
                    'geo_stations.longitude',
                    DB::raw("
                        (6371 * acos(
                            cos(radians({$lat})) * 
                            cos(radians(geo_stations.latitude)) * 
                            cos(radians(geo_stations.longitude) - radians({$lng})) + 
                            sin(radians({$lat})) * 
                            sin(radians(geo_stations.latitude))
                        )) as distance_km
                    "),
                    'geo_stations.station_group_id',
                    'geo_stations.id as original_station_id'
                )
                ->orderBy('distance_km')
                ->get();

            // 重複を除外(グループ化されている駅は1つだけ表示)
            $seenSlugs = [];
            $uniqueStations = $nearbyStations->filter(function($station) use (&$seenSlugs) {
                if (in_array($station->slug, $seenSlugs)) {
                    return false;
                }
                $seenSlugs[] = $station->slug;
                return true;
            })->take($limit * 2); // 余分に取得してフィルタリング後に調整

            // 各駅の店舗数を取得（雀荘がある駅のみフィルタリング）
            $nearbyStationsWithShops = $uniqueStations->map(function($station) use ($prefecture) {
                // グループ化されている駅の場合は、グループ内全駅の店舗数を取得
                if ($station->station_group_id) {
                    $stationIds = DB::table('geo_stations')
                        ->where('station_group_id', $station->station_group_id)
                        ->pluck('id')
                        ->toArray();
                } else {
                    $stationIds = [$station->original_station_id];
                }

                $shopCount = DB::table('shop_stations')
                    ->join('shops', 'shop_stations.shop_id', '=', 'shops.id')
                    ->whereIn('shop_stations.station_id', $stationIds)
                    ->where('shops.is_verified', true)
                    ->distinct('shop_stations.shop_id')
                    ->count('shop_stations.shop_id');

                return [
                    'id' => $station->id,
                    'name' => $station->name,
                    'name_kana' => $station->name_kana,
                    'slug' => $station->slug,
                    'city_slug' => $station->city_slug,
                    'line_name' => $station->line_name,
                    'prefecture_slug' => $prefecture->slug,
                    'distance_km' => round($station->distance_km, 1),
                    'shop_count' => $shopCount,
                ];
            })
            ->filter(function($station) {
                return $station['shop_count'] > 0; // ✅ 雀荘がある駅のみ
            })
            ->take($limit) // フィルタリング後に指定件数まで
            ->values();

            $response = [
                'current_station' => $currentStation,
                'nearby_stations' => $nearbyStationsWithShops,
                'total' => $nearbyStationsWithShops->count(),
            ];

            return $this->successResponse(
                $response,
                '周辺駅を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('周辺駅取得エラー: ' . $e->getMessage(), [
                'prefecture_slug' => $prefectureSlug,
                'station_slug' => $stationSlug,
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('周辺駅の取得に失敗しました。', 500);
        }
    }

    /**
     * 駅周辺の店舗一覧を取得(公開)
     * 
     * @param string $prefectureSlug
     * @param string $stationSlug
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShops($prefectureSlug, $stationSlug, Request $request)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $prefectureSlug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            // 対象駅のIDリストを取得
            $stationIds = [];

            // まず駅グループを検索
            $stationGroup = DB::table('geo_station_groups')
                ->where('slug', $stationSlug)
                ->where('prefecture_id', $prefecture->id)
                ->first();

            if ($stationGroup) {
                // 駅グループの場合、グループ内の全駅IDを取得
                $stationIds = DB::table('geo_stations')
                    ->where('station_group_id', $stationGroup->id)
                    ->pluck('id')
                    ->toArray();
            } else {
                // 単独駅の場合
                $station = DB::table('geo_stations')
                    ->where('slug', $stationSlug)
                    ->where('prefecture_id', $prefecture->id)
                    ->whereNull('station_group_id')
                    ->first();

                if (!$station) {
                    return $this->errorResponse('指定された駅が見つかりません。', 404);
                }

                $stationIds = [$station->id];
            }

            // 駅に紐づく店舗を取得
            $query = DB::table('shops')
                ->select(
                    'shops.id',
                    'shops.name',
                    'shops.description',
                    'shops.phone',
                    'shops.website_url',
                    'shops.table_count',
                    'shops.score_table_count',
                    'shops.auto_table_count',
                    'shops.lat',
                    'shops.lng',
                    'shops.address_pref',
                    'shops.address_city',
                    'shops.address_town',
                    'shops.address_street',
                    'shops.address_building',
                    'shops.main_image_paths',
                    'shops.logo_image_paths',
                    'shops.created_at',
                    'geo_prefectures.name as prefecture_name',
                    'geo_prefectures.slug as prefecture_slug',
                    'geo_cities.name as city_name',
                    'geo_cities.slug as city_slug',
                    'shop_stations.distance_km',
                    'shop_stations.walking_minutes'
                )
                ->join('shop_stations', 'shops.id', '=', 'shop_stations.shop_id')
                ->join('geo_prefectures', 'shops.prefecture_id', '=', 'geo_prefectures.id')
                ->join('geo_cities', 'shops.city_id', '=', 'geo_cities.id')
                ->whereIn('shop_stations.station_id', $stationIds)
                ->where('shops.is_verified', true)
                ->distinct();

            // === フィルタリング追加 ===

            // 距離でのフィルタリング（既存）
            if ($request->filled('max_distance_km')) {
                $maxDistance = (float) $request->input('max_distance_km');
                $query->where('shop_stations.distance_km', '<=', $maxDistance);
            }

            // 営業形態フィルター（OR検索）
            $hasBusinessTypeFilter = $request->boolean('has_three_player_free') || 
                                    $request->boolean('has_four_player_free') || 
                                    $request->boolean('has_set');

            if ($hasBusinessTypeFilter) {
                $query->where(function($q) use ($request) {
                    if ($request->boolean('has_three_player_free')) {
                        $q->orWhereExists(function ($subQ) {
                            $subQ->select(DB::raw(1))
                                ->from('shop_frees')
                                ->whereColumn('shop_frees.shop_id', 'shops.id')
                                ->where('shop_frees.game_format', 'THREE_PLAYER');
                        });
                    }
                    
                    if ($request->boolean('has_four_player_free')) {
                        $q->orWhereExists(function ($subQ) {
                            $subQ->select(DB::raw(1))
                                ->from('shop_frees')
                                ->whereColumn('shop_frees.shop_id', 'shops.id')
                                ->where('shop_frees.game_format', 'FOUR_PLAYER');
                        });
                    }
                    
                    if ($request->boolean('has_set')) {
                        $q->orWhereExists(function ($subQ) {
                            $subQ->select(DB::raw(1))
                                ->from('shop_sets')
                                ->whereColumn('shop_sets.shop_id', 'shops.id');
                        });
                    }
                });
            }

            // 卓の種類フィルター（AND検索）
            if ($request->boolean('auto_table')) {
                $query->where('shops.auto_table_count', '>', 0);
            }
            if ($request->boolean('score_table')) {
                $query->where('shops.score_table_count', '>', 0);
            }

            // ルールフィルター（モデルのメソッドを使用）
            if ($request->filled('rules')) {
                $rules = is_array($request->input('rules')) 
                    ? $request->input('rules') 
                    : explode(',', $request->input('rules'));
                
                \App\Models\ShopRule::applyRuleFilters($query, $rules);
            }

            // 特徴フィルター（モデルのメソッドを使用）
            if ($request->filled('features')) {
                $features = is_array($request->input('features')) 
                    ? $request->input('features') 
                    : explode(',', $request->input('features'));
                
                \App\Models\ShopFeature::applyFeatureFilters($query, $features);
            }

            // === フィルタリング終了 ===

            // ソート
            $sortBy = $request->input('sort_by', 'distance_km');
            $sortDirection = $request->input('sort_direction', 'asc');
            
            if ($sortBy === 'distance_km' || $sortBy === 'walking_minutes') {
                $query->orderBy('shop_stations.' . $sortBy, $sortDirection);
            } else {
                $query->orderBy('shops.' . $sortBy, $sortDirection);
            }

            // ページネーション
            $perPage = $request->input('per_page', 15);
            $shops = $query->paginate($perPage);

            // 各店舗の最寄り駅情報を追加
            $shopsData = collect($shops->items())->map(function ($shop) {
                $nearestStation = DB::table('shop_stations')
                    ->join('geo_stations', 'shop_stations.station_id', '=', 'geo_stations.id')
                    ->leftJoin('geo_station_groups', 'geo_stations.station_group_id', '=', 'geo_station_groups.id')
                    ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                    ->where('shop_stations.shop_id', $shop->id)
                    ->where('shop_stations.is_nearest', true)
                    ->select(
                        'geo_stations.id as station_id',
                        'geo_stations.name as station_name',
                        'geo_stations.slug as station_slug',
                        'geo_station_groups.id as station_group_id',
                        'geo_station_groups.name as station_group_name',
                        'geo_station_groups.slug as station_group_slug',
                        'geo_station_lines.name as line_name',
                        'shop_stations.distance_km',
                        'shop_stations.walking_minutes'
                    )
                    ->first();

                // 営業時間を取得
                $businessHours = DB::table('shop_business_hours')
                    ->where('shop_id', $shop->id)
                    ->orderBy('day_of_week')
                    ->get();

                // フリー雀荘情報を取得
                $frees = DB::table('shop_frees')
                    ->where('shop_id', $shop->id)
                    ->select('game_format')
                    ->get();

                // セット雀荘情報を取得
                $hasSet = DB::table('shop_sets')
                    ->where('shop_id', $shop->id)
                    ->exists();

                $shopArray = (array) $shop;
                $shopArray['nearest_station'] = $nearestStation ? [
                    'id' => $nearestStation->station_id,
                    'name' => $nearestStation->station_group_name ?? $nearestStation->station_name,
                    'slug' => $nearestStation->station_group_slug ?? $nearestStation->station_slug,
                    'line_name' => $nearestStation->line_name,
                    'distance_km' => (float) $nearestStation->distance_km,
                    'walking_minutes' => (int) $nearestStation->walking_minutes,
                ] : null;

                // 営業時間を追加
                $shopArray['business_hours'] = $businessHours;

                // 営業形態を追加
                $shopArray['has_three_player_free'] = $frees->contains('game_format', 'THREE_PLAYER');
                $shopArray['has_four_player_free'] = $frees->contains('game_format', 'FOUR_PLAYER');
                $shopArray['has_set'] = $hasSet;

                return $shopArray;
            });

            $response = [
                'data' => $shopsData,
                'current_page' => $shops->currentPage(),
                'last_page' => $shops->lastPage(),
                'per_page' => $shops->perPage(),
                'total' => $shops->total(),
            ];

            return $this->successResponse(
                $response,
                '駅周辺の店舗一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('駅周辺店舗一覧取得エラー: ' . $e->getMessage(), [
                'prefecture_slug' => $prefectureSlug,
                'station_slug' => $stationSlug
            ]);
            return $this->errorResponse('店舗一覧の取得に失敗しました。', 500);
        }
    }
}