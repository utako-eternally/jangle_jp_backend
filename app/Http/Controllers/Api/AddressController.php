<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AddressService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class AddressController extends Controller
{
    use ApiResponse;

    protected AddressService $addressService;

    public function __construct(AddressService $addressService)
    {
        $this->addressService = $addressService;
    }

    /**
     * 郵便番号から住所を取得
     */
    public function getAddressByPostalCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'postal_code' => 'required|string|min:7|max:8',
        ], [
            'postal_code.required' => '郵便番号は必須です。',
            'postal_code.min' => '郵便番号は7桁で入力してください。',
            'postal_code.max' => '郵便番号は7桁で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                $validator->errors()->first(),
                422
            );
        }

        try {
            $postalCode = $request->input('postal_code');
            $result = $this->addressService->getAddressByPostalCode($postalCode);

            if (!$result['success']) {
                return $this->errorResponse($result['error'], 404);
            }

            return $this->successResponse(
                $result['data'],
                '住所情報を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('郵便番号取得エラー', [
                'postal_code' => $request->input('postal_code'),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('郵便番号の取得に失敗しました。', 500);
        }
    }

    /**
     * 住所を正規化
     */
    public function normalizeAddress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string|max:500',
        ], [
            'address.required' => '住所は必須です。',
            'address.max' => '住所は500文字以内で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                $validator->errors()->first(),
                422
            );
        }

        try {
            $address = $request->input('address');
            $result = $this->addressService->normalizeAddress($address);

            if (!$result['success']) {
                return $this->errorResponse($result['error'], 422);
            }

            return $this->successResponse(
                $result['data'],
                '住所を正規化しました'
            );

        } catch (\Exception $e) {
            Log::error('住所正規化エラー', [
                'address' => mb_substr($request->input('address'), 0, 50),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('住所の正規化に失敗しました。', 500);
        }
    }

    /**
     * 郵便番号 + 詳細住所の複合処理
     */
    public function processFullAddress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'postal_code' => 'required|string|size:7',
            'address_detail' => 'nullable|string|max:255',
            'building' => 'nullable|string|max:255',
        ], [
            'postal_code.required' => '郵便番号は必須です。',
            'postal_code.size' => '郵便番号は7桁で入力してください。',
            'address_detail.max' => '詳細住所は255文字以内で入力してください。',
            'building.max' => '建物名は255文字以内で入力してください。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                $validator->errors()->first(),
                422
            );
        }

        try {
            $postalCode = $request->input('postal_code');
            $addressDetail = $request->input('address_detail');
            $building = $request->input('building');

            $result = $this->addressService->processFullAddress(
                $postalCode,
                $addressDetail,
                $building
            );

            if (!$result['success']) {
                return $this->errorResponse($result['error'], 422);
            }

            return $this->successResponse(
                $result['data'],
                '住所情報を処理しました'
            );

        } catch (\Exception $e) {
            Log::error('複合住所処理エラー', [
                'postal_code' => $request->input('postal_code'),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('住所処理に失敗しました。', 500);
        }
    }

    /**
     * Node.js APIのヘルスチェック
     */
    public function healthCheck()
    {
        try {
            $isHealthy = $this->addressService->checkHealth();

            if ($isHealthy) {
                return $this->successResponse(
                    ['status' => 'healthy'],
                    'Node.js APIは正常に動作しています'
                );
            }

            return $this->errorResponse(
                'Node.js APIに接続できません',
                503
            );

        } catch (\Exception $e) {
            Log::error('ヘルスチェックエラー', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('ヘルスチェックに失敗しました。', 500);
        }
    }

    /**
     * Google Maps Geocoding APIで座標を取得
     */
    public function geocode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string|max:500',
        ], [
            'address.required' => '住所は必須です。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                $validator->errors()->first(),
                422
            );
        }

        try {
            $address = $request->input('address');
            $result = $this->addressService->geocode($address);

            if (!$result['success']) {
                return $this->errorResponse($result['error'], 422);
            }

            return $this->successResponse(
                $result['data'],
                '位置情報を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('Geocodingエラー', [
                'address' => mb_substr($request->input('address'), 0, 50),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('位置情報の取得に失敗しました。', 500);
        }
    }

}