<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoPrefecture;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SearchController extends Controller
{
    use ApiResponse;

    /**
     * オートコンプリート検索（公開）
     * 駅名・市区町村名を検索
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function suggest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:1|max:100',
            'prefecture' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ], [
            'q.required' => '検索キーワードは必須です。',
            'q.min' => '検索キーワードは1文字以上で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                $validator->errors()->first(),
                422
            );
        }

        try {
            $keyword = $request->input('q');
            $prefectureSlug = $request->input('prefecture');
            $limit = $request->input('limit', 10);

            // 都道府県の絞り込み
            $prefectureId = null;
            if ($prefectureSlug) {
                $prefecture = GeoPrefecture::where('slug', $prefectureSlug)->first();
                if ($prefecture) {
                    $prefectureId = $prefecture->id;
                }
            }

            // 駅グループを検索
            $stationGroups = $this->searchStationGroups($keyword, $prefectureId, $limit);

            // 単独駅を検索
            $singleStations = $this->searchSingleStations($keyword, $prefectureId, $limit);

            // 市区町村を検索
            $cities = $this->searchCities($keyword, $prefectureId, $limit);

            // 結果をマージ
            $results = [
                'stations' => array_merge($stationGroups, $singleStations),
                'cities' => $cities,
            ];

            return $this->successResponse(
                $results,
                '検索結果を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('オートコンプリート検索エラー: ' . $e->getMessage(), [
                'keyword' => $request->input('q'),
                'prefecture' => $request->input('prefecture')
            ]);
            return $this->errorResponse('検索に失敗しました。', 500);
        }
    }

    /**
     * 駅グループを検索
     * 
     * @param string $keyword
     * @param int|null $prefectureId
     * @param int $limit
     * @return array
     */
    private function searchStationGroups($keyword, $prefectureId, $limit)
    {
        $query = DB::table('geo_station_groups')
            ->select(
                'geo_station_groups.id as station_group_id',
                'geo_station_groups.name',
                'geo_station_groups.name_kana',
                'geo_station_groups.slug',
                'geo_prefectures.name as prefecture_name',
                'geo_prefectures.slug as prefecture_slug',
                DB::raw('COUNT(DISTINCT shop_stations.shop_id) as shop_count')
            )
            ->join('geo_prefectures', 'geo_station_groups.prefecture_id', '=', 'geo_prefectures.id')
            ->join('geo_stations', 'geo_stations.station_group_id', '=', 'geo_station_groups.id')
            ->leftJoin('shop_stations', 'shop_stations.station_id', '=', 'geo_stations.id')
            ->leftJoin('shops', function($join) {
                $join->on('shops.id', '=', 'shop_stations.shop_id')
                     ->where('shops.is_verified', true);
            })
            ->where(function($q) use ($keyword) {
                $q->where('geo_station_groups.name', 'like', "%{$keyword}%")
                  ->orWhere('geo_station_groups.name_kana', 'like', "%{$keyword}%");
            });

        if ($prefectureId) {
            $query->where('geo_station_groups.prefecture_id', $prefectureId);
        }

        $results = $query
            ->groupBy(
                'geo_station_groups.id',
                'geo_station_groups.name',
                'geo_station_groups.name_kana',
                'geo_station_groups.slug',
                'geo_prefectures.name',
                'geo_prefectures.slug'
            )
            ->havingRaw('COUNT(DISTINCT shop_stations.shop_id) > 0')
            ->orderByDesc('shop_count')
            ->limit($limit)
            ->get();

        return $results->map(function($item) {
            return [
                'type' => 'station_group',
                'station_group_id' => $item->station_group_id,
                'name' => $item->name,
                'name_kana' => $item->name_kana,
                'slug' => $item->slug,
                'prefecture_name' => $item->prefecture_name,
                'prefecture_slug' => $item->prefecture_slug,
                'shop_count' => (int) $item->shop_count,
                'display_name' => $item->name . '駅 (' . $item->prefecture_name . ')',
            ];
        })->toArray();
    }

    /**
     * 単独駅を検索
     * 
     * @param string $keyword
     * @param int|null $prefectureId
     * @param int $limit
     * @return array
     */
    private function searchSingleStations($keyword, $prefectureId, $limit)
    {
        $query = DB::table('geo_stations')
            ->select(
                'geo_stations.id as station_id',
                'geo_stations.name',
                'geo_stations.name_kana',
                'geo_stations.slug',
                'geo_station_lines.name as line_name',
                'geo_prefectures.name as prefecture_name',
                'geo_prefectures.slug as prefecture_slug',
                DB::raw('COUNT(DISTINCT shop_stations.shop_id) as shop_count')
            )
            ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
            ->join('geo_prefectures', 'geo_stations.prefecture_id', '=', 'geo_prefectures.id')
            ->leftJoin('shop_stations', 'shop_stations.station_id', '=', 'geo_stations.id')
            ->leftJoin('shops', function($join) {
                $join->on('shops.id', '=', 'shop_stations.shop_id')
                     ->where('shops.is_verified', true);
            })
            ->whereNull('geo_stations.station_group_id')
            ->where(function($q) use ($keyword) {
                $q->where('geo_stations.name', 'like', "%{$keyword}%")
                  ->orWhere('geo_stations.name_kana', 'like', "%{$keyword}%");
            });

        if ($prefectureId) {
            $query->where('geo_stations.prefecture_id', $prefectureId);
        }

        $results = $query
            ->groupBy(
                'geo_stations.id',
                'geo_stations.name',
                'geo_stations.name_kana',
                'geo_stations.slug',
                'geo_station_lines.name',
                'geo_prefectures.name',
                'geo_prefectures.slug'
            )
            ->havingRaw('COUNT(DISTINCT shop_stations.shop_id) > 0')
            ->orderByDesc('shop_count')
            ->limit($limit)
            ->get();

        return $results->map(function($item) {
            return [
                'type' => 'station_single',
                'station_id' => $item->station_id,
                'name' => $item->name,
                'name_kana' => $item->name_kana,
                'slug' => $item->slug,
                'line_name' => $item->line_name,
                'prefecture_name' => $item->prefecture_name,
                'prefecture_slug' => $item->prefecture_slug,
                'shop_count' => (int) $item->shop_count,
                'display_name' => $item->name . '駅 (' . $item->line_name . ' / ' . $item->prefecture_name . ')',
            ];
        })->toArray();
    }

    /**
     * 市区町村を検索
     * 
     * @param string $keyword
     * @param int|null $prefectureId
     * @param int $limit
     * @return array
     */
    private function searchCities($keyword, $prefectureId, $limit)
    {
        $query = DB::table('geo_cities')
            ->select(
                'geo_cities.id as city_id',
                'geo_cities.name',
                'geo_cities.name_kana',
                'geo_cities.slug',
                'geo_prefectures.name as prefecture_name',
                'geo_prefectures.slug as prefecture_slug',
                DB::raw('COUNT(shops.id) as shop_count')
            )
            ->join('geo_prefectures', 'geo_cities.prefecture_id', '=', 'geo_prefectures.id')
            ->leftJoin('shops', function($join) {
                $join->on('shops.city_id', '=', 'geo_cities.id')
                     ->where('shops.is_verified', true);
            })
            ->where(function($q) use ($keyword) {
                $q->where('geo_cities.name', 'like', "%{$keyword}%")
                  ->orWhere('geo_cities.name_kana', 'like', "%{$keyword}%");
            });

        if ($prefectureId) {
            $query->where('geo_cities.prefecture_id', $prefectureId);
        }

        $results = $query
            ->groupBy(
                'geo_cities.id',
                'geo_cities.name',
                'geo_cities.name_kana',
                'geo_cities.slug',
                'geo_prefectures.name',
                'geo_prefectures.slug'
            )
            ->havingRaw('COUNT(shops.id) > 0')
            ->orderByDesc('shop_count')
            ->limit($limit)
            ->get();

        return $results->map(function($item) {
            return [
                'type' => 'city',
                'city_id' => $item->city_id,
                'name' => $item->name,
                'name_kana' => $item->name_kana,
                'slug' => $item->slug,
                'prefecture_name' => $item->prefecture_name,
                'prefecture_slug' => $item->prefecture_slug,
                'shop_count' => (int) $item->shop_count,
                'display_name' => $item->name . ' (' . $item->prefecture_name . ')',
            ];
        })->toArray();
    }
}