<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoPrefecture;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrefectureController extends Controller
{
    use ApiResponse;

    /**
     * 都道府県一覧を取得（公開）
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $prefectures = GeoPrefecture::select(
                'geo_prefectures.id',
                'geo_prefectures.name',
                'geo_prefectures.name_kana',
                'geo_prefectures.slug',
                DB::raw('COUNT(shops.id) as shop_count')
            )
            ->leftJoin('shops', function($join) {
                $join->on('shops.prefecture_id', '=', 'geo_prefectures.id')
                     ->where('shops.is_verified', true);
            })
            ->groupBy(
                'geo_prefectures.id',
                'geo_prefectures.name',
                'geo_prefectures.name_kana',
                'geo_prefectures.slug'
            )
            ->orderBy('geo_prefectures.slug')
            ->get();

            return $this->successResponse(
                $prefectures,
                '都道府県一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('都道府県一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('都道府県一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 都道府県詳細を取得（公開）
     * 
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($slug)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $slug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            // 店舗数を取得
            $shopCount = DB::table('shops')
                ->where('prefecture_id', $prefecture->id)
                ->where('is_verified', true)
                ->count();

            $prefectureData = [
                'id' => $prefecture->id,
                'name' => $prefecture->name,
                'name_kana' => $prefecture->name_kana,
                'slug' => $prefecture->slug,
                'shop_count' => $shopCount,
            ];

            return $this->successResponse(
                $prefectureData,
                '都道府県詳細を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('都道府県詳細取得エラー: ' . $e->getMessage(), [
                'slug' => $slug
            ]);
            return $this->errorResponse('都道府県の詳細情報を取得できませんでした。', 500);
        }
    }

    /**
     * 都道府県内の店舗一覧を取得（公開）
     * 
     * @param string $slug
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShops($slug, Request $request)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $slug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
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
                ->where('shops.prefecture_id', $prefecture->id)
                ->where('shops.is_verified', true);

            // === フィルタリング追加 ===

            // 市区町村フィルター
            if ($request->filled('cities')) {
                $cityIds = is_array($request->input('cities')) 
                    ? $request->input('cities') 
                    : explode(',', $request->input('cities'));
                $query->whereIn('shops.city_id', $cityIds);
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
                '都道府県内の店舗一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('都道府県内店舗一覧取得エラー: ' . $e->getMessage(), [
                'slug' => $slug
            ]);
            return $this->errorResponse('店舗一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 都道府県内の駅一覧を取得（公開）
     * 店舗数の多い順に取得
     * 
     * @param string $slug
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStations($slug, Request $request)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $slug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            $limit = $request->input('limit', 50);

            // 駅グループがある駅の集計
            $groupedStations = DB::table('geo_station_groups')
                ->select(
                    'geo_station_groups.id as station_group_id',
                    'geo_station_groups.name',
                    'geo_station_groups.name_kana',
                    'geo_station_groups.slug',
                    DB::raw('COUNT(DISTINCT shop_stations.shop_id) as shop_count')
                )
                ->join('geo_stations', 'geo_stations.station_group_id', '=', 'geo_station_groups.id')
                ->leftJoin('shop_stations', 'shop_stations.station_id', '=', 'geo_stations.id')
                ->leftJoin('shops', function($join) {
                    $join->on('shops.id', '=', 'shop_stations.shop_id')
                         ->where('shops.is_verified', true);
                })
                ->where('geo_station_groups.prefecture_id', $prefecture->id)
                ->groupBy(
                    'geo_station_groups.id',
                    'geo_station_groups.name',
                    'geo_station_groups.name_kana',
                    'geo_station_groups.slug'
                )
                ->havingRaw('COUNT(DISTINCT shop_stations.shop_id) > 0')
                ->get();

            // 駅グループがない単独駅の集計
            $singleStations = DB::table('geo_stations')
                ->select(
                    DB::raw('NULL as station_group_id'),
                    'geo_stations.id as station_id',
                    'geo_stations.name',
                    'geo_stations.name_kana',
                    'geo_stations.slug',
                    'geo_station_lines.name as line_name',
                    DB::raw('COUNT(DISTINCT shop_stations.shop_id) as shop_count')
                )
                ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                ->leftJoin('shop_stations', 'shop_stations.station_id', '=', 'geo_stations.id')
                ->leftJoin('shops', function($join) {
                    $join->on('shops.id', '=', 'shop_stations.shop_id')
                         ->where('shops.is_verified', true);
                })
                ->where('geo_stations.prefecture_id', $prefecture->id)
                ->whereNull('geo_stations.station_group_id')
                ->groupBy(
                    'geo_stations.id',
                    'geo_stations.name',
                    'geo_stations.name_kana',
                    'geo_stations.slug',
                    'geo_station_lines.name'
                )
                ->havingRaw('COUNT(DISTINCT shop_stations.shop_id) > 0')
                ->get();

            // グループ化された駅を整形
            $formattedGroupedStations = $groupedStations->map(function($group) {
                return [
                    'type' => 'group',
                    'station_group_id' => $group->station_group_id,
                    'name' => $group->name,
                    'name_kana' => $group->name_kana,
                    'slug' => $group->slug,
                    'shop_count' => (int)$group->shop_count,
                ];
            });

            // 単独駅を整形
            $formattedSingleStations = $singleStations->map(function($station) {
                return [
                    'type' => 'single',
                    'station_id' => $station->station_id,
                    'name' => $station->name,
                    'name_kana' => $station->name_kana,
                    'slug' => $station->slug,
                    'line_name' => $station->line_name,
                    'shop_count' => (int)$station->shop_count,
                ];
            });

            // マージして店舗数順にソート
            $allStations = $formattedGroupedStations
                ->concat($formattedSingleStations)
                ->sortByDesc('shop_count')
                ->take($limit)
                ->values();

            return $this->successResponse(
                $allStations,
                '都道府県内の駅一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('都道府県内駅一覧取得エラー: ' . $e->getMessage(), [
                'slug' => $slug
            ]);
            return $this->errorResponse('駅一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 都道府県内の市区町村一覧を取得（公開）
     * 店舗数付き
     * 
     * @param string $slug
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCities($slug, Request $request)
    {
        try {
            $prefecture = GeoPrefecture::where('slug', $slug)->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 404);
            }

            $cities = DB::table('geo_cities')
                ->select(
                    'geo_cities.id',
                    'geo_cities.name',
                    'geo_cities.name_kana',
                    'geo_cities.slug',
                    DB::raw('COUNT(shops.id) as shop_count')
                )
                ->leftJoin('shops', function($join) {
                    $join->on('shops.city_id', '=', 'geo_cities.id')
                         ->where('shops.is_verified', true);
                })
                ->where('geo_cities.prefecture_id', $prefecture->id)
                ->groupBy(
                    'geo_cities.id',
                    'geo_cities.name',
                    'geo_cities.name_kana',
                    'geo_cities.slug'
                )
                ->havingRaw('COUNT(shops.id) > 0')
                ->orderByDesc('shop_count')
                ->get();

            return $this->successResponse(
                $cities,
                '都道府県内の市区町村一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('都道府県内市区町村一覧取得エラー: ' . $e->getMessage(), [
                'slug' => $slug
            ]);
            return $this->errorResponse('市区町村一覧の取得に失敗しました。', 500);
        }
    }
}