<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoPrefecture;
use App\Models\GeoCity;
use App\Models\GeoStation;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CityController extends Controller
{
    use ApiResponse;

    /**
     * 市区町村詳細を取得（公開）
     * 
     * @param string $prefectureSlug
     * @param string $citySlug
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($prefectureSlug, $citySlug)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $prefectureSlug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            $city = GeoCity::where('slug', $citySlug)
                ->where('prefecture_id', $prefecture->id)
                ->first();

            if (!$city) {
                return $this->errorResponse('指定された市区町村が見つかりません。', 404);
            }

            // 店舗数を取得
            $shopCount = DB::table('shops')
                ->where('city_id', $city->id)
                ->where('is_verified', true)
                ->count();

            $cityData = [
                'id' => $city->id,
                'name' => $city->name,
                'name_kana' => $city->name_kana,
                'slug' => $city->slug,
                'prefecture' => [
                    'id' => $prefecture->id,
                    'name' => $prefecture->name,
                    'slug' => $prefecture->slug,
                ],
                'shop_count' => $shopCount,
            ];

            return $this->successResponse(
                $cityData,
                '市区町村詳細を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('市区町村詳細取得エラー: ' . $e->getMessage(), [
                'prefecture_slug' => $prefectureSlug,
                'city_slug' => $citySlug
            ]);
            return $this->errorResponse('市区町村の詳細情報を取得できませんでした。', 500);
        }
    }

    /**
     * 市区町村内の店舗一覧を取得（公開）
     * 
     * @param string $prefectureSlug
     * @param string $citySlug
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShops($prefectureSlug, $citySlug, Request $request)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $prefectureSlug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            $city = GeoCity::where('slug', $citySlug)
                ->where('prefecture_id', $prefecture->id)
                ->first();

            if (!$city) {
                return $this->errorResponse('指定された市区町村が見つかりません。', 404);
            }

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
                    'geo_cities.slug as city_slug'
                )
                ->join('geo_prefectures', 'shops.prefecture_id', '=', 'geo_prefectures.id')
                ->join('geo_cities', 'shops.city_id', '=', 'geo_cities.id')
                ->where('shops.city_id', $city->id)
                ->where('shops.is_verified', true);

            // === フィルタリング追加 ===

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
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy('shops.' . $sortBy, $sortDirection);

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
                '市区町村内の店舗一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('市区町村内店舗一覧取得エラー: ' . $e->getMessage(), [
                'prefecture_slug' => $prefectureSlug,
                'city_slug' => $citySlug
            ]);
            return $this->errorResponse('店舗一覧の取得に失敗しました。', 500);
        }
    }
    /**
     * 市区町村内の駅一覧を取得（修正版）
     * 
     * @param string $prefecture_slug 都道府県スラッグ
     * @param string $city_slug 市区町村スラッグ
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStations($prefecture_slug, $city_slug)
    {
        try {
            // 都道府県を取得
            $prefecture = GeoPrefecture::where('slug', $prefecture_slug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            // 市区町村を取得
            $city = GeoCity::where('prefecture_id', $prefecture->id)
                ->where('slug', $city_slug)
                ->first();

            if (!$city) {
                return $this->errorResponse('指定された市区町村が見つかりません。', 404);
            }

            // 市区町村内の駅を取得（路線情報と駅グループも含む）
            // geo_station_linesテーブルのカラムを確認して適切なものだけ取得
            $stations = GeoStation::where('city_id', $city->id)
                ->with([
                    'stationLine:id,name,name_kana',
                    'stationGroup:id,name,name_kana'
                ])
                ->select([
                    'id',
                    'station_group_id',
                    'station_line_id',
                    'name',
                    'name_kana',
                    'slug',
                    'latitude',
                    'longitude',
                    'line_order',
                    'is_grouped'
                ])
                ->orderBy('station_line_id')
                ->orderBy('line_order')
                ->get();

            // 駅グループでグルーピング
            $groupedStations = [];
            $processedGroups = [];

            foreach ($stations as $station) {
                // 駅グループがある場合
                if ($station->stationGroup && $station->is_grouped) {
                    $groupId = $station->stationGroup->id;
                    
                    // 既に処理済みのグループならスキップ
                    if (in_array($groupId, $processedGroups)) {
                        continue;
                    }
                    
                    $processedGroups[] = $groupId;
                    
                    // グループに属する全路線を取得
                    $linesInGroup = GeoStation::where('station_group_id', $groupId)
                        ->where('city_id', $city->id)
                        ->with('stationLine:id,name,name_kana')
                        ->get()
                        ->pluck('stationLine')
                        ->filter()
                        ->unique('id')
                        ->values()
                        ->map(function ($line) {
                            return [
                                'id' => $line->id,
                                'name' => $line->name,
                                'name_kana' => $line->name_kana,
                            ];
                        });

                    $groupedStations[] = [
                        'id' => $station->id,
                        'name' => $station->stationGroup->name,
                        'name_kana' => $station->stationGroup->name_kana,
                        'slug' => $station->slug, // 駅のslugを使用
                        'latitude' => (float) $station->latitude,
                        'longitude' => (float) $station->longitude,
                        'is_grouped' => true,
                        'station_group' => [
                            'id' => $station->stationGroup->id,
                            'name' => $station->stationGroup->name,
                            'name_kana' => $station->stationGroup->name_kana,
                        ],
                        'lines' => $linesInGroup,
                    ];
                } else {
                    // 単独駅
                    $groupedStations[] = [
                        'id' => $station->id,
                        'name' => $station->name,
                        'name_kana' => $station->name_kana,
                        'slug' => $station->slug,
                        'latitude' => (float) $station->latitude,
                        'longitude' => (float) $station->longitude,
                        'is_grouped' => false,
                        'station_group' => null,
                        'lines' => $station->stationLine ? [[
                            'id' => $station->stationLine->id,
                            'name' => $station->stationLine->name,
                            'name_kana' => $station->stationLine->name_kana,
                        ]] : [],
                    ];
                }
            }

            return $this->successResponse([
                'city' => [
                    'id' => $city->id,
                    'name' => $city->name,
                    'name_kana' => $city->name_kana,
                    'slug' => $city->slug,
                    'prefecture' => [
                        'id' => $prefecture->id,
                        'name' => $prefecture->name,
                        'slug' => $prefecture->slug,
                    ]
                ],
                'stations' => $groupedStations,
                'total' => count($groupedStations)
            ], '市区町村内の駅一覧を取得しました');

        } catch (\Exception $e) {
            Log::error('市区町村内駅一覧取得エラー: ' . $e->getMessage(), [
                'prefecture_slug' => $prefecture_slug,
                'city_slug' => $city_slug,
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('駅一覧の取得に失敗しました。', 500);
        }
    }

}