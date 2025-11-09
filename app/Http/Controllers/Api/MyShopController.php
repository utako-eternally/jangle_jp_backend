<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopFree;
use App\Models\ShopSet;
use App\Models\ShopImage;
use App\Models\ShopPlan;
use App\Models\ShopBusinessHour;
use App\Services\ImageService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image as ImageManagerStatic;

class MyShopController extends Controller
{
    use ApiResponse;
    protected ImageService $imageService;

    public function __construct(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    /**
     * 自分が所有する雀荘一覧を取得
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            
            $query = $user->shops()->with(['prefecture', 'city', 'activePlan']);
            
            if ($request->filled('keyword')) {
                $keyword = '%' . $request->keyword . '%';
                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', $keyword)
                        ->orWhere('description', 'like', $keyword);
                });
            }

            if ($request->filled('plan_type')) {
                $planType = $request->input('plan_type');
                $query->whereHas('activePlan', function ($q) use ($planType) {
                    $q->where('plan_type', $planType)
                    ->where('status', 'active');
                });
            }
            
            $shops = $query->orderBy('created_at', 'desc')
                        ->paginate($request->input('per_page', 15));
            
            return $this->successResponse(
                $shops,
                '自分の雀荘一覧を取得しました'
            );
            
        } catch (\Exception $e) {
            Log::error('自分の雀荘一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('雀荘一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 自分が所有する雀荘の詳細を取得
     */
    public function show($id)
    {
        try {
            $shop = Shop::with([
                'prefecture', 
                'city',
                'owner',
                'activePlan',
                'shopStations.station.stationLine',
                'shopStations.stationGroup',
                'features',
                'rules',
                'menus',
                'frees',
                'set',
                'businessHours'
            ])->findOrFail($id);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この雀荘の詳細を閲覧する権限がありません。', 403);
            }

            $shopData = $shop->toArray();
            $shopData['nearest_station'] = null;
            $shopData['sub_stations'] = [];

            foreach ($shop->shopStations as $shopStation) {
                $stationInfo = [
                    'id' => $shopStation->station->id,
                    'name' => $shopStation->stationGroup 
                        ? $shopStation->stationGroup->name 
                        : $shopStation->station->name,
                    'line_name' => $shopStation->station->stationLine->name,
                    'distance_km' => $shopStation->distance_km,
                    'walking_minutes' => $shopStation->walking_minutes,
                ];

                if ($shopStation->is_nearest) {
                    $shopData['nearest_station'] = $stationInfo;
                } else {
                    $shopData['sub_stations'][] = $stationInfo;
                }
            }

            $shopData['plan_info'] = [
                'is_paid' => $shop->isPaidPlan(),
                'can_use_line' => $shop->canUseLine(),
                'can_use_gallery' => $shop->canUseGallery(),
                'can_use_blog' => $shop->canUseBlog(),
                'remaining_days' => $shop->getPlanRemainingDays(),
            ];

            $shopData['business_hours'] = $shop->businessHours->map(function ($hour) {
                return [
                    'day_of_week' => $hour->day_of_week,
                    'day_name' => $hour->getDayName(),
                    'is_closed' => $hour->is_closed,
                    'is_24h' => $hour->is_24h,
                    'open_time' => $hour->open_time,
                    'close_time' => $hour->close_time,
                    'display_text' => $hour->getDisplayText(),
                ];
            })->toArray();

            $todayHour = $shop->getTodayBusinessHour();
            $shopData['today_business_hour'] = $todayHour ? [
                'day_name' => $todayHour->getDayName(),
                'display_text' => $todayHour->getDisplayText(),
                'is_open_now' => $shop->isOpenNow(),
            ] : null;

            return $this->successResponse(
                $shopData,
                '雀荘詳細を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('自分の雀荘詳細取得エラー: ' . $e->getMessage(), [
                'shop_id' => $id
            ]);

            return $this->errorResponse('雀荘の詳細情報を取得できませんでした。', 500);
        }
    }

    /**
     * 新しい雀荘を作成
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'phone' => 'nullable|string|max:32',
            'website_url' => 'nullable|url|max:255',
            'open_hours_text' => 'nullable|string|max:1000',
            'business_hours' => 'nullable|array',
            'business_hours.*.day_of_week' => 'required|integer|min:0|max:7',
            'business_hours.*.is_closed' => 'nullable|boolean',
            'business_hours.*.is_24h' => 'nullable|boolean',
            'business_hours.*.open_time' => 'nullable|date_format:H:i',
            'business_hours.*.close_time' => 'nullable|date_format:H:i',
            
            'table_count' => 'nullable|integer|min:0|max:100',
            'score_table_count' => 'nullable|integer|min:0|max:100',
            'auto_table_count' => 'nullable|integer|min:0|max:100',
            'postal_code' => 'nullable|string|regex:/^\d{3}-?\d{4}$/|max:8',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'address_pref' => 'required|string|max:64',
            'address_city' => 'required|string|max:64',
            'address_town' => 'required|string|max:64',
            'address_street' => 'nullable|string|max:255',
            'address_building' => 'nullable|string|max:128',
            
            'nearest_station_id' => 'nullable|integer|exists:geo_stations,id',
            'sub_station_ids' => 'nullable|array|max:5',
            'sub_station_ids.*' => 'integer|exists:geo_stations,id',
            
            'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            
            'three_player_free' => 'nullable|in:0,1',
            'four_player_free' => 'nullable|in:0,1',
            'set' => 'nullable|in:0,1',
        ], [
            'name.required' => '雀荘名は必須です。',
            'name.max' => '雀荘名は255文字以内で入力してください。',
            'lat.required' => '緯度は必須です。',
            'lng.required' => '経度は必須です。',
            'lat.between' => '緯度は-90度から90度の間で入力してください。',
            'lng.between' => '経度は-180度から180度の間で入力してください。',
            'postal_code.regex' => '郵便番号は7桁の数字で入力してください。',
            'postal_code.max' => '郵便番号はハイフンを含めた8文字以内で入力してください。',
            'address_pref.required' => '都道府県は必須です。',
            'address_city.required' => '市区町村は必須です。',
            'address_town.required' => '町名は必須です。',
            'nearest_station_id.exists' => '指定された最寄り駅が存在しません。',
            'sub_station_ids.array' => 'サブ駅IDは配列形式で送信してください。',
            'sub_station_ids.max' => 'サブ駅は最大5つまで選択できます。',
            'sub_station_ids.*.exists' => '指定されたサブ駅の中に存在しない駅があります。',
            'cover_image.image' => '有効な画像ファイルをアップロードしてください。',
            'cover_image.mimes' => 'JPEG、PNG、WebP形式の画像のみアップロード可能です。',
            'cover_image.max' => '画像サイズは10MB以下にしてください。',
            'business_hours.array' => '営業時間は配列形式で送信してください。',
            'business_hours.*.day_of_week.required' => '曜日は必須です。',
            'business_hours.*.day_of_week.integer' => '曜日は整数で指定してください。',
            'business_hours.*.day_of_week.min' => '曜日は0〜7の範囲で指定してください。',
            'business_hours.*.day_of_week.max' => '曜日は0〜7の範囲で指定してください。',
            'business_hours.*.open_time.date_format' => '開店時刻はHH:MM形式で入力してください。',
            'business_hours.*.close_time.date_format' => '閉店時刻はHH:MM形式で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '雀荘作成データに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        DB::beginTransaction();
        try {
            Log::info('雀荘作成開始', [
                'user_id' => Auth::id(),
                'name' => $request->input('name'),
            ]);

            $prefecture = DB::table('geo_prefectures')
                ->where('name', $request->input('address_pref'))
                ->first();

            if (!$prefecture) {
                return $this->errorResponse('指定された都道府県が見つかりません。', 422);
            }

            $city = DB::table('geo_cities')
                ->where('prefecture_id', $prefecture->id)
                ->where('name', $request->input('address_city'))
                ->first();

            if (!$city) {
                return $this->errorResponse('指定された市区町村が見つかりません。', 422);
            }

            $shopData = [
                'user_id' => Auth::id(),
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'phone' => $request->input('phone'),
                'website_url' => $request->input('website_url'),
                'open_hours_text' => $request->input('open_hours_text'),
                'table_count' => $request->input('table_count', 0),
                'score_table_count' => $request->input('score_table_count', 0),
                'auto_table_count' => $request->input('auto_table_count', 0),
                'postal_code' => $request->input('postal_code'),
                'prefecture_id' => $prefecture->id,
                'city_id' => $city->id,
                'address_pref' => $request->input('address_pref'),
                'address_city' => $request->input('address_city'),
                'address_town' => $request->input('address_town'),
                'address_street' => $request->input('address_street'),
                'address_building' => $request->input('address_building'),
                'lat' => $request->input('lat'),
                'lng' => $request->input('lng'),
                'is_verified' => false,
            ];

            $shop = Shop::create($shopData);

            Log::info('雀荘データ作成完了', [
                'shop_id' => $shop->id,
                'prefecture_id' => $prefecture->id,
                'city_id' => $city->id,
            ]);

            if ($request->hasFile('cover_image')) {
                try {
                    $directory = $this->imageService->getDirectoryPath('shops', $shop->id);
                    $imagePaths = $this->imageService->uploadImage(
                        $request->file('cover_image'),
                        $directory,
                        'shop'
                    );

                    $shop->main_image_paths = $imagePaths;
                    $shop->save();

                    Log::info('カバー画像アップロード完了', [
                        'shop_id' => $shop->id,
                        'image_paths' => $imagePaths,
                    ]);
                } catch (\Exception $e) {
                    Log::error('カバー画像アップロードエラー', [
                        'shop_id' => $shop->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ロゴ画像の処理を追加
            if ($request->hasFile('logo_image')) {
                try {
                    $directory = $this->imageService->getDirectoryPath('shops', $shop->id);
                    $imagePaths = $this->imageService->uploadImage(
                        $request->file('logo_image'),
                        $directory,
                        'logo'
                    );

                    $shop->logo_image_paths = $imagePaths;
                    $shop->save();

                    Log::info('ロゴ画像アップロード完了', [
                        'shop_id' => $shop->id,
                        'image_paths' => $imagePaths,
                    ]);
                } catch (\Exception $e) {
                    Log::error('ロゴ画像アップロードエラー', [
                        'shop_id' => $shop->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->saveStationInfo($shop, $request);

            if ($request->filled('business_hours')) {
                $this->saveBusinessHours($shop, $request->input('business_hours'));
            }

            if ($request->input('three_player_free') === '1') {
                ShopFree::create([
                    'shop_id' => $shop->id,
                    'game_format' => ShopFree::GAME_FORMAT_THREE_PLAYER,
                ]);
                
                Log::info('3人フリー作成完了', [
                    'shop_id' => $shop->id,
                ]);
            }

            if ($request->input('four_player_free') === '1') {
                ShopFree::create([
                    'shop_id' => $shop->id,
                    'game_format' => ShopFree::GAME_FORMAT_FOUR_PLAYER,
                ]);
                
                Log::info('4人フリー作成完了', [
                    'shop_id' => $shop->id,
                ]);
            }

            if ($request->input('set') === '1') {
                ShopSet::create([
                    'shop_id' => $shop->id,
                ]);
                
                Log::info('セット雀荘作成完了', [
                    'shop_id' => $shop->id,
                ]);
            }

            $freePlan = ShopPlan::create([
                'shop_id' => $shop->id,
                'plan_type' => ShopPlan::PLAN_TYPE_FREE,
                'status' => ShopPlan::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => null,
                'auto_renew' => false,
            ]);

            Log::info('無料プラン作成完了', [
                'shop_id' => $shop->id,
                'plan_id' => $freePlan->id,
            ]);

            $shop->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);

            DB::commit();

            Log::info('雀荘作成成功', [
                'shop_id' => $shop->id,
                'user_id' => Auth::id(),
                'name' => $shop->name,
            ]);

            return $this->successResponse(
                $shop->load(['prefecture', 'city', 'owner', 'activePlan']),
                '雀荘を作成しました',
                201
            );

        } catch (\Exception $e) {
            DB::rollback();

            Log::error('雀荘作成エラー: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('雀荘の作成に失敗しました。', 500);
        }
    }

    /**
     * 雀荘情報を更新
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'address_pref' => 'nullable|string|max:64',
            'address_city' => 'nullable|string|max:64', 
            'address_town' => 'nullable|string|max:64',
            'address_street' => 'nullable|string|max:255',
            'address_building' => 'nullable|string|max:128',
            'phone' => 'nullable|string|max:32',
            'website_url' => 'nullable|url|max:255',
            'open_hours_text' => 'nullable|string|max:1000',
            'table_count' => 'nullable|integer|min:0',
            'score_table_count' => 'nullable|integer|min:0',
            'auto_table_count' => 'nullable|integer|min:0',
            'postal_code' => 'nullable|string|regex:/^\d{3}-?\d{4}$/|max:8',
            'description' => 'nullable|string',
            'prefecture_id' => 'nullable|exists:geo_prefectures,id',
            'city_id' => 'nullable|exists:geo_cities,id',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'line_official_id' => 'nullable|string|max:255',
            'line_add_url' => 'nullable|url|max:512',
            
            'business_hours' => 'nullable|array',
            'business_hours.*.day_of_week' => 'required|integer|min:0|max:7',
            'business_hours.*.is_closed' => 'nullable|boolean',
            'business_hours.*.is_24h' => 'nullable|boolean',
            'business_hours.*.open_time' => 'nullable|date_format:H:i',
            'business_hours.*.close_time' => 'nullable|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '雀荘更新情報に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($id);
            
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この雀荘を更新する権限がありません。', 403);
            }

            $verificationFields = [
                'name',
                'phone',
                'address_pref',
                'address_city',
                'address_town',
                'address_street',
                'address_building',
                'lat',
                'lng',
            ];

            $needsReview = false;
            foreach ($verificationFields as $field) {
                if ($request->filled($field) && $shop->$field != $request->input($field)) {
                    $needsReview = true;
                    break;
                }
            }

            if ($needsReview && $shop->is_verified) {
                $shop->is_verified = false;
                $shop->verified_at = null;
            }

            $shop->update($request->only([
                'name',
                'address_pref',
                'address_city', 
                'address_town',
                'address_street',
                'address_building',
                'phone',
                'website_url',
                'open_hours_text',
                'table_count',
                'score_table_count',
                'auto_table_count',
                'postal_code',
                'description',
                'prefecture_id',
                'city_id',
                'lat',
                'lng',
                'line_official_id',
                'line_add_url',
            ]));

            if ($request->filled('business_hours')) {
                DB::beginTransaction();
                try {
                    $shop->businessHours()->delete();
                    $this->saveBusinessHours($shop, $request->input('business_hours'));
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }
            }

            $message = $needsReview 
                ? '基本情報を更新しました。重要な情報が変更されたため、管理事務局の確認が完了するまで公開ページには表示されません。'
                : '雀荘情報を更新しました。';

            return $this->successResponse(
                $shop->load(['prefecture', 'city', 'owner', 'activePlan']),
                $message
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('雀荘更新エラー: ' . $e->getMessage(), [
                'shop_id' => $id,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('雀荘の更新に失敗しました。', 500);
        }
    }

    /**
     * 雀荘の駅情報を更新
     */
    public function updateStations(Request $request, $shopId)
    {
        try {
            Log::info('=== 駅設定リクエスト受信 ===', [
                'shop_id' => $shopId,
                'main_station_id' => $request->input('main_station_id'),
                'sub_station_ids' => $request->input('sub_station_ids'),
            ]);

            $shop = Shop::findOrFail($shopId);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この雀荘の駅情報を更新する権限がありません。', 403);
            }

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
                DB::table('shop_stations')->where('shop_id', $shopId)->delete();

                $shopController = new ShopController($this->imageService);
                $this->saveStationInfoFromShopController($shop, $request, $shopController);

                DB::commit();

                $stationsCount = ($request->filled('main_station_id') ? 1 : 0) + 
                                count($request->input('sub_station_ids', []));

                return $this->successResponse([
                    'stations_count' => $stationsCount,
                    'message' => '駅情報を更新しました'
                ], '駅情報を更新しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('雀荘駅情報更新エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('駅情報の更新に失敗しました。', 500);
        }
    }

    /**
     * 雀荘の駅情報を取得
     */
    public function getStations($shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この雀荘の駅情報を閲覧する権限がありません。', 403);
            }

            $stations = DB::table('shop_stations')
                ->join('geo_stations', 'shop_stations.station_id', '=', 'geo_stations.id')
                ->join('geo_station_lines', 'geo_stations.station_line_id', '=', 'geo_station_lines.id')
                ->leftJoin('geo_station_groups', 'shop_stations.station_group_id', '=', 'geo_station_groups.id')
                ->where('shop_stations.shop_id', $shopId)
                ->select(
                    'shop_stations.*',  // ← これで is_nearest も取得される
                    'geo_stations.name as station_name',
                    'geo_stations.name_kana',
                    'geo_station_lines.name as line_name',
                    'geo_station_groups.id as station_group_id',
                    'geo_station_groups.name as station_group_name'
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
                    'name' => $station->station_group_name ?? $station->station_name,
                    'name_kana' => $station->name_kana,
                    'line_name' => $station->line_name,
                    'distance_km' => $station->distance_km,
                    'walking_minutes' => $station->walking_minutes,
                    'is_nearest' => (bool)$station->is_nearest
                ];

                // station_group情報を追加
                if ($station->station_group_id) {
                    $stationData['station_group'] = [
                        'id' => $station->station_group_id,
                        'name' => $station->station_group_name,
                    ];
                }

                if ($station->is_nearest) {  // ← これで正しく判定される
                    $result['main_station'] = $stationData;
                } else {
                    $result['sub_stations'][] = $stationData;
                }
            }

            return $this->successResponse($result, '雀荘の駅情報を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('雀荘駅情報取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId
            ]);

            return $this->errorResponse('駅情報の取得に失敗しました。', 500);
        }
    }

    /**
     * LINE公式アカウント情報を更新
     */
    public function updateLineInfo(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'line_official_id' => 'nullable|string|max:255',
            'line_add_url' => 'nullable|url|max:512',
        ], [
            'line_official_id.string' => 'LINE公式アカウントIDは文字列で入力してください。',
            'line_official_id.max' => 'LINE公式アカウントIDは255文字以内で入力してください。',
            'line_add_url.url' => '友だち追加URLは有効なURL形式で入力してください。',
            'line_add_url.max' => '友だち追加URLは512文字以内で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'LINE情報に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($id);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この雀荘のLINE情報を更新する権限がありません。', 403);
            }

            if (!$shop->canUseLine()) {
                return $this->errorResponse(
                    'LINE機能は有料プラン限定です。プランをアップグレードしてください。',
                    403
                );
            }

            $shop->update([
                'line_official_id' => $request->input('line_official_id'),
                'line_add_url' => $request->input('line_add_url'),
            ]);

            Log::info('LINE情報更新成功', [
                'shop_id' => $id,
                'user_id' => Auth::id(),
                'line_official_id' => $request->input('line_official_id'),
            ]);

            return $this->successResponse([
                'line_official_id' => $shop->line_official_id,
                'line_add_url' => $shop->line_add_url,
                'has_line_account' => $shop->hasLineAccount(),
            ], 'LINE情報を更新しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('LINE情報更新エラー: ' . $e->getMessage(), [
                'shop_id' => $id,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('LINE情報の更新に失敗しました。', 500);
        }
    }

    /**
     * LINEのQRコード画像をアップロード
     */
    public function uploadLineQrCode(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'qr_code' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
        ], [
            'qr_code.required' => 'QRコード画像は必須です。',
            'qr_code.image' => '有効な画像ファイルをアップロードしてください。',
            'qr_code.mimes' => 'JPEG、PNG、WebP形式の画像のみアップロード可能です。',
            'qr_code.max' => '画像サイズは2MB以下にしてください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'QRコード画像のアップロードに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($id);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この雀荘のQRコードをアップロードする権限がありません。', 403);
            }

            if (!$shop->canUseLine()) {
                return $this->errorResponse(
                    'LINE機能は有料プラン限定です。プランをアップグレードしてください。',
                    403
                );
            }

            DB::beginTransaction();
            try {
                if ($shop->line_qr_code_path) {
                    Storage::disk('public')->delete($shop->line_qr_code_path);
                }

                $file = $request->file('qr_code');
                $filename = 'line_qr_' . $shop->id . '_' . time() . '.' . $file->extension();
                $path = $file->storeAs('line_qr_codes', $filename, 'public');

                $shop->line_qr_code_path = $path;
                $shop->save();

                DB::commit();

                Log::info('LINEのQRコードアップロード成功', [
                    'shop_id' => $id,
                    'user_id' => Auth::id(),
                    'path' => $path,
                ]);

                return $this->successResponse([
                    'line_qr_code_path' => $shop->line_qr_code_path,
                    'line_qr_code_url' => $shop->getLineQrCodeUrl(),
                ], 'QRコード画像をアップロードしました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('LINEのQRコードアップロードエラー: ' . $e->getMessage(), [
                'shop_id' => $id,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('QRコード画像のアップロードに失敗しました。', 500);
        }
    }

    /**
     * LINEのQRコード画像を削除
     */
    public function deleteLineQrCode($id)
    {
        try {
            $shop = Shop::findOrFail($id);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この雀荘のQRコードを削除する権限がありません。', 403);
            }

            if (!$shop->canUseLine()) {
                return $this->errorResponse(
                    'LINE機能は有料プラン限定です。',
                    403
                );
            }

            if (!$shop->line_qr_code_path) {
                return $this->errorResponse('削除するQRコード画像が存在しません。', 404);
            }

            DB::beginTransaction();
            try {
                Storage::disk('public')->delete($shop->line_qr_code_path);

                $shop->line_qr_code_path = null;
                $shop->save();

                DB::commit();

                Log::info('LINEのQRコード削除成功', [
                    'shop_id' => $id,
                    'user_id' => Auth::id(),
                ]);

                return $this->successResponse(null, 'QRコード画像を削除しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された雀荘が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('LINEのQRコード削除エラー: ' . $e->getMessage(), [
                'shop_id' => $id,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('QRコード画像の削除に失敗しました。', 500);
        }
    }

    /**
     * 店舗のメイン画像をアップロード
     */
    public function uploadMainImage(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ], [
            'image.required' => '画像ファイルは必須です。',
            'image.image' => '有効な画像ファイルをアップロードしてください。',
            'image.mimes' => 'JPEG、PNG、WebP形式の画像のみアップロード可能です。',
            'image.max' => '画像サイズは10MB以下にしてください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '画像アップロードに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗の画像をアップロードする権限がありません。', 403);
            }

            DB::beginTransaction();
            try {
                if ($shop->main_image_paths) {
                    $this->imageService->deleteImagePaths($shop->main_image_paths);
                }

                $directory = $this->imageService->getDirectoryPath('shops', $shopId);
                $imagePaths = $this->imageService->uploadImage(
                    $request->file('image'),
                    $directory,
                    'shop'
                );

                $shop->main_image_paths = $imagePaths;
                $shop->save();

                DB::commit();

                Log::info('店舗メイン画像アップロード成功', [
                    'shop_id' => $shopId,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse([
                    'main_image_paths' => $imagePaths,
                    'main_image_url' => $shop->getMainImageUrl('medium')
                ], 'メイン画像をアップロードしました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗メイン画像アップロードエラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * 店舗のギャラリー画像を追加
     */
    public function addGalleryImage(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
            'alt_text' => 'nullable|string|max:255',
        ], [
            'image.required' => '画像ファイルは必須です。',
            'image.image' => '有効な画像ファイルをアップロードしてください。',
            'image.mimes' => 'JPEG、PNG、WebP形式の画像のみアップロード可能です。',
            'image.max' => '画像サイズは10MB以下にしてください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '画像アップロードに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗の画像をアップロードする権限がありません。', 403);
            }

            if (!$shop->canUseGallery()) {
                return $this->errorResponse(
                    'ギャラリー画像機能は有料プラン限定です。プランをアップグレードしてください。',
                    403
                );
            }

            DB::beginTransaction();
            try {
                $directory = $this->imageService->getDirectoryPath('shops', $shopId);
                $imagePaths = $this->imageService->uploadImage(
                    $request->file('image'),
                    $directory,
                    'shop'
                );

                $maxOrder = ShopImage::where('shop_id', $shopId)->max('display_order') ?? 0;

                $shopImage = ShopImage::create([
                    'shop_id' => $shopId,
                    'image_paths' => $imagePaths,
                    'alt_text' => $request->input('alt_text'),
                    'display_order' => $maxOrder + 1,
                ]);

                DB::commit();

                Log::info('店舗ギャラリー画像追加成功', [
                    'shop_id' => $shopId,
                    'image_id' => $shopImage->id,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse([
                    'image' => [
                        'id' => $shopImage->id,
                        'image_paths' => $shopImage->image_paths,
                        'alt_text' => $shopImage->alt_text,
                        'display_order' => $shopImage->display_order,
                        'image_url' => $shopImage->getImageUrl('medium')
                    ]
                ], 'ギャラリー画像を追加しました', 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ギャラリー画像追加エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * 店舗のギャラリー画像を削除
     */
    public function deleteGalleryImage($shopId, $imageId)
    {
        try {
            $shop = Shop::findOrFail($shopId);
            $shopImage = ShopImage::where('shop_id', $shopId)
                ->where('id', $imageId)
                ->firstOrFail();

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗の画像を削除する権限がありません。', 403);
            }

            DB::beginTransaction();
            try {
                $this->imageService->deleteImagePaths($shopImage->image_paths);

                $shopImage->delete();

                $this->reorderGalleryImagesInternal($shopId);

                DB::commit();

                Log::info('店舗ギャラリー画像削除成功', [
                    'shop_id' => $shopId,
                    'image_id' => $imageId,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse(null, 'ギャラリー画像を削除しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された画像が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ギャラリー画像削除エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'image_id' => $imageId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('ギャラリー画像の削除に失敗しました。', 500);
        }
    }

    /**
     * 店舗のギャラリー画像の並び順を変更
     */
    public function reorderGalleryImages(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'image_orders' => 'required|array',
            'image_orders.*.id' => 'required|integer|exists:shop_images,id',
            'image_orders.*.display_order' => 'required|integer|min:0',
        ], [
            'image_orders.required' => '並び順データは必須です。',
            'image_orders.array' => '並び順データは配列形式で送信してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '並び順データに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗の画像を並び替える権限がありません。', 403);
            }

            DB::beginTransaction();
            try {
                foreach ($request->input('image_orders') as $order) {
                    ShopImage::where('shop_id', $shopId)
                        ->where('id', $order['id'])
                        ->update(['display_order' => $order['display_order']]);
                }

                DB::commit();

                Log::info('店舗ギャラリー画像並び順変更成功', [
                    'shop_id' => $shopId,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse(null, 'ギャラリー画像の並び順を変更しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ギャラリー画像並び順変更エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('並び順の変更に失敗しました。', 500);
        }
    }

    /**
     * ロゴ画像をアップロード
     */
    public function uploadLogoImage(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'logo_image' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ], [
            'logo_image.required' => 'ロゴ画像は必須です。',
            'logo_image.image' => '有効な画像ファイルをアップロードしてください。',
            'logo_image.mimes' => 'JPEG、PNG、WebP形式の画像のみアップロード可能です。',
            'logo_image.max' => '画像サイズは10MB以下にしてください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'ロゴ画像のアップロードに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗のロゴ画像をアップロードする権限がありません。', 403);
            }

            DB::beginTransaction();
            try {
                if ($shop->logo_image_paths) {
                    $this->imageService->deleteImagePaths($shop->logo_image_paths);
                }

                $directory = $this->imageService->getDirectoryPath('shops', $shopId);
                $imagePaths = $this->imageService->uploadImage(
                    $request->file('logo_image'),
                    $directory,
                    'shop'  // 'logo' ではなく 'shop' を使用
                );

                $shop->logo_image_paths = $imagePaths;
                $shop->save();

                DB::commit();

                Log::info('店舗ロゴ画像アップロード成功', [
                    'shop_id' => $shopId,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse([
                    'logo_image_paths' => $imagePaths,
                    'logo_image_url' => $shop->getLogoImageUrl('medium')
                ], 'ロゴ画像をアップロードしました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ロゴ画像アップロードエラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('ロゴ画像のアップロードに失敗しました。', 500);
        }
    }

    /**
     * ロゴ画像を削除
     */
    public function deleteLogoImage($shopId)
    {
        try {
            $shop = Shop::findOrFail($shopId);

            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗のロゴ画像を削除する権限がありません。', 403);
            }

            if (!$shop->logo_image_paths) {
                return $this->errorResponse('削除するロゴ画像が存在しません。', 404);
            }

            DB::beginTransaction();
            try {
                $this->imageService->deleteImagePaths($shop->logo_image_paths);

                $shop->logo_image_paths = null;
                $shop->save();

                DB::commit();

                Log::info('店舗ロゴ画像削除成功', [
                    'shop_id' => $shopId,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse(null, 'ロゴ画像を削除しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ロゴ画像削除エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id()
            ]);

            return $this->errorResponse('ロゴ画像の削除に失敗しました。', 500);
        }
    }

    // ========================================
    // プライベートメソッド
    // ========================================

    /**
     * ギャラリー画像のdisplay_orderを再調整（内部用）
     */
    private function reorderGalleryImagesInternal($shopId)
    {
        $images = ShopImage::where('shop_id', $shopId)
            ->orderBy('display_order')
            ->get();

        foreach ($images as $index => $image) {
            $image->display_order = $index + 1;
            $image->save();
        }
    }

    /**
     * 駅情報を保存（内部用）
     */
    private function saveStationInfo($shop, $request)
    {
        $mainStationId = $request->input('nearest_station_id');
        $subStationIds = $request->input('sub_station_ids', []);

        if (!$mainStationId && empty($subStationIds)) {
            return;
        }

        $stationsToInsert = [];

        if ($mainStationId) {
            $distance = $this->calculateDistanceFromShopController(
                $shop->lat,
                $shop->lng,
                $mainStationId
            );

            // station_idからstation_group_idを取得
            $stationData = DB::table('geo_stations')
                ->where('id', $mainStationId)
                ->first(['station_group_id']);

            $stationsToInsert[] = [
                'shop_id' => $shop->id,
                'station_id' => $mainStationId,
                'station_group_id' => $stationData->station_group_id ?? null,
                'is_nearest' => true,
                'distance_km' => $distance,
                'walking_minutes' => round($distance * 15),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach ($subStationIds as $stationId) {
            if ($stationId != $mainStationId) {
                $distance = $this->calculateDistanceFromShopController(
                    $shop->lat,
                    $shop->lng,
                    $stationId
                );

                // station_idからstation_group_idを取得
                $stationData = DB::table('geo_stations')
                    ->where('id', $stationId)
                    ->first(['station_group_id']);

                $stationsToInsert[] = [
                    'shop_id' => $shop->id,
                    'station_id' => $stationId,
                    'station_group_id' => $stationData->station_group_id ?? null,
                    'is_nearest' => false,
                    'distance_km' => $distance,
                    'walking_minutes' => round($distance * 15),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($stationsToInsert)) {
            DB::table('shop_stations')->insert($stationsToInsert);

            Log::info('駅情報保存完了', [
                'shop_id' => $shop->id,
                'stations_count' => count($stationsToInsert),
            ]);
        }
    }

    /**
     * ShopControllerの駅情報保存処理を呼び出す
     */
    private function saveStationInfoFromShopController($shop, $request, $shopController)
    {
        $mainStationId = $request->input('main_station_id');
        $subStationIds = $request->input('sub_station_ids', []);

        if (!$mainStationId && empty($subStationIds)) {
            return;
        }

        $stationsToInsert = [];

        if ($mainStationId) {
            $reflection = new \ReflectionClass($shopController);
            $method = $reflection->getMethod('calculateDistance');
            $method->setAccessible(true);
            
            $distance = $method->invoke(
                $shopController,
                $shop->lat,
                $shop->lng,
                $mainStationId
            );

            // station_idからstation_group_idを取得
            $stationData = DB::table('geo_stations')
                ->where('id', $mainStationId)
                ->first(['station_group_id']);

            $stationsToInsert[] = [
                'shop_id' => $shop->id,
                'station_id' => $mainStationId,
                'station_group_id' => $stationData->station_group_id ?? null,
                'is_nearest' => true,
                'distance_km' => $distance,
                'walking_minutes' => round($distance * 15),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach ($subStationIds as $stationId) {
            if ($stationId != $mainStationId) {
                $reflection = new \ReflectionClass($shopController);
                $method = $reflection->getMethod('calculateDistance');
                $method->setAccessible(true);
                
                $distance = $method->invoke(
                    $shopController,
                    $shop->lat,
                    $shop->lng,
                    $stationId
                );

                // station_idからstation_group_idを取得
                $stationData = DB::table('geo_stations')
                    ->where('id', $stationId)
                    ->first(['station_group_id']);

                $stationsToInsert[] = [
                    'shop_id' => $shop->id,
                    'station_id' => $stationId,
                    'station_group_id' => $stationData->station_group_id ?? null,
                    'is_nearest' => false,
                    'distance_km' => $distance,
                    'walking_minutes' => round($distance * 15),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($stationsToInsert)) {
            DB::table('shop_stations')->insert($stationsToInsert);

            Log::info('駅情報保存完了', [
                'shop_id' => $shop->id,
                'stations_count' => count($stationsToInsert),
            ]);
        }
    }

    /**
     * 距離計算（ShopControllerのメソッドを使用）
     */
    private function calculateDistanceFromShopController($shopLat, $shopLng, $stationId)
    {
        $station = DB::table('geo_stations')
            ->where('id', $stationId)
            ->first(['latitude', 'longitude']);

        if (!$station) {
            return 0;
        }

        $earthRadius = 6371;

        $latDiff = deg2rad($station->latitude - $shopLat);
        $lngDiff = deg2rad($station->longitude - $shopLng);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos(deg2rad($shopLat)) * cos(deg2rad($station->latitude)) *
             sin($lngDiff / 2) * sin($lngDiff / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return round($earthRadius * $c, 3);
    }

    /**
     * 営業時間を保存
     */
    private function saveBusinessHours($shop, array $businessHoursData)
    {
        foreach ($businessHoursData as $hourData) {
            $data = [
                'shop_id' => $shop->id,
                'day_of_week' => $hourData['day_of_week'],
                'is_closed' => $hourData['is_closed'] ?? false,
                'is_24h' => $hourData['is_24h'] ?? false,
                'open_time' => null,
                'close_time' => null,
            ];

            if (!$data['is_closed'] && !$data['is_24h']) {
                $data['open_time'] = $hourData['open_time'] ?? null;
                $data['close_time'] = $hourData['close_time'] ?? null;
            }

            ShopBusinessHour::create($data);
        }

        Log::info('営業時間保存完了', [
            'shop_id' => $shop->id,
            'hours_count' => count($businessHoursData),
        ]);
    }
}