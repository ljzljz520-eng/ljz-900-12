<template>
  <div class="fix-page">
    <div class="fix-bg">
      <div class="fix-grid" aria-hidden="true"></div>
      <div class="fix-glow fix-glow-1"></div>
      <div class="fix-glow fix-glow-2"></div>
    </div>

    <div class="fix-container">
      <header class="fix-header">
        <div class="fix-brand">
          <div class="fix-logo">
            <svg viewBox="0 0 40 40" fill="none">
              <rect width="40" height="40" rx="10" fill="url(#fix-grad)" />
              <path d="M20 12v16M12 20h16" stroke="white" stroke-width="2" stroke-linecap="round" />
              <defs>
                <linearGradient id="fix-grad" x1="0" y1="0" x2="40" y2="40">
                  <stop stop-color="#0EA5E9" />
                  <stop offset="1" stop-color="#06B6D4" />
                </linearGradient>
              </defs>
            </svg>
          </div>
          <div>
            <h1 class="fix-title">员工整改</h1>
            <p v-if="authState === 'valid' && employeeName" class="fix-sub">
              {{ employeeName }}，查看待整改项并上传整改图（图片对按 #key 从小到大排序）
            </p>
            <p v-else-if="authState === 'checking'" class="fix-sub">正在校验二维码链接…</p>
          </div>
        </div>
      </header>

      <!-- 二维码无效 / 过期 / 缺参数 / 账号禁用：阻断，提示找管理员重新生成 -->
      <section v-if="authState === 'checking'" v-loading="true" element-loading-text="正在校验二维码链接…" class="fix-content">
        <div class="fix-empty" style="visibility: hidden"></div>
      </section>
      <section v-else-if="authState !== 'valid'" class="fix-content">
        <div class="fix-empty fix-block">
          <div class="fix-empty-icon" :class="authState === 'disabled' ? 'warn' : ''">
            <el-icon><WarningFilled v-if="authState === 'disabled'" /><CircleClose v-else /></el-icon>
          </div>
          <p class="fix-empty-text">{{ blockTitle }}</p>
          <p class="fix-empty-hint">{{ blockHint }}</p>
          <p class="fix-empty-contact">
            <el-icon><ChatDotSquare /></el-icon>
            请联系管理员重新生成您的专属整改二维码
          </p>
        </div>
      </section>

      <section v-else v-loading="loading" class="fix-content">
        <div class="fix-toolbar">
          <span v-if="expireText" class="fix-expire">二维码有效期至 {{ expireText }}</span>
          <el-switch v-model="onlyPending" active-text="仅看待整改" inactive-text="显示全部" />
        </div>

        <div v-if="records.length === 0 && !loading" class="fix-empty">
          <div class="fix-empty-icon success">
            <el-icon><CircleCheck /></el-icon>
          </div>
          <p class="fix-empty-text">暂无待整改记录</p>
          <p class="fix-empty-hint">您当前没有需要整改的项目</p>
        </div>

        <div v-else class="fix-list">
          <transition-group name="fix-list" tag="div" class="fix-list-inner">
            <div
              v-for="r in records"
              :key="r.id"
              class="fix-card"
            >
              <div class="fix-card-meta">
                <span class="fix-seq" :title="'序号 #' + r.sequence_key">#{{ r.sequence_key }}</span>
                <span class="fix-badge-name">{{ r.item_name_snapshot || r.item?.name }}</span>
                <span class="fix-badge-score">-{{ (r.item_score_snapshot ?? r.item?.score) }}分</span>
              </div>
              <div class="fix-card-images">
                <div class="fix-img-box">
                  <img
                    :src="imageUrl(r.issue_image)"
                    alt="问题图"
                    @error="(e) => (e.target.style.display = 'none')"
                  />
                </div>
                <div class="fix-arrow">
                  <el-icon v-if="r.status === 'completed'" class="fix-check"><CircleCheck /></el-icon>
                  <span v-else>→</span>
                </div>
                <div class="fix-img-box">
                  <template v-if="r.status === 'completed' && r.fix_image">
                    <img
                      :src="imageUrl(r.fix_image)"
                      alt="整改图"
                      @error="(e) => (e.target.style.display = 'none')"
                    />
                  </template>
                  <template v-else>
                    <div v-if="uploadingId === r.id" class="fix-uploading">
                      <el-icon class="fix-spin"><Loading /></el-icon>
                    </div>
                    <div v-else class="fix-upload-area">
                      <span>待处理</span>
                      <el-upload
                        :show-file-list="false"
                        accept="image/jpeg,image/png,image/gif"
                        :before-upload="(file) => uploadFix(r.id, file)"
                      >
                        <el-button type="primary" size="small">上传整改图</el-button>
                      </el-upload>
                    </div>
                  </template>
                </div>
              </div>
            </div>
          </transition-group>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { CircleCheck, Loading, CircleClose, WarningFilled, ChatDotSquare } from '@element-plus/icons-vue'
import { api, apiBase } from '@/api/request'

const route = useRoute()
const loading = ref(false)
const uploadingId = ref(null)
const records = ref([])
const onlyPending = ref(false)

// 二维码链接参数：uid（员工ID）+ token（随机凭证），二者由后端绑定校验
const uid = computed(() => String(route.query.uid || '').trim())
const token = computed(() => String(route.query.token || '').trim())

// checking | missing | invalid | expired | disabled | valid
const authState = ref('checking')
const employeeName = ref('')
const qrExpires = ref('')

const blockTitle = computed(() => {
  switch (authState.value) {
    case 'missing':
      return '链接信息不完整'
    case 'expired':
      return '二维码已过期'
    case 'disabled':
      return '账号已被停用'
    default:
      return '二维码链接无效'
  }
})

const blockHint = computed(() => {
  switch (authState.value) {
    case 'missing':
      return '链接中缺少员工 ID 或 token，无法加载您的整改内容'
    case 'expired':
      return '该整改二维码的有效期已结束'
    case 'disabled':
      return '您的员工账号当前处于停用状态'
    default:
      return '链接中的员工 ID 与 token 不匹配，无法访问任何整改内容'
  }
})

const expireText = computed(() => {
  if (!qrExpires.value) return ''
  const d = new Date(qrExpires.value.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return qrExpires.value
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
})

function imageUrl(path) {
  if (!path) return ''
  const base = apiBase() || (typeof window !== 'undefined' ? window.location.origin : '')
  return path.startsWith('http') ? path : (base.replace(/\/$/, '') + path)
}

// 后端业务错误码 -> 页面阻断状态
function stateFromBizCode(code) {
  if (code === 4001) return 'missing'
  if (code === 4002) return 'invalid'
  if (code === 4003) return 'disabled'
  if (code === 4004) return 'expired'
  return 'invalid'
}

async function verifySession() {
  if (!uid.value || !token.value) {
    authState.value = 'missing'
    return false
  }
  authState.value = 'checking'
  try {
    const data = await api.getEmployeeSession(uid.value, token.value)
    employeeName.value = data?.name || ''
    qrExpires.value = data?.qr_token_expires || ''
    authState.value = 'valid'
    return true
  } catch (e) {
    authState.value = stateFromBizCode(e?.bizCode)
    employeeName.value = ''
    records.value = []
    return false
  }
}

async function loadRecords() {
  if (authState.value !== 'valid') {
    loading.value = false
    return
  }
  loading.value = true
  try {
    // 始终携带 uid + token；后端只按 token 绑定的本人 ID 取数，忽略其他 user_id
    const list = await api.getRecords({
      uid: uid.value,
      token: token.value,
      status: onlyPending.value ? 'pending' : undefined,
    })
    records.value = list || []
  } catch (e) {
    records.value = []
    // 会话中途过期/被禁用/被重置：切换为阻断提示
    if ([4001, 4002, 4003, 4004].includes(e?.bizCode)) {
      authState.value = stateFromBizCode(e.bizCode)
    }
  } finally {
    loading.value = false
  }
}

async function uploadFix(recordId, file) {
  if (authState.value !== 'valid') return false
  uploadingId.value = recordId
  try {
    const res = await api.uploadImage(file, uid.value, token.value)
    if (!res?.path) throw new Error('上传失败')
    await api.uploadFix(recordId, res.path, uid.value, token.value)
    const idx = records.value.findIndex((r) => r.id === recordId)
    if (idx !== -1) {
      records.value[idx] = { ...records.value[idx], fix_image: res.path, status: 'completed' }
    }
    ElMessage.success('整改已提交')
  } catch (e) {
    if ([4001, 4002, 4003, 4004].includes(e?.bizCode)) {
      authState.value = stateFromBizCode(e.bizCode)
    } else {
      ElMessage.error('上传失败')
    }
  } finally {
    uploadingId.value = null
  }
  return false
}

onMounted(async () => {
  const ok = await verifySession()
  if (ok) await loadRecords()
})

// 切换筛选后刷新
watch(onlyPending, () => {
  if (authState.value === 'valid') loadRecords()
})
</script>

<style scoped>
.fix-page {
  min-height: 100vh;
  padding: 24px;
  position: relative;
}

.fix-bg {
  position: fixed;
  inset: 0;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
  z-index: 0;
}

.fix-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(14, 165, 233, 0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(14, 165, 233, 0.03) 1px, transparent 1px);
  background-size: 40px 40px;
}

.fix-glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(100px);
  opacity: 0.3;
  pointer-events: none;
}

.fix-glow-1 {
  width: 400px;
  height: 400px;
  background: #0ea5e9;
  top: -100px;
  right: -100px;
}

.fix-glow-2 {
  width: 300px;
  height: 300px;
  background: #06b6d4;
  bottom: -80px;
  left: -80px;
}

.fix-container {
  position: relative;
  z-index: 1;
  max-width: 1120px;
  margin: 0 auto;
}

.fix-header {
  margin-bottom: 32px;
}

.fix-brand {
  display: flex;
  align-items: center;
  gap: 16px;
}

.fix-logo {
  width: 56px;
  height: 56px;
}

.fix-logo svg {
  width: 100%;
  height: 100%;
}

.fix-title {
  font-size: 28px;
  font-weight: 700;
  color: white;
  margin: 0 0 4px;
  letter-spacing: -0.02em;
}

.fix-warn {
  font-size: 14px;
  color: #f87171;
  margin: 0;
}

.fix-sub {
  font-size: 14px;
  color: #94a3b8;
  margin: 0;
}

.fix-content {
  min-height: 200px;
}

.fix-toolbar {
  display: flex;
  justify-content: flex-end;
  margin: 0 0 16px;
}

.fix-empty {
  background: rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 20px;
  padding: 60px 40px;
  text-align: center;
}

.fix-empty-icon {
  width: 80px;
  height: 80px;
  margin: 0 auto 24px;
  border-radius: 20px;
  background: rgba(248, 113, 113, 0.2);
  color: #f87171;
  font-size: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.fix-empty-icon.success {
  background: rgba(16, 185, 129, 0.2);
  color: #34d399;
}

.fix-empty-icon.warn {
  background: rgba(245, 158, 11, 0.2);
  color: #fbbf24;
}

.fix-block {
  border-color: rgba(248, 113, 113, 0.35);
}

.fix-empty-contact {
  margin-top: 20px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #fca5a5;
  font-size: 14px;
  font-weight: 500;
}

.fix-expire {
  font-size: 13px;
  color: #94a3b8;
}

.fix-empty-text {
  font-size: 20px;
  font-weight: 600;
  color: white;
  margin: 0 0 8px;
}

.fix-empty-hint {
  font-size: 14px;
  color: #94a3b8;
  margin: 0;
}

.fix-list-inner {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.fix-card {
  background: rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  padding: 20px;
  transition: all 0.2s;
}

.fix-card:hover {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(14, 165, 233, 0.3);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.fix-card-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.fix-seq {
  background: rgba(14, 165, 233, 0.25);
  color: #7dd3fc;
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
}

.fix-badge-name {
  font-size: 14px;
  font-weight: 600;
  color: #e2e8f0;
}

.fix-badge-score {
  font-size: 14px;
  font-weight: 600;
  color: #f87171;
}

.fix-card-images {
  display: flex;
  align-items: stretch;
  gap: 16px;
}

.fix-img-box {
  flex: 1;
  min-width: 0;
  border-radius: 12px;
  overflow: hidden;
  background: rgba(0, 0, 0, 0.2);
  aspect-ratio: 4/3;
}

.fix-img-box img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.fix-arrow {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  font-size: 24px;
  color: #64748b;
}

.fix-check {
  color: #34d399;
  font-size: 28px;
}

.fix-upload-area {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: #94a3b8;
  font-size: 14px;
}

.fix-uploading {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.fix-spin {
  font-size: 32px;
  color: #0ea5e9;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.fix-list-enter-active,
.fix-list-leave-active {
  transition: all 0.3s ease;
}

.fix-list-enter-from,
.fix-list-leave-to {
  opacity: 0;
  transform: translateY(12px);
}
</style>
