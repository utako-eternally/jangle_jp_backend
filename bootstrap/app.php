<?php

// bootstrap/app.php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // リダイレクト先を完全に無効化（null で OK）
        $middleware->redirectGuestsTo(fn ($request) => null);
        // API専用でログを出力
        $middleware->appendToGroup('api', \App\Http\Middleware\LogRequestResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 未認証は常に 401 JSON
        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })
    ->create();
