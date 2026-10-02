<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class QrService
{
    /** 二维码中 token 的有效期（天） */
    public const TTL_DAYS = 90;

    /**
     * 为员工生成唯一整改链接（含员工 ID 与新 token）与二维码图片
     * - 每次生成都刷新 token 与过期时间，旧二维码立即失效
     * - 链接形如 <baseUrl>?uid=<员工ID>&token=<token>
     * 返回：['link' => string, 'qr_code_url' => string, 'token_expires_at' => string]
     */
    public function generateForUser(User $user, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        // 刷新 token：保证每名员工二维码唯一，且旧码不可再用
        $user->token = $this->uniqueToken();
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400);
        $user->token_expires_at = $expiresAt;

        $link = $baseUrl . '?uid=' . (int) $user->id . '&token=' . urlencode((string) $user->token);

        $dir = public_path() . 'uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'qr_' . (int) $user->id . '_' . time() . '.png';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        $qrCode = new QrCode($link);
        $qrCode->setSize(300);
        $qrCode->setMargin(10);
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        file_put_contents($path, $result->getString());

        // 清理该员工上一张二维码文件（旧码已失效，避免误用旧图）
        foreach (glob($dir . '/qr_' . (int) $user->id . '_*.png') ?: [] as $old) {
            if (realpath($old) !== realpath($path)) {
                @unlink($old);
            }
        }

        $url = '/uploads/' . $filename;
        $user->qr_code_url = $url;
        $user->save();

        return [
            'link' => $link,
            'qr_code_url' => $url,
            'token' => (string) $user->token,
            'token_expires_at' => $expiresAt,
        ];
    }

    private function uniqueToken(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $token = bin2hex(random_bytes(16));
            if (!User::where('token', $token)->find()) {
                return $token;
            }
        }
        return bin2hex(random_bytes(16)) . dechex(time());
    }
}
