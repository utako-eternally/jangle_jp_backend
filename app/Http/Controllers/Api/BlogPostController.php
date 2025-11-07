<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\BlogImage;
use App\Models\Shop;
use App\Services\ImageService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BlogPostController extends Controller
{
    use ApiResponse;

    protected $imageService;

    public function __construct(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    /**
     * ブログ投稿一覧を取得（公開済みのみ）
     */
    public function index(Request $request)
    {
        try {
            $query = BlogPost::published()
                ->with(['shop', 'author']);

            // 店舗でフィルタ
            if ($request->filled('shop_id')) {
                $query->where('shop_id', $request->shop_id);
            }

            // キーワード検索
            if ($request->filled('keyword')) {
                $keyword = '%' . $request->keyword . '%';
                $query->where(function ($q) use ($keyword) {
                    $q->where('title', 'like', $keyword)
                        ->orWhere('content', 'like', $keyword);
                });
            }

            // ソート
            $sortBy = $request->input('sort_by', 'published_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);

            // ページネーション
            $perPage = $request->input('per_page', 15);
            $posts = $query->paginate($perPage);

            return $this->successResponse(
                $posts,
                'ブログ投稿一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('ブログ投稿一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('ブログ投稿一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * ブログ投稿詳細を取得
     */
    public function show($id)
    {
        try {
            $post = BlogPost::with(['shop', 'author', 'images'])
                ->findOrFail($id);

            // 公開済みでない場合は投稿者または管理者のみ閲覧可
            if (!$post->isPublished()) {
                $user = Auth::user();
                if (!$user || (!$post->isAuthoredBy($user) && !$user->isAdmin())) {
                    return $this->errorResponse('この投稿を閲覧する権限がありません。', 403);
                }
            }

            return $this->successResponse(
                $post,
                'ブログ投稿詳細を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたブログ投稿が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログ投稿詳細取得エラー: ' . $e->getMessage(), [
                'post_id' => $id
            ]);
            return $this->errorResponse('ブログ投稿の詳細情報を取得できませんでした。', 500);
        }
    }

    /**
     * 自分のブログ投稿一覧を取得
     */
    public function myPosts(Request $request)
    {
        try {
            $user = Auth::user();
            
            $query = BlogPost::where('user_id', $user->id)
                ->with(['shop', 'images']);

            // ステータスでフィルタ
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // 店舗でフィルタ
            if ($request->filled('shop_id')) {
                $query->where('shop_id', $request->shop_id);
            }

            $posts = $query->orderBy('created_at', 'desc')
                          ->paginate($request->input('per_page', 15));

            return $this->successResponse(
                $posts,
                '自分のブログ投稿一覧を取得しました'
            );

        } catch (\Exception $e) {
            Log::error('自分のブログ投稿一覧取得エラー: ' . $e->getMessage());
            return $this->errorResponse('ブログ投稿一覧の取得に失敗しました。', 500);
        }
    }

    /**
     * 自分のブログ投稿を個別取得（編集用）
     */
    public function myPost($id)
    {
        try {
            $post = BlogPost::with(['shop', 'author', 'images'])
                ->where('id', $id)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            return $this->successResponse(
                $post,
                'ブログ投稿詳細を取得しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたブログ投稿が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログ投稿詳細取得エラー: ' . $e->getMessage(), [
                'post_id' => $id,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse('ブログ投稿の詳細情報を取得できませんでした。', 500);
        }
    }

    /**
     * ブログ投稿を作成
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_id' => 'required|exists:shops,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'nullable|in:DRAFT,PUBLISHED',
            'published_at' => 'nullable|date',
        ], [
            'shop_id.required' => '店舗IDは必須です。',
            'shop_id.exists' => '指定された店舗が存在しません。',
            'title.required' => 'タイトルは必須です。',
            'content.required' => '本文は必須です。',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'ブログ投稿情報に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $shop = Shop::findOrFail($request->shop_id);

            // 権限チェック
            if (!$shop->isOwnedBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('この店舗のブログ投稿を作成する権限がありません。', 403);
            }

            // 有料プランチェック
            if (!$shop->canUseBlog()) {
                return $this->errorResponse(
                    'ブログ機能は有料プラン限定です。プランをアップグレードしてください。',
                    403
                );
            }

            DB::beginTransaction();
            try {
                $postData = [
                    'shop_id' => $request->shop_id,
                    'user_id' => Auth::id(),
                    'title' => $request->title,
                    'content' => $request->content,
                    'status' => $request->input('status', BlogPost::STATUS_DRAFT),
                ];

                // 公開日時の設定
                if ($request->status === BlogPost::STATUS_PUBLISHED) {
                    $postData['published_at'] = $request->published_at ?? now();
                }

                $post = BlogPost::create($postData);

                DB::commit();

                Log::info('ブログ投稿作成成功', [
                    'post_id' => $post->id,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse(
                    $post->load(['shop', 'author']),
                    'ブログ投稿を作成しました',
                    201
                );

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('ブログ投稿作成エラー: ' . $e->getMessage(), [
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse('ブログ投稿の作成に失敗しました。', 500);
        }
    }

    /**
     * ブログ投稿を更新
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'status' => 'nullable|in:DRAFT,PUBLISHED,ARCHIVED',
            'published_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'ブログ投稿更新情報に不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $post = BlogPost::with('shop')->findOrFail($id);

            // 権限チェック
            if (!$post->isAuthoredBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このブログ投稿を更新する権限がありません。', 403);
            }

            // 有料プランチェック
            if (!$post->shop->canUseBlog()) {
                return $this->errorResponse(
                    'ブログ機能は有料プラン限定です。',
                    403
                );
            }

            $updateData = $request->only(['title', 'content', 'status']);

            // ステータスが公開に変更された場合
            if ($request->status === BlogPost::STATUS_PUBLISHED && $post->isDraft()) {
                $updateData['published_at'] = $request->published_at ?? now();
            }

            $post->update($updateData);

            return $this->successResponse(
                $post->load(['shop', 'author', 'images']),
                'ブログ投稿を更新しました'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたブログ投稿が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログ投稿更新エラー: ' . $e->getMessage(), [
                'post_id' => $id,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse('ブログ投稿の更新に失敗しました。', 500);
        }
    }

    /**
     * ブログ投稿を削除
     */
    public function destroy($id)
    {
        try {
            $post = BlogPost::with('shop')->findOrFail($id);

            // 権限チェック
            if (!$post->isAuthoredBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このブログ投稿を削除する権限がありません。', 403);
            }

            // 有料プランチェック（削除は許可）

            DB::beginTransaction();
            try {
                // サムネイル画像を削除
                if ($post->thumbnail_paths) {
                    $this->imageService->deleteImagePaths($post->thumbnail_paths);
                }

                // ブログ画像を削除
                foreach ($post->images as $image) {
                    $this->imageService->deleteImagePaths($image->image_paths);
                }

                // 投稿を削除
                $post->delete();

                DB::commit();

                Log::info('ブログ投稿削除成功', [
                    'post_id' => $id,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse(null, 'ブログ投稿を削除しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたブログ投稿が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログ投稿削除エラー: ' . $e->getMessage(), [
                'post_id' => $id,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse('ブログ投稿の削除に失敗しました。', 500);
        }
    }


    /**
     * サムネイル画像をアップロード
     */
    public function uploadThumbnail(Request $request, $postId)
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
            $post = BlogPost::with('shop')->findOrFail($postId);

            // 権限チェック
            if (!$post->isAuthoredBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このブログ投稿の画像をアップロードする権限がありません。', 403);
            }

            // 有料プランチェック
            if (!$post->shop->canUseBlog()) {
                return $this->errorResponse(
                    'ブログ機能は有料プラン限定です。',
                    403
                );
            }

            DB::beginTransaction();
            try {
                // 既存のサムネイルを削除
                if ($post->thumbnail_paths) {
                    $this->imageService->deleteImagePaths($post->thumbnail_paths);
                }

                // 新しい画像をアップロード
                $directory = $this->imageService->getDirectoryPath('blogs', $postId);
                $imagePaths = $this->imageService->uploadImage(
                    $request->file('image'),
                    $directory,
                    'blog'
                );

                // DBを更新
                $post->thumbnail_paths = $imagePaths;
                $post->save();

                DB::commit();

                Log::info('ブログサムネイル画像アップロード成功', [
                    'post_id' => $postId,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse([
                    'thumbnail_paths' => $imagePaths,
                    'thumbnail_url' => $post->getThumbnailUrl('medium')
                ], 'サムネイル画像をアップロードしました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたブログ投稿が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログサムネイル画像アップロードエラー: ' . $e->getMessage(), [
                'post_id' => $postId,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * ブログ本文画像を追加
     */
    public function addContentImage(Request $request, $postId)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
            'alt_text' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '画像アップロードに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $post = BlogPost::with('shop')->findOrFail($postId);

            // 権限チェック
            if (!$post->isAuthoredBy(Auth::user()) && !Auth::user()->isAdmin()) {
                return $this->errorResponse('このブログ投稿の画像をアップロードする権限がありません。', 403);
            }

            // 有料プランチェック
            if (!$post->shop->canUseBlog()) {
                return $this->errorResponse(
                    'ブログ機能は有料プラン限定です。',
                    403
                );
            }

            DB::beginTransaction();
            try {
                // 画像をアップロード
                $directory = $this->imageService->getDirectoryPath('blogs', $postId);
                $imagePaths = $this->imageService->uploadImage(
                    $request->file('image'),
                    $directory,
                    'blog'
                );

                // 現在の最大display_orderを取得
                $maxOrder = BlogImage::where('blog_post_id', $postId)->max('display_order') ?? 0;

                // ブログ画像を保存
                $blogImage = BlogImage::create([
                    'blog_post_id' => $postId,
                    'image_paths' => $imagePaths,
                    'alt_text' => $request->input('alt_text'),
                    'caption' => $request->input('caption'),
                    'display_order' => $maxOrder + 1,
                ]);

                DB::commit();

                Log::info('ブログ本文画像追加成功', [
                    'post_id' => $postId,
                    'image_id' => $blogImage->id,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse([
                    'image' => [
                        'id' => $blogImage->id,
                        'image_paths' => $blogImage->image_paths,
                        'alt_text' => $blogImage->alt_text,
                        'caption' => $blogImage->caption,
                        'display_order' => $blogImage->display_order,
                        'image_url' => $blogImage->getImageUrl('medium')
                    ]
                ], 'ブログ画像を追加しました', 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたブログ投稿が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログ本文画像追加エラー: ' . $e->getMessage(), [
                'post_id' => $postId,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * ブログ本文画像一覧を取得
     */
    public function getContentImages($postId)
    {
        try {
            $post = BlogPost::findOrFail($postId);

            $images = $post->images()->get()->map(function ($image) {
                return [
                    'id' => $image->id,
                    'image_paths' => $image->image_paths,
                    'alt_text' => $image->alt_text,
                    'caption' => $image->caption,
                    'display_order' => $image->display_order,
                    'thumbnail_url' => $image->getThumbnailUrl(),
                    'medium_url' => $image->getImageUrl('medium'),
                    'large_url' => $image->getImageUrl('large'),
                    'created_at' => $image->created_at,
                ];
            });

            return $this->successResponse($images, 'ブログ画像を取得しました');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたブログ投稿が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログ画像取得エラー: ' . $e->getMessage(), [
                'post_id' => $postId
            ]);
            return $this->errorResponse('ブログ画像の取得に失敗しました。', 500);
        }
    }

    /**
     * ブログ本文画像を削除
     */
    public function deleteContentImage($postId, $imageId)
    {
        try {
            $post = BlogPost::findOrFail($postId);
            $blogImage = BlogImage::where('blog_post_id', $postId)
                ->where('id', $imageId)
                ->firstOrFail();

            DB::beginTransaction();
            try {
                // ストレージから画像ファイルを削除
                $this->imageService->deleteImagePaths($blogImage->image_paths);

                // DBから削除
                $blogImage->delete();

                // display_orderを再調整
                $this->reorderContentImagesInternal($postId);

                DB::commit();

                Log::info('ブログ本文画像削除成功', [
                    'post_id' => $postId,
                    'image_id' => $imageId,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse(null, 'ブログ画像を削除しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定された画像が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログ本文画像削除エラー: ' . $e->getMessage(), [
                'post_id' => $postId,
                'image_id' => $imageId,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse('ブログ画像の削除に失敗しました。', 500);
        }
    }

    /**
     * ブログ本文画像の並び順を変更
     */
    public function reorderContentImages(Request $request, $postId)
    {
        $validator = Validator::make($request->all(), [
            'image_orders' => 'required|array',
            'image_orders.*.id' => 'required|integer|exists:blog_images,id',
            'image_orders.*.display_order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                '並び順データに不備があります: ' . $validator->errors()->first(),
                422
            );
        }

        try {
            $post = BlogPost::findOrFail($postId);

            DB::beginTransaction();
            try {
                foreach ($request->input('image_orders') as $order) {
                    BlogImage::where('blog_post_id', $postId)
                        ->where('id', $order['id'])
                        ->update(['display_order' => $order['display_order']]);
                }

                DB::commit();

                Log::info('ブログ画像並び順変更成功', [
                    'post_id' => $postId,
                    'user_id' => Auth::id()
                ]);

                return $this->successResponse(null, 'ブログ画像の並び順を変更しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('指定されたブログ投稿が見つかりません。', 404);
        } catch (\Exception $e) {
            Log::error('ブログ画像並び順変更エラー: ' . $e->getMessage(), [
                'post_id' => $postId,
                'user_id' => Auth::id()
            ]);
            return $this->errorResponse('並び順の変更に失敗しました。', 500);
        }
    }

    /**
     * ブログ画像のdisplay_orderを再調整（内部用）
     */
    private function reorderContentImagesInternal($postId)
    {
        $images = BlogImage::where('blog_post_id', $postId)
            ->orderBy('display_order')
            ->get();

        foreach ($images as $index => $image) {
            $image->display_order = $index + 1;
            $image->save();
        }
    }
}