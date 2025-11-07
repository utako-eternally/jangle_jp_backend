<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワードリセット - {{ config('app.name') }}</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Helvetica Neue', Arial, sans-serif; background-color: #f5f5f5;">
    <table cellpadding="0" cellspacing="0" width="100%" style="background-color: #f5f5f5; padding: 40px 0;">
        <tr>
            <td align="center">
                <table cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- ヘッダー -->
                    <tr>
                        <td style="padding: 40px 40px 20px 40px; text-align: center; background-color: #2c3e50; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">{{ config('app.name') }}</h1>
                            <p style="margin: 10px 0 0 0; color: #ecf0f1; font-size: 16px;">パスワードリセットのご案内</p>
                        </td>
                    </tr>
                    
                    <!-- コンテンツ -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px 0; color: #2c3e50; font-size: 24px; font-weight: 600;">パスワードリセットのご依頼</h2>
                            
                            <p style="margin: 0 0 30px 0; color: #555; font-size: 16px; line-height: 24px;">
                                パスワードをリセットするには、下記のボタンをクリックしてください。
                            </p>
                            
                            <table cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center">
                                        <a href="{{ config('app.frontend_url') }}/auth/reset-password?token={{ $token }}" 
                                           style="display: inline-block; padding: 15px 40px; background-color: #e74c3c; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: 600;">
                                            パスワードをリセットする
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 30px 0 20px 0; color: #999; font-size: 14px; line-height: 20px;">
                                ボタンが機能しない場合は、以下のURLをコピーしてブラウザに貼り付けてください：
                            </p>
                            
                            <p style="margin: 0 0 30px 0; padding: 15px; background-color: #f8f9fa; border-radius: 5px; word-break: break-all; font-size: 12px; color: #666;">
                                {{ config('app.frontend_url') }}/auth/reset-password?token={{ $token }}
                            </p>
                            
                            <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
                            
                            <p style="margin: 0; color: #999; font-size: 14px;">
                                このリンクの有効期限は1時間です。<br>
                                心当たりのない場合は、このメールを無視してください。
                            </p>
                        </td>
                    </tr>
                    
                    <!-- フッター -->
                    <tr>
                        <td style="padding: 20px 40px; background-color: #f8f9fa; border-radius: 0 0 8px 8px; text-align: center;">
                            <p style="margin: 0; color: #999; font-size: 12px;">
                                © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>