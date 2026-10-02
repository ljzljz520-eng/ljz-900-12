// @ts-check
// 员工整改二维码安全场景
// 前置：应用已启动（docker compose up -d），且员工张三(id=2)已生成过二维码
//   可先以管理员登录，在 /admin 保存一次检查记录（会自动生成二维码）
// 运行：npx playwright test qr-security
import { test, expect } from '@playwright/test'

const API = process.env.PLAYWRIGHT_API_BASE || 'http://localhost:8080'

// 从数据库 seed 无法拿到带过期时间的二维码；测试采用“无效/过期”均可复现的场景。
// 这里使用一个明显不匹配的组合，断言后端拒绝并给出找管理员重新生成的语义。
test('篡改 uid 无法看到他人整改内容', async ({ request }) => {
  // emp-token-001 属于 id=2 的张三；把 uid 改成 3（李四）必须被拒绝
  const res = await request.get(`${API}/api/records`, {
    params: { uid: 3, token: 'emp-token-001' },
  })
  const body = await res.json()
  expect(body.code).toBe(4002)
  expect(body.message).toContain('联系管理员重新生成')
})

test('匿名访问（无 uid/token）被拒绝', async ({ request }) => {
  const res = await request.get(`${API}/api/records`)
  const body = await res.json()
  expect(body.code).toBe(4001)
  expect(body.message).toMatch(/员工信息|重新生成/)
})

test('无效 token 访问员工会话接口返回重新生成提示', async ({ request }) => {
  const res = await request.get(`${API}/api/employee/session`, {
    params: { uid: 2, token: 'not-a-real-token' },
  })
  const body = await res.json()
  expect(body.code).toBe(4002)
  expect(body.message).toContain('联系管理员重新生成')
})

test('整改页遇到无效二维码展示阻断提示', async ({ page }) => {
  await page.goto('/fix?uid=2&token=not-a-real-token')
  await expect(page.getByText(/请联系管理员重新生成您的专属整改二维码/)).toBeVisible({ timeout: 10000 })
})
