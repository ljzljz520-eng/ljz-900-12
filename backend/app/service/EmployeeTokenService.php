<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;

/**
 * 员工整改二维码 token 统一校验
 *
 * 二维码链接携带 uid（员工ID）与 token（随机凭证），二者必须同时匹配。
 * 只改其中任何一个参数（例如把 uid 改成别人的 ID）都无法通过校验，
 * 从而保证员工扫码后只能看到/上传自己的整改内容。
 */
class EmployeeTokenService
{
    public const OK               = 0;
    public const ERR_MISSING      = 4001; // 缺少 uid 或 token
    public const ERR_INVALID      = 4002; // token 无效 / 与员工不匹配
    public const ERR_DISABLED     = 4003; // 员工账号已禁用
    public const ERR_EXPIRED      = 4004; // 二维码已过期

    /** 二维码有效期（秒），可用环境变量 QR_TOKEN_TTL 覆盖；0 表示永不过期 */
    public static function ttlSeconds(): int
    {
        $env = getenv('QR_TOKEN_TTL');
        if ($env === false || $env === '') {
            $env = $_SERVER['QR_TOKEN_TTL'] ?? '604800'; // 默认 7 天
        }
        return max(0, (int) $env);
    }

    /**
     * 校验二维码参数
     *
     * @param mixed $uid       链接中的员工 ID
     * @param mixed $token     链接中的 token
     * @return array{0:int,1:?User,2:?string} [状态码, 用户(成功时), 提示信息]
     */
    public function validate($uid, $token): array
    {
        $uid = (int) $uid;
        $token = trim((string) $token);
        if ($uid <= 0 || $token === '') {
            return [self::ERR_MISSING, null, '链接缺少员工信息，请联系管理员重新生成二维码'];
        }

        // uid 与 token 必须同时匹配，且必须是员工角色
        $user = User::where('id', $uid)
            ->where('token', $token)
            ->where('role', 'employee')
            ->find();
        if (!$user) {
            return [self::ERR_INVALID, null, '二维码链接无效，请联系管理员重新生成'];
        }

        if (isset($user->is_active) && (int) $user->is_active !== 1) {
            return [self::ERR_DISABLED, null, '该员工账号已被停用，请联系管理员'];
        }

        // qr_token_expires 为 NULL：尚未生成过二维码或刚重置，token 不可用于扫码入口
        $expires = $user->getData('qr_token_expires');
        if ($expires === null || $expires === '') {
            return [self::ERR_INVALID, null, '二维码链接无效，请联系管理员重新生成'];
        }
        if (strtotime((string) $expires) < time()) {
            return [self::ERR_EXPIRED, null, '二维码已过期，请联系管理员重新生成'];
        }

        return [self::OK, $user, null];
    }

    /** 计算"从现在起 ttl 秒后"的过期时间字符串 */
    public static function newExpiry(?int $ttl = null): ?string
    {
        $ttl = $ttl ?? self::ttlSeconds();
        if ($ttl <= 0) {
            return date('Y-m-d H:i:s', strtotime('+10 years'));
        }
        return date('Y-m-d H:i:s', time() + $ttl);
    }
}
