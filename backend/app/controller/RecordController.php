<?php
declare(strict_types=1);
namespace app\controller;
use app\model\InspectionItem;
use app\model\Record;
use app\model\User;
use app\service\EmployeeTokenService;
use app\service\QrService;
use app\service\RecordSequenceService;
use think\facade\Log;
use think\facade\Request;
use think\Response;
class RecordController
{
    protected function seq(): RecordSequenceService
    {
        return new RecordSequenceService();
    }

    /**
     * 管理端 Bearer 登录态（auth_token，未过期的 admin）
     * 用于员工端开放接口中区分管理员访问
     */
    private function adminFromBearer(): ?User
    {
        $header = (string) Request::header('authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }
        return User::where('auth_token', trim($m[1]))
            ->where('role', 'admin')
            ->where('auth_token_expires', '>', date('Y-m-d H:i:s'))
            ->find() ?: null;
    }

    private function normalizeCheckDate($checkDate): ?string
    {
        if (!$checkDate) {
            return null;
        }
        $checkDate = (string) $checkDate;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate)) {
            return '__INVALID__';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $checkDate);
        if (!$dt || $dt->format('Y-m-d') !== $checkDate) {
            return '__INVALID__';
        }
        return $checkDate;
    }

    public function index(): Response
    {
        try {
            // 鉴权（二选一，禁止匿名访问）：
            // 1) 管理员 Bearer 登录态：可按 user_id 查看任意员工；
            // 2) 员工扫码：必须提供与 token 匹配的 uid，后端只认 token 解析出的本人 ID，
            //    前端传任何 user_id 都不能看到他人内容。
            $admin = $this->adminFromBearer();
            $token = (string) Request::param('token', '');
            $uid = Request::param('uid');

            if ($admin) {
                $userId = (int) Request::param('user_id');
                if ($userId <= 0) {
                    return api_json(['code' => 400, 'message' => '缺少 user_id', 'data' => null]);
                }
            } else {
                // uid 缺省时兼容旧链接：尝试从 token 反查（仍要求 token 本身有效）
                if (!$uid) {
                    $guessed = User::where('token', $token)
                        ->where('role', 'employee')
                        ->find();
                    $uid = $guessed?->id;
                }
                [$errCode, $employee, $errMsg] = (new EmployeeTokenService())->validate($uid, $token);
                if ($errCode !== EmployeeTokenService::OK) {
                    return api_json(['code' => $errCode, 'message' => $errMsg, 'data' => null]);
                }
                $userId = (int) $employee->id;
            }

            $checkDate = $this->normalizeCheckDate(Request::param('check_date'));
            $status = Request::param('status');
            if ($checkDate === '__INVALID__') {
                return api_json(['code' => 400, 'message' => 'check_date 格式错误（应为 YYYY-MM-DD）', 'data' => null]);
            }
            $query = Record::with(['item'])->where('user_id', (int) $userId);
            if ($checkDate) {
                $query->where('check_date', $checkDate);
            }
            if ($status) {
                $query->where('status', $status);
            }
            $list = $query->order('sequence_key', 'asc')->select();
            return api_json(['code' => 0, 'message' => 'ok', 'data' => $list->toArray()]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Log::error('RecordController@index: ' . $msg . "\n" . $e->getTraceAsString());
            if (stripos($msg, 'Unknown column') !== false && stripos($msg, 'check_date') !== false) {
                return api_json(['code' => 500, 'message' => '数据库缺少 records.check_date 字段，请执行 migrate_add_check_date.sql', 'data' => null]);
            }
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
    public function save(): Response
    {
        try {
            $userId = (int) Request::param('user_id');
            $items = Request::param('items'); // [{ item_id, issue_image }]
            $baseUrl = trim((string) Request::param('base_url', ''));
            if (!$userId || !is_array($items) || empty($items)) {
                return api_json(['code' => 400, 'message' => '参数错误', 'data' => null]);
            }
            $user = User::find($userId);
            if (!$user) {
                return api_json(['code' => 404, 'message' => '用户不存在', 'data' => null]);
            }
            $checkDate = (string) Request::param('check_date') ?: date('Y-m-d');
            $startKey = $this->seq()->getNextSequenceKey($userId, $checkDate);

            // 预取检查项，用于写入快照，避免后续修改 inspection_items 造成历史漂移
            $itemIds = [];
            foreach ($items as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                if ($itemId) {
                    $itemIds[] = $itemId;
                }
            }
            $itemMap = [];
            if (!empty($itemIds)) {
                $rows = InspectionItem::whereIn('id', array_values(array_unique($itemIds)))->select();
                foreach ($rows as $row) {
                    $itemMap[(int) $row->id] = $row;
                }
            }

            $created = [];
            foreach ($items as $i => $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $issueImage = (string) ($item['issue_image'] ?? '');
                if (!$itemId || !$issueImage) {
                    continue;
                }
                $snapName = null;
                $snapScore = null;
                if (isset($itemMap[$itemId])) {
                    $snapName = (string) $itemMap[$itemId]->name;
                    $snapScore = (int) $itemMap[$itemId]->score;
                }
                $record = Record::create([
                    'user_id'      => $userId,
                    'item_id'      => $itemId,
                    'item_name_snapshot'  => $snapName,
                    'item_score_snapshot' => $snapScore,
                    'sequence_key' => $startKey + $i,
                    'issue_image'  => $issueImage,
                    'status'       => 'pending',
                    'check_date'   => $checkDate,
                ]);
                $created[] = Record::with(['item'])->find($record->id)->toArray();
            }

            // 可选：同一步生成“带 token 链接 + 唯一二维码”
            if ($baseUrl !== '') {
                $qr = (new QrService())->generateForUser($user, $baseUrl);
                return api_json([
                    'code' => 0,
                    'message' => 'ok',
                    'data' => [
                        'records' => $created,
                        'link' => $qr['link'],
                        'qr_code_url' => $qr['qr_code_url'],
                        'qr_token_expires' => $qr['qr_token_expires'],
                    ],
                ]);
            }

            return api_json(['code' => 0, 'message' => 'ok', 'data' => $created]);
        } catch (\Throwable $e) {
            Log::error('RecordController@save: ' . $e->getMessage());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
    public function delete(int $id): Response
    {
        try {
            $record = Record::find($id);
            if (!$record) {
                return api_json(['code' => 404, 'message' => '记录不存在', 'data' => null]);
            }
            $userId = $record->user_id;
            $seqKey = $record->sequence_key;
            $checkDate = $record->check_date ? (string) $record->check_date : null;
            $record->delete();
            $this->seq()->reorderAfterDelete($userId, $seqKey, $checkDate);
            return api_json(['code' => 0, 'message' => 'ok', 'data' => null]);
        } catch (\Throwable $e) {
            Log::error('RecordController@delete: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
    public function uploadFix(int $id): Response
    {
        try {
            $record = Record::find($id);
            if (!$record) {
                return api_json(['code' => 404, 'message' => '记录不存在', 'data' => null]);
            }
            // 员工只能提交自己记录的整改图：uid + token 必须同时匹配且未过期
            [$errCode, $employee, $errMsg] = (new EmployeeTokenService())->validate(
                Request::param('uid'),
                (string) Request::param('token', '')
            );
            if ($errCode !== EmployeeTokenService::OK) {
                return api_json(['code' => $errCode, 'message' => $errMsg, 'data' => null]);
            }
            if ((int) $record->user_id !== (int) $employee->id) {
                // token 与记录归属不一致（例如改了记录 id 操作他人记录）
                return api_json(['code' => 403, 'message' => '无权操作该记录', 'data' => null]);
            }
            $fixImage = Request::param('fix_image');
            if (!$fixImage) {
                return api_json(['code' => 400, 'message' => '缺少 fix_image', 'data' => null]);
            }
            $record->fix_image = $fixImage;
            $record->status = 'completed';
            $record->save();
            $record = Record::with(['item'])->find($record->id)->toArray();
            return api_json(['code' => 0, 'message' => 'ok', 'data' => $record]);
        } catch (\Throwable $e) {
            Log::error('RecordController@uploadFix: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
}
