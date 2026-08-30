import { defineStore } from 'pinia'
import { USE_BACKEND } from '@/api/backend'
import { useUserStore } from './user'
import { useYimaiStore } from './yimai'

export interface PlanPhase {
  name: string
  duration: string
  items: string[]
}

export interface PlanContent {
  summary: string
  phases: PlanPhase[]
  cautions: string[]
}

export type TrainingStatus = '待生成' | '待老师确认' | '已确认'

export interface PlanImage {
  id: number
  url: string
  label: string
}

export interface TrainingPlan {
  images: PlanImage[]
  share: { enabled: boolean; code: string; views: number }

  id: number
  memberName: string
  age: string
  gender: '女' | '男'
  height: string
  weight: string
  bodyFat: string
  focus: string
  coreGoal: string
  freq: string
  stageWeeks: string
  stageGoal: string
  risks: string
  status: TrainingStatus
  content: PlanContent | null
  source: '' | 'llm' | 'fallback'
  createdBy: string
  createdAt: string
  confirmedAt: string
}

const SEEDS: TrainingPlan[] = [
  {
    images: [],
    share: { enabled: true, code: 'plan-1-demo', views: 0 },
    id: 1,
    memberName: '陈晓芸',
    age: '32',
    gender: '女',
    height: '163',
    weight: '55',
    bodyFat: '26',
    focus: '肩颈紧张、核心无力',
    coreGoal: '改善体态、建立核心力量',
    freq: '每周2-3次小班',
    stageWeeks: '6',
    stageGoal: '掌握呼吸发力模式，完成标准平板支撑1分钟',
    risks: '无特殊风险',
    status: '已确认',
    source: 'fallback',
    content: {
      summary: '以呼吸重建与核心激活为主线的6周入门计划，配合肩颈放松练习。',
      phases: [
        { name: '第1-2周 基础建立', duration: '2周', items: ['腹式呼吸与肋间呼吸', '仰卧中立位找发力', '猫牛式脊柱灵活'] },
        { name: '第3-4周 核心激活', duration: '2周', items: ['死虫式', '鸟狗式', '肩颈松解系列'] },
        { name: '第5-6周 力量整合', duration: '2周', items: ['平板支撑进阶', '桥式系列', '全身串联流'] }
      ],
      cautions: ['练习中如出现手麻头晕立即停止并告知教练', '生理期前三天降低强度']
    },
    createdBy: '婷婷',
    createdAt: '2026-08-20 15:20',
    confirmedAt: '2026-08-21 09:10'
  }
]

function nowStr(): string {
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

interface TrainingState {
  nextId: number
  plans: TrainingPlan[]
}

const STORAGE_PREFIX = 'yimai-training-store:user:'

function emptyState(): TrainingState {
  return {
    nextId: 100,
    plans: USE_BACKEND ? [] : JSON.parse(JSON.stringify(SEEDS))
  }
}

export const useTrainingStore = defineStore('trainingStore', () => {
  const state = ref<TrainingState>(emptyState())
  const loadedUserId = ref('')

  function storageKey(userId: string): string {
    return `${STORAGE_PREFIX}${encodeURIComponent(userId)}`
  }

  function reset() {
    loadedUserId.value = ''
    state.value = emptyState()
  }

  function loadForUser(userId: string | number) {
    const id = String(userId || '')
    reset()
    localStorage.removeItem('yimai-training-store')
    if (!id) return

    loadedUserId.value = id
    if (USE_BACKEND) return

    const saved = localStorage.getItem(storageKey(id))
    if (!saved) return
    try {
      const parsed = JSON.parse(saved) as TrainingState
      if (Array.isArray(parsed.plans)) {
        state.value = {
          plans: parsed.plans,
          nextId: Number(parsed.nextId) || 100
        }
      }
    } catch {
      localStorage.removeItem(storageKey(id))
    }
  }

  function replacePlans(plans: TrainingPlan[]) {
    state.value.plans = plans
    state.value.nextId = Math.max(99, ...plans.map((p) => Number(p.id ?? 0))) + 1
  }

  watch(
    state,
    (value) => {
      if (!USE_BACKEND && loadedUserId.value) {
        localStorage.setItem(storageKey(loadedUserId.value), JSON.stringify(value))
      }
    },
    { deep: true }
  )

  function actorName(): string {
    return useUserStore().getUserInfo.userName ?? ''
  }

  function audit(action: string, targetLabel: string, detail: string) {
    useYimaiStore().writeAudit(action, '训练计划', 0, targetLabel, '双店', detail)
  }

  function saveDraft(plan: Omit<TrainingPlan, 'id' | 'status' | 'content' | 'source' | 'createdBy' | 'createdAt' | 'confirmedAt' | 'images' | 'share'> & { id?: number }): number {
    const name = plan.memberName || '未命名会员'
    if (plan.id) {
      const i = state.value.plans.findIndex((p) => p.id === plan.id)
      if (i >= 0) {
        const prev = state.value.plans[i]
        state.value.plans.splice(i, 1, { ...prev, ...plan, id: plan.id })
        audit('修改', `训练计划 #${plan.id} ${name}`, '更新会员情况或目标信息，草稿需重新生成确认')
        return plan.id
      }
    }
    const id = state.value.nextId++
    state.value.plans.unshift({
      ...(plan as Omit<TrainingPlan, 'id' | 'status' | 'content' | 'source' | 'createdBy' | 'createdAt' | 'confirmedAt' | 'images' | 'share'>),
      id,
      status: '待生成',
      content: null,
      source: '',
      createdBy: actorName(),
      createdAt: nowStr(),
      confirmedAt: '',
      images: [],
      share: { enabled: false, code: '', views: 0 }
    })
    audit('新增', `训练计划 #${id} ${name}`, `录入会员情况：目标[${plan.coreGoal}] 频率[${plan.freq}]`)
    return id
  }

  function attachContent(id: number, content: PlanContent, source: 'llm' | 'fallback') {
    const p = state.value.plans.find((x) => x.id === id)
    if (!p) return
    p.content = content
    p.source = source
    p.status = '待老师确认'
    audit('生成', `训练计划 #${id} ${p.memberName}`, `AI生成草稿（${source === 'llm' ? '大模型' : '本地模板'}），待有权限老师确认`)
  }

  function confirmPlan(id: number) {
    const p = state.value.plans.find((x) => x.id === id)
    if (!p || !p.content) return
    p.status = '已确认'
    p.confirmedAt = nowStr()
    if (!p.share.code) p.share.code = `plan-${p.id}-${Math.random().toString(36).slice(2, 6)}`
    audit('确认', `训练计划 #${id} ${p.memberName}`, `由 ${actorName()} 确认生效，可向会员分享`)
  }

  function addPlanImage(id: number, url: string, label: string) {
    const p = state.value.plans.find((x) => x.id === id)
    if (!p) return
    if (p.images.length >= 8) return
    p.images.push({ id: Date.now(), url, label: label || `对比照${p.images.length + 1}` })
  }

  function removePlanImage(id: number, imgId: number) {
    const p = state.value.plans.find((x) => x.id === id)
    if (!p) return
    p.images = p.images.filter((img) => img.id !== imgId)
  }

  function setPlanShare(id: number, enabled: boolean) {
    const p = state.value.plans.find((x) => x.id === id)
    if (!p) return
    if (enabled && !p.share.code) p.share.code = `plan-${p.id}-${Math.random().toString(36).slice(2, 6)}`
    p.share.enabled = enabled
    audit('修改', `训练计划 #${id} ${p.memberName}`, `H5分享${enabled ? '已开启，链接 /s/plan/' + p.share.code : '已停用'}`)
  }

  function registerPlanView(code: string) {
    const p = state.value.plans.find((x) => x.share.code === code)
    if (p) p.share.views += 1
  }

  function removePlan(id: number) {
    const p = state.value.plans.find((x) => x.id === id)
    state.value.plans = state.value.plans.filter((x) => x.id !== id)
    if (p) audit('修改', `训练计划 #${id} ${p.memberName}`, '删除计划草稿')
  }

  return {
    state,
    loadedUserId,
    reset,
    loadForUser,
    replacePlans,
    saveDraft,
    attachContent,
    confirmPlan,
    addPlanImage,
    removePlanImage,
    setPlanShare,
    registerPlanView,
    removePlan
  }
})
