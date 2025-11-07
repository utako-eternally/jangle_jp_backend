<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogRequestResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        // リクエストのログ出力
        $requestBody = json_encode($request->all(), JSON_UNESCAPED_UNICODE);
        Log::info("[REQUEST] {$request->method()} {$request->getRequestUri()}");
        Log::info("Body: {$requestBody}");

        $response = $next($request);

        // レスポンスのログ出力（内容を取得せずにステータスだけ）
        Log::info("[RESPONSE] {$response->status()} " . $this->getStatusText($response->status()));
        
        // JsonResponseの場合のみ安全に内容を取得
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            Log::info("Body: " . $response->getContent());
        }
        
        Log::info("---");

        return $response;
    }

    private function getStatusText(int $statusCode): string
    {
        $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
        ];

        return $statusTexts[$statusCode] ?? '';
    }
}