<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopRule;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopRuleController extends Controller
{
    use ApiResponse;

    /**
     * 店舗のルール一覧を取得
     */
    public function index(Request $request, $shopId)
    {
        try {
            $shop = Shop::with('rules')->findOrFail($shopId);
            
            // 店舗のルール一覧を取得
            $rules = ShopRule::where('shop_id', $shop->id)
                ->get()
                ->map(function ($rule) {
                    return [
                        'id' => $rule->id,
                        'rule' => $rule->rule,
                    ];
                });

            return $this->successResponse([
                'shop_info' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                ],
                'all_rules' => $rules,
            ], '店舗のルール一覧を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ルール一覧取得エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse('店舗ルール一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 店舗のルールを更新（一括設定）
     */
    public function update(Request $request, $shopId)
    {
        $validator = Validator::make($request->all(), [
            'rules' => 'present|array',
            'rules.*' => 'string',
        ], [
            'rules.present' => 'ルールデータは必須です。',
            'rules.array' => 'ルールデータは配列である必要があります。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'ルールデータに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($shopId);
            
            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗のルールを設定する権限がありません。', 403);
            }
            
            $createdRules = DB::transaction(function () use ($shopId, $request) {
                // 既存ルールをすべて削除
                ShopRule::where('shop_id', $shopId)->delete();
                
                // 新しいルールを一括挿入
                $createdRules = [];
                foreach ($request->rules as $rule) {
                    $shopRule = ShopRule::create([
                        'shop_id' => $shopId,
                        'rule' => $rule,
                    ]);
                    
                    $createdRules[] = [
                        'id' => $shopRule->id,
                        'rule' => $shopRule->rule,
                    ];
                }
                
                return $createdRules;
            });
            
            Log::info('店舗ルールが更新されました', [
                'shop_id' => $shopId,
                'rules_count' => count($createdRules),
                'rules' => $request->rules,
                'operator_id' => Auth::id()
            ]);
            
            $message = count($createdRules) > 0 
                ? count($createdRules) . '件のルールを設定しました。'
                : '全てのルールをクリアしました。';
            
            return $this->successResponse(
                $createdRules,
                $message
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された店舗が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('店舗ルール更新エラー: ' . $e->getMessage(), [
                'shop_id' => $shopId,
                'user_id' => Auth::id(),
                'rules' => $request->rules ?? [],
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('店舗ルールの更新に失敗しました。', 500);
        }
    }

    /**
     * 利用可能な全ルールの定義を取得
     */
    public function getAvailableRules(Request $request)
    {
        try {
            return $this->successResponse([
                'groups' => ShopRule::formatGroupsForApi(),
            ], '利用可能なルール一覧を取得しました');

        } catch (\Exception $e) {
            Log::error('利用可能なルール一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('利用可能なルール一覧の取得に失敗しました。', 500);
        }
    }
}