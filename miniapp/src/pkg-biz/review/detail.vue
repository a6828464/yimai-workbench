<script setup lang="ts">
/**
 * 课后分析详情 / 新建 / 编辑（授课老师端）
 *
 * 一个页面三种用途（pages.json 只注册了 pages/review/detail 这一页）：
 *  - 默认（?id=）：只读详情 + 「编辑」「确认」
 *  - 编辑：同一页切到表单，PUT /post-class-reviews/{id}
 *  - 新建（?mode=create）：两步 —— 先 GET /candidates 选人，再按 GET /catalog 的维度填写，
 *    POST /post-class-reviews 成功后跳到新记录的详情
 *
 * 为什么新建不做成独立页：队长本轮只注册了 detail 一页；把向导放这里可以共用同一套
 * 表单与校验，避免两个页面各抄一份「观察项 / 红线 / 反馈」的规则文案。
 *
 * 契约（PostClassReviewController）：
 *  - show = payload 摊平 + {status,share,redFlag,studentType,teacherName,classAt,createdAt,confirmedAt}
 *  - store/update 走 validatedPayload：required 只有 studentName；observations[{key,level}]、
 *    redFlags[]、feedback{bodyFeel,like,concern}、scene∈trial|private_first|private、
 *    venue∈绿地店|东部店，classAt 是 date
 *  - confirm：已确认再点 → 422「该课后分析已确认」；不在可见范围 → 403
 *  - 写权限只有 SUPER/MANAGER/TEACHER（服务老师只读）
 */
import { computed, reactive, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { copyText, requireFeature } from '@/utils/nav'
import { hasAnyRole } from '@/utils/permission'
import {
  REVIEW_LEVELS,
  confirmReview,
  createReview,
  deleteReview,
  fetchReviewCandidates,
  fetchReviewCatalog,
  fetchReviewDetail,
  updateReview,
  type ReviewCandidate,
  type ReviewCatalog,
  type ReviewDetail,
  type ReviewLevel,
  type ReviewPayload
} from '@/api/review'

const WRITE_ROLES: Array<'R_SUPER' | 'R_MANAGER' | 'R_TEACHER'> = [
  'R_SUPER',
  'R_MANAGER',
  'R_TEACHER'
]

const user = useUserStore()

/* ==========================================================================
 * 路由状态
 * ========================================================================== */

type PageMode = 'view' | 'create'

const mode = ref<PageMode>('view')
const reviewId = ref(0)
const pageReady = ref(false)

/* ==========================================================================
 * 目录（维度/类型/红线/场景）
 * ========================================================================== */

const catalog = ref<ReviewCatalog | null>(null)
const catalogError = ref('')

/** key → 观察项文案（详情里的历史记录用 key 存，展示时必须回填中文） */
const obsLabel = computed<Record<string, string>>(() => {
  const map: Record<string, string> = {}
  for (const g of catalog.value?.groups ?? []) {
    for (const it of g.items ?? []) {
      map[it.key] = it.label
    }
  }
  return map
})

/** 红线 key → {label,hint} */
const redFlagMap = computed<Record<string, { label: string; hint: string }>>(() => {
  const map: Record<string, { label: string; hint: string }> = {}
  for (const f of catalog.value?.redFlags ?? []) {
    map[f.key] = { label: f.label, hint: f.hint }
  }
  return map
})

const sceneColumns = computed(() => {
  const scenes = catalog.value?.scenes ?? {}
  const rows = Object.keys(scenes).map((k) => ({ label: scenes[k] || k, value: k }))
  return rows.length ? rows : [
    { label: '体验课', value: 'trial' },
    { label: '私教首课', value: 'private_first' },
    { label: '私教常规课', value: 'private' }
  ]
})

const studentTypeColumns = computed(() => ['', ...(catalog.value?.students ?? [])])

const sceneText = computed(() => {
  const s = form.scene
  const hit = sceneColumns.value.find((x) => x.value === s)
  return hit?.label ?? '体验课'
})

async function loadCatalog() {
  try {
    catalog.value = await fetchReviewCatalog()
    catalogError.value = ''
  } catch (e) {
    catalogError.value = e instanceof Error ? e.message : '规则库加载失败'
  }
}

/* ==========================================================================
 * 详情
 * ========================================================================== */

const detail = ref<ReviewDetail | null>(null)
const detailLoading = ref(false)
const detailError = ref('')
const detailForbidden = ref(false)

const canWrite = computed(() => hasAnyRole(user.roles, WRITE_ROLES))
const confirmed = computed(() => (detail.value?.status ?? '') === '已确认')
const statusText = computed(() => detail.value?.status || '草稿')

async function loadDetail() {
  if (!reviewId.value) return
  detailLoading.value = true
  detailError.value = ''
  detailForbidden.value = false
  try {
    detail.value = await fetchReviewDetail(reviewId.value)
  } catch (e) {
    const msg = e instanceof Error ? e.message : '加载失败'
    detailError.value = msg
    detailForbidden.value = /无权/.test(msg)
  } finally {
    detailLoading.value = false
    pageReady.value = true
  }
}

/** 详情里的观察项（key → 中文，找不到就显示 key，不吞数据） */
const observationRows = computed(() => {
  const rows = detail.value?.observations ?? []
  return rows.map((o) => ({
    key: o.key,
    label: obsLabel.value[o.key] || o.key,
    level: String(o.level || '中')
  }))
})

/** 评分汇总：轻 / 中 / 重 各几项 */
const levelSummary = computed(() => {
  const out: Record<string, number> = { 轻: 0, 中: 0, 重: 0 }
  for (const r of observationRows.value) {
    if (out[r.level] === undefined) out[r.level] = 0
    out[r.level] += 1
  }
  return out
})

const redFlagRows = computed(() => {
  const keys = detail.value?.redFlags ?? []
  return keys.map((k) => ({ key: k, ...(redFlagMap.value[k] ?? { label: k, hint: '' }) }))
})

const plan = computed(() => detail.value?.plan ?? null)
const objective = computed(() => detail.value?.objective ?? null)
const scriptLines = computed(() => {
  const s = detail.value?.script ?? {}
  const rows = [
    { key: 'whatPracticed', label: '今天练了什么', text: s.whatPracticed ?? '' },
    { key: 'progress', label: '进步点', text: s.progress ?? '' },
    { key: 'nextClass', label: '下节课', text: s.nextClass ?? '' },
    { key: 'reminder', label: '回家提醒', text: s.reminder ?? '' }
  ]
  return rows.filter((r) => !!r.text)
})

/** 命中红线时后端不产出 plan，只给 cautions / notes */
const redFlagCautions = computed(() => {
  if (plan.value) return []
  const g = detail.value?.generated ?? null
  return g?.cautions ?? []
})
const redFlagNotes = computed(() => (plan.value ? [] : detail.value?.generated?.notes ?? []))

const feedbackRows = computed(() => {
  const f = detail.value?.feedback ?? {}
  return [
    { key: 'bodyFeel', label: '身体感受', text: f.bodyFeel ?? '' },
    { key: 'like', label: '喜欢的方向', text: f.like ?? '' },
    { key: 'concern', label: '担心的问题', text: f.concern ?? '' }
  ].filter((r) => !!r.text)
})

/* ==========================================================================
 * 表单（新建 / 编辑共用）
 * ========================================================================== */

interface FormModel {
  studentName: string
  studentPhone: string
  venue: string
  scene: string
  studentType: string
  classAt: string
  courseName: string
  goalText: string
  observations: { key: string; level: string }[]
  redFlags: string[]
  feedback: { bodyFeel: string; like: string; concern: string }
  leadId: number | null
  customerId: number | null
  bookingId: number | null
}

function emptyForm(): FormModel {
  return {
    studentName: '',
    studentPhone: '',
    venue: user.venue || '绿地店',
    scene: 'trial',
    studentType: '',
    classAt: nowText(),
    courseName: '',
    goalText: '',
    observations: [],
    redFlags: [],
    feedback: { bodyFeel: '', like: '', concern: '' },
    leadId: null,
    customerId: null,
    bookingId: null
  }
}

function nowText(): string {
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

const form = reactive<FormModel>(emptyForm())
const formVisible = ref(false)
const saving = ref(false)
const saveError = ref('')

const openGroups = ref<string[]>([])

function toggleGroup(g: string) {
  openGroups.value = openGroups.value.includes(g)
    ? openGroups.value.filter((x) => x !== g)
    : [...openGroups.value, g]
}

function isObs(key: string): boolean {
  return form.observations.some((o) => o.key === key)
}

function levelOf(key: string): string {
  return form.observations.find((o) => o.key === key)?.level ?? ''
}

function toggleObs(key: string) {
  if (isObs(key)) {
    form.observations = form.observations.filter((o) => o.key !== key)
    return
  }
  form.observations = [...form.observations, { key, level: '中' }]
}

function setLevel(key: string, level: ReviewLevel) {
  form.observations = form.observations.map((o) => (o.key === key ? { ...o, level } : o))
}

function toggleRedFlag(key: string) {
  form.redFlags = form.redFlags.includes(key)
    ? form.redFlags.filter((x) => x !== key)
    : [...form.redFlags, key]
}

function groupSelectedCount(group: string): number {
  const items = catalog.value?.groups.find((g) => g.group === group)?.items ?? []
  const keys = items.map((i) => i.key)
  return form.observations.filter((o) => keys.includes(o.key)).length
}

/** 详情 → 表单（编辑） */
function fillFormFromDetail(d: ReviewDetail) {
  Object.assign(form, emptyForm())
  form.studentName = d.studentName ?? ''
  form.studentPhone = d.studentPhone ?? ''
  form.venue = d.venue ?? (user.venue || '绿地店')
  form.scene = d.scene ?? 'trial'
  form.studentType = d.studentType ?? ''
  form.classAt = d.classAt ?? nowText()
  form.courseName = d.courseName ?? ''
  form.goalText = d.goalText ?? ''
  form.observations = (d.observations ?? []).map((o) => ({ key: o.key, level: String(o.level || '中') }))
  form.redFlags = [...(d.redFlags ?? [])]
  form.feedback = {
    bodyFeel: d.feedback?.bodyFeel ?? '',
    like: d.feedback?.like ?? '',
    concern: d.feedback?.concern ?? ''
  }
  form.leadId = d.leadId ?? null
  form.customerId = d.customerId ?? null
  form.bookingId = d.bookingId ?? null
  // 编辑时把已选项所在的组默认展开，老师一眼看到之前勾了什么
  openGroups.value = Array.from(
    new Set(
      (form.observations ?? [])
        .map((o) => catalog.value?.groups.find((g) => g.items.some((it) => it.key === o.key))?.group)
        .filter((x): x is string => !!x)
    )
  )
}

function buildPayload(): ReviewPayload {
  return {
    scene: form.scene || 'trial',
    studentType: form.studentType,
    studentName: form.studentName.trim(),
    studentPhone: form.studentPhone.replace(/\D+/g, ''),
    classAt: form.classAt.trim(),
    courseName: form.courseName.trim(),
    venue: form.venue,
    goalText: form.goalText.trim(),
    observations: form.observations.map((o) => ({ key: o.key, level: o.level })),
    redFlags: form.redFlags,
    feedback: {
      bodyFeel: form.feedback.bodyFeel.trim(),
      like: form.feedback.like.trim(),
      concern: form.feedback.concern.trim()
    },
    leadId: form.leadId,
    customerId: form.customerId,
    bookingId: form.bookingId
  }
}

function validate(): string {
  if (!form.studentName.trim()) return '请填写学员姓名'
  // classAt 在后端是 nullable|date：空值合法，填了才校验格式（省一次 422 往返）
  const at = form.classAt.trim()
  if (at && !/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/.test(at)) {
    return '上课时间格式应为 2026-11-04 10:00'
  }
  return ''
}

async function submitForm() {
  if (saving.value) return
  const msg = validate()
  if (msg) {
    uni.showToast({ title: msg, icon: 'none' })
    return
  }
  saving.value = true
  saveError.value = ''
  try {
    const payload = buildPayload()
    if (mode.value === 'create') {
      await createReview(payload)
      uni.showToast({ title: '已保存', icon: 'none' })
      // 成功后回列表（列表 onShow 会重新拉数据，新增的那条立刻可见）
      backToList()
      return
    }
    await updateReview(reviewId.value, payload)
    uni.showToast({ title: '已保存', icon: 'none' })
    formVisible.value = false
    await loadDetail()
  } catch (e) {
    saveError.value = e instanceof Error ? e.message : '保存失败，请稍后重试'
  } finally {
    saving.value = false
  }
}

/* ==========================================================================
 * 新建：第一步选人
 * ========================================================================== */

const candidates = ref<{ records: ReviewCandidate[]; leads: ReviewCandidate[]; members: ReviewCandidate[] }>({
  records: [],
  leads: [],
  members: []
})
const candidateLoading = ref(false)
const candidateError = ref('')
const candidateLoaded = ref(false)
const candidateTab = ref<'records' | 'leads' | 'members'>('records')
const keyword = ref('')

const candidateTabs = [
  { key: 'records' as const, label: '上过课' },
  { key: 'leads' as const, label: '留资' },
  { key: 'members' as const, label: '会员' }
]

const currentCandidates = computed(() => {
  const rows = candidates.value[candidateTab.value] ?? []
  const k = keyword.value.trim()
  if (!k) return rows
  return rows.filter(
    (r) =>
      (r.studentName ?? '').includes(k) ||
      (r.phoneTail ?? '').includes(k) ||
      (r.phone ?? '').includes(k)
  )
})

async function loadCandidates() {
  if (candidateLoading.value) return
  candidateLoading.value = true
  candidateError.value = ''
  try {
    const res = await fetchReviewCandidates(7)
    candidates.value = {
      records: Array.isArray(res?.records) ? res.records : [],
      leads: Array.isArray(res?.leads) ? res.leads : [],
      members: Array.isArray(res?.members) ? res.members : []
    }
    candidateLoaded.value = true
  } catch (e) {
    candidateError.value = e instanceof Error ? e.message : '候选名单加载失败'
    candidateLoaded.value = true
  } finally {
    candidateLoading.value = false
  }
}

/** 选中候选 → 预填表单（能带的全带上，老师少打字） */
function pickCandidate(c: ReviewCandidate) {
  Object.assign(form, emptyForm())
  form.studentName = c.studentName ?? ''
  form.studentPhone = c.phone ?? ''
  form.venue = c.venue || user.venue || '绿地店'
  form.scene = c.scene === 'private' ? 'private' : 'trial'
  form.classAt = c.classAt ?? nowText()
  form.courseName = c.courseName ?? ''
  form.leadId = c.leadId ?? null
  form.customerId = c.customerId ?? null
  form.bookingId = c.bookingId ?? null
  openGroups.value = []
  formVisible.value = true
}

/* ==========================================================================
 * 确认
 * ========================================================================== */

const confirming = ref(false)

function onConfirm() {
  if (!detail.value) return
  if (confirmed.value) {
    uni.showToast({ title: '该课后分析已确认', icon: 'none' })
    return
  }
  uni.showModal({
    title: '确认课后分析',
    content: '确认后生成对客训练方向与分享码，内容仍可再编辑。',
    confirmText: '确认',
    success: (res) => {
      if (res.confirm) void doConfirm()
    }
  })
}

async function doConfirm() {
  if (confirming.value) return
  confirming.value = true
  try {
    await confirmReview(reviewId.value)
    uni.showToast({ title: '已确认', icon: 'none' })
    await loadDetail()
  } catch (e) {
    uni.showModal({
      title: '确认失败',
      content: e instanceof Error ? e.message : '请稍后重试',
      showCancel: false
    })
  } finally {
    confirming.value = false
  }
}

/* ==========================================================================
 * 删除（F06：deleteReview 这个接口从 t14 之前就一直存在，但全仓 0 调用 —— 页面没有入口）
 *
 * 权限口径与后端 PostClassReviewController::destroy 完全一致：
 * destroy = isVisible($u,$row) + assertCanWrite($u)，而 assertCanWrite 要求
 * SUPER / MANAGER / TEACHER —— 正是本页的 canWrite。服务老师（只读）看不到删除按钮，
 * 直达也只会拿到后端 403「仅授课老师、店长可填写课后分析」。
 * 删除不可恢复（后端没有回收站），所以必须二次确认。
 * ========================================================================== */

const removing = ref(false)

function onDelete() {
  if (!detail.value) return
  if (!canWrite.value) {
    uni.showToast({ title: '仅授课老师、店长可删除课后分析', icon: 'none' })
    return
  }
  uni.showModal({
    title: '删除课后分析',
    content: `确定删除「${detail.value.studentName || '这位学员'}」的课后分析？删除后不可恢复。`,
    confirmText: '删除',
    confirmColor: '#B23C33', // = $danger-500，showModal 只吃字面量
    success: (res) => {
      if (res.confirm) void doDelete()
    }
  })
}

async function doDelete() {
  if (removing.value) return
  removing.value = true
  try {
    await deleteReview(reviewId.value)
    uni.showToast({ title: '已删除', icon: 'none' })
    // 只负责退出详情：列表页 onShow 会重新拉取，被删的那条自然消失
    backToList()
  } catch (e) {
    uni.showModal({
      title: '删除失败',
      content: e instanceof Error ? e.message : '请稍后重试',
      showCancel: false
    })
  } finally {
    removing.value = false
  }
}

/* ==========================================================================
 * 生命周期
 * ========================================================================== */

onLoad((query?: Record<string, string | undefined>) => {
  const q = query ?? {}
  mode.value = q.mode === 'create' ? 'create' : 'view'
  reviewId.value = Number(q.id) || 0
  if (!requireFeature('reviews')) return

  if (mode.value === 'create') {
    uni.setNavigationBarTitle({ title: '新建课后分析' })
    void loadCatalog()
    void loadCandidates()
    pageReady.value = true
    formVisible.value = false
    return
  }

  void loadCatalog()
  if (reviewId.value) {
    void loadDetail()
  } else {
    detailError.value = '缺少课后分析 id'
    pageReady.value = true
  }
})

function openEdit() {
  if (!detail.value) return
  if (!canWrite.value) {
    uni.showToast({ title: '仅授课老师、店长可填写课后分析', icon: 'none' })
    return
  }
  fillFormFromDetail(detail.value)
  saveError.value = ''
  formVisible.value = true
}

function backToList() {
  const pages = getCurrentPages()
  if (pages.length > 1) uni.navigateBack({ delta: 1 })
  else uni.redirectTo({ url: '/pkg-biz/review/review' })
}
</script>

<template>
  <view class="ym-page rv">
    <!-- 新建向导步骤条 -->
    <view v-if="mode === 'create'" class="rv__stepbar">
      <view class="rv__step" :class="{ 'is-on': !formVisible }">
        <text class="rv__step-no">1</text>
        <text>选学员</text>
      </view>
      <view class="rv__stepline" />
      <view class="rv__step" :class="{ 'is-on': formVisible }">
        <text class="rv__step-no">2</text>
        <text>填分析</text>
      </view>
    </view>

    <!-- ===================== 新建：第一步选人 ===================== -->
    <template v-if="mode === 'create' && !formVisible">
      <ym-section
        title="选择这次分析对应的学员"
        desc="来自你近 7 天的课、分配给你的留资与会籍归属的会员"
        :action-text="candidateLoading ? '' : '刷新'"
        @action="loadCandidates"
      >
        <view class="rv__ctabs">
          <view
            v-for="t in candidateTabs"
            :key="t.key"
            class="rv__ctab ym-tap"
            :class="{ 'is-active': candidateTab === t.key }"
            @click="candidateTab = t.key"
          >
            {{ t.label }}（{{ (candidates[t.key] || []).length }}）
          </view>
        </view>

        <view class="rv__search">
          <wd-input v-model="keyword" placeholder="搜姓名或手机尾号" clearable />
        </view>

        <view v-if="candidateError" class="rv__err">
          <text>{{ candidateError }}</text>
          <text class="rv__err-act" @click="loadCandidates">重试</text>
        </view>

        <view v-else-if="candidateLoading && !candidateLoaded" class="rv__sk">
          <view v-for="i in 3" :key="i" class="rv__sk-bar" />
        </view>

        <ym-empty
          v-else-if="!currentCandidates.length"
          compact
          icon="user"
          text="没有可选学员"
          :desc="candidateTab === 'records' ? '近 7 天没有你授课的体验课/私教课' : '这个来源下暂时没有记录'"
        />

        <view
          v-for="c in currentCandidates"
          :key="`${c.source}-${c.bookingId || c.leadId || c.customerId}-${c.studentName}`"
          class="rv__cand ym-tap"
          @click="pickCandidate(c)"
        >
          <view class="rv__cand-main">
            <view class="rv__cand-head">
              <text class="rv__cand-name ym-ellipsis">{{ c.studentName || '未命名' }}</text>
              <ym-badge v-if="c.hasReview" text="已有分析" tone="muted" />
              <ym-badge v-else-if="c.isTrial" text="体验课" tone="gold" />
            </view>
            <text class="rv__cand-sub ym-ellipsis">
              {{ [c.courseName, c.classAt || c.date, c.venue].filter(Boolean).join(' · ') || c.demand || '—' }}
            </text>
            <text v-if="c.phoneTail" class="rv__cand-tail ym-num">手机尾号 {{ c.phoneTail }}</text>
          </view>
          <wd-icon name="chevron-right" size="14px" color="#8B9A92" />
        </view>
      </ym-section>
    </template>

    <!-- ===================== 只读详情 ===================== -->
    <template v-else-if="!formVisible">
      <view v-if="detailLoading && !detail" class="ym-card">
        <view v-for="i in 4" :key="i" class="rv__sk">
          <view class="rv__sk-bar" />
        </view>
      </view>

      <view v-else-if="detailError" class="ym-card">
        <ym-empty
          icon="warning"
          :text="detailForbidden ? '没有查看权限' : '详情加载失败'"
          :desc="detailError"
          action-text="返回列表"
          @action="backToList"
        />
      </view>

      <template v-else-if="detail">
        <!-- 头部 -->
        <view class="rv__head">
          <view class="rv__head-row">
            <text class="rv__name ym-ellipsis">{{ detail.studentName || '未命名会员' }}</text>
            <ym-badge :text="statusText" :tone="confirmed ? 'brand' : 'warn'" />
          </view>
          <view class="rv__tags">
            <ym-badge v-if="detail.redFlag" text="命中红线" tone="danger" />
            <ym-badge v-if="detail.studentType" :text="detail.studentType" tone="info" />
            <ym-badge :text="sceneText" tone="muted" />
          </view>
          <text class="rv__meta">{{ [detail.courseName, detail.classAt].filter(Boolean).join(' · ') || '—' }}</text>
          <text class="rv__meta">{{ [detail.teacherName, detail.venue].filter(Boolean).join(' · ') || '—' }}</text>
          <text v-if="detail.confirmedAt" class="rv__meta">确认时间：{{ detail.confirmedAt }}</text>
        </view>

        <!-- 操作 -->
        <view v-if="canWrite" class="rv__actions">
          <wd-button size="small" plain @click="openEdit">编辑</wd-button>
          <wd-button
            v-if="!confirmed"
            size="small"
            type="primary"
            :loading="confirming"
            @click="onConfirm"
          >
            确认
          </wd-button>
          <text v-else class="rv__done-tip">已确认 · 可继续编辑</text>
          <!-- 删除（F06）：接口一直有、按钮一直缺；放最后一位，避免误触 -->
          <wd-button
            size="small"
            type="error"
            plain
            :loading="removing"
            :disabled="removing"
            @click="onDelete"
          >
            删除
          </wd-button>
        </view>
        <view v-else class="rv__readonly">
          <text>服务老师只读：填写与确认由授课老师完成</text>
        </view>

        <!-- 各维度评分 -->
        <ym-section title="各维度观察" :desc="`轻 ${levelSummary.轻} · 中 ${levelSummary.中} · 重 ${levelSummary.重}`">
          <view v-if="observationRows.length" class="rv__obslist">
            <view v-for="o in observationRows" :key="o.key" class="rv__obsrow">
              <text class="rv__obsrow-label">{{ o.label }}</text>
              <ym-badge
                :text="o.level"
                :tone="o.level === '重' ? 'danger' : o.level === '轻' ? 'info' : 'warn'"
              />
            </view>
          </view>
          <ym-empty v-else compact text="未记录观察项" desc="编辑时可以补勾" />
        </ym-section>

        <!-- 主诉与学员反馈 -->
        <ym-section v-if="detail.goalText || feedbackRows.length" title="主诉与反馈">
          <view v-if="detail.goalText" class="rv__para">
            <text class="rv__para-key">学员主诉 / 目标</text>
            <text class="rv__para-val">{{ detail.goalText }}</text>
          </view>
          <view v-for="f in feedbackRows" :key="f.key" class="rv__para">
            <text class="rv__para-key">{{ f.label }}</text>
            <text class="rv__para-val">{{ f.text }}</text>
          </view>
        </ym-section>

        <!-- 红线 -->
        <view v-if="redFlagRows.length" class="rv__danger">
          <view class="rv__danger-head">
            <wd-icon name="warning" size="16px" color="#B23C33" />
            <text class="rv__danger-title">命中红线：不产出对客训练方案</text>
          </view>
          <view v-for="f in redFlagRows" :key="f.key" class="rv__danger-row">
            <text class="rv__danger-label">· {{ f.label }}</text>
            <text v-if="f.hint" class="rv__danger-hint">{{ f.hint }}</text>
          </view>
        </view>

        <!-- 结论：三阶段方向 -->
        <ym-section v-if="plan" title="训练方向（结论）" :desc="plan.frequency?.text || ''">
          <view v-for="(p, i) in plan.phases" :key="p.key || i" class="rv__phase">
            <view class="rv__phase-head">
              <text class="rv__phase-name">{{ p.name }}</text>
              <text class="rv__phase-dur">{{ p.duration }}</text>
            </view>
            <text class="rv__phase-goal">{{ p.goal }}</text>
            <view v-if="p.focus?.length" class="rv__chips">
              <text v-for="(x, xi) in p.focus" :key="xi" class="rv__chip">{{ x }}</text>
            </view>
            <text v-if="p.durationTimes" class="rv__phase-times">{{ p.durationTimes }}</text>
          </view>

          <view v-if="plan.courses?.length" class="rv__para">
            <text class="rv__para-key">建议课程</text>
            <text class="rv__para-val">{{ plan.courses.join(' / ') }}</text>
          </view>
          <view v-if="plan.homeWork?.length" class="rv__para">
            <text class="rv__para-key">回家作业</text>
            <view class="rv__bullets">
              <text v-for="(h, hi) in plan.homeWork" :key="hi" class="rv__bullet">· {{ h }}</text>
            </view>
          </view>
          <view v-if="plan.cautions?.length" class="rv__para">
            <text class="rv__para-key">注意事项</text>
            <view class="rv__bullets">
              <text v-for="(c, ci) in plan.cautions" :key="ci" class="rv__bullet">· {{ c }}</text>
            </view>
          </view>
        </ym-section>

        <!-- 红线记录没有 plan：给出后端 cautions / notes -->
        <ym-section v-else-if="redFlagCautions.length || redFlagNotes.length" title="处理建议">
          <view class="rv__bullets">
            <text v-for="(c, ci) in redFlagCautions" :key="`c${ci}`" class="rv__bullet">· {{ c }}</text>
            <text v-for="(n, ni) in redFlagNotes" :key="`n${ni}`" class="rv__bullet">· {{ n }}</text>
          </view>
        </ym-section>

        <!-- 对客表达 -->
        <ym-section v-if="scriptLines.length" title="对客表达" desc="可直接照着说">
          <view v-for="s in scriptLines" :key="s.key" class="rv__para">
            <view class="rv__para-head">
              <text class="rv__para-key">{{ s.label }}</text>
              <text class="rv__copy ym-tap" @click="copyText(s.text, '已复制')">复制</text>
            </view>
            <text class="rv__para-val">{{ s.text }}</text>
          </view>
        </ym-section>

        <!-- 客观信息 -->
        <ym-section title="记录信息">
          <view class="rv__kv">
            <text class="rv__kv-key">创建时间</text>
            <text class="rv__kv-val">{{ detail.createdAt || '—' }}</text>
          </view>
          <view class="rv__kv">
            <text class="rv__kv-key">创建人（老师）</text>
            <text class="rv__kv-val">{{ detail.teacherName || '—' }}</text>
          </view>
          <view class="rv__kv">
            <text class="rv__kv-key">门店</text>
            <text class="rv__kv-val">{{ detail.venue || '—' }}</text>
          </view>
          <view class="rv__kv">
            <text class="rv__kv-key">分享码</text>
            <text class="rv__kv-val">{{ detail.share?.code || '确认后生成' }}</text>
          </view>
        </ym-section>
      </template>

      <view v-else-if="!pageReady" class="ym-card">
        <view class="rv__sk"><view class="rv__sk-bar" /></view>
      </view>
    </template>

    <!-- ===================== 表单（新建第二步 / 编辑） ===================== -->
    <template v-else>
      <ym-section title="基本信息" desc="带 * 为必填">
        <wd-input v-model="form.studentName" label="学员姓名" placeholder="必填" />
        <wd-input v-model="form.studentPhone" label="手机号" placeholder="选填，用于关联会员" type="number" />
        <wd-picker v-model="form.venue" label="门店" :columns="['绿地店', '东部店']" />
        <wd-picker v-model="form.scene" label="场景" :columns="sceneColumns" value-key="value" label-key="label" />
        <wd-picker v-model="form.studentType" label="学员类型" :columns="studentTypeColumns" placeholder="未判定" />
        <wd-input v-model="form.classAt" label="上课时间" placeholder="2026-11-04 10:00" />
        <wd-input v-model="form.courseName" label="课程" placeholder="选填，如 核心床体验课" />
        <wd-textarea v-model="form.goalText" label="主诉 / 目标" :maxlength="255" placeholder="学员自己说的诉求，原话最好" />
      </ym-section>

      <ym-section title="观察项（评分维度）" :desc="`已选 ${form.observations.length} 项`">
        <view v-if="catalogError" class="rv__err">
          <text>{{ catalogError }}</text>
          <text class="rv__err-act" @click="loadCatalog">重试</text>
        </view>
        <view v-for="g in catalog?.groups ?? []" :key="g.group" class="rv__group">
          <view class="rv__group-head ym-tap" @click="toggleGroup(g.group)">
            <text class="rv__group-label">{{ g.label }}</text>
            <view class="rv__group-right">
              <text class="rv__group-count">{{ groupSelectedCount(g.group) }} 项</text>
              <wd-icon
                :name="openGroups.includes(g.group) ? 'chevron-up' : 'chevron-down'"
                size="12px"
                color="#8A948F"
              />
            </view>
          </view>
          <view v-if="openGroups.includes(g.group)" class="rv__group-body">
            <view v-for="it in g.items" :key="it.key" class="rv__obs">
              <view class="rv__obs-main ym-tap" @click="toggleObs(it.key)">
                <view class="rv__box" :class="{ 'is-on': isObs(it.key) }">
                  <text v-if="isObs(it.key)" class="rv__box-mark">✓</text>
                </view>
                <text class="rv__obs-label" :class="{ 'is-on': isObs(it.key) }">{{ it.label }}</text>
              </view>
              <view v-if="isObs(it.key)" class="rv__levels">
                <text
                  v-for="lv in REVIEW_LEVELS"
                  :key="lv"
                  class="rv__level ym-tap"
                  :class="{ 'is-on': levelOf(it.key) === lv }"
                  @click="setLevel(it.key, lv)"
                >
                  {{ lv }}
                </text>
              </view>
            </view>
          </view>
        </view>
      </ym-section>

      <ym-section title="红线" desc="命中则不产出对客方案，只给专业评估建议">
        <view v-for="f in catalog?.redFlags ?? []" :key="f.key" class="rv__flag ym-tap" @click="toggleRedFlag(f.key)">
          <view class="rv__box" :class="{ 'is-danger': form.redFlags.includes(f.key) }">
            <text v-if="form.redFlags.includes(f.key)" class="rv__box-mark">✓</text>
          </view>
          <view class="rv__flag-main">
            <text class="rv__flag-label">{{ f.label }}</text>
            <text v-if="f.hint" class="rv__flag-hint">{{ f.hint }}</text>
          </view>
        </view>
        <view v-if="form.redFlags.length" class="rv__danger-note">
          已勾选 {{ form.redFlags.length }} 项红线：保存后不会生成训练方案
        </view>
      </ym-section>

      <ym-section title="学员反馈" desc="选填，写原话更真实">
        <wd-input v-model="form.feedback.bodyFeel" label="身体感受" placeholder="如 肩颈松了、第二天不酸" />
        <wd-input v-model="form.feedback.like" label="喜欢的方向" placeholder="如 喜欢慢一点、喜欢器械" />
        <wd-input v-model="form.feedback.concern" label="担心的问题" placeholder="如 怕跟不上、怕花钱" />
      </ym-section>

      <view v-if="saveError" class="rv__err rv__err--block">
        <text>{{ saveError }}</text>
      </view>

      <view class="rv__submit">
        <wd-button type="primary" block :loading="saving" @click="submitForm">
          {{ mode === 'create' ? '保存课后分析' : '保存修改' }}
        </wd-button>
        <text class="rv__submit-tip">保存后由规则库生成三阶段训练方向；确认后才生成对客分享码</text>
        <text v-if="formVisible" class="rv__cancel" @click="formVisible = false">取消</text>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.rv {
  padding-top: 20rpx;

  /* ---------- 步骤条 ---------- */
  &__stepbar {
    display: flex;
    align-items: center;
    gap: 12rpx;
    padding: 0 28rpx 8rpx;
  }

  &__step {
    display: flex;
    gap: 8rpx;
    align-items: center;
    font-size: 24rpx;
    color: $ink-400;

    &.is-on {
      color: $brand-600;
      font-weight: 600;
    }
  }

  &__step-no {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36rpx;
    height: 36rpx;
    font-size: 20rpx;
    color: $white;
    background: $ink-300;
    border-radius: 50%;
  }

  &__step.is-on &__step-no {
    background: $brand-500;
  }

  &__stepline {
    flex: 1;
    height: 2rpx;
    background: $hairline;
  }

  /* ---------- 候选 ---------- */
  &__ctabs {
    display: flex;
    gap: 12rpx;
  }

  &__ctab {
    flex: 1;
    min-height: 72rpx;
    font-size: 24rpx;
    line-height: 72rpx;
    color: $ink-500;
    text-align: center;
    background: $ink-50;
    border-radius: 999rpx;

    &.is-active {
      color: $brand-600;
      background: $brand-100;
      font-weight: 600;
    }
  }

  &__search {
    margin-top: 16rpx;
    background: $ink-50;
    border-radius: $r-md;
  }

  &__cand {
    display: flex;
    gap: 16rpx;
    align-items: center;
    min-height: 112rpx;
    padding: 18rpx 0;
    border-bottom: 2rpx solid $hairline;

    &:last-child {
      border-bottom: none;
    }
  }

  &__cand-main {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 6rpx;
    min-width: 0;
  }

  &__cand-head {
    display: flex;
    gap: 10rpx;
    align-items: center;
    min-width: 0;
  }

  &__cand-name {
    max-width: 60%;
    font-size: 30rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__cand-sub {
    font-size: 23rpx;
    color: $ink-500;
  }

  &__cand-tail {
    font-size: 22rpx;
    color: $ink-400;
  }

  /* ---------- 详情头 ---------- */
  &__head {
    padding: 26rpx 28rpx;
    margin: 0 24rpx;
    background: linear-gradient(150deg, $brand-50, $white 62%);
    border: 2rpx solid $brand-100;
    border-radius: $r-lg;
  }

  &__head-row {
    display: flex;
    gap: 14rpx;
    align-items: center;
    min-width: 0;
  }

  &__name {
    flex: 1;
    min-width: 0;
    font-size: 40rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 10rpx;
    margin-top: 14rpx;
  }

  &__meta {
    display: block;
    margin-top: 10rpx;
    font-size: 24rpx;
    color: $ink-500;
  }

  /* ---------- 操作 ---------- */
  &__actions {
    display: flex;
    /* 三个按钮（编辑 / 确认 / 删除）在 375px 下会挤，允许换行 */
    flex-wrap: wrap;
    gap: 16rpx;
    align-items: center;
    padding: 20rpx 28rpx 0;
  }

  &__done-tip {
    font-size: 22rpx;
    color: $ink-400;
  }

  &__readonly {
    padding: 18rpx 28rpx 0;
    font-size: 22rpx;
    color: $ink-400;
  }

  /* ---------- 观察项列表（只读） ---------- */
  &__obslist {
    display: flex;
    flex-direction: column;
  }

  &__obsrow {
    display: flex;
    gap: 16rpx;
    align-items: center;
    justify-content: space-between;
    min-height: 72rpx;
    padding: 6rpx 0;
    border-bottom: 2rpx solid $hairline;

    &:last-child {
      border-bottom: none;
    }
  }

  &__obsrow-label {
    flex: 1;
    min-width: 0;
    font-size: 26rpx;
    color: $ink-700;
  }

  /* ---------- 段落 ---------- */
  &__para {
    padding: 12rpx 0;
  }

  &__para-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  &__para-key {
    display: block;
    font-size: 23rpx;
    color: $ink-400;
  }

  &__para-val {
    display: block;
    margin-top: 6rpx;
    font-size: 27rpx;
    line-height: 1.7;
    color: $ink-800;
    white-space: pre-wrap;
    word-break: break-word;
  }

  &__copy {
    padding: 8rpx 18rpx;
    font-size: 22rpx;
    color: $brand-600;
    background: $brand-50;
    border-radius: 999rpx;
  }

  &__bullets {
    display: flex;
    flex-direction: column;
    gap: 6rpx;
    margin-top: 6rpx;
  }

  &__bullet {
    font-size: 25rpx;
    line-height: 1.7;
    color: $ink-700;
  }

  &__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8rpx;
    margin-top: 10rpx;
  }

  &__chip {
    padding: 4rpx 14rpx;
    font-size: 22rpx;
    color: $ink-600;
    background: $ink-50;
    border-radius: 6rpx;
  }

  /* ---------- 阶段 ---------- */
  &__phase {
    padding: 18rpx 20rpx;
    margin-bottom: 16rpx;
    background: $brand-50;
    border-radius: $r-md;

    &:last-of-type {
      margin-bottom: 8rpx;
    }
  }

  &__phase-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12rpx;
  }

  &__phase-name {
    font-size: 27rpx;
    font-weight: 600;
    color: $brand-700;
  }

  &__phase-dur {
    flex-shrink: 0;
    font-size: 22rpx;
    color: $ink-400;
  }

  &__phase-goal {
    display: block;
    margin-top: 8rpx;
    font-size: 25rpx;
    line-height: 1.65;
    color: $ink-700;
  }

  &__phase-times {
    display: block;
    margin-top: 8rpx;
    font-size: 22rpx;
    color: $ink-400;
  }

  /* ---------- 红线 ---------- */
  &__danger {
    padding: 24rpx 28rpx;
    margin: 20rpx 24rpx;
    background: $danger-100;
    border-radius: $r-lg;
  }

  &__danger-head {
    display: flex;
    gap: 8rpx;
    align-items: center;
  }

  &__danger-title {
    font-size: 26rpx;
    font-weight: 600;
    color: $danger-600;
  }

  &__danger-row {
    margin-top: 10rpx;
  }

  &__danger-label {
    display: block;
    font-size: 24rpx;
    color: $ink-700;
  }

  &__danger-hint {
    display: block;
    font-size: 22rpx;
    color: $ink-500;
  }

  &__danger-note {
    padding: 14rpx 18rpx;
    margin-top: 12rpx;
    font-size: 22rpx;
    color: $danger-600;
    background: $danger-100;
    border-radius: $r-md;
  }

  /* ---------- 记录信息 ---------- */
  &__kv {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 72rpx;
    border-bottom: 2rpx solid $hairline;

    &:last-child {
      border-bottom: none;
    }
  }

  &__kv-key {
    font-size: 24rpx;
    color: $ink-400;
  }

  &__kv-val {
    font-size: 25rpx;
    color: $ink-700;
  }

  /* ---------- 表单：观察项 ---------- */
  &__group {
    border-bottom: 2rpx solid $hairline;

    &:last-child {
      border-bottom: none;
    }
  }

  &__group-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 88rpx;
  }

  &__group-label {
    font-size: 27rpx;
    font-weight: 600;
    color: $ink-700;
  }

  &__group-right {
    display: flex;
    gap: 8rpx;
    align-items: center;
  }

  &__group-count {
    font-size: 22rpx;
    color: $ink-400;
  }

  &__group-body {
    padding-bottom: 12rpx;
  }

  &__obs {
    display: flex;
    gap: 16rpx;
    align-items: center;
    justify-content: space-between;
    min-height: 88rpx;
    padding: 6rpx 0;
  }

  &__obs-main {
    display: flex;
    flex: 1;
    gap: 14rpx;
    align-items: center;
    min-width: 0;
  }

  &__obs-label {
    flex: 1;
    min-width: 0;
    font-size: 25rpx;
    color: $ink-700;

    &.is-on {
      color: $brand-700;
      font-weight: 600;
    }
  }

  &__box {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 40rpx;
    height: 40rpx;
    background: $white;
    border: 2rpx solid $ink-200;
    border-radius: 8rpx;

    &.is-on {
      background: $brand-500;
      border-color: $brand-500;
    }

    &.is-danger {
      background: $danger-500;
      border-color: $danger-500;
    }
  }

  &__box-mark {
    font-size: 24rpx;
    line-height: 1;
    color: $white;
  }

  &__levels {
    display: flex;
    flex-shrink: 0;
    gap: 6rpx;
  }

  &__level {
    min-width: 56rpx;
    min-height: 56rpx;
    font-size: 22rpx;
    line-height: 56rpx;
    color: $ink-500;
    text-align: center;
    background: $ink-50;
    border-radius: 8rpx;

    &.is-on {
      color: $white;
      background: $brand-500;
    }
  }

  /* ---------- 表单：红线 ---------- */
  &__flag {
    display: flex;
    gap: 14rpx;
    align-items: flex-start;
    min-height: 88rpx;
    padding: 14rpx 0;
    border-bottom: 2rpx solid $hairline;

    &:last-of-type {
      border-bottom: none;
    }
  }

  &__flag-main {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 4rpx;
    min-width: 0;
  }

  &__flag-label {
    font-size: 25rpx;
    color: $ink-700;
  }

  &__flag-hint {
    font-size: 22rpx;
    color: $ink-400;
  }

  /* ---------- 错误 / 提交 ---------- */
  &__err {
    display: flex;
    gap: 12rpx;
    align-items: center;
    justify-content: space-between;
    padding: 16rpx 18rpx;
    margin: 12rpx 0;
    font-size: 23rpx;
    color: $danger-600;
    background: $danger-100;
    border-radius: $r-md;

    &--block {
      margin: 20rpx 24rpx 0;
    }
  }

  &__err-act {
    flex-shrink: 0;
    color: $brand-600;
  }

  &__submit {
    display: flex;
    flex-direction: column;
    gap: 12rpx;
    padding: 8rpx 28rpx calc(40rpx + env(safe-area-inset-bottom));
  }

  &__submit-tip {
    font-size: 22rpx;
    line-height: 1.6;
    color: $ink-400;
    text-align: center;
  }

  &__cancel {
    padding: 12rpx 0;
    font-size: 24rpx;
    color: $ink-400;
    text-align: center;
  }

  /* ---------- 骨架 ---------- */
  &__sk {
    padding: 16rpx 0;
  }

  &__sk-bar {
    height: 24rpx;
    background: $ink-50;
    border-radius: 999rpx;
  }
}
</style>
