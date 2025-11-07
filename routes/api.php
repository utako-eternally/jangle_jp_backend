<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\MyShopController;
use App\Http\Controllers\Api\ShopPlanController;
use App\Http\Controllers\Api\ShopFeatureController;
use App\Http\Controllers\Api\ShopRuleController;
use App\Http\Controllers\Api\ShopRuleTextController;
use App\Http\Controllers\Api\ShopMenuController;
use App\Http\Controllers\Api\ShopFreeController;
use App\Http\Controllers\Api\ShopSetController;
use App\Http\Controllers\Api\BlogPostController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\PrefectureController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\ShopServiceController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// 認証不要のルート
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/verify', [AuthController::class, 'verify']);
Route::post('/auth/complete', [AuthController::class, 'complete']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/resend-invitation', [AuthController::class, 'resendInvitation']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/verify-reset-token', [AuthController::class, 'verifyResetToken']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

// 都道府県関連API（公開）
Route::get('/prefectures', [PrefectureController::class, 'index']);
Route::get('/prefectures/{slug}', [PrefectureController::class, 'show']);
Route::post('/prefectures/{slug}/shops', [PrefectureController::class, 'getShops']);
Route::get('/prefectures/{slug}/stations', [PrefectureController::class, 'getStations']);
Route::get('/prefectures/{slug}/cities', [PrefectureController::class, 'getCities']);

// 市区町村関連API（公開）
Route::get('/cities/{prefecture_slug}/{city_slug}', [CityController::class, 'show']);
Route::post('/cities/{prefecture_slug}/{city_slug}/shops', [CityController::class, 'getShops']);
Route::get('/cities/{prefecture_slug}/{city_slug}/stations', [CityController::class, 'getStations']);

// 駅関連API（公開）
Route::get('/stations/{prefecture_slug}/{station_slug}', [StationController::class, 'show']);
Route::post('/stations/{prefecture_slug}/{station_slug}/shops', [StationController::class, 'getShops']);
Route::get('/stations/{prefecture_slug}/{station_slug}/nearby', [StationController::class, 'getNearby']);

// 検索API（公開）
Route::get('/search/suggest', [SearchController::class, 'suggest']);

// 雀荘検索API（公開）
Route::get('/shops', [ShopController::class, 'index']);
Route::get('/shops/{shop}', [ShopController::class, 'show']);

// 雀荘画像取得API（公開）
Route::get('/shops/{shop}/gallery-images', [ShopController::class, 'getGalleryImages']);

// 雀荘LINE情報取得API（公開）
Route::get('/shops/{shop}/line-info', [ShopController::class, 'getLineInfo']);

// 駅検索API（公開）
Route::post('/stations/nearby', [ShopController::class, 'getNearbyStations']);
Route::post('/stations/nearby-by-address', [ShopController::class, 'getNearbyStationsByAddress']);
Route::post('/stations/search', [ShopController::class, 'searchStationsByName']);

// 雀荘特徴関連API（公開）
Route::get('/shops/{shop}/features', [ShopFeatureController::class, 'index']);
Route::get('/features/available', [ShopFeatureController::class, 'getAvailableFeatures']);

// 雀荘ルール関連API（公開）
Route::get('/shops/{shop}/rules', [ShopRuleController::class, 'index']);
Route::get('/rules/available', [ShopRuleController::class, 'getAvailableRules']);

// 雀荘ルールテキスト関連API（公開）
Route::get('/shops/{shop}/rule-texts', [ShopRuleTextController::class, 'index']);
Route::get('/shops/{shop}/rule-texts/{category}', [ShopRuleTextController::class, 'show']);
Route::get('/rule-texts/categories', [ShopRuleTextController::class, 'getAvailableCategories']);

// 雀荘メニュー関連API（公開）
Route::get('/shops/{shop}/menus', [ShopMenuController::class, 'index']);
Route::get('/shops/{shop}/menus/{menu}', [ShopMenuController::class, 'show']);
Route::get('/menus/categories', [ShopMenuController::class, 'getCategories']);

// 雀荘フリー設定関連API（公開）
Route::get('/shops/{shop}/frees', [ShopFreeController::class, 'index']);
Route::get('/shops/{shop}/frees/{free}', [ShopFreeController::class, 'show']);
Route::get('/frees/game-formats', [ShopFreeController::class, 'getGameFormats']);

// 雀荘セット設定関連API（公開）
Route::get('/shops/{shop}/set', [ShopSetController::class, 'show']);

// ブログ投稿取得API（公開）
Route::get('/blog-posts', [BlogPostController::class, 'index']);
Route::get('/blog-posts/{post}', [BlogPostController::class, 'show']);
Route::get('/blog-posts/{post}/content-images', [BlogPostController::class, 'getContentImages']);

// 住所関連API（公開）
Route::post('/address/postal-code', [AddressController::class, 'getAddressByPostalCode']);
Route::post('/address/normalize', [AddressController::class, 'normalizeAddress']);
Route::post('/address/process', [AddressController::class, 'processFullAddress']);
Route::post('/address/geocode', [AddressController::class, 'geocode']);
Route::get('/address/health', [AddressController::class, 'healthCheck']);

// 雀荘サービス関連API（公開）
Route::get('/shops/{shop}/services', [ShopServiceController::class, 'index']);
Route::get('/services/available', [ShopServiceController::class, 'getAvailableServices']);

// 認証済みユーザー用のルート
Route::middleware('auth:sanctum')->group(function () {
    // ユーザー認証関連
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/me/update', [AuthController::class, 'updateMe']);
    Route::post('/auth/password/change', [AuthController::class, 'changePassword']);
    Route::post('/auth/me/delete', [AuthController::class, 'deleteAccount']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // アバター画像管理
    Route::post('/auth/me/avatar', [AuthController::class, 'uploadAvatar']);
    Route::post('/auth/me/avatar/delete', [AuthController::class, 'deleteAvatar']);

    // 自分の雀荘関連（MyShopController）
    Route::get('/my-shops', [MyShopController::class, 'index']);
    Route::get('/my-shops/{shop}', [MyShopController::class, 'show']);
    Route::post('/my-shops', [MyShopController::class, 'store']);
    Route::post('/my-shops/{shop}/update', [MyShopController::class, 'update']);
    
    // 自分の雀荘の駅情報管理
    Route::get('/my-shops/{shop}/stations', [MyShopController::class, 'getStations']);
    Route::post('/my-shops/{shop}/stations/update', [MyShopController::class, 'updateStations']);

    // 自分の雀荘のLINE情報管理
    Route::post('/my-shops/{shop}/line-info/update', [MyShopController::class, 'updateLineInfo']);
    Route::post('/my-shops/{shop}/line-qr-code', [MyShopController::class, 'uploadLineQrCode']);
    Route::post('/my-shops/{shop}/line-qr-code/delete', [MyShopController::class, 'deleteLineQrCode']);

    // 自分の雀荘の画像管理
    Route::post('/my-shops/{shop}/main-image', [MyShopController::class, 'uploadMainImage']);
    Route::post('/my-shops/{shop}/logo-image', [MyShopController::class, 'uploadLogoImage']);
    Route::post('/my-shops/{shop}/logo-image/delete', [MyShopController::class, 'deleteLogoImage']);
    Route::post('/my-shops/{shop}/gallery-images', [MyShopController::class, 'addGalleryImage']);
    Route::post('/my-shops/{shop}/gallery-images/{imageId}/delete', [MyShopController::class, 'deleteGalleryImage']);
    Route::post('/my-shops/{shop}/gallery-images/reorder', [MyShopController::class, 'reorderGalleryImages']);

    // 雀荘プラン管理
    Route::get('/shops/{shop}/plan', [ShopPlanController::class, 'getCurrentPlan']);
    Route::get('/shops/{shop}/plan/history', [ShopPlanController::class, 'getPlanHistory']);
    Route::get('/shops/{shop}/plan/payments', [ShopPlanController::class, 'getPaymentHistory']);
    Route::post('/shops/{shop}/plan/start-paid', [ShopPlanController::class, 'startPaidPlan']);
    Route::post('/shops/{shop}/plan/cancel', [ShopPlanController::class, 'cancelPlan']);

    // 雀荘特徴管理
    Route::post('/shops/{shop}/features/update', [ShopFeatureController::class, 'update']);

    // 雀荘ルール管理
    Route::post('/shops/{shop}/rules/update', [ShopRuleController::class, 'update']);

    // 雀荘ルールテキスト管理
    Route::post('/shops/{shop}/rule-texts/{category}', [ShopRuleTextController::class, 'update']);

    // 雀荘メニュー管理
    Route::post('/shops/{shop}/menus', [ShopMenuController::class, 'store']);
    Route::post('/shops/{shop}/menus/{menu}/update', [ShopMenuController::class, 'update']);
    Route::post('/shops/{shop}/menus/{menu}/delete', [ShopMenuController::class, 'destroy']);

    // 雀荘フリー設定管理
    Route::post('/shops/{shop}/frees', [ShopFreeController::class, 'store']);
    Route::post('/shops/{shop}/frees/{free}/update', [ShopFreeController::class, 'update']);
    Route::post('/shops/{shop}/frees/{free}/delete', [ShopFreeController::class, 'destroy']);

    // 雀荘セット設定管理
    Route::post('/shops/{shop}/set', [ShopSetController::class, 'store']);
    Route::post('/shops/{shop}/set/update', [ShopSetController::class, 'update']);
    Route::post('/shops/{shop}/set/delete', [ShopSetController::class, 'destroy']);

    // ブログ投稿管理
    Route::get('/my-blog-posts', [BlogPostController::class, 'myPosts']);
    Route::get('/my-blog-posts/{post}', [BlogPostController::class, 'myPost']);
    Route::post('/blog-posts', [BlogPostController::class, 'store']);
    Route::post('/blog-posts/{post}/update', [BlogPostController::class, 'update']);
    Route::post('/blog-posts/{post}/delete', [BlogPostController::class, 'destroy']);
    
    // ブログ画像管理
    Route::post('/blog-posts/{post}/thumbnail', [BlogPostController::class, 'uploadThumbnail']);
    Route::post('/blog-posts/{post}/content-images', [BlogPostController::class, 'addContentImage']);
    Route::post('/blog-posts/{post}/content-images/{imageId}/delete', [BlogPostController::class, 'deleteContentImage']);
    Route::post('/blog-posts/{post}/content-images/reorder', [BlogPostController::class, 'reorderContentImages']);

    // 管理者用（開発段階では権限チェックなし）
    Route::get('/admin/shops/unverified', [ShopController::class, 'unverifiedShops']);
    Route::post('/admin/shops/{shop}/verify', [ShopController::class, 'verify']);
    Route::post('/admin/plans/expire-expired', [ShopPlanController::class, 'expireExpiredPlans']);

    // 雀荘サービス管理
    Route::post('/shops/{shop}/services/update', [ShopServiceController::class, 'update']);
});