<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;

/**
 * 员工整改 token 统一校验
 *
 * 二维码链接形如：/fix?uid=<员工ID>&token=<随机token>
 * 规则：
 * - uid 与 token 必须同时提供且互相匹配（只改参数看不到别人内容）
 * - token 必须为 employee 角色
 * - 账号必须启用
 * - token 必须未过期（过期需管理员重新生成二维码）
 */
class EmployeeTokenService
{
    public const OK = 0;
    public const E_MISSING = 400;   // 缺少参数
    public const E_INVALID = 401;   // token 无效 / 与员工不匹配
    public const E_DISABLED = 403;  // 账号已禁用
    public const E_EXPIRED = 410;   // token 已过期

    /**
     * @return array{0:int,1:?User} [状态码, 用户]；状态码非 OK 时用户为 null
     */
    public function verify($uid, string $token): array
    {
        $uid = (int) $uid;
        $token = trim($token);
        if ($token === '') {
            return [self::E_MISSING, null];
        }

        if ($uid > 0) {
            // 新二维码：uid + token 双键查询，换任何一个参数都无法命中
            $user = User::where('id', $uid)
                ->where('token', $token)
                ->where('role', 'employee')
                ->find();
        } else {
            // 兼容升级前发出的旧链接（只有 token、没有 uid）：
            // 仅接受长度 >=16 的由小写字母/数字/连字符组成的高熵 token，全表唯一反查
            if (!preg_match('/^[a-z0-9\-]{16,64}$/', $token)) {
                return [self::E_MISSING, null];
            }
            $user = User::where('token', $token)->where('role', 'employee')->find();
        }
        if (!$user) {
            return [self::E_INVALID, null];
        }
        if ((int) $user->is_active !== 1) {
            return [self::E_DISABLED, null];
        }
        if ($this->isExpired($user)) {
            return [self::E_EXPIRED, null];
        }
        return [self::OK, $user];
    }

    public function isExpired(User $user): bool
    {
        $expires = $user->getAttr('token_expires_at');
        if (!$expires) {
            // 未设置过期时间视为不过期（由迁移脚本为历史数据补齐宽限期）
            return false;
        }
        $ts = strtotime((string) $expires);
        return $ts !== false && $ts < time();
    }

    public function message(int $code): string
    {
        return match ($code) {
            self::E_MISSING  => '链接缺少员工 ID 或 token，请使用管理员提供的二维码进入',
            self::E_DISABLED => '账号已被禁用，请联系管理员重新生成二维码',
            self::E_EXPIRED  => '二维码已过期，请联系管理员重新生成',
            default          => '二维码无效或已失效，请联系管理员重新生成',
        };
    }
}
