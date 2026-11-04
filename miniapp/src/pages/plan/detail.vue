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
import { onLoad } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { requireFeature } from '@/utils/nav'
import { isManagerOrAbove } from '@/utils/permission'
import {
  createTrainingPlan,
  emptyPlan,
  fetchTrainingPlans,
  movePlanDraft,
  normalizePlan,
  readPlanDraft,
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

/** 详情数据：先本地草稿，再回查列表（后端没有 show 接口） */
async function loadPlan() {
  if (!planId.value) return
  loading.value = true
  errorText.value = ''
  try {
    const draft = readPlanDraft(planId.value)
    if (draft) {
      plan.value = draft
      loaded.value = true
      return
    }
    // 草稿丢了（换设备/清缓存）：用列表接口回查同一行
    const rows = await fetchTrainingPlans()
    const hit = rows.find((p) => p.id === planId.value) ?? null
    plan.value = hit
    loaded.value = true
    if (!hit) errorText.value = '计划不存在或不在你的可见范围内'
  } catch (e) {
    errorText.value = e instanceof Error ? e.message : '加载失败'
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

function openEdit() {
  if (!plan.value) return
  if (!canEdit.value) {
    uni.showToast({ title: '只能编辑自己创建的计划', icon: 'none' })
    return
  }
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
  else uni.redirectTo({ url: '/pages/plan/plan' })
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
        <view class="pd__head">
          <view class="pd__head-row">
            <text class="pd__name ym-ellipsis">{{ plan.memberName }}</text>
            <ym-badge :text="plan.status" :tone="statusTone(plan.status)" />
          </view>
          <text v-if="bodyLine(plan)" class="pd__meta">{{ bodyLine(plan) }}</text>
          <view class="pd__tags">
            <ym-badge v-if="plan.sourceReviewId" text="课后分析流转" tone="info" />
            <ym-badge v-if="plan.venue" :text="plan.venue" tone="muted" />
          </view>
        </view>

        <view class="pd__actions">
          <wd-button v-if="canEdit" size="small" plain @click="openEdit">编辑</wd-button>
          <text v-else class="pd__readonly">这条计划由 {{ plan.createdBy || '他人' }} 创建，你只能查看</text>
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
    align-items: center;
    gap: 16rpx;
    padding: 20rpx 28rpx 0;
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
