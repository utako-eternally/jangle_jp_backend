<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AddressService
{
    private string $apiBaseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiBaseUrl = config('services.node_address_api.url');
        $this->timeout = config('services.node_address_api.timeout');
    }

    /**
     * 郵便番号から住所を取得
     *
     * @param string $postalCode 7桁の郵便番号（ハイフンあり・なし両方可）
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function getAddressByPostalCode(string $postalCode): array
    {
        try {
            // 郵便番号をクリーンアップ
            $cleanCode = preg_replace('/[^\d]/', '', $postalCode);

            if (strlen($cleanCode) !== 7) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => '郵便番号は7桁の数字である必要があります'
                ];
            }

            $url = "{$this->apiBaseUrl}/api/postal-code/{$cleanCode}";

            Log::info('郵便番号API呼び出し', [
                'postal_code' => $cleanCode,
                'url' => $url
            ]);

            $response = Http::timeout($this->timeout)->get($url);

            if (!$response->successful()) {
                Log::error('郵便番号API呼び出し失敗', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => '郵便番号の取得に失敗しました'
                ];
            }

            $data = $response->json();

            if (!($data['success'] ?? false)) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => $data['error'] ?? '郵便番号の取得に失敗しました'
                ];
            }

            return [
                'success' => true,
                'data' => $data['data'] ?? [],
                'error' => null
            ];

        } catch (\Exception $e) {
            Log::error('郵便番号取得エラー', [
                'postal_code' => $postalCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => '郵便番号の取得中にエラーが発生しました'
            ];
        }
    }

    /**
     * 住所を正規化
     *
     * @param string $address 正規化する住所
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function normalizeAddress(string $address): array
    {
        try {
            if (empty(trim($address))) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => '住所が入力されていません'
                ];
            }

            $url = "{$this->apiBaseUrl}/api/normalize";

            Log::info('住所正規化API呼び出し', [
                'address' => mb_substr($address, 0, 50) . '...',
                'url' => $url
            ]);

            $response = Http::timeout($this->timeout)->post($url, [
                'address' => $address
            ]);

            if (!$response->successful()) {
                Log::error('住所正規化API呼び出し失敗', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => '住所の正規化に失敗しました'
                ];
            }

            $data = $response->json();

            // Node.jsの生レスポンスをログ出力
            Log::info('Node.js正規化レスポンス', [
                'raw_data' => $data['data'] ?? null
            ]);

            if (!($data['success'] ?? false)) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => $data['error'] ?? '住所の正規化に失敗しました'
                ];
            }

            // レスポンスデータをサニタイズ
            $normalizedData = $data['data'] ?? null;
            if ($normalizedData) {
                $normalizedData = $this->sanitizeAddressData($normalizedData);
            }

            return [
                'success' => true,
                'data' => $normalizedData,
                'error' => null
            ];

        } catch (\Exception $e) {
            Log::error('住所正規化エラー', [
                'address' => mb_substr($address, 0, 50),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => '住所の正規化中にエラーが発生しました'
            ];
        }
    }

    /**
     * 郵便番号 + 詳細住所の複合処理
     *
     * @param string $postalCode 郵便番号
     * @param string|null $addressDetail 番地など
     * @param string|null $building 建物名
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function processFullAddress(string $postalCode, ?string $addressDetail = null, ?string $building = null): array
    {
        try {
            $cleanCode = preg_replace('/[^\d]/', '', $postalCode);

            if (strlen($cleanCode) !== 7) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => '郵便番号は7桁の数字である必要があります'
                ];
            }

            $url = "{$this->apiBaseUrl}/api/process-address";

            Log::info('複合住所処理API呼び出し', [
                'postal_code' => $cleanCode,
                'has_detail' => !empty($addressDetail),
                'has_building' => !empty($building),
                'url' => $url
            ]);

            $response = Http::timeout($this->timeout)->post($url, [
                'postalCode' => $cleanCode,
                'addressDetail' => $addressDetail,
                'building' => $building
            ]);

            if (!$response->successful()) {
                Log::error('複合住所処理API呼び出し失敗', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => '住所処理に失敗しました'
                ];
            }

            $data = $response->json();

            if (!($data['success'] ?? false)) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => $data['error'] ?? '住所処理に失敗しました'
                ];
            }

            // レスポンスデータをサニタイズ
            $processedData = $data['data'] ?? null;
            if ($processedData && isset($processedData['normalized'])) {
                $processedData['normalized'] = $this->sanitizeAddressData($processedData['normalized']);
            }

            return [
                'success' => true,
                'data' => $processedData,
                'error' => null
            ];

        } catch (\Exception $e) {
            Log::error('複合住所処理エラー', [
                'postal_code' => $postalCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => '住所処理中にエラーが発生しました'
            ];
        }
    }

    /**
     * Node.js APIのヘルスチェック
     *
     * @return bool
     */
    public function checkHealth(): bool
    {
        try {
            $url = "{$this->apiBaseUrl}/health";
            $response = Http::timeout(5)->get($url);

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('Node.js APIヘルスチェック失敗', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * 住所データをサニタイズ（undefined文字列の除去など）
     *
     * @param array $data
     * @return array
     */
    private function sanitizeAddressData(array $data): array
    {
        try {
            Log::info('sanitizeAddressData開始', ['input_data' => $data]);
            
            // 🔧 個別フィールドから fullAddress を再構築
            $addressParts = [];
            
            // 都道府県
            if (!empty($data['pref']) && $data['pref'] !== 'undefined') {
                $addressParts[] = $data['pref'];
            }
            
            // 市区町村
            if (!empty($data['city']) && $data['city'] !== 'undefined') {
                $addressParts[] = $data['city'];
            }
            
            // 町名
            if (!empty($data['town']) && $data['town'] !== 'undefined') {
                $addressParts[] = $data['town'];
            }
            
            // 🆕 番地（addr）を追加
            if (!empty($data['addr']) && $data['addr'] !== 'undefined') {
                $addressParts[] = $data['addr'];
            }
            
            // 🆕 その他（other）を追加
            if (!empty($data['other']) && $data['other'] !== 'undefined') {
                $addressParts[] = $data['other'];
            }
            
            // fullAddress を再構築
            $data['fullAddress'] = implode('', $addressParts);
            
            Log::info('sanitizeAddressData完了', [
                'address_parts' => $addressParts,
                'result_fullAddress' => $data['fullAddress']
            ]);
            
            return $data;
        } catch (\Exception $e) {
            Log::error('sanitizeAddressDataエラー', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // エラーが起きても元のデータを返す
            return $data;
        }
    }

    /**
     * Google Maps Geocoding APIで座標を取得
     *
     * @param string $address
     * @return array
     */
    public function geocode(string $address): array
    {
        try {
            $url = "{$this->apiBaseUrl}/api/geo/geocode";

            Log::info('Geocoding API呼び出し', [
                'address' => mb_substr($address, 0, 50) . '...',
                'url' => $url
            ]);

            $response = Http::timeout($this->timeout)->post($url, [
                'address' => $address,
                'region' => 'JP'
            ]);

            if (!$response->successful()) {
                Log::error('Geocoding API呼び出し失敗', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => '位置情報の取得に失敗しました'
                ];
            }

            $data = $response->json();

            if (!($data['success'] ?? false)) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => $data['error'] ?? '位置情報の取得に失敗しました'
                ];
            }

            return [
                'success' => true,
                'data' => $data['data'] ?? null,
                'error' => null
            ];

        } catch (\Exception $e) {
            Log::error('Geocodingエラー', [
                'address' => mb_substr($address, 0, 50),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => '位置情報の取得中にエラーが発生しました'
            ];
        }
    }

}