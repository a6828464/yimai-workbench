<script setup lang="ts">
/**
 * 训练计划详情 / 新建 / 编辑（授课老师端）
 *
 * 关键约束：后端**没有** GET /training-plans/{id}（只有列表裸数组），
 * 所以详情数据来自列表页写入的本地草稿（savePlanDraft）；草稿缺失时兜底「回查一次列表」，
 * 冷启动/清缓存后也不至于白屏。
 *
 * 写：POST /training-plans（body 必须是 {plan:{...}} → {clientId, serverId}）、
 *     PUT /training-plans/{id}（不可见会 404「计划不存在或不在你的可见范围内」）。
 * 字段白名单见 TrainingPlanController::attributes()：memberName / profile(age,gender,height,
 * weight,bodyFat,focus) / goal(coreGoal,freq,stageWeeks,stageGoal,risks) / content / status …
 */
import { computed, reactive, ref } from 'vue'
import { onLoad, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { useUserStore } from '@/store/user'
import { requireFeature } from '@/utils/nav'
import { isManagerOrAbove } from '@/utils/permission'
import {
  createTrainingPlan,
  deleteTrainingPlan,
  emptyPlan,
  fetchTrainingPlans,
  movePlanDraft,
  normalizePlan,
  readPlanDraft,
  removePlanDraft,
  savePlanDraft,
  updateTrainingPlan,
  type TrainingPlan
} from '@/api/review'

const STATUS_OPTIONS = ['待生成', '待老师确认', '已确认']
const GENDER_OPTIONS = ['女', '男']
const MAX_PHASES = 8

const user = useUserStore()

const mode = ref<'view' | 'create'>('view')
const planId = ref(0)
const plan = ref<TrainingPlan | null>(null)
const loading = ref(false)
const loaded = ref(false)
const errorText = ref('')

const editing = ref(false)
const saving = ref(false)
const saveError = ref('')

const pageReady = ref(false)

/* ==========================================================================
 * 加载
 * ========================================================================== */

/** 内容不是最新时的一句话提示（顶部细条，不弹窗打断） */
const staleTip = ref('')

/**
 * 草稿 vs 服务端行是否一致。
 *
 * 为什么不用 updatedAt 比对：后端 `TrainingPlanController::present()` 只回
 * `id + payload + sourceReviewId/sourceBodyTestId/venue`，**没有 updatedAt/createdAt 列**，
 * 想按时间戳判新旧都无从下手。两边都过同一个 normalizePlan()（字段集合与顺序固定），
 * 所以直接比整行 JSON 就是最可靠的「内容指纹」——任何字段被电脑端改过都会不同。
 * 若将来后端补了 updatedAt，这里可以简化成时间戳比较。
 */
function sameAsServer(draftPlan: TrainingPlan, serverPlan: TrainingPlan): boolean {
  return JSON.stringify(normalizePlan(draftPlan)) === JSON.stringify(normalizePlan(serverPlan))
}

/**
 * 详情数据：草稿秒开 + 服务端校准（后端没有 show 接口，只能靠列表裸数组）。
 *
 * 修 F19 前的行为是「草稿命中就直接 return」：电脑端改过、甚至计划已被删，
 * 手机还照样展示旧快照且毫无提示，用户会照着旧计划上课。
 * 现在按两级信任处理：
 *  - 草稿新鲜（<30min）→ 先渲染让人秒开，**仍然**回查列表做内容比对；
 *  - 草稿过期 / 老格式 → 不信任，直接以服务端为准并提示。
 */
async function loadPlan() {
  if (!planId.value) return
  loading.value = true
  errorText.value = ''
  staleTip.value = ''
  try {
    const draft = readPlanDraft(planId.value)
    // ① 新鲜草稿先上屏：详情页是高频入口，等一次列表请求会白屏一两秒
    if (draft && !draft.stale) {
      plan.value = draft.plan
      loaded.value = true
    }

    // ② 无论如何回查一次列表：草稿过期/缺失时这是唯一数据源；草稿新鲜时它是「有没有被改过」的裁判
    const rows = await fetchTrainingPlans()
    const hit = rows.find((p) => p.id === planId.value) ?? null

    if (!hit) {
      // 列表里没有：被删了，或已不在可见范围。绝不能拿草稿继续编辑（后端会 404）
      plan.value = null
      loaded.value = true
      errorText.value = '计划不存在或不在你的可见范围内'
      return
    }

    if (!draft) {
      // 老兜底：草稿丢了（换设备/清缓存），直接用服务端行
      plan.value = hit
      loaded.value = true
      return
    }

    if (draft.stale) {
      plan.value = hit
      loaded.value = true
      staleTip.value = '本页缓存已超过 30 分钟，已按服务端数据载入'
      savePlanDraft(hit) // 顺手刷新草稿，下次进来不必再校准
      return
    }

    // ③ 草稿新鲜但内容与服务端不一致 → 电脑端改过，以服务端为准并明确告知
    if (!sameAsServer(draft.plan, hit)) {
      plan.value = hit
      staleTip.value = '本页内容可能不是最新，已按服务端数据载入'
      savePlanDraft(hit)
    }
  } catch (e) {
    // 网络失败时如果草稿已经上屏，保留内容只提示网络，不要把人踢到错误页
    if (plan.value) {
      staleTip.value = '网络异常，本页显示的是本机缓存，可能不是最新'
    } else {
      errorText.value = e instanceof Error ? e.message : '加载失败'
    }
  } finally {
    loading.value = false
    pageReady.value = true
  }
}

/* ==========================================================================
 * 表单
 * ========================================================================== */

interface PhaseForm {
  name: string
  duration: string
  itemsText: string
}

interface PlanForm {
  memberName: string
  gender: string
  age: string
  height: string
  weight: string
  bodyFat: string
  focus: string
  coreGoal: string
  freq: string
  stageWeeks: string
  stageGoal: string
  risks: string
  status: string
  summary: string
  cautionsText: string
  phases: PhaseForm[]
}

function emptyForm(): PlanForm {
  return {
    memberName: '',
    gender: '女',
    age: '',
    height: '',
    weight: '',
    bodyFat: '',
    focus: '',
    coreGoal: '',
    freq: '',
    stageWeeks: '',
    stageGoal: '',
    risks: '',
    status: '待生成',
    summary: '',
    cautionsText: '',
    phases: [{ name: '', duration: '', itemsText: '' }]
  }
}

const form = reactive<PlanForm>(emptyForm())

function fillForm(p: TrainingPlan) {
  Object.assign(form, emptyForm())
  form.memberName = p.memberName === '未命名' ? '' : p.memberName
  form.gender = p.gender || '女'
  form.age = p.age
  form.height = p.height
  form.weight = p.weight
  form.bodyFat = p.bodyFat
  form.focus = p.focus
  form.coreGoal = p.coreGoal
  form.freq = p.freq
  form.stageWeeks = p.stageWeeks
  form.stageGoal = p.stageGoal
  form.risks = p.risks
  form.status = p.status || '待生成'
  form.summary = p.content?.summary ?? ''
  form.cautionsText = (p.content?.cautions ?? []).join('\n')
  const phases = p.content?.phases ?? []
  form.phases = phases.length
    ? phases.map((x) => ({ name: x.name, duration: x.duration, itemsText: x.items.join('\n') }))
    : [{ name: '', duration: '', itemsText: '' }]
}

function splitLines(text: string): string[] {
  return (text ?? '')
    .split('\n')
    .map((x) => x.trim())
    .filter(Boolean)
}

function nowText(): string {
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

/** 表单 → 提交模型（保留服务端维护的 id/share/来源等字段） */
function buildPlan(): TrainingPlan {
  const base = plan.value ?? emptyPlan()
  return normalizePlan({
    ...base,
    memberName: form.memberName.trim() || '未命名',
    gender: form.gender,
    age: form.age.trim(),
    height: form.height.trim(),
    weight: form.weight.trim(),
    bodyFat: form.bodyFat.trim(),
    focus: form.focus.trim(),
    coreGoal: form.coreGoal.trim(),
    freq: form.freq.trim(),
    stageWeeks: form.stageWeeks.trim(),
    stageGoal: form.stageGoal.trim(),
    risks: form.risks.trim(),
    status: form.status,
    content: {
      summary: form.summary.trim(),
      phases: form.phases
        .map((p) => ({ name: p.name.trim(), duration: p.duration.trim(), items: splitLines(p.itemsText) }))
        // 全空的阶段不发（后端会原样落库，空阶段在电脑端显示为一块空卡）
        .filter((p) => p.name || p.duration || p.items.length),
      cautions: splitLines(form.cautionsText)
    },
    createdBy: base.createdBy || user.staffName || '',
    createdAt: base.createdAt || nowText(),
    confirmedAt: form.status === '已确认' ? base.confirmedAt || nowText() : ''
  })
}

function addPhase() {
  if (form.phases.length >= MAX_PHASES) {
    uni.showToast({ title: `最多 ${MAX_PHASES} 个阶段`, icon: 'none' })
    return
  }
  form.phases.push({ name: '', duration: '', itemsText: '' })
}

function removePhase(i: number) {
  if (form.phases.length <= 1) {
    uni.showToast({ title: '至少保留一个阶段', icon: 'none' })
    return
  }
  form.phases.splice(i, 1)
}

function validate(): string {
  if (!form.memberName.trim()) return '请填写会员姓名'
  if (form.stageWeeks.trim() && !/^\d+(\.\d+)?$/.test(form.stageWeeks.trim())) {
    return '周期（周）请填数字'
  }
  return ''
}

async function submit() {
  if (saving.value) return
  const msg = validate()
  if (msg) {
    uni.showToast({ title: msg, icon: 'none' })
    return
  }
  saving.value = true
  saveError.value = ''
  try {
    const payload = buildPlan()
    if (mode.value === 'create') {
      const res = await createTrainingPlan(payload)
      const serverId = Number(res?.serverId) || 0
      if (serverId) {
        // 草稿迁到服务端 id 上：回到列表后再点进来，详情页拿的是真 id
        movePlanDraft(payload.id, { ...payload, id: serverId })
      }
      uni.showToast({ title: '已保存', icon: 'none' })
      // 成功后回列表（列表 onShow 重新拉取，新建的那条立刻可见）
      backToList()
      return
    }
    if (!payload.id) {
      saveError.value = '缺少计划 id，无法保存'
      return
    }
    await updateTrainingPlan(payload.id, payload)
    uni.showToast({ title: '已保存', icon: 'none' })
    plan.value = payload
    editing.value = false
  } catch (e) {
    saveError.value = e instanceof Error ? e.message : '保存失败，请稍后重试'
  } finally {
    saving.value = false
  }
}

/* ==========================================================================
 * 交互
 * ========================================================================== */

const canEdit = computed(() => {
  if (mode.value === 'create') return true
  const p = plan.value
  if (!p) return false
  if (isManagerOrAbove(user.roles)) return true
  if (!p.createdBy) return true
  return p.createdBy === (user.staffName || user.userName)
})

/**
 * 进入编辑。
 *
 * 与删除同一类死分支（R1 只点了删除那处，这里是同构的第二份）：
 * 模板里编辑按钮是 `v-if="canEdit"`，所以原来那段「非本人创建就提示」的判断永远不可达，
 * 已一并删除；而且 canEdit 对店长恒真，那句提示本身也不成立（真正的限制是可见范围）。
 * 留着会让下次有人照着它去别处复制「只认创建者」的错误判据。
 */
function openEdit() {
  if (!plan.value) return
  fillForm(plan.value)
  saveError.value = ''
  editing.value = true
}

function cancelEdit() {
  editing.value = false
  saveError.value = ''
}

/** 返回列表（模板里不能直接用 uni：那不是 setup 作用域的标识符） */
function backToList() {
  const pages = getCurrentPages()
  if (pages.length > 1) uni.navigateBack({ delta: 1 })
  else uni.redirectTo({ url: '/pkg-biz/plan/plan' })
}

/* ==========================================================================
 * 删除（F06 + X-01）
 *
 * ## 为什么必须走 DELETE /training-plans/{id}，不能走 PUT /training-plans/bulk
 * `bulkSave` 的显式删除分支是：
 *     TrainingPlan::where('created_by', $name)->whereIn('id', $deletedIds)->delete();
 * 其中 `$name = $r->user()->name`（**当前操作人姓名**）。也就是说批量删除只能删
 * 「created_by 恰好等于自己名字」的行 —— 店长删老师创建的计划时匹配 0 行，
 * 接口仍然 200，前端弹「已删除」，一刷新计划又回来了（cross-verify 的 X-01）。
 * 而 `destroy` 用的是 `canRead()`：超管全通、店长按门店、其他人按本人 —— 语义是
 * 「可见即可删」，才是这条按钮该有的权限。
 *
 * UI 用 canEdit 控制删除按钮的显隐（管理岗恒真、其余只认本人创建），与 canRead 基本同口径；
 * 函数内**不再重复判权限** —— 那是死分支，还会写出与后端语义矛盾的文案（见 onDelete）。
 * 越权/不可见仍由后端 destroy 兜底：404「计划不存在或不在你的可见范围内」。
 * ========================================================================== */

const removing = ref(false)

/**
 * 打开删除确认。
 *
 * 这里**故意不判 canEdit**（R1 复核指出的死分支）：
 *  1. 删除按钮本身是 `v-if="canEdit"`，进到本函数时 canEdit 必为真，
 *     原来那段「非本人创建就 toast 一句」的判断永远不可达，已整段删除；
 *  2. 即使可达，那句文案也是错的：canEdit 对店长恒真，而后端 destroy 用的是 canRead
 *     （超管全通 / 店长按门店 / 其余按本人创建）—— 真正的限制是**可见范围**，不是"谁创建的"。
 *     留着它会让人以为删除受创建者限制，把它当成权限依据去改别处。
 */
function onDelete() {
  // create 模式是本地空计划（没有服务端 id），模板里不渲染删除按钮，这里只防直接调用
  if (!plan.value || mode.value === 'create') return
  uni.showModal({
    title: '删除训练计划',
    content: `确定删除「${plan.value.memberName || '这份计划'}」？删除后不可恢复。`,
    confirmText: '删除',
    confirmColor: '#B23C33', // = $danger-500，uni.showModal 只吃字面量
    success: (res) => {
      if (res.confirm) void doDelete()
    }
  })
}

async function doDelete() {
  if (removing.value) return
  removing.value = true
  try {
    await deleteTrainingPlan(planId.value)
    // 草稿必须一起清：否则以后主键复用时，详情页会读到一份「已删计划」的快照
    removePlanDraft(planId.value)
    uni.showToast({ title: '已删除', icon: 'none' })
    // 只退出详情；列表页 onShow 会重新拉取，被删的那条自然消失
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

function statusTone(s?: string): 'brand' | 'gold' | 'warn' | 'muted' {
  if (!s) return 'muted'
  if (s.includes('已确认')) return 'brand'
  if (s.includes('待老师确认')) return 'gold'
  if (s.includes('待')) return 'warn'
  return 'muted'
}

function cycleText(p: TrainingPlan): string {
  const weeks = (p.stageWeeks || '').replace(/[^0-9.]/g, '')
  return weeks ? `${weeks} 周` : '—'
}

function bodyLine(p: TrainingPlan): string {
  return [
    p.gender,
    p.age ? `${p.age} 岁` : '',
    p.height ? `${p.height} cm` : '',
    p.weight ? `${p.weight} kg` : '',
    p.bodyFat ? `体脂 ${p.bodyFat}%` : ''
  ]
    .filter(Boolean)
    .join(' · ')
}

/* ==========================================================================
 * 初始化
 * ========================================================================== */

onLoad((query?: Record<string, string | undefined>) => {
  const q = query ?? {}
  mode.value = q.mode === 'create' ? 'create' : 'view'
  planId.value = Number(q.id) || 0
  if (!requireFeature('plans')) return

  if (mode.value === 'create') {
    uni.setNavigationBarTitle({ title: '新建训练计划' })
    plan.value = emptyPlan()
    fillForm(plan.value)
    pageReady.value = true
    return
  }
  if (!planId.value) {
    errorText.value = '缺少计划 id'
    pageReady.value = true
    return
  }
  void loadPlan()
})

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="ym-page pd">
    <!-- ============ 只读详情 ============ -->
    <template v-if="!editing">
      <view v-if="loading && !plan" class="ym-card">
        <view v-for="i in 4" :key="i" class="pd__sk">
          <view class="pd__sk-bar" />
        </view>
      </view>

      <view v-else-if="errorText && !plan" class="ym-card">
        <ym-empty
          icon="warning"
          text="计划打不开"
          :desc="errorText"
          action-text="返回列表"
          @action="backToList"
        />
      </view>

      <template v-else-if="plan">
        <!-- 草稿与服务端不一致 / 缓存过期：明确告知，别让人照着旧计划上课 -->
        <view v-if="staleTip" class="pd__stale">
          <text class="pd__stale-text ym-ellipsis">{{ staleTip }}</text>
        </view>

        <view class="pd__head">
          <view class="pd__head-row">
            <text class="pd__name ym-ellipsis">{{ plan.memberName }}</text>
            <ym-badge :text="plan.status" :tone="statusTone(plan.status)" />
          </view>
          <text v-if="bodyLine(plan)" class="pd__meta">{{ bodyLine(plan) }}</text>
          <view class="pd__tags">
            <ym-badge v-if="plan.sourceReviewId" text="课后分析流转" tone="info" />
            <!-- 微信深度合成整改：AI 生成的计划必须显著标识（2026-11-07） -->
            <ym-badge v-if="plan.source === 'llm'" text="内容由人工智能（AI）生成" tone="brand" />
            <ym-badge v-if="plan.venue" :text="plan.venue" tone="muted" />
          </view>
        </view>

        <view class="pd__actions">
          <wd-button v-if="canEdit" size="small" plain @click="openEdit">编辑</wd-button>
          <text v-else class="pd__readonly">这条计划由 {{ plan.createdBy || '他人' }} 创建，你只能查看</text>
          <!-- 删除（F06）：走 DELETE /training-plans/{id}，理由见 script 的 onDelete 注释（X-01） -->
          <wd-button
            v-if="canEdit"
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

        <ym-section title="训练目标">
          <view class="pd__kv">
            <text class="pd__kv-key">核心目标</text>
            <text class="pd__kv-val">{{ plan.coreGoal || '—' }}</text>
          </view>
          <view class="pd__kv">
            <text class="pd__kv-key">建议频次</text>
            <text class="pd__kv-val">{{ plan.freq || '—' }}</text>
          </view>
          <view class="pd__kv">
            <text class="pd__kv-key">周期</text>
            <text class="pd__kv-val">{{ cycleText(plan) }}</text>
          </view>
          <view class="pd__kv">
            <text class="pd__kv-key">关注要点</text>
            <text class="pd__kv-val">{{ plan.focus || '—' }}</text>
          </view>
          <view class="pd__kv">
            <text class="pd__kv-key">阶段目标</text>
            <text class="pd__kv-val">{{ plan.stageGoal || '—' }}</text>
          </view>
          <view v-if="plan.risks" class="pd__kv pd__kv--stack">
            <text class="pd__kv-key">风险提示</text>
            <text class="pd__kv-val pd__kv-val--warn">{{ plan.risks }}</text>
          </view>
        </ym-section>

        <ym-section v-if="plan.content?.summary" title="计划说明">
          <text class="pd__para">{{ plan.content?.summary }}</text>
        </ym-section>

        <ym-section title="阶段与动作" :desc="`${plan.content?.phases?.length ?? 0} 个阶段`">
          <view
            v-for="(ph, i) in plan.content?.phases ?? []"
            :key="i"
            class="pd__phase"
          >
            <view class="pd__phase-head">
              <text class="pd__phase-name">{{ ph.name || `第 ${i + 1} 阶段` }}</text>
              <text v-if="ph.duration" class="pd__phase-dur">{{ ph.duration }}</text>
            </view>
            <view v-if="ph.items?.length" class="pd__bullets">
              <text v-for="(it, ii) in ph.items" :key="ii" class="pd__bullet">· {{ it }}</text>
            </view>
          </view>
          <ym-empty
            v-if="!(plan.content?.phases?.length)"
            compact
            text="还没有编排阶段"
            desc="点「编辑」补充阶段与动作"
          />
        </ym-section>

        <ym-section v-if="plan.content?.cautions?.length" title="注意事项">
          <view class="pd__bullets">
            <text v-for="(c, i) in plan.content?.cautions ?? []" :key="i" class="pd__bullet">· {{ c }}</text>
          </view>
        </ym-section>

        <ym-section title="记录信息">
          <view class="pd__kv">
            <text class="pd__kv-key">创建人</text>
            <text class="pd__kv-val">{{ plan.createdBy || '—' }}</text>
          </view>
          <view class="pd__kv">
            <text class="pd__kv-key">创建时间</text>
            <text class="pd__kv-val">{{ plan.createdAt || '—' }}</text>
          </view>
          <view class="pd__kv">
            <text class="pd__kv-key">确认时间</text>
            <text class="pd__kv-val">{{ plan.confirmedAt || '未确认' }}</text>
          </view>
          <view class="pd__kv">
            <text class="pd__kv-key">门店</text>
            <text class="pd__kv-val">{{ plan.venue || '—' }}</text>
          </view>
        </ym-section>
      </template>

      <view v-else-if="!pageReady" class="ym-card">
        <view class="pd__sk"><view class="pd__sk-bar" /></view>
      </view>
    </template>

    <!-- ============ 表单（新建 / 编辑） ============ -->
    <template v-else>
      <ym-section title="会员与身体数据" desc="姓名必填，其余选填">
        <wd-input v-model="form.memberName" label="会员姓名" placeholder="必填" />
        <wd-picker v-model="form.gender" label="性别" :columns="GENDER_OPTIONS" />
        <wd-input v-model="form.age" label="年龄" type="number" placeholder="如 32" />
        <wd-input v-model="form.height" label="身高(cm)" type="digit" placeholder="如 163" />
        <wd-input v-model="form.weight" label="体重(kg)" type="digit" placeholder="如 55" />
        <wd-input v-model="form.bodyFat" label="体脂率(%)" type="digit" placeholder="如 26" />
      </ym-section>

      <ym-section title="训练目标">
        <wd-input v-model="form.coreGoal" label="核心目标" placeholder="如 改善体态、建立核心力量" />
        <wd-input v-model="form.freq" label="建议频次" placeholder="如 每周 2 次小班" />
        <wd-input v-model="form.stageWeeks" label="周期(周)" type="number" placeholder="如 8" />
        <wd-input v-model="form.stageGoal" label="阶段目标" placeholder="如 掌握呼吸发力模式" />
        <wd-input v-model="form.focus" label="关注要点" placeholder="如 肩颈紧张、核心无力" />
        <wd-textarea v-model="form.risks" label="风险提示" :maxlength="200" placeholder="如 腰部不适，避免后弯过深" />
        <wd-picker v-model="form.status" label="状态" :columns="STATUS_OPTIONS" />
      </ym-section>

      <ym-section title="计划说明">
        <wd-textarea
          v-model="form.summary"
          label="一句话说明"
          :maxlength="300"
          placeholder="如 以呼吸重建与核心激活为主线的 8 周入门计划"
        />
      </ym-section>

      <ym-section title="阶段与动作" :desc="`${form.phases.length} 个阶段 · 动作每行一条`">
        <view v-for="(ph, i) in form.phases" :key="i" class="pd__phase-edit">
          <view class="pd__phase-edit-head">
            <text class="pd__phase-edit-no">第 {{ i + 1 }} 阶段</text>
            <text class="pd__phase-edit-del ym-tap" @click="removePhase(i)">删除</text>
          </view>
          <wd-input v-model="ph.name" label="阶段名" placeholder="如 第1-2周 基础建立" />
          <wd-input v-model="ph.duration" label="时长" placeholder="如 2周" />
          <wd-textarea v-model="ph.itemsText" label="动作/内容" :maxlength="500" placeholder="每行一条，如：&#10;腹式呼吸与肋间呼吸&#10;仰卧中立位找发力" />
        </view>
        <view class="pd__add ym-tap" @click="addPhase">
          <wd-icon name="add" size="14px" color="#2E7D5B" />
          <text>添加阶段</text>
        </view>
      </ym-section>

      <ym-section title="注意事项" desc="每行一条">
        <wd-textarea
          v-model="form.cautionsText"
          label="注意事项"
          :maxlength="500"
          placeholder="如：&#10;练习中出现手麻头晕立即停止&#10;生理期前三天降低强度"
        />
      </ym-section>

      <view v-if="saveError" class="pd__err">
        <text>{{ saveError }}</text>
      </view>

      <view class="pd__submit">
        <wd-button type="primary" block :loading="saving" @click="submit">
          {{ mode === 'create' ? '保存训练计划' : '保存修改' }}
        </wd-button>
        <text v-if="mode !== 'create'" class="pd__cancel" @click="cancelEdit">取消</text>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.pd {
  padding-top: 20rpx;

  /* ---------- 头部 ---------- */
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

  &__meta {
    display: block;
    margin-top: 10rpx;
    font-size: 24rpx;
    color: $ink-500;
  }

  &__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 10rpx;
    margin-top: 12rpx;
  }

  &__actions {
    display: flex;
    /* 编辑 + 删除在 375px 下会挤到一行末尾，允许换行 */
    flex-wrap: wrap;
    align-items: center;
    gap: 16rpx;
    padding: 20rpx 28rpx 0;
  }

  /* 草稿不是最新时的提示条（F19）：用告警色细条，不抢主内容 */
  &__stale {
    display: flex;
    align-items: center;
    padding: 14rpx 28rpx;
    margin: 16rpx 24rpx 0;
    background: rgba(193, 154, 75, 0.12);
    border-radius: 12rpx;
  }

  &__stale-text {
    flex: 1;
    min-width: 0;
    font-size: 22rpx;
    line-height: 1.5;
    color: $gold-600;
  }

  &__readonly {
    font-size: 22rpx;
    color: $ink-400;
  }

  /* ---------- 信息行 ---------- */
  &__kv {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20rpx;
    min-height: 76rpx;
    padding: 12rpx 0;
    border-bottom: 2rpx solid $hairline;

    &:last-child {
      border-bottom: none;
    }

    &--stack {
      flex-direction: column;
      gap: 4rpx;
    }
  }

  &__kv-key {
    flex-shrink: 0;
    font-size: 24rpx;
    color: $ink-400;
  }

  &__kv-val {
    flex: 1;
    min-width: 0;
    font-size: 26rpx;
    line-height: 1.6;
    color: $ink-800;
    text-align: right;
    word-break: break-word;

    &--warn {
      text-align: left;
      color: $warn-600;
    }
  }

  &__para {
    display: block;
    font-size: 27rpx;
    line-height: 1.7;
    color: $ink-700;
    white-space: pre-wrap;
    word-break: break-word;
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

  &__bullets {
    display: flex;
    flex-direction: column;
    gap: 6rpx;
    margin-top: 8rpx;
  }

  &__bullet {
    font-size: 25rpx;
    line-height: 1.7;
    color: $ink-700;
  }

  /* ---------- 阶段编辑 ---------- */
  &__phase-edit {
    padding: 16rpx 0;
    border-bottom: 2rpx solid $hairline;

    &:last-of-type {
      border-bottom: none;
    }
  }

  &__phase-edit-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8rpx;
  }

  &__phase-edit-no {
    font-size: 24rpx;
    font-weight: 600;
    color: $ink-600;
  }

  &__phase-edit-del {
    padding: 8rpx 18rpx;
    font-size: 22rpx;
    color: $danger-600;
    background: $danger-100;
    border-radius: 999rpx;
  }

  &__add {
    display: flex;
    gap: 8rpx;
    align-items: center;
    justify-content: center;
    min-height: 88rpx;
    margin-top: 12rpx;
    font-size: 24rpx;
    color: $brand-600;
    background: $brand-50;
    border-radius: $r-md;
  }

  /* ---------- 错误与提交 ---------- */
  &__err {
    padding: 16rpx 20rpx;
    margin: 20rpx 24rpx 0;
    font-size: 23rpx;
    color: $danger-600;
    background: $danger-100;
    border-radius: $r-md;
  }

  &__submit {
    display: flex;
    flex-direction: column;
    gap: 12rpx;
    padding: 8rpx 28rpx calc(40rpx + env(safe-area-inset-bottom));
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
