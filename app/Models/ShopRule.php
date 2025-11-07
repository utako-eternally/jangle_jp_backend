<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ShopRule extends Model
{
    protected $table = 'shop_rules';

    protected $fillable = [
        'shop_id',
        'rule',
    ];

    // ===== JSONに自動的に追加するアクセサ =====
    protected $appends = [
        'display_name',
        'category',
        'category_name',
    ];

    // ========================================
    // ルール定数
    // ========================================
    
    // ゲーム形式
    const RULE_TONPU = 'TONPU';
    const RULE_TONNAN = 'TONNAN';
    
    // クイタン
    const RULE_KUITAN_ALLOWED = 'KUITAN_ALLOWED';
    const RULE_KUITAN_PROHIBITED = 'KUITAN_PROHIBITED';
    
    // 後付け
    const RULE_ATODZUKE_ALLOWED = 'ATODZUKE_ALLOWED';
    const RULE_SAKIZUKE_ONLY = 'SAKIZUKE_ONLY';
    
    // 連荘
    const RULE_TENPAI_RENCHAN = 'TENPAI_RENCHAN';
    const RULE_AGARI_RENCHAN = 'AGARI_RENCHAN';
    
    // 形聴
    const RULE_KATA_TEN_ALLOWED = 'KATA_TEN_ALLOWED';
    
    // 特殊牌
    const RULE_RED_TILES = 'RED_TILES';
    const RULE_POTCHI_TILES = 'POTCHI_TILES';
    const RULE_SPECIAL_TILES = 'SPECIAL_TILES';
    
    // ゲームタイプ
    const RULE_SPEED_BATTLE = 'SPEED_BATTLE';
    const RULE_RANKING_MATCH = 'RANKING_MATCH';
    
    // 計算ルール
    const RULE_NO_HAKOSHITA = 'NO_HAKOSHITA';
    const RULE_NO_FU_CALCULATION = 'NO_FU_CALCULATION';

    // ========================================
    // グループ定義
    // ========================================

    /**
     * ルールグループ定義を取得
     * toggle: グループ内OR検索
     * checkbox: 個別AND検索
     */
    public static function getRuleGroups(): array
    {
        return [
            // toggle グループ（グループ内 OR）
            'toggle' => [
                'game_format' => [
                    'label' => 'ゲーム形式',
                    'rules' => [self::RULE_TONPU, self::RULE_TONNAN],
                    'labels' => [
                        self::RULE_TONPU => '東風戦',
                        self::RULE_TONNAN => '東南戦',
                    ],
                ],
                'kuitan' => [
                    'label' => 'クイタン',
                    'rules' => [self::RULE_KUITAN_ALLOWED, self::RULE_KUITAN_PROHIBITED],
                    'labels' => [
                        self::RULE_KUITAN_ALLOWED => 'あり',
                        self::RULE_KUITAN_PROHIBITED => 'なし',
                    ],
                ],
                'atozuke' => [
                    'label' => '後付け',
                    'rules' => [self::RULE_ATODZUKE_ALLOWED, self::RULE_SAKIZUKE_ONLY],
                    'labels' => [
                        self::RULE_ATODZUKE_ALLOWED => '後付けあり',
                        self::RULE_SAKIZUKE_ONLY => '先付のみ',
                    ],
                ],
                'renchan' => [
                    'label' => '連荘',
                    'rules' => [self::RULE_TENPAI_RENCHAN, self::RULE_AGARI_RENCHAN],
                    'labels' => [
                        self::RULE_TENPAI_RENCHAN => '聴牌連荘',
                        self::RULE_AGARI_RENCHAN => '上がり連荘',
                    ],
                ],
                'keichou' => [
                    'label' => '形聴',
                    'rules' => [self::RULE_KATA_TEN_ALLOWED],
                    'labels' => [
                        self::RULE_KATA_TEN_ALLOWED => 'あり',
                    ],
                ],
            ],
            
            // checkbox グループ（個別 AND）
            'checkbox' => [
                'special_tiles' => [
                    'label' => '特殊牌',
                    'rules' => [
                        self::RULE_RED_TILES,
                        self::RULE_POTCHI_TILES,
                        self::RULE_SPECIAL_TILES,
                    ],
                    'labels' => [
                        self::RULE_RED_TILES => '赤牌',
                        self::RULE_POTCHI_TILES => 'ぽっち牌',
                        self::RULE_SPECIAL_TILES => '特殊牌',
                    ],
                ],
                'game_types' => [
                    'label' => 'ゲームタイプ',
                    'rules' => [
                        self::RULE_SPEED_BATTLE,
                        self::RULE_RANKING_MATCH,
                    ],
                    'labels' => [
                        self::RULE_SPEED_BATTLE => 'スピード戦',
                        self::RULE_RANKING_MATCH => 'ランキング戦',
                    ],
                ],
                'calculation' => [
                    'label' => '計算ルール',
                    'rules' => [
                        self::RULE_NO_HAKOSHITA,
                        self::RULE_NO_FU_CALCULATION,
                    ],
                    'labels' => [
                        self::RULE_NO_HAKOSHITA => '箱下なし',
                        self::RULE_NO_FU_CALCULATION => '符計算なし',
                    ],
                ],
            ],
        ];
    }

    /**
     * ===== 追加: 表示名アクセサ =====
     */
    public function getDisplayNameAttribute(): string
    {
        $groups = self::getRuleGroups();
        
        // toggle グループから検索
        foreach ($groups['toggle'] as $group) {
            if (isset($group['labels'][$this->rule])) {
                return $group['labels'][$this->rule];
            }
        }
        
        // checkbox グループから検索
        foreach ($groups['checkbox'] as $group) {
            if (isset($group['labels'][$this->rule])) {
                return $group['labels'][$this->rule];
            }
        }
        
        return $this->rule;
    }

    /**
     * ===== 追加: カテゴリアクセサ =====
     */
    public function getCategoryAttribute(): ?string
    {
        $groups = self::getRuleGroups();
        
        foreach ($groups['toggle'] as $groupKey => $group) {
            if (in_array($this->rule, $group['rules'])) {
                return 'toggle_' . $groupKey;
            }
        }
        
        foreach ($groups['checkbox'] as $groupKey => $group) {
            if (in_array($this->rule, $group['rules'])) {
                return 'checkbox_' . $groupKey;
            }
        }
        
        return null;
    }

    /**
     * ===== 追加: カテゴリ名アクセサ =====
     */
    public function getCategoryNameAttribute(): ?string
    {
        $groups = self::getRuleGroups();
        
        foreach ($groups['toggle'] as $groupKey => $group) {
            if (in_array($this->rule, $group['rules'])) {
                return $group['label'];
            }
        }
        
        foreach ($groups['checkbox'] as $groupKey => $group) {
            if (in_array($this->rule, $group['rules'])) {
                return $group['label'];
            }
        }
        
        return null;
    }

    /**
     * API レスポンス用にフォーマット
     */
    public static function formatGroupsForApi(): array
    {
        $definition = self::getRuleGroups();
        $formatted = [];
        
        foreach (['toggle', 'checkbox'] as $type) {
            foreach ($definition[$type] as $groupId => $group) {
                $options = [];
                foreach ($group['rules'] as $rule) {
                    $options[] = [
                        'value' => $rule,
                        'label' => $group['labels'][$rule] ?? $rule,
                    ];
                }
                
                $formatted[] = [
                    'id' => $groupId,
                    'label' => $group['label'],
                    'type' => $type === 'toggle' ? 'toggle' : 'checkbox',
                    'options' => $options,
                ];
            }
        }
        
        return $formatted;
    }

    /**
     * クエリにルールフィルターを適用
     * toggle: グループ間AND、グループ内OR
     * checkbox: 個別AND
     */
    public static function applyRuleFilters($query, array $rules): void
    {
        $definition = self::getRuleGroups();
        
        // toggle グループを処理（グループ間 AND、グループ内 OR）
        $toggleRulesByGroup = [];
        foreach ($rules as $rule) {
            foreach ($definition['toggle'] as $groupKey => $group) {
                if (in_array($rule, $group['rules'])) {
                    if (!isset($toggleRulesByGroup[$groupKey])) {
                        $toggleRulesByGroup[$groupKey] = [];
                    }
                    $toggleRulesByGroup[$groupKey][] = $rule;
                    break;
                }
            }
        }
        
        // toggle グループ間は AND、グループ内は OR
        foreach ($toggleRulesByGroup as $groupKey => $groupRules) {
            $query->where(function($q) use ($groupRules) {
                foreach ($groupRules as $rule) {
                    $q->orWhereExists(function ($subQ) use ($rule) {
                        $subQ->select(DB::raw(1))
                            ->from('shop_rules')
                            ->whereColumn('shop_rules.shop_id', 'shops.id')
                            ->where('shop_rules.rule', $rule);
                    });
                }
            });
        }
        
        // checkbox ルールは個別に AND
        $allCheckboxRules = [];
        foreach ($definition['checkbox'] as $group) {
            $allCheckboxRules = array_merge($allCheckboxRules, $group['rules']);
        }
        
        foreach ($rules as $rule) {
            if (in_array($rule, $allCheckboxRules)) {
                $query->whereExists(function ($subQ) use ($rule) {
                    $subQ->select(DB::raw(1))
                        ->from('shop_rules')
                        ->whereColumn('shop_rules.shop_id', 'shops.id')
                        ->where('shop_rules.rule', $rule);
                });
            }
        }
    }

    // ========================================
    // リレーション
    // ========================================

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}