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
  /** 上游来源：由哪次课后分析 / 哪份体测流转而来（后端只读，不由前端维护） */
  sourceReviewId?: number | null
  sourceBodyTestId?: number | null
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
        {
          name: '第1-2周 基础建立',
          duration: '2周',
          items: ['腹式呼吸与肋间呼吸', '仰卧中立位找发力', '猫牛式脊柱灵活']
        },
        { name: '第3-4周 核心激活', duration: '2周', items: ['死虫式', '鸟狗式', '肩颈松解系列'] },
        {
          name: '第5-6周 力量整合',
          duration: '2周',
          items: ['平板支撑进阶', '桥式系列', '全身串联流']
        }
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
  /** 待删除的计划 id（本地 id）：提交时显式带给后端，避免用「不在提交列表里」推断删除 */
  const pendingDeletes = ref<number[]>([])
  /** 服务端已知状态：serverId → 内容指纹。用于只提交发生变化的行 */
  const baseline = ref<Record<string, string>>({})
  /** 本地 id → 服务端 id：新建计划服务端可能另分配主键（id 是全局主键，跨账号会撞） */
  const serverIdMap = ref<Record<string, number>>({})

  function storageKey(userId: string): string {
    return `${STORAGE_PREFIX}${encodeURIComponent(userId)}`
  }

  function reset() {
    loadedUserId.value = ''
    state.value = emptyState()
    pendingDeletes.value = []
    baseline.value = {}
    serverIdMap.value = {}
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
    serverIdMap.value = {}
    baseline.value = Object.fromEntries(plans.map((p) => [String(p.id), fingerprint(p)]))
  }

  /** 内容指纹：不含 id（新建计划的本地 id 与服务端主键可能不同） */
  function fingerprint(plan: TrainingPlan): string {
    const rest: Record<string, unknown> = { ...(plan as unknown as Record<string, unknown>) }
    delete rest.id
    return JSON.stringify(rest)
  }

  function serverIdOf(localId: number): number {
    return serverIdMap.value[localId] ?? localId
  }

  /**
   * 只挑出发生变化的行：新增（基线里没有）或内容变了。
   *
   * 关键点：不再提交整表。以前整表提交 + 服务端整表替换，任何一份较早的客户端列表
   * 都能把服务端刚写入的计划（如课后分析流转生成的那份）覆盖掉。
   */
  function pendingChanges(): { plans: TrainingPlan[]; deletedIds: number[] } {
    const changed = state.value.plans.filter((p) => {
      const sid = String(serverIdOf(p.id))
      return baseline.value[sid] === undefined || baseline.value[sid] !== fingerprint(p)
    })
    // 删除同样按显式列表；没同步过的新增行直接本地丢掉即可，不必往返服务端
    const deletedIds = pendingDeletes.value
      .map((id) => serverIdOf(id))
      .filter((sid) => baseline.value[String(sid)] !== undefined)

    return { plans: changed, deletedIds }
  }

  /** 提交成功：记录新基线，并在服务端另分配主键时校正本地映射 */
  function applySyncResult(ids: { clientId: number; serverId: number }[] = []): void {
    ids.forEach(({ clientId, serverId }) => {
      if (clientId > 0 && serverId > 0 && clientId !== serverId) {
        serverIdMap.value[clientId] = serverId
      }
    })
    baseline.value = Object.fromEntries(
      state.value.plans.map((p) => [String(serverIdOf(p.id)), fingerprint(p)])
    )
    pendingDeletes.value = []
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

  function saveDraft(
    plan: Omit<
      TrainingPlan,
      | 'id'
      | 'status'
      | 'content'
      | 'source'
      | 'createdBy'
      | 'createdAt'
      | 'confirmedAt'
      | 'images'
      | 'share'
    > & { id?: number }
  ): number {
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
      ...(plan as Omit<
        TrainingPlan,
        | 'id'
        | 'status'
        | 'content'
        | 'source'
        | 'createdBy'
        | 'createdAt'
        | 'confirmedAt'
        | 'images'
        | 'share'
      >),
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
    audit(
      '新增',
      `训练计划 #${id} ${name}`,
      `录入会员情况：目标[${plan.coreGoal}] 频率[${plan.freq}]`
    )
    return id
  }

  function attachContent(id: number, content: PlanContent, source: 'llm' | 'fallback') {
    const p = state.value.plans.find((x) => x.id === id)
    if (!p) return
    p.content = content
    p.source = source
    p.status = '待老师确认'
    audit(
      '生成',
      `训练计划 #${id} ${p.memberName}`,
      `AI生成草稿（${source === 'llm' ? '大模型' : '本地模板'}），待有权限老师确认`
    )
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
    if (enabled && !p.share.code)
      p.share.code = `plan-${p.id}-${Math.random().toString(36).slice(2, 6)}`
    p.share.enabled = enabled
    audit(
      '修改',
      `训练计划 #${id} ${p.memberName}`,
      `H5分享${enabled ? '已开启，链接 /s/plan/' + p.share.code : '已停用'}`
    )
  }

  function registerPlanView(code: string) {
    const p = state.value.plans.find((x) => x.share.code === code)
    if (p) p.share.views += 1
  }

  function removePlan(id: number) {
    const p = state.value.plans.find((x) => x.id === id)
    state.value.plans = state.value.plans.filter((x) => x.id !== id)
    // 记录待删除 id：后端按显式列表删除，避免用「不在提交列表里」推断而误删服务端生成计划
    if (id > 0 && !pendingDeletes.value.includes(id)) pendingDeletes.value.push(id)
    if (p) audit('修改', `训练计划 #${id} ${p.memberName}`, '删除计划草稿')
  }

  function clearPendingDeletes(): void {
    pendingDeletes.value = []
  }

  return {
    state,
    loadedUserId,
    pendingDeletes,
    clearPendingDeletes,
    pendingChanges,
    applySyncResult,
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
