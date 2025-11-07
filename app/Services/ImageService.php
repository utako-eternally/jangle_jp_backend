<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Log;

class ImageService
{
    /**
     * 画像タイプ別のサイズ定義
     */
    const SIZE_CONFIGS = [
        'shop' => [
            'thumb' => ['width' => 300, 'height' => 200, 'fit' => 'cover'],
            'medium' => ['width' => 800, 'height' => 600, 'fit' => 'contain'],
            'large' => ['width' => 1600, 'height' => 1200, 'fit' => 'contain'],
        ],
        'avatar' => [
            'thumb' => ['width' => 100, 'height' => 100, 'fit' => 'crop'],
            'medium' => ['width' => 300, 'height' => 300, 'fit' => 'crop'],
            'large' => ['width' => 600, 'height' => 600, 'fit' => 'crop'],
        ],
        'blog' => [
            'thumb' => ['width' => 400, 'height' => 300, 'fit' => 'cover'],
            'medium' => ['width' => 1000, 'height' => 750, 'fit' => 'contain'],
            'large' => ['width' => 1920, 'height' => 1440, 'fit' => 'contain'],
        ],
    ];

    const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    const MAX_FILE_SIZE = 10485760;

    public function uploadImage(UploadedFile $file, string $directory, string $type = 'shop'): array
    {
        $this->validateImage($file);

        if (!isset(self::SIZE_CONFIGS[$type])) {
            throw new \Exception("無効な画像タイプです: {$type}");
        }

        $filename = $this->generateUniqueFilename($file);
        $paths = [];

        try {
            // オリジナル画像を保存
            $originalPath = $directory . '/' . $filename;
            Storage::disk('public')->put($originalPath, file_get_contents($file->getRealPath()));
            $paths['original'] = $originalPath;

            // 各サイズの画像を生成
            $sizeConfig = self::SIZE_CONFIGS[$type];
            foreach ($sizeConfig as $sizeName => $config) {
                $resizedPath = $this->resizeAndSave(
                    $file,
                    $directory,
                    $filename,
                    $sizeName,
                    $config['width'],
                    $config['height'],
                    $config['fit']
                );
                $paths[$sizeName] = $resizedPath;
            }

            Log::info('画像アップロード成功', [
                'directory' => $directory,
                'filename' => $filename,
                'type' => $type,
                'sizes' => array_keys($paths)
            ]);

            return $paths;

        } catch (\Exception $e) {
            $this->deleteImagePaths($paths);
            
            Log::error('画像アップロードエラー: ' . $e->getMessage(), [
                'directory' => $directory,
                'filename' => $filename,
                'type' => $type
            ]);

            throw new \Exception('画像のアップロードに失敗しました: ' . $e->getMessage());
        }
    }

    private function resizeAndSave(
        UploadedFile $file,
        string $directory,
        string $filename,
        string $sizeName,
        int $width,
        int $height,
        string $fit = 'contain'
    ): string {
        $image = Image::make($file->getRealPath());

        switch ($fit) {
            case 'crop':
                // 正方形に切り抜き（アバター用）
                $image->fit($width, $height, function ($constraint) {
                    $constraint->upsize();
                });
                break;

            case 'cover':
                // 指定サイズを埋めるようにリサイズ（はみ出た部分は切り取り）
                $image->fit($width, $height, function ($constraint) {
                    $constraint->upsize();
                }, 'center');
                break;

            case 'contain':
            default:
                // アスペクト比を維持して指定サイズ内に収める（余白あり）
                $image->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                break;
        }

        $resizedFilename = pathinfo($filename, PATHINFO_FILENAME) 
                         . "_{$sizeName}." 
                         . pathinfo($filename, PATHINFO_EXTENSION);

        $path = $directory . '/' . $resizedFilename;

        Storage::disk('public')->put($path, (string) $image->encode());

        return $path;
    }

    public function deleteImagePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function validateImage(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \Exception('画像サイズが大きすぎます。10MB以下のファイルをアップロードしてください。');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new \Exception('サポートされていない画像形式です。JPEG、PNG、WebPのみアップロード可能です。');
        }

        try {
            $imageInfo = getimagesize($file->getRealPath());
            if ($imageInfo === false) {
                throw new \Exception('有効な画像ファイルではありません。');
            }
        } catch (\Exception $e) {
            throw new \Exception('画像ファイルの検証に失敗しました。');
        }
    }

    private function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('YmdHis');
        $random = Str::random(8);
        
        return "{$timestamp}_{$random}.{$extension}";
    }

    public function getDirectoryPath(string $type, int $id): string
    {
        return "{$type}/{$id}";
    }
}