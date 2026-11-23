<script setup lang="ts">
/**
 * 客户分析 / 训练计划的**对外展示页**（免登录，客户点分享卡片进来的落地页）
 *
 * ## 为什么是独立页、而不是详情页加个「对客模式」
 * 详情页是员工后台：导航栏有返回、卡片里叠着编辑/确认/删除、字段里有手机号与备注。
 * 客户看到的应该只有「你的评估结论、给你的建议、你的训练安排」这种人话。
 * 所以这里按 `pkg-public/guide/guide.vue`（到店指引）的对外页语言重做一版：
 * 同样的 `ym-page` 底色 + `ym-section` 卡片节奏 + 大标题留白，不放任何操作按钮。
 *
 * ## 安全模型（两道锁，缺一不可）
 *  1. **token 固定单份**：`?t=<64 位 hex>`，后端与「kind + 内容 id」绑死，
 *     所以客户不可能点进别人的内容（不是靠前端过滤）。
 *  2. **手机号后四位**：首屏先要后四位；后端 401/403/410 三态分明。
 *     校验通过的结果只缓存在**进程内**（见 api/share.ts），不写 storage —— 分享页是给
 *     非员工看的，凭据不落盘。
 *
 * ## 不做什么
 *  - 不调任何需要登录的接口（本页只打 `GET /public/share/{token}`）；
 *  - 不渲染手机号全号 / 学员姓名 / 归属账号 / 内部备注 / 金额 —— 后端白名单里没有这些，
 *    这里也只按 `PublicReviewShare` / `PublicPlanShare` 声明过的字段渲染；
 *  - 不把后端中文原文怼给客户：410 → 「分享已失效」，401/403 → 「后四位不正确」。
 */
import { computed, ref } from 'vue'
import { onLoad, onShareAppMessage } from '@dcloudio/uni-app'
import { shareSelf } from '@/utils/share'
import {
  ShareError,
  cachedPublicShare,
  fetchPublicShare,
  rememberPublicShare,
  shareViewPath,
  type PublicPlanShare,
  type PublicReviewShare,
  type PublicShareContent
} from '@/api/share'

/** loading → 加载中 / need-code → 等输后四位 / ready → 展示 / gone → 失效 / error → 网络 */
type Stage = 'loading' | 'need-code' | 'ready' | 'gone' | 'error'

const token = ref('')
const stage = ref<Stage>('loading')
const content = ref<PublicShareContent | null>(null)
const code = ref('')
const codeError = ref('')
const submitting = ref(false)
/**
 * 这份内容「不需要校验码」（后端 needCode=false，例如学员没有手机号）。
 * 一旦成立就在页面上常驻一行风险提示 —— 馆主明确担心「客户点进别人的内容」，
 * 无码链接必须让拿到链接的人自己知道它没有第二道锁。
 */
const noCodeWarn = ref(false)

const rv = computed<PublicReviewShare | null>(() =>
  content.value?.kind === 'review' ? content.value : null
)
const pl = computed<PublicPlanShare | null>(() =>
  content.value?.kind === 'plan' ? content.value : null
)
const isReview = computed(() => content.value?.kind === 'review')

/** 出具日期只到分钟：训练计划的 confirmedAt 是 `Y-m-d H:i:s`，秒对客户没有意义 */
function shortDate(v: string): string {
  return String(v).replace('T', ' ').slice(0, 16)
}

/** 头部一行元信息：教练 + 出具日期（都没有就不占位） */
const metaLine = computed(() => {
  const c = content.value
  if (!c) return ''
  const at = c.confirmedAt ? shortDate(c.confirmedAt) : ''
  return [c.teacherName ? `${c.teacherName} 教练` : '', at ? `${at} 出具` : '']
    .filter(Boolean)
    .join(' · ')
})

const footTeacher = computed(() => {
  const name = content.value?.teacherName || ''
  return name ? `这份内容由 ${name} 教练为你准备` : '这份内容由你的教练为你准备'
})

/** 档位 → 颜色：轻=品牌绿 / 中=提醒 / 重=告警（只是视觉分档，不做医学判断） */
function levelTone(level: string): 'brand' | 'warn' | 'danger' | 'muted' {
  if (level.includes('重')) return 'danger'
  if (level.includes('中')) return 'warn'
  if (level.includes('轻')) return 'brand'
  return 'muted'
}

/** 动作强度一句话：`3 组 × 12 次 · 间歇 60 秒`（缺哪段就少哪段） */
function actionSpec(a: { sets: string; reps: string; rest: string }): string {
  const volume = [a.sets, a.reps].filter(Boolean).join(' × ')
  const parts = [volume ? `${volume}` : '', a.rest ? `间歇 ${a.rest}` : ''].filter(Boolean)
  return parts.join(' · ')
}

/** 把后端失败翻译成人话（客户视角，不出现状态码与技术词） */
function messageOf(e: unknown): { stage: Stage; text: string } {
  if (e instanceof ShareError) {
    if (e.kind === 'gone') return { stage: 'gone', text: '' }
    if (e.kind === 'need-code') return { stage: 'need-code', text: '' }
    if (e.kind === 'bad-code') return { stage: 'need-code', text: '手机号后四位不正确，请重新输入' }
    if (e.kind === 'busy') return { stage: 'error', text: '访问太频繁了，请稍等一会儿再打开' }
  }
  return { stage: 'error', text: '网络不太顺畅，换一下网络再试' }
}

/** 拉内容：code 为空 = 第一次尝试（后端据此判 401 要不要码） */
async function load(withCode: string): Promise<void> {
  stage.value = 'loading'
  codeError.value = ''
  try {
    const data = await fetchPublicShare(token.value, withCode)
    content.value = data
    // 一次都没提交过 code 就拿到内容 → 这份内容本身不需要校验码
    const noCode = withCode === ''
    noCodeWarn.value = noCode
    stage.value = 'ready'
    // 校验通过才进缓存：401/403 的半途状态不缓存，避免下次跳过校验
    rememberPublicShare(token.value, { content: data, noCode })
  } catch (e) {
    const { stage: s, text } = messageOf(e)
    stage.value = s
    if (text) codeError.value = text
    // 需要校验但刚才给的码是错的：把输入框留着让人改
    if (s === 'need-code' && withCode !== '') code.value = withCode
  }
}

function retry(): void {
  if (!token.value) {
    stage.value = 'gone'
    return
  }
  void load(code.value ? code.value : '')
}

/**
 * 只收数字，最多 4 位（type="number" 在小程序里仍可能带进符号/空格）。
 *
 * 取值的两种形态必须都认，否则一边是编译期报错、一边是运行期取不到值：
 *  - 小程序原生 `<input>` 的 `@input` 给的是事件对象 `{ detail: { value } }`；
 *  - H5 / Vue 的 `onInput` 契约是 `(payload: InputEvent)`，值在 `target.value`。
 * 签名写成 `unknown`（而不是小程序的窄类型）也是为了让模板里直接绑不触发
 * `onInput` 的类型比对失败 —— 这个页面是免登录对外页，编译闸门不能红。
 */
function onCodeInput(e: unknown): void {
  const ev = e as
    | { detail?: { value?: unknown }; target?: { value?: unknown } }
    | null
    | undefined
  const raw = ev?.detail?.value ?? ev?.target?.value ?? ''
  code.value = String(raw).replace(/\D+/g, '').slice(0, 4)
  if (codeError.value) codeError.value = ''
}

function submitCode(): void {
  if (submitting.value) return
  const v = code.value.trim()
  if (v.length !== 4) {
    codeError.value = '请输入手机号后四位'
    return
  }
  submitting.value = true
  void load(v).finally(() => {
    submitting.value = false
  })
}

onLoad((options) => {
  const opt = (options ?? {}) as Record<string, string>
  token.value = String(opt.t ?? '').trim()
  if (!token.value) {
    // 没有 token 只能是「链接被截断 / 手工改了地址」，按失效处理，不进网络请求
    stage.value = 'gone'
    return
  }
  // 回到本页（或分享时带了 code）时先用进程内缓存，避免重复输后四位
  const hit = cachedPublicShare(token.value)
  if (hit) {
    content.value = hit.content
    noCodeWarn.value = hit.noCode
    stage.value = 'ready'
    return
  }
  // 链接里自带 code 时直接带上（正常分享不带，这是给「教练把码一起发」留的口子）
  const inline = String(opt.code ?? '').replace(/\D+/g, '').slice(0, 4)
  if (inline.length === 4) code.value = inline
  void load(inline.length === 4 ? inline : '')
})

// 客户可以把它再转给家人（同一个 token、同一道校验）；标题不带任何客户信息。
// 还没拿到内容时（输码门槛页）不能瞎猜类型 —— 用中性标题，否则 review 链接会被写成「训练计划」。
onShareAppMessage(() =>
  shareSelf(
    content.value ? (isReview.value ? '一麦 · 体态评估' : '一麦 · 训练计划') : '一麦 · 专属内容',
    shareViewPath(token.value)
  )
)
</script>

<template>
  <view class="ym-page share">
    <view class="share__brand">
      <text class="share__brand-name">一麦瑜伽 · 普拉提</text>
      <view class="share__brand-rule" />
    </view>

    <!-- 加载 -->
    <view v-if="stage === 'loading'" class="gate">
      <text class="gate__title">正在打开…</text>
      <text class="gate__desc">稍等一下，马上就好</text>
    </view>

    <!-- 失效（410 / 无 token） -->
    <view v-else-if="stage === 'gone'" class="gate">
      <text class="gate__title">分享已失效</text>
      <text class="gate__desc">这条链接可能已过期，或者已被教练重新生成。请联系你的教练再发一份给你。</text>
    </view>

    <!-- 网络异常 -->
    <view v-else-if="stage === 'error'" class="gate">
      <text class="gate__title">暂时打不开</text>
      <text class="gate__desc">{{ codeError || '网络不太顺畅，换一下网络再试' }}</text>
      <view class="gate__btn ym-tap" @click="retry">重试</view>
    </view>

    <!-- 手机号后四位 -->
    <view v-else-if="stage === 'need-code'" class="gate">
      <text class="gate__title">输入手机号后四位</text>
      <text class="gate__desc">
        你的教练把这份内容单独发给了你。为了保护隐私，输入手机号后四位就能看。
      </text>
      <input
        class="gate__input ym-num"
        type="number"
        :maxlength="4"
        :value="code"
        placeholder="例如 8821"
        @input="onCodeInput"
        @confirm="submitCode"
      />
      <text v-if="codeError" class="gate__err">{{ codeError }}</text>
      <view class="gate__btn ym-tap" @click="submitCode">
        {{ submitting ? '核对中…' : '查看我的内容' }}
      </view>
      <text class="gate__foot">后四位只用来核对这份内容是不是发给你的，不会被保存。</text>
    </view>

    <!-- 内容 -->
    <block v-else-if="stage === 'ready' && content">
      <view class="hero">
        <text class="hero__kicker">{{ isReview ? 'BODY ASSESSMENT' : 'TRAINING PLAN' }}</text>
        <text class="hero__title">{{ isReview ? '你的身体评估' : '你的训练安排' }}</text>
        <text v-if="metaLine" class="hero__meta">{{ metaLine }}</text>
      </view>

      <!-- 无校验码的风险提示（needCode=false 时常驻，不静默） -->
      <view v-if="noCodeWarn" class="warn">
        <text class="warn__text">
          这条链接没有设置查看校验，拿到链接的人都能看到。请只发给本人，不要转发到群里。
        </text>
      </view>

      <!-- ========== 客户分析 ========== -->
      <template v-if="rv">
        <ym-section v-if="rv.conclusion" title="评估结论" desc="这次课上教练的主要观察">
          <text class="quote">{{ rv.conclusion }}</text>
        </ym-section>

        <ym-section
          v-if="rv.dimensions.length"
          title="各维度观察"
          desc="轻 / 中 / 重 是教练当次的判断，不是诊断"
        >
          <view class="dims">
            <view v-for="(d, i) in rv.dimensions" :key="d.key || i" class="dimrow">
              <text class="dimrow__label">{{ d.label || '—' }}</text>
              <ym-badge :text="d.level || '—'" :tone="levelTone(d.level)" />
            </view>
          </view>
        </ym-section>

        <ym-section v-if="rv.suggestions.length" title="给你的建议" desc="按阶段来，不用一次到位">
          <view class="sug">
            <view v-for="(s, i) in rv.suggestions" :key="i" class="sug__card">
              <view class="sug__head">
                <text class="sug__name">{{ s.name || `第 ${i + 1} 阶段` }}</text>
                <text v-if="s.duration" class="sug__dur ym-num">{{ s.duration }}</text>
              </view>
              <text v-if="s.goal" class="sug__goal">{{ s.goal }}</text>
              <text v-if="s.focus" class="sug__focus">重点：{{ s.focus }}</text>
            </view>
          </view>
        </ym-section>

        <view v-if="rv.nextStep" class="next">
          <text class="next__label">下一步</text>
          <text class="next__text">{{ rv.nextStep }}</text>
        </view>
      </template>

      <!-- ========== 训练计划 ========== -->
      <template v-if="pl">
        <ym-section v-if="pl.coreGoal" title="核心目标" desc="这份计划围绕这一件事">
          <text class="quote">{{ pl.coreGoal }}</text>
        </ym-section>

        <ym-section v-if="pl.summary" title="整体安排">
          <text class="body">{{ pl.summary }}</text>
        </ym-section>

        <ym-section
          v-if="pl.phases.length"
          title="训练阶段"
          desc="按顺序练；动作细节以教练现场指导为准"
        >
          <view v-for="(p, pi) in pl.phases" :key="pi" class="phase">
            <view class="phase__head">
              <text class="phase__name">{{ p.name || `第 ${pi + 1} 阶段` }}</text>
              <text v-if="p.weeks" class="phase__weeks ym-num">{{ p.weeks }}</text>
            </view>
            <text v-if="p.goal" class="phase__goal">{{ p.goal }}</text>
            <view v-if="p.actions.length" class="acts">
              <view v-for="(a, ai) in p.actions" :key="ai" class="act">
                <view class="act__top">
                  <text class="act__name">{{ a.name || '动作' }}</text>
                  <text v-if="actionSpec(a)" class="act__spec ym-num">{{ actionSpec(a) }}</text>
                </view>
                <text v-if="a.note" class="act__note">{{ a.note }}</text>
              </view>
            </view>
          </view>
        </ym-section>

        <ym-section v-if="pl.cautions.length" title="注意这些">
          <view v-for="(c, i) in pl.cautions" :key="i" class="caution">
            <view class="caution__dot" />
            <text class="caution__text">{{ c }}</text>
          </view>
        </ym-section>
      </template>

      <view class="foot">
        <text class="foot__teacher">{{ footTeacher }}</text>
        <text class="foot__hint">
          训练中出现疼痛或不适请立刻停下并告诉教练；身体情况有变化时，建议重新做一次评估。
        </text>
        <text class="foot__brand">一麦瑜伽 · 普拉提</text>
      </view>
    </block>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.share {
  min-height: 100vh;
  padding-bottom: calc(60rpx + env(safe-area-inset-bottom));
  /* 对外页用浅底，卡片纯白 —— 与到店指引同一套观感，不做后台的灰底列表 */
  background: $brand-50;
}

/* ---------- 品牌条 ---------- */
.share__brand {
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 40rpx 40rpx 10rpx;
}

.share__brand-name {
  font-size: 24rpx;
  letter-spacing: 2rpx;
  color: $brand-700;
}

.share__brand-rule {
  flex: 1;
  height: 2rpx;
  background: linear-gradient(90deg, $brand-200, transparent);
}

/* ---------- 门槛态（加载 / 失效 / 输码） ---------- */
.gate {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
  padding: 120rpx 56rpx 0;
}

.gate__title {
  font-size: 44rpx;
  font-weight: 600;
  line-height: 1.35;
  color: $ink-900;
}

.gate__desc {
  font-size: 26rpx;
  line-height: 1.7;
  color: $ink-500;
}

.gate__input {
  height: 108rpx;
  padding: 0 32rpx;
  margin-top: 8rpx;
  font-size: 40rpx;
  letter-spacing: 12rpx;
  color: $ink-900;
  text-align: center;
  background: $white;
  border: 2rpx solid $brand-100;
  border-radius: $r-lg;
}

.gate__err {
  font-size: 24rpx;
  color: $danger-600;
}

.gate__btn {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 96rpx;
  margin-top: 8rpx;
  font-size: 30rpx;
  color: $white;
  background: $brand-500;
  border-radius: 999rpx;
}

.gate__foot {
  font-size: 22rpx;
  line-height: 1.6;
  color: $ink-400;
}

/* ---------- 头图区 ---------- */
.hero {
  display: flex;
  flex-direction: column;
  gap: 10rpx;
  padding: 24rpx 40rpx 8rpx;
}

.hero__kicker {
  font-size: 20rpx;
  letter-spacing: 4rpx;
  color: $brand-400;
}

.hero__title {
  font-size: 52rpx;
  font-weight: 600;
  line-height: 1.3;
  color: $ink-900;
}

.hero__meta {
  font-size: 24rpx;
  color: $ink-400;
}

/* ---------- 无校验码提示 ---------- */
.warn {
  padding: 20rpx 24rpx;
  margin: 20rpx 24rpx 0;
  background: $warn-100;
  border-radius: $r-sm;
}

.warn__text {
  font-size: 23rpx;
  line-height: 1.6;
  color: $warn-600;
}

/* ---------- 正文 ---------- */
.quote {
  display: block;
  font-size: 30rpx;
  line-height: 1.75;
  color: $ink-800;
}

.body {
  display: block;
  font-size: 26rpx;
  line-height: 1.75;
  color: $ink-600;
}

.dims {
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}

.dimrow {
  display: flex;
  gap: 16rpx;
  align-items: center;
  justify-content: space-between;
  padding: 14rpx 0;
  border-top: 2rpx solid $hairline;

  &:first-child {
    border-top: none;
  }
}

.dimrow__label {
  flex: 1;
  min-width: 0;
  font-size: 26rpx;
  color: $ink-700;
}

/* ---------- 建议卡片 ---------- */
.sug {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.sug__card {
  padding: 22rpx 24rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

.sug__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12rpx;
}

.sug__name {
  font-size: 27rpx;
  font-weight: 500;
  color: $ink-800;
}

.sug__dur {
  flex-shrink: 0;
  font-size: 22rpx;
  color: $brand-600;
}

.sug__goal {
  display: block;
  margin-top: 8rpx;
  font-size: 25rpx;
  line-height: 1.6;
  color: $ink-600;
}

.sug__focus {
  display: block;
  margin-top: 6rpx;
  font-size: 23rpx;
  line-height: 1.6;
  color: $ink-400;
}

/* ---------- 下一步 ---------- */
.next {
  display: flex;
  flex-direction: column;
  gap: 10rpx;
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx;
  background: $brand-100;
  border-radius: $r-lg;
}

.next__label {
  font-size: 21rpx;
  letter-spacing: 2rpx;
  color: $brand-700;
}

.next__text {
  font-size: 27rpx;
  line-height: 1.7;
  color: $ink-800;
}

/* ---------- 训练阶段 ---------- */
.phase {
  & + & {
    margin-top: 28rpx;
    padding-top: 24rpx;
    border-top: 2rpx solid $hairline;
  }
}

.phase__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12rpx;
}

.phase__name {
  font-size: 28rpx;
  font-weight: 600;
  color: $ink-800;
}

.phase__weeks {
  flex-shrink: 0;
  font-size: 22rpx;
  color: $brand-600;
}

.phase__goal {
  display: block;
  margin-top: 6rpx;
  font-size: 24rpx;
  line-height: 1.6;
  color: $ink-500;
}

.acts {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  margin-top: 16rpx;
}

.act {
  padding: 18rpx 20rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

.act__top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12rpx;
}

.act__name {
  flex: 1;
  min-width: 0;
  font-size: 26rpx;
  color: $ink-800;
}

.act__spec {
  flex-shrink: 0;
  font-size: 22rpx;
  color: $brand-600;
}

.act__note {
  display: block;
  margin-top: 6rpx;
  font-size: 23rpx;
  line-height: 1.55;
  color: $ink-400;
}

/* ---------- 注意事项 ---------- */
.caution {
  display: flex;
  gap: 12rpx;
  padding: 10rpx 0;
}

.caution__dot {
  flex-shrink: 0;
  width: 10rpx;
  height: 10rpx;
  margin-top: 14rpx;
  background: $warn-600;
  border-radius: 50%;
}

.caution__text {
  flex: 1;
  font-size: 25rpx;
  line-height: 1.65;
  color: $ink-600;
}

/* ---------- 页脚 ---------- */
.foot {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  padding: 40rpx 40rpx 0;
}

.foot__teacher {
  font-size: 25rpx;
  color: $ink-700;
}

.foot__hint {
  font-size: 22rpx;
  line-height: 1.65;
  color: $ink-400;
}

.foot__brand {
  margin-top: 8rpx;
  font-size: 21rpx;
  letter-spacing: 3rpx;
  color: $ink-300;
}
</style>
