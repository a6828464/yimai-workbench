import { defineStore } from 'pinia'
import { useYimaiStore } from './yimai'

export interface SalesStoreInfo {
  name: string
  industry: string
  slogan: string
  intro: string
  address: string
  phone: string
}

export interface SalesProduct {
  id: number
  name: string
  desc: string
  showPrice: boolean
  cols: string[]
  rows: string[][]
}

export interface SalesCoach {
  id: number
  name: string
  title: string
  tags: string[]
  intro: string
}

export interface SalesCase {
  id: number
  coachId: number | ''
  goal: string
  desc: string
  authorized: boolean
  stages: { duration: string }[]
}

export interface SalesShare {
  enabled: boolean
  code: string
  views: number
}

const SEED_INFO: SalesStoreInfo = {
  name: '一麦瑜伽（绿地店）',
  industry: '瑜伽 · 普拉提',
  slogan: '让身体回到本来的样子',
  intro: '专业瑜伽普拉提馆，小班与私教并重，从评估开始定制练习路径。',
  address: '宁波市鄞州区绿地缤纷城1号楼4层',
  phone: '0574-87214863'
}

function seedProducts(): SalesProduct[] {
  return [
    { id: 1, name: '精品白领年卡', desc: '每周3次精品小班，适合久坐上班族建立规律练习。', showPrice: true, cols: ['卡项', '次数', '有效期', '标准价'], rows: [['年卡（3次/周）', '不限次·限每周3次', '12个月', '¥6800'], ['半年卡', '不限次·限每周3次', '6个月', '¥3980']] },
    { id: 2, name: '私教课程', desc: '一对一评估定制，针对体态改善、产后修复与肩颈腰背。', showPrice: true, cols: ['卡项', '节数', '有效期', '标准价'], rows: [['VIP私教', '50节', '24个月', '¥22500'], ['私教小班', '10节', '12个月', '¥3200']] },
    { id: 3, name: '新客体验', desc: '首次到店体验课，含体态评估与练习建议。', showPrice: false, cols: ['项目', '时长', '说明'], rows: [['体验课', '60分钟', '新客专享·需预约']] }
  ]
}

function seedCoaches(): SalesCoach[] {
  return [
    { id: 1, name: '婷婷', title: '资深教销导师', tags: ['体态改善', '续费沟通', '小班教学'], intro: '8年教龄，擅长把专业目标翻译成学员听得懂的练习计划。' },
    { id: 2, name: '黄敏', title: '普拉提主教练', tags: ['核心床', '产后修复', '呼吸'], intro: '器械普拉提方向，注重中立位与发力顺序的细节打磨。' },
    { id: 3, name: '冰璐', title: '私教导师', tags: ['减脂塑形', '私教陪跑'], intro: '陪伴式教学风格，擅长帮零基础学员建立信心与节奏。' },
    { id: 4, name: '芷晴', title: '小班教练', tags: ['内观流', '团课氛围'], intro: '课堂感染力强，内观流主题课广受会员好评。' }
  ]
}

function seedCases(): SalesCase[] {
  return [
    { id: 1, coachId: 1, goal: '改善骨盆前倾与肩颈紧张', desc: '办公室人群典型体态问题，通过12周小班+居家作业结合逐步改善。', authorized: true, stages: [{ duration: '' }, { duration: '第4周' }, { duration: '第12周' }] },
    { id: 2, coachId: 2, goal: '产后核心恢复', desc: '产后8个月妈妈，从呼吸重建开始到核心床训练，恢复腹部力量。', authorized: true, stages: [{ duration: '' }, { duration: '第6周' }] }
  ]
}

interface SalesState {
  info: SalesStoreInfo
  products: SalesProduct[]
  coaches: SalesCoach[]
  cases: SalesCase[]
  share: SalesShare
}

export const useSalesStore = defineStore('salesStore', () => {
  const state = ref<SalesState>({
    info: { ...SEED_INFO },
    products: seedProducts(),
    coaches: seedCoaches(),
    cases: seedCases(),
    share: { enabled: true, code: 'yimai-lvdi', views: 0 }
  })

  function audit(action: string, targetLabel: string, detail: string) {
    try {
      useYimaiStore().writeAudit(action, '谈单工具', 0, targetLabel, state.value.info.name.includes('东部') ? '东部店' : '绿地店', detail)
    } catch {
      /* 审计失败不阻塞主流程 */
    }
  }

  function updateInfo(info: SalesStoreInfo) {
    state.value.info = { ...info }
    audit('修改', '门店基础信息', `更新门店资料[${info.name}]`)
  }

  function saveProducts(products: SalesProduct[]) {
    state.value.products = products.map((p) => ({ ...p }))
    audit('修改', '产品与价目表', `共${products.length}个产品；价目展示开关：${products.filter((p) => p.showPrice).map((p) => p.name).join('、') || '无'}`)
  }

  function saveCoaches(coaches: SalesCoach[]) {
    state.value.coaches = coaches.map((c) => ({ ...c }))
    audit('修改', '推荐教练', `当前${coaches.length}位：${coaches.map((c) => c.name).join('、')}`)
  }

  function saveCases(cases: SalesCase[]) {
    state.value.cases = cases.map((c) => ({ ...c }))
    audit('修改', '学员案例', `当前${cases.length}个案例；未授权案例不进入分享页：${cases.filter((c) => !c.authorized).length}个`)
  }

  function setShareEnabled(enabled: boolean) {
    state.value.share.enabled = enabled
    audit('修改', '分享设置', `分享链接${enabled ? '已开启' : '已停用'}；访问量 ${state.value.share.views}`)
  }

  function resetShareCode(code: string) {
    state.value.share.code = code
    audit('修改', '分享设置', `分享码重置为 ${code}，旧链接失效`)
  }

  function registerView() {
    state.value.share.views += 1
  }

  return { state, updateInfo, saveProducts, saveCoaches, saveCases, setShareEnabled, resetShareCode, registerView }
}, {
  persist: {
    key: 'yimai-sales-store',
    storage: localStorage
  }
})
