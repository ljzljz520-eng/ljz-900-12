<?php
declare(strict_types=1);
namespace app\controller;
use app\service\EmployeeTokenService;
use think\facade\Log;
use think\facade\Request;
use think\Response;

class EmployeeController
{
    /**
     * 员工扫码进入时的会话校验
     * GET /api/employee/session?uid=xx&token=xx
     * 成功返回员工基本信息（不含敏感字段）；失败返回 4001/4002/4003/4004
     */
    public function session(): Response
    {
        try {
            [$errCode, $user, $errMsg] = (new EmployeeTokenService())->validate(
                Request::param('uid'),
                (string) Request::param('token', '')
            );
            if ($errCode !== EmployeeTokenService::OK) {
                return api_json(['code' => $errCode, 'message' => $errMsg, 'data' => null]);
            }
            return api_json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'uid'              => (int) $user->id,
                    'name'             => (string) $user->name,
                    'qr_token_expires' => (string) $user->qr_token_expires,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('EmployeeController@session: ' . $e->getMessage());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
}
