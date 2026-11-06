<script setup lang="ts">
/**
 * 会员详情（移动端）
 *
 * 读：GET /customers/{id} → { customer, logs, leads }（客户 360）
 * 写：PATCH /customers/{id}，只用后端白名单里的字段：
 *     lastTouch（最近沟通）/ birthday（生日）/ inRevive（标记待复活）
 *
 * 为什么只做这三个：
 *  - 现场最常用、且是单值（不需要并排核对表格）；
 *  - 卡项、续费评估这类**计算派生**字段在手机上只读展示，改动一律回桌面，
 *    避免「手机上改一个数、桌面口径对不上」。
 *
 * 视觉层（2026-11 重构）：客户 360 头卡 + 指标区 + 信息行 + 可维护区 + 留痕，
 * 表单控件换 wd-switch / wd-button，业务字段与提交口径一字未改。
 */
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
import {
  EVAL_DIMENSIONS,
  EVAL_RISKS,
  evalLevelLabel,
  evalTotalScore,
  fetchCustomerDetail,
  fetchRenewalEvaluation,
  saveRenewalEvaluation,
  updateCustomer,
  type CustomerPatch,
  type RenewalEvalDimension,
  type RenewalEvaluationAnswers,
  type RenewalEvaluationContext
} from '@/api/members'
import type { CustomerCard, CustomerDetail } from '@/types'

const id = ref(0)
const detail = ref<CustomerDetail | null>(null)
const loading = ref(false)
const saving = ref(false)
const failed = ref(false)
/** 首次加载是否已完成（onShow 只在首个 show 拉一次，回退不重复请求） */
const inited = ref(false)

/** 本地编辑态（与服务端值分离，保存成功后才回写） */
const form = ref({ lastTouch: '', birthday: '', memberState: '正常' })

/** 会员经营状态（与后端 Customer::MEMBER_STATES 同源；「待复活」会联动 in_revive） */
const MEMBER_STATES = ['正常', '待续费', '待复活', '已暂停', '已流失']
const stateIndex = computed(() => Math.max(0, MEMBER_STATES.indexOf(form.value.memberState)))

const c = computed(() => detail.value?.customer ?? null)

/* ---------- 全部卡项（GET /customers/{id} 顶层 cardsList，三态全量） ----------
 *
 * 为什么要在详情页单独展示：会员列表只给「主卡 + 剩余 + 到期」三列摘要，
 * 判断"这个人还有几张卡、有没有过期卡没消课、是不是刚开卡还没上"全靠翻电脑。
 * 网页端会员详情已经有完整卡项（v3.11），小程序这里对齐同一份数据。
 */
const cards = computed<CustomerCard[]>(() => detail.value?.cardsList ?? [])

const CARD_STATE_LABEL: Record<string, string> = {
  current: '在用',
  unactivated: '未开卡',
  history: '已过期'
}

/** 按 在用 / 未开卡 / 已过期 三档分组；空档不渲染（不占屏幕） */
const cardSections = computed(() => {
  const buckets: Record<string, CustomerCard[]> = { current: [], unactivated: [], history: [] }
  for (const card of cards.value) {
    const state = String(card.state || 'current')
    ;(buckets[state] ?? buckets.current).push(card)
  }
  return ['current', 'unactivated', 'history']
    .filter((state) => buckets[state].length > 0)
    .map((state) => ({
      state,
      label: CARD_STATE_LABEL[state] ?? state,
      items: buckets[state]
    }))
})

/** 卡项剩余：单位取自后端（节/天/元），未知就写「剩余未知」而不是 0 */
/**
 * 卡项剩余。
 *
 * 后端没返回剩余量时不再写「剩余未知」——那四个字既占地方又让人以为是系统算错了。
 * 按卡的性质给可读口径：
 *  - 期限卡（天）：本来就没有"次数"，写「按到期日计」；
 *  - 其余（次卡/储值卡）：写「—」，由下方「卡号」和状态说明承担信息量。
 */
function cardRemain(card: CustomerCard): string {
  if (card.remaining === null || card.remaining === undefined) {
    return card.unit === '天' ? '按到期日计' : '—'
  }
  const unit = card.unit || '节'
  return `剩 ${card.remaining} ${unit}`
}

/** 卡项到期日（只到天） */
function cardDeadline(card: CustomerCard): string {
  const d = String(card.deadline || '')
  return d ? `到期 ${d.slice(0, 10)}` : ''
}

function todayStr(): string {
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

function toDateStr(v?: string | null): string {
  if (!v) return ''
  return String(v).slice(0, 10)
}

function syncForm() {
  const row = c.value
  if (!row) return
  form.value = {
    lastTouch: toDateStr(row.lastTouch),
    birthday: toDateStr(row.birthday),
    memberState: String((row as { memberState?: string }).memberState || '正常')
  }
}

/* ---------- 续费评估（五维 + 风险，口径与网页端完全一致） ---------- */

const evalCtx = ref<RenewalEvaluationContext | null>(null)
const evalOpen = ref(false)
const evalSaving = ref(false)
const evalRemark = ref('')
const evalAnswers = ref<RenewalEvaluationAnswers>({
  goal: 'none',
  feedback: 'none',
  wechat: 'no_response',
  intent: 'none',
  service: 'normal',
  risks: []
})

/** 实时得分（含出勤与卡项窗口，与后端同一条公式；保存后以服务端为准） */
const evalScoreNow = computed(() =>
  evalTotalScore({
    ...evalAnswers.value,
    attendanceCount: evalCtx.value?.attendanceCount ?? 0,
    cardWindow: evalCtx.value?.cardWindow ?? 0
  })
)

const evalLevelNow = computed(() =>
  evalLevelLabel(evalScoreNow.value >= 70 ? 'high' : evalScoreNow.value >= 40 ? 'medium' : 'low')
)

function dimValue(dim: RenewalEvalDimension): string {
  return String((evalAnswers.value as unknown as Record<string, string>)[dim.key] || '')
}

function dimIndex(dim: RenewalEvalDimension): number {
  return Math.max(
    0,
    dim.options.findIndex((o) => o.key === dimValue(dim))
  )
}

function dimLabel(dim: RenewalEvalDimension): string {
  return dim.options.find((o) => o.key === dimValue(dim))?.label || '请选择'
}

function onDimPick(dim: RenewalEvalDimension, e: { detail: { value: string | number } }): void {
  const opt = dim.options[Number(e.detail.value)]
  if (opt) {
    ;(evalAnswers.value as unknown as Record<string, string>)[dim.key] = opt.key
  }
}

function riskOn(key: string): boolean {
  return evalAnswers.value.risks.includes(key)
}

function toggleRisk(key: string): void {
  const list = evalAnswers.value.risks
  if (list.includes(key)) {
    evalAnswers.value.risks = list.filter((k) => k !== key)
    return
  }
  // 与网页端一致：最多同时命中的 3 个风险项
  evalAnswers.value.risks = [...list, key].slice(0, 3)
}

async function loadEval(): Promise<void> {
  if (!id.value) return
  try {
    evalCtx.value = await fetchRenewalEvaluation(id.value)
  } catch {
    // 评估接口失败不影响会员详情本身，静默（展开时还能再试）
  }
}

function openEval(): void {
  const latest = evalCtx.value?.latest
  if (latest?.answers) {
    evalAnswers.value = {
      goal: latest.answers.goal || 'none',
      feedback: latest.answers.feedback || 'none',
      wechat: latest.answers.wechat || 'no_response',
      intent: latest.answers.intent || 'none',
      service: latest.answers.service || 'normal',
      risks: [...(latest.answers.risks ?? [])]
    }
    evalRemark.value = latest.remark || ''
  }
  evalOpen.value = true
}

async function submitEval(): Promise<void> {
  if (!c.value || evalSaving.value) return
  evalSaving.value = true
  try {
    await saveRenewalEvaluation(c.value.id, {
      answers: { ...evalAnswers.value, risks: [...evalAnswers.value.risks] },
      remark: evalRemark.value.trim()
    })
    uni.showToast({ title: '评估已保存', icon: 'success' })
    evalOpen.value = false
    await Promise.all([load(), loadEval()])
  } catch {
    /* http 层已 toast */
  } finally {
    evalSaving.value = false
  }
}

async function load() {
  if (!id.value) return
  inited.value = true
  loading.value = true
  try {
    detail.value = await fetchCustomerDetail(id.value)
    void loadEval()
    syncForm()
    failed.value = false
  } catch {
    /* http 层已 toast */
    failed.value = true
  } finally {
    loading.value = false
  }
}

function call() {
  const phone = c.value?.phone
  if (!phone) {
    uni.showToast({ title: '无手机号', icon: 'none' })
    return
  }
  uni.makePhoneCall({ phoneNumber: phone, fail: () => {} })
}

function copyPhone() {
  const phone = c.value?.phone
  if (!phone) return
  uni.setClipboardData({ data: phone, success: () => uni.showToast({ title: '已复制', icon: 'none' }) })
}

function onDateChange(e: { detail: { value: string } }, field: 'lastTouch' | 'birthday') {
  form.value[field] = e.detail.value
}

/** 会员状态选择：待复活 → 后端会同步把 in_revive 打开（五清单口径不变） */
function onStateChange(e: { detail: { value: string | number } }): void {
  const i = Number(e.detail.value)
  form.value.memberState = MEMBER_STATES[i] ?? '正常'
}

async function save() {
  if (!c.value || saving.value) return
  saving.value = true
  try {
    const patch: CustomerPatch = {
      lastTouch: form.value.lastTouch || null,
      birthday: form.value.birthday || null
    }
    // 状态只在变化时提交：它联动 in_revive 并触发后端重算 layer，无谓写入会白跑一次重算
    const currentState = String((c.value as { memberState?: string }).memberState || '正常')
    if (form.value.memberState !== currentState) {
      ;(patch as Record<string, unknown>).memberState = form.value.memberState
    }
    await updateCustomer(c.value.id, patch, '移动端维护')
    uni.showToast({ title: '已保存', icon: 'success' })
    await load()
  } catch {
    /* http 层已 toast */
  } finally {
    saving.value = false
  }
}

function money(v?: number | null): string {
  if (v === null || v === undefined) return '—'
  return `¥${Number(v).toLocaleString('zh-CN')}`
}

function levelText(): string {
  const row = c.value
  if (!row || row.evalScore === null || row.evalScore === undefined) return '待评估'
  const map: Record<string, string> = { high: '高机会', medium: '重点培育', low: '风险修复' }
  return `${row.evalScore} · ${map[row.evalLevel || ''] ?? '—'}`
}

function logTime(v?: string): string {
  if (!v) return ''
  return String(v).replace('T', ' ').slice(0, 16)
}

function toLeadForm(leadId: number) {
  // 关联留资只在详情里展示跳转；编辑回列表页做（列表行已有完整字段）
  uni.navigateTo({ url: `/pkg-crm/leads/leads?focus=${leadId}` })
}

/* ---------- 客户分析（结论层，只用 GET /customers/{id} 已返回字段） ---------- */

type TrendTone = 'down' | 'up' | 'flat' | 'none'

/** 出勤三档：M1 最早、M3 最近 30 天（与「近三档出勤」同序） */
const attendTrend = computed<{ text: string; tone: TrendTone }>(() => {
  const row = c.value
  if (!row) return { text: '暂无', tone: 'none' }
  const m1 = row.attendM1 ?? 0
  const m2 = row.attendM2 ?? 0
  const m3 = row.attendM3 ?? 0
  if (m1 === 0 && m2 === 0 && m3 === 0) return { text: '近三月无出勤', tone: 'none' }
  if (m1 > m2 && m2 > m3) return { text: `连续下降（${m1}→${m3}）`, tone: 'down' }
  if (m1 < m2 && m2 < m3) return { text: `连续上升（${m1}→${m3}）`, tone: 'up' }
  if (m1 === m3) return { text: '基本持平', tone: 'flat' }
  return m3 < m1 ? { text: `总体下降（${m1}→${m3}）`, tone: 'down' } : { text: `总体上升（${m1}→${m3}）`, tone: 'up' }
})

/** 分层与清单：只摆后端已给的事实，猜规则留给电脑端 */
const listSignals = computed(() => {
  const row = c.value
  if (!row) return ''
  const out: string[] = []
  if (row.inRevive) out.push('待复活')
  if (row.mainCard) out.push(`主卡 ${row.mainCard}`)
  if (row.status) out.push(`状态 ${row.status}`)
  return out.join(' · ')
})

/** 滚到操作留痕（锚点 id 在模板里，见 #logs） */
function scrollToLogs() {
  uni.pageScrollTo({ selector: '#logs', duration: 300, fail: () => {} })
}

function layerTone(layer?: string): 'brand' | 'gold' | 'warn' | 'info' | 'muted' {
  if (layer === 'P0' || layer === 'P1') return 'brand'
  if (layer === 'P2') return 'gold'
  if (layer === 'P3') return 'info'
  if (layer === 'P4') return 'warn'
  return 'muted'
}

onLoad((options) => {
  id.value = Number((options as Record<string, string>)?.id ?? 0)
})

// 权限：先登录，再校验功能权限（暂无权限 -> toast + 返回）
onShow(() => {
  if (!requireLogin()) return
  if (!requireFeature('members')) return
  // onLoad 里才拿到 id，首次 show 时才真正拉数据
  if (!inited.value) load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})
</script>

<template>
  <view class="ym-page member-detail">
    <!-- 三态 -->
    <view v-if="failed && !c" class="ym-card">
      <ym-empty icon="warning" text="会员资料加载失败" desc="网络或服务异常" action-text="重试" @action="load" />
    </view>

    <view v-else-if="!c && loading" class="ym-card">
      <wd-skeleton
        theme="text"
        animation="gradient"
        :row-col="[{ width: '42%' }, { width: '66%' }, { width: '86%' }, { width: '54%' }]"
      />
    </view>

    <ym-empty v-else-if="!c" icon="user" text="未找到该会员" desc="可能已被合并或删除" />

    <template v-if="c">
      <!-- 客户 360 头卡 -->
      <view class="dhead">
        <view class="dhead__row">
          <ym-avatar :name="c.name" :size="96" />
          <view class="dhead__id">
            <view class="dhead__name-row">
              <text class="dhead__name ym-ellipsis">{{ c.name }}</text>
              <ym-badge v-if="c.layer" :text="c.layer" :tone="layerTone(c.layer)" size="md" />
            </view>
            <text class="dhead__sub ym-ellipsis">
              {{ c.venue }}<text v-if="c.consultant"> · 会籍顾问 {{ c.consultant }}</text>
            </text>
          </view>
        </view>

        <view class="dhead__phone-row">
          <text class="dhead__phone ym-num">{{ c.phone || `尾号 ${c.phoneTail}` }}</text>
          <view class="dhead__acts">
            <wd-button size="medium" plain @click="copyPhone">复制</wd-button>
            <wd-button size="medium" plain @click="call">拨打</wd-button>
          </view>
        </view>

        <view v-if="c.source || c.status || c.nextAction" class="dhead__tags">
          <ym-badge v-if="c.source" :text="`来源 ${c.source}`" tone="muted" />
          <ym-badge v-if="c.status" :text="c.status" tone="info" />
          <ym-badge v-if="c.nextAction" :text="c.nextAction" tone="warn" />
        </view>
      </view>

      <!-- 卡项与出勤 -->
      <ym-section title="卡项与出勤" desc="派生字段，只读">
        <view class="grid">
          <view class="grid__cell">
            <text class="grid__value ym-num">{{ c.remainTimes ?? '—' }}</text>
            <text class="grid__label">剩余课时</text>
          </view>
          <view class="grid__cell">
            <text class="grid__value ym-num">{{ toDateStr(c.expireDate) || '—' }}</text>
            <text class="grid__label">到期日</text>
          </view>
          <view class="grid__cell">
            <text class="grid__value ym-num">{{ c.attendM3 ?? 0 }}</text>
            <text class="grid__label">近30天出勤</text>
          </view>
          <view class="grid__cell">
            <text class="grid__value ym-num">{{ money(c.cardPaidAmount) }}</text>
            <text class="grid__label">累计购买</text>
          </view>
        </view>

        <view class="rows">
          <ym-cell-row v-if="c.mainCard" label="主卡" :value="c.mainCard" divider />
          <ym-cell-row
            label="近三档出勤"
            :value="`${c.attendM1 ?? 0} / ${c.attendM2 ?? 0} / ${c.attendM3 ?? 0}`"
            divider
          />
          <ym-cell-row label="最近到店" :value="toDateStr(c.lastVisit) || '无记录'" />
        </view>

        <!-- 全部卡项：主卡以外还有哪些卡、哪几张已过期，判读续费时最常看这一块 -->
        <view v-if="cards.length" class="cards">
          <view class="cards__head">
            <text class="cards__title">全部卡项</text>
            <text class="cards__count ym-num">{{ cards.length }} 张</text>
          </view>
          <view v-for="g in cardSections" :key="g.state" class="cards__group">
            <view class="cards__group-head">
              <text class="cards__group-title">{{ g.label }}</text>
              <text class="cards__group-count ym-num">{{ g.items.length }} 张</text>
            </view>
            <view
              v-for="(card, i) in g.items"
              :key="`${card.id ?? card.cardNo ?? i}`"
              class="cards__item"
            >
              <view class="cards__item-top">
                <text class="cards__item-title ym-ellipsis">{{ card.title || '卡项' }}</text>
                <ym-badge v-if="card.isMain" text="主卡" tone="brand" plain />
              </view>
              <text class="cards__item-meta ym-num">
                {{ cardRemain(card) }}<text v-if="cardDeadline(card)"> · {{ cardDeadline(card) }}</text>
              </text>
              <text v-if="card.cardNo" class="cards__item-no ym-ellipsis">卡号 {{ card.cardNo }}</text>
            </view>
          </view>
        </view>
      </ym-section>

      <!-- 客户分析：三行结论（续费 / 出勤 / 分层），字段缺失显示「暂无」 -->
      <ym-section title="客户分析" desc="结论来自卡项、出勤与分层事实">
        <view class="ana">
          <view class="ana__row">
            <view class="ana__head">
              <text class="ana__label">续费评估</text>
              <text class="ana__value ym-num">{{ levelText() }}</text>
            </view>
            <text class="ana__hint">{{ c.evalAt ? `评估于 ${toDateStr(c.evalAt)}` : '暂无评估记录' }}</text>
          </view>

          <view class="ana__row">
            <view class="ana__head">
              <text class="ana__label">出勤趋势</text>
              <text class="ana__value ym-num" :class="`is-${attendTrend.tone}`">
                {{ attendTrend.text }}
              </text>
            </view>
            <text class="ana__hint">
              近三档 {{ c.attendM1 ?? 0 }} / {{ c.attendM2 ?? 0 }} / {{ c.attendM3 ?? 0 }}（左→右由远到近）
            </text>
          </view>

          <view class="ana__row">
            <view class="ana__head">
              <text class="ana__label">分层与清单</text>
              <text class="ana__value">{{ c.layer || '暂无' }}</text>
            </view>
            <text class="ana__hint ym-ellipsis">{{ listSignals || '暂无清单命中' }}</text>
          </view>
        </view>

        <view v-if="detail?.logs?.length" class="ana__link ym-tap" @click="scrollToLogs">
          <text class="ana__link-text">查看全部留痕（{{ detail?.logs?.length }} 条）</text>
          <wd-icon name="arrow-down" size="13px" />
        </view>
        <text v-else class="ana__link-empty">暂无操作留痕</text>
      </ym-section>

      <!-- 续费评估结论：卡片上的既有字段（回填受控），手机端只读 -->
      <ym-section title="续费评估结论（只读）" desc="回填受控，手机端只显示不改">
        <view class="rows">
          <ym-cell-row label="30 天评估" :value="levelText()" divider />
          <ym-cell-row v-if="c.evalAt" label="评估时间" :value="toDateStr(c.evalAt)" divider />
          <ym-cell-row
            v-if="c.renewalPlan?.time"
            label="续课计划"
            :value="`${c.renewalPlan.intent} · ${c.renewalPlan.time}`"
            divider
          />
          <ym-cell-row v-if="c.decline?.reason" label="下降原因" :value="c.decline.reason" />
        </view>
      </ym-section>

      <!-- 填写续费评估：五维打分表单（上一块是只读结论，两块标题必须区分，否则看起来是重复区块） -->
      <ym-section title="填写续费评估" desc="五维 + 风险，与服务端同一评分口径">
        <view v-if="evalCtx?.latest && !evalOpen" class="evsum">
          <view class="evsum__row">
            <text class="evsum__score ym-num">{{ evalCtx.latest.score }}</text>
            <view class="evsum__meta">
              <ym-badge :text="evalLevelLabel(evalCtx.latest.level)" tone="gold" />
              <text class="evsum__by">
                {{ evalCtx.latest.evaluatorName }} · {{ String(evalCtx.latest.evaluatedAt).slice(0, 10) }}
              </text>
            </view>
            <wd-button size="small" plain @click="openEval">重新评估</wd-button>
          </view>
          <text v-if="evalCtx.latest.remark" class="evsum__remark">{{ evalCtx.latest.remark }}</text>
        </view>

        <view v-else-if="!evalOpen" class="evsum evsum--empty">
          <text class="evsum__hint">还没有续费评估</text>
          <wd-button size="small" type="primary" plain @click="openEval">开始评估</wd-button>
        </view>

        <view v-if="evalOpen" class="evform">
          <view class="evform__base">
            出勤 {{ evalCtx?.attendanceCount ?? 0 }} 次 · 卡项窗口 {{ evalCtx?.cardWindow ?? 0 }} 分
            <text class="evform__base-tip">（只读，来自后端上下文）</text>
          </view>

          <view v-for="dim in EVAL_DIMENSIONS" :key="dim.key" class="evdim">
            <text class="evdim__title">{{ dim.title }}</text>
            <picker
              mode="selector"
              :range="dim.options"
              range-key="label"
              :value="dimIndex(dim)"
              @change="onDimPick(dim, $event)"
            >
              <view class="evdim__pick ym-tap">
                <text class="evdim__label">{{ dimLabel(dim) }}</text>
                <wd-icon name="chevron-right" size="13px" />
              </view>
            </picker>
          </view>

          <view class="evrisk">
            <text class="evrisk__title">风险项（最多 3 项，命中扣分）</text>
            <view class="evrisk__chips">
              <view
                v-for="r in EVAL_RISKS"
                :key="r.key"
                class="evchip ym-tap"
                :class="{ 'evchip--on': riskOn(r.key) }"
                @click="toggleRisk(r.key)"
              >
                {{ r.label }}
              </view>
            </view>
          </view>

          <wd-textarea
            v-model="evalRemark"
            label="备注"
            placeholder="补充判断依据（可选）"
            :maxlength="500"
          />

          <view class="evscore">
            <text class="evscore__label">本次得分</text>
            <text class="evscore__value ym-num">{{ evalScoreNow }}</text>
            <ym-badge :text="evalLevelNow" tone="gold" />
          </view>

          <view class="evactions">
            <wd-button size="small" plain @click="evalOpen = false">取消</wd-button>
            <wd-button size="small" type="primary" :loading="evalSaving" @click="submitEval">
              保存评估
            </wd-button>
          </view>
        </view>
      </ym-section>

      <!-- 移动端可维护 -->
      <ym-section title="移动端可维护" desc="只改这三项；卡项与评估结果请回电脑端操作">
        <view class="edit">
          <picker mode="date" :value="form.lastTouch" @change="onDateChange($event, 'lastTouch')">
            <view class="edit__row ym-tap">
              <text class="edit__label">最近沟通</text>
              <view class="edit__right">
                <text :class="form.lastTouch ? 'edit__value' : 'edit__placeholder'">{{ form.lastTouch || '未填写' }}</text>
                <view class="edit__chevron"><wd-icon name="chevron-right" size="14px" /></view>
              </view>
            </view>
          </picker>

          <picker mode="date" :value="form.birthday || todayStr()" @change="onDateChange($event, 'birthday')">
            <view class="edit__row ym-tap">
              <text class="edit__label">生日</text>
              <view class="edit__right">
                <text :class="form.birthday ? 'edit__value' : 'edit__placeholder'">{{ form.birthday || '未填写' }}</text>
                <view class="edit__chevron"><wd-icon name="chevron-right" size="14px" /></view>
              </view>
            </view>
          </picker>

          <picker
            mode="selector"
            :range="MEMBER_STATES"
            :value="stateIndex"
            @change="onStateChange"
          >
            <view class="edit__row ym-tap">
              <text class="edit__label">会员状态</text>
              <view class="edit__right">
                <text class="edit__value">{{ form.memberState }}</text>
                <view class="edit__chevron"><wd-icon name="chevron-right" size="14px" /></view>
              </view>
            </view>
          </picker>
        </view>

        <view class="edit__submit">
          <wd-button type="primary" size="large" block :loading="saving" @click="save">
            {{ saving ? '保存中…' : '保存改动' }}
          </wd-button>
        </view>
      </ym-section>

      <!-- 关联留资 -->
      <ym-section v-if="detail?.leads?.length" title="关联留资" desc="同手机号命中">
        <view
          v-for="l in detail.leads"
          :key="l.id"
          class="lead-row ym-tap"
          @click="toLeadForm(l.id)"
        >
          <view class="lead-row__main">
            <view class="lead-row__top">
              <text class="lead-row__name ym-ellipsis">{{ l.name }}</text>
              <ym-badge :text="l.status" tone="gold" />
            </view>
            <text class="lead-row__meta ym-ellipsis">
              {{ l.leadDate?.slice(0, 10) }} · {{ l.source }} · {{ l.demand || '未填需求' }}
            </text>
          </view>
          <view class="lead-row__chevron"><wd-icon name="chevron-right" size="14px" /></view>
        </view>
      </ym-section>

      <!-- 操作留痕（客户分析里的「查看全部留痕」锚点指向这里） -->
      <view id="logs">
        <ym-section v-if="detail?.logs?.length" title="操作留痕" desc="最近 8 条">
          <view v-for="log in detail.logs.slice(0, 8)" :key="log.id" class="log-row">
            <view class="log-row__dot" />
            <view class="log-row__main">
              <text class="log-row__text">{{ log.action }} · {{ log.detail }}</text>
              <text class="log-row__time ym-num">
                {{ logTime(log.createdAt) }}<text v-if="log.operator"> · {{ log.operator }}</text>
              </text>
            </view>
          </view>
        </ym-section>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

/* ---------- 续费评估 ---------- */
.evsum {
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.evsum--empty {
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
}

.evsum__hint {
  font-size: 24rpx;
  color: $ink-400;
}

.evsum__row {
  display: flex;
  align-items: center;
  gap: 16rpx;
}

.evsum__score {
  font-size: 44rpx;
  font-weight: 700;
  color: $gold-600;
}

.evsum__meta {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}

.evsum__by {
  font-size: 21rpx;
  color: $ink-400;
}

.evsum__remark {
  font-size: 23rpx;
  line-height: 1.5;
  color: $ink-600;
}

.evform {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.evform__base {
  padding: 12rpx 16rpx;
  font-size: 22rpx;
  color: $ink-500;
  background: $ink-50;
  border-radius: 10rpx;
}

.evform__base-tip {
  color: $ink-400;
}

.evdim__title {
  display: block;
  font-size: 24rpx;
  font-weight: 500;
  color: $ink-700;
  margin-bottom: 8rpx;
}

.evdim__pick {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12rpx;
  padding: 16rpx 18rpx;
  background: $ink-50;
  border-radius: 12rpx;
}

.evdim__label {
  flex: 1;
  min-width: 0;
  font-size: 24rpx;
  color: $ink-700;
}

.evrisk__title {
  display: block;
  font-size: 24rpx;
  font-weight: 500;
  color: $ink-700;
  margin-bottom: 10rpx;
}

.evrisk__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}

.evchip {
  padding: 10rpx 18rpx;
  font-size: 22rpx;
  color: $ink-600;
  background: $ink-50;
  border: 2rpx solid transparent;
  border-radius: 999rpx;
}

.evchip--on {
  color: $gold-600;
  background: rgba(191, 145, 61, 0.1);
  border-color: rgba(191, 145, 61, 0.4);
}

.evscore {
  display: flex;
  align-items: baseline;
  gap: 12rpx;
  padding: 14rpx 18rpx;
  background: $ink-50;
  border-radius: 12rpx;
}

.evscore__label {
  font-size: 23rpx;
  color: $ink-500;
}

.evscore__value {
  font-size: 38rpx;
  font-weight: 700;
  color: $gold-600;
}

.evactions {
  display: flex;
  justify-content: flex-end;
  gap: 16rpx;
}

/* ---------- 全部卡项 ---------- */
.cards {
  margin-top: 24rpx;
  padding-top: 20rpx;
  border-top: 2rpx solid $hairline;
}

.cards__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 12rpx;
}

.cards__title {
  font-size: 26rpx;
  font-weight: 600;
  color: $ink-700;
}

.cards__count {
  font-size: 22rpx;
  color: $ink-400;
}

.cards__group {
  margin-top: 12rpx;
}

.cards__group-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding: 6rpx 0;
}

.cards__group-title {
  font-size: 23rpx;
  color: $ink-500;
}

.cards__group-count {
  font-size: 21rpx;
  color: $ink-400;
}

.cards__item {
  padding: 14rpx 16rpx;
  margin-top: 8rpx;
  background: $ink-50;
  border-radius: 12rpx;
}

.cards__item-top {
  display: flex;
  align-items: center;
  gap: 8rpx;
}

.cards__item-title {
  flex: 1;
  min-width: 0;
  font-size: 25rpx;
  font-weight: 500;
  color: $ink-800;
}

.cards__item-meta {
  display: block;
  margin-top: 6rpx;
  font-size: 23rpx;
  color: $ink-600;
}

.cards__item-no {
  display: block;
  margin-top: 4rpx;
  font-size: 21rpx;
  color: $ink-400;
}

.member-detail {
  padding-top: 20rpx;
  padding-bottom: calc(40rpx + env(safe-area-inset-bottom));

  /* 触控按钮统一 84rpx */
  --wot-button-medium-height: 84rpx;
  --wot-button-medium-fs: 26rpx;
}

/* ---------- 头卡 ---------- */
.dhead {
  padding: 28rpx;
  margin: 20rpx 24rpx;
  background: linear-gradient(150deg, $brand-50, $white 58%);
  border: 2rpx solid $brand-100;
  border-radius: $r-lg;
}

.dhead__row {
  display: flex;
  gap: 22rpx;
  align-items: center;
}

.dhead__id {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8rpx;
  min-width: 0;
}

.dhead__name-row {
  display: flex;
  gap: 14rpx;
  align-items: center;
  min-width: 0;
}

.dhead__name {
  flex-shrink: 1;
  min-width: 0;
  font-size: 40rpx;
  font-weight: 600;
  color: $ink-800;
}

.dhead__sub {
  display: block;
  font-size: 24rpx;
  color: $ink-500;
}

.dhead__phone-row {
  display: flex;
  gap: 20rpx;
  align-items: center;
  justify-content: space-between;
  padding-top: 22rpx;
  margin-top: 22rpx;
  border-top: 2rpx solid $brand-100;
}

.dhead__phone {
  font-size: 30rpx;
  font-weight: 500;
  color: $ink-800;
}

.dhead__acts {
  display: flex;
  flex-shrink: 0;
  gap: 12rpx;
}

.dhead__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 20rpx;
}

/* ---------- 客户分析 ---------- */
.ana {
  border-top: 2rpx solid $hairline;
}

.ana__row {
  padding: 18rpx 0;
  border-bottom: 2rpx solid $hairline;
}

.ana__head {
  display: flex;
  gap: 16rpx;
  align-items: baseline;
  justify-content: space-between;
  min-width: 0;
}

.ana__label {
  flex-shrink: 0;
  font-size: 26rpx;
  color: $ink-500;
}

.ana__value {
  flex-shrink: 1;
  min-width: 0;
  font-size: 28rpx;
  font-weight: 600;
  color: $ink-800;
  text-align: right;

  &.is-down {
    color: $danger-600;
  }

  &.is-up {
    color: $brand-600;
  }

  &.is-flat,
  &.is-none {
    color: $ink-500;
  }
}

.ana__hint {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: $ink-400;
}

.ana__link {
  display: flex;
  gap: 6rpx;
  align-items: center;
  justify-content: center;
  min-height: 88rpx;
  margin-top: 6rpx;
  color: $brand-600;
}

.ana__link-text {
  font-size: 24rpx;
}

.ana__link-empty {
  display: block;
  padding: 20rpx 0 6rpx;
  font-size: 22rpx;
  color: $ink-400;
  text-align: center;
}

/* ---------- 指标格 ---------- */
.grid {
  display: flex;
  flex-wrap: wrap;
  padding: 8rpx 0 4rpx;
}

.grid__cell {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  width: 50%;
  padding: 14rpx 0;
}

.grid__value {
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.grid__label {
  font-size: 21rpx;
  color: $ink-400;
}

.rows {
  margin-top: 8rpx;
}

/* ---------- 可维护区 ---------- */
.edit {
  border-top: 2rpx solid $hairline;
}

.edit__row {
  display: flex;
  gap: 16rpx;
  align-items: center;
  justify-content: space-between;
  min-height: 96rpx;
  border-bottom: 2rpx solid $hairline;

  &--switch {
    border-bottom: none;
  }
}

.edit__label {
  font-size: 28rpx;
  color: $ink-700;
}

.edit__right {
  display: flex;
  gap: 6rpx;
  align-items: center;
}

.edit__value {
  font-size: 27rpx;
  color: $brand-600;
}

.edit__placeholder {
  font-size: 27rpx;
  color: $ink-400;
}

.edit__chevron {
  display: flex;
  align-items: center;
  color: $ink-300;
}

.edit__submit {
  margin-top: 26rpx;
}

/* ---------- 关联留资 ---------- */
.lead-row {
  display: flex;
  gap: 12rpx;
  align-items: center;
  min-height: 96rpx;
  padding: 16rpx 0;
  border-top: 2rpx solid $hairline;
}

.lead-row__main {
  flex: 1;
  min-width: 0;
}

.lead-row__top {
  display: flex;
  gap: 12rpx;
  align-items: center;
  margin-bottom: 8rpx;
  min-width: 0;
}

.lead-row__name {
  display: block;
  flex-shrink: 1;
  min-width: 0;
  font-size: 28rpx;
  font-weight: 500;
  color: $ink-800;
}

.lead-row__meta {
  display: block;
  font-size: 22rpx;
  color: $ink-400;
}

.lead-row__chevron {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  color: $ink-300;
}

/* ---------- 留痕 ---------- */
.log-row {
  display: flex;
  gap: 16rpx;
  padding: 16rpx 0;
  border-top: 2rpx solid $hairline;
}

.log-row__dot {
  flex-shrink: 0;
  width: 12rpx;
  height: 12rpx;
  margin-top: 14rpx;
  background: $brand-200;
  border-radius: 50%;
}

.log-row__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6rpx;
  min-width: 0;
}

.log-row__text {
  font-size: 26rpx;
  line-height: 1.5;
  color: $ink-700;
}

.log-row__time {
  font-size: 21rpx;
  color: $ink-400;
}
</style>
