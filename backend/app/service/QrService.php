<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class QrService
{
    /**
     * 为员工生成唯一整改二维码
     *
     * 二维码内容（链接）同时携带 uid 与 token：
     *   {baseUrl}?uid={员工ID}&token={随机token}
     * 员工扫码进入后，后端要求 uid 与 token 同时匹配，且 token 在有效期内，
     * 单独篡改任何一个参数都无法看到他人内容。
     *
     * 同时刷新该员工 token 的过期时间（users.qr_token_expires），
     * 并写回 users.qr_code_url。
     *
     * @return array{link:string, qr_code_url:string, qr_token_expires:string}
     */
    public function generateForUser(User $user, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $link = $baseUrl
            . '?uid=' . (int) $user->id
            . '&token=' . urlencode((string) $user->token);

        $dir = public_path() . 'uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 文件名带随机串，避免同员工重复生成时被浏览器/CDN缓存旧图
        $filename = 'qr_' . $user->id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.png';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        $qrCode = new QrCode($link);
        $qrCode->setSize(300);
        $qrCode->setMargin(10);
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        file_put_contents($path, $result->getString());

        $url = '/uploads/' . $filename;

        // 删除旧二维码文件，避免堆积
        $oldUrl = (string) $user->getOrigin('qr_code_url');
        if ($oldUrl !== '' && $oldUrl !== $url) {
            $oldPath = public_path() . ltrim($oldUrl, '/');
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $expires = EmployeeTokenService::newExpiry();
        $user->qr_token_expires = $expires;
        $user->qr_code_url = $url;
        $user->save();

        return [
            'link' => $link,
            'qr_code_url' => $url,
            'qr_token_expires' => $expires,
        ];
    }
}
