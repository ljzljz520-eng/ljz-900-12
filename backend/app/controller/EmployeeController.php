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
     * 员工扫码后的会话校验：uid + token 有效时返回最小员工信息
     * GET /api/employee/session?uid=<id>&token=<token>
     */
    public function session(): Response
    {
        try {
            $svc = new EmployeeTokenService();
            [$code, $user] = $svc->verify(Request::param('uid'), (string) Request::param('token'));
            if ($code !== EmployeeTokenService::OK) {
                return api_json(['code' => $code, 'message' => $svc->message($code), 'data' => null]);
            }
            return api_json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'token_expires_at' => $user->getAttr('token_expires_at')
                        ? date('Y-m-d H:i:s', strtotime((string) $user->getAttr('token_expires_at')))
                        : null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('EmployeeController@session: ' . $e->getMessage());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
}
