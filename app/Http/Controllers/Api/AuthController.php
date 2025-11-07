<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\User;
use App\Models\UserSignup;
use App\Mail\SignupVerificationMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\Log;
use App\Services\ImageService;

class AuthController extends Controller
{
    use ApiResponse;

    protected $imageService;
    public function __construct(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    /**
     * ユーザー登録開始（メール送信）
     */
    public function register(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email'
        ], [
            'email.required' => 'メールアドレスは必須です',
            'email.email' => '有効なメールアドレスを入力してください',
            'email.unique' => 'このメールアドレスは既に登録されています'
        ]);

        DB::transaction(function () use ($request) {
            // 既存の未完了登録を削除
            UserSignup::where('email', $request->email)
                ->where('completed', false)
                ->delete();

            // 新規登録作成
            $signup = UserSignup::create([
                'email' => $request->email,
                'role' => 'BUSINESS',
                'verification_token' => Str::random(64),
                'token_expires_at' => now()->addHours(24)
            ]);

            // 確認メール送信
            Mail::to($signup->email)->send(new SignupVerificationMail($signup));
        });

        return $this->successResponse(null, '確認メールを送信しました');
    }

    /**
     * トークン検証
     */
    public function verify(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $signup = UserSignup::validToken($request->token)->first();

        if (!$signup) {
            return $this->errorResponse('無効または期限切れのトークンです', 400);
        }

        // メール確認済みフラグを立てる
        $signup->update(['email_verified' => true]);

        return $this->successResponse([
            'email' => $signup->email,
            'token' => $signup->verification_token
        ]);
    }

    /**
     * アカウント作成完了
     */
    public function complete(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'nick_name' => 'nullable|string|max:255'
        ]);

        $signup = UserSignup::validToken($request->token)
            ->where('email_verified', true)
            ->first();

        if (!$signup) {
            return $this->errorResponse('無効なトークンです', 400);
        }

        $user = DB::transaction(function () use ($request, $signup) {
            // ユーザー作成
            $user = User::create([
                'email' => $signup->email,
                'password' => Hash::make($request->password),
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'nick_name' => $request->nick_name ?? config('user.display_name_default', '名無しの麻雀打ち'),
                'system_role' => $signup->role,
                'status' => 'ACTIVE',
                'last_login_at' => now()
            ]);

            // サインアップ完了
            $signup->update([
                'user_id' => $user->id,
                'completed' => true,
                'completed_at' => now()
            ]);

            return $user;
        });

        // ログイントークン生成
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'user' => $user,
            'token' => $token
        ], 'アカウントを作成しました', 201);
    }

    /**
     * ログイン
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)
            ->where('status', 'ACTIVE')
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('メールアドレスまたはパスワードが正しくありません', 401);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'user' => $user,
            'token' => $token
        ]);
    }

    /**
     * ログアウト
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        
        return $this->successResponse(null, 'ログアウトしました');
    }

    /**
     * アバター画像をアップロード
     */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ], [
            'image.required' => '画像ファイルは必須です。',
            'image.image' => '有効な画像ファイルをアップロードしてください。',
            'image.mimes' => 'JPEG、PNG、WebP形式の画像のみアップロード可能です。',
            'image.max' => '画像サイズは10MB以下にしてください。',
        ]);

        try {
            $user = $request->user();

            DB::beginTransaction();
            try {
                // 既存のアバター画像を削除
                if ($user->avatar_paths) {
                    $this->imageService->deleteImagePaths($user->avatar_paths);
                }

                // 新しい画像をアップロード
                $directory = $this->imageService->getDirectoryPath('users', $user->id);
                $imagePaths = $this->imageService->uploadImage(
                    $request->file('image'),
                    $directory,
                    'avatar'
                );

                // DBを更新
                $user->avatar_paths = $imagePaths;
                $user->save();

                DB::commit();

                Log::info('アバター画像アップロード成功', [
                    'user_id' => $user->id
                ]);

                return $this->successResponse([
                    'avatar_paths' => $imagePaths,
                    'avatar_url' => $user->getAvatarUrl('medium')
                ], 'アバター画像をアップロードしました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('アバター画像アップロードエラー: ' . $e->getMessage(), [
                'user_id' => $request->user()->id
            ]);

            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * アバター画像を削除
     */
    public function deleteAvatar(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user->avatar_paths) {
                return $this->errorResponse('アバター画像が設定されていません。', 400);
            }

            DB::beginTransaction();
            try {
                // ストレージから画像ファイルを削除
                $this->imageService->deleteImagePaths($user->avatar_paths);

                // DBから削除
                $user->avatar_paths = null;
                $user->save();

                DB::commit();

                Log::info('アバター画像削除成功', [
                    'user_id' => $user->id
                ]);

                return $this->successResponse(null, 'アバター画像を削除しました');

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('アバター画像削除エラー: ' . $e->getMessage(), [
                'user_id' => $request->user()->id
            ]);

            return $this->errorResponse('アバター画像の削除に失敗しました。', 500);
        }
    }

    /**
     * 認証ユーザー情報取得
     */
    public function me(Request $request)
    {

        Log::info('Request URL: ' . $request->fullUrl());
        Log::info('Request Method: ' . $request->method());
        Log::info('Request Path: ' . $request->path());
        return $this->successResponse($request->user());
    }

    /**
     * 認証ユーザー情報更新
     */
    public function updateMe(Request $request)
    {
        $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'nick_name' => 'nullable|string|max:255'
        ]);

        $user = $request->user();
        $user->update($request->only(['first_name', 'last_name', 'nick_name']));

        return $this->successResponse($user, 'プロフィールを更新しました');
    }

    /**
     * パスワードリセット要求（メール送信）
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);

        $user = User::where('email', $request->email)
            ->where('status', 'ACTIVE')
            ->first();

        if (!$user) {
            // セキュリティのため、ユーザーが存在しない場合も成功レスポンスを返す
            return $this->successResponse(null, 'メールアドレスが登録されている場合は、パスワードリセットの案内を送信しました');
        }

        // トークン生成と保存
        $token = Str::random(64);
        $user->update([
            'reset_token' => Hash::make($token),
            'reset_token_expires_at' => now()->addHour()
        ]);

        // パスワードリセットメール送信
        Mail::to($user->email)->send(new PasswordResetMail($user, $token));

        return $this->successResponse(null, 'メールアドレスが登録されている場合は、パスワードリセットの案内を送信しました');
    }

    /**
     * パスワードリセットトークン検証
     */
    public function verifyResetToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $user = User::whereNotNull('reset_token')
            ->where('reset_token_expires_at', '>', now())
            ->get()
            ->first(function ($user) use ($request) {
                return Hash::check($request->token, $user->reset_token);
            });

        if (!$user) {
            return $this->errorResponse('無効または期限切れのトークンです', 400);
        }

        return $this->successResponse([
            'email' => $user->email,
            'token' => $request->token
        ]);
    }

    /**
     * パスワードリセット実行
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed'
        ]);

        $user = User::whereNotNull('reset_token')
            ->where('reset_token_expires_at', '>', now())
            ->get()
            ->first(function ($user) use ($request) {
                return Hash::check($request->token, $user->reset_token);
            });

        if (!$user) {
            return $this->errorResponse('無効なトークンです', 400);
        }

        // パスワード更新とトークンクリア
        $user->update([
            'password' => Hash::make($request->password),
            'reset_token' => null,
            'reset_token_expires_at' => null
        ]);

        return $this->successResponse(null, 'パスワードをリセットしました');
    }

/**
     * 招待メール再送信
     */
    public function resendInvitation(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $signup = UserSignup::signup()
            ->where('email', $request->email)
            ->where('completed', false)
            ->latest()
            ->first();

        if (!$signup) {
            return $this->errorResponse('該当する登録情報が見つかりません', 404);
        }

        // トークンを再生成
        $signup->update([
            'verification_token' => Str::random(64),
            'token_expires_at' => now()->addHours(24),
            'email_verified' => false
        ]);

        // 確認メール再送信
        Mail::to($signup->email)->send(new SignupVerificationMail($signup));

        return $this->successResponse(null, '確認メールを再送信しました');
    }

/**
     * パスワード変更
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed|different:current_password'
        ]);

        $user = $request->user();

        // 現在のパスワードが正しいか確認
        if (!Hash::check($request->current_password, $user->password)) {
            return $this->errorResponse('現在のパスワードが正しくありません', 400);
        }

        // パスワード更新
        $user->update([
            'password' => Hash::make($request->password)
        ]);

        // 全てのトークンを削除（他のデバイスからログアウト）
        $user->tokens()->delete();

        // 新しいトークンを発行
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token
        ], 'パスワードを変更しました');
    }

/**
     * アカウント削除
     */
    public function deleteAccount(Request $request)
    {
        $request->validate([
            'password' => 'required|string'
        ]);

        $user = $request->user();

        // パスワード確認
        if (!Hash::check($request->password, $user->password)) {
            return $this->errorResponse('パスワードが正しくありません', 400);
        }

        DB::transaction(function () use ($user) {
            // 全てのトークンを削除
            $user->tokens()->delete();

            // ユーザーを論理削除
            $user->update([
                'status' => 'DELETED',
                'email' => $user->email . '_deleted_' . now()->timestamp // 将来の再登録を可能にする
            ]);

            // 関連するサインアップレコードも削除
            UserSignup::where('user_id', $user->id)->delete();
        });

        return $this->successResponse(null, 'アカウントを削除しました');
    }

}