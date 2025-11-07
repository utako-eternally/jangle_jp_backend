<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $exception)
    {

        Log::info('Exception caught in Handler', [
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'is_api' => $request->is('api/*'),
            'url' => $request->fullUrl(),
        ]);

        if ($request->is('api/*')) {
            // バリデーションエラー
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->errors()
                ], 422);
            }

            // 404エラー
            if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'データが見つかりません'
                ], 404);
            }

            // 認証エラー
            if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => '認証が必要です'
                ], 401);
            }

            // 認可エラー
            if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'この操作は許可されていません'
                ], 403);
            }

            // その他のエラー
            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $exception->getMessage() : 'エラーが発生しました'
            ], 500);
        }

        return parent::render($request, $exception);
    }
}