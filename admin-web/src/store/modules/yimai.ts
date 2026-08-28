import { defineStore } from 'pinia'
import { useUserStore } from './user'

export interface YimaiLead {
  id: number
  leadDate: string
  name: string
  /** 完整手机号（内部系统全量显示） */
  phone?: string
  phoneTail?: string
  /** 微信号 */
  wechat?: string
  demand: string
  source: string
  /** 体验课下单平台 */
  orderPlatform?: string
  venue: '绿地店' | '东部店'
  serviceTeacher: string
  status: '新留资' | '已联系' | '已约体验' | '已体验' | '已成交' | '已流失' | '爽约'
  grade: '' | 'A' | 'B' | 'C'
  trialTime: string
  trialTopic: string
  trialTeacher: string
  dealCard: string
  dealAmount: number | null
  /** 团单核销金额 */
  redeemAmount: number | null
  voucherCode: string
  /** 平台购买的券名称 */
  couponName?: string
  /** 券码总次数 */
  couponTotal?: number | null
  /** 券码剩余次数 */
  couponRemaining?: number | null
  /** 体验课节次卡片：第一节课/第二节课 各自券码等 */
  trialCards?: Array<{
    session: number
    couponName: string
    voucherCode: string
    platform: string
    total?: number | null
    remaining?: number | null
  }>
  remark: string
  createdBy: string
  createdAt: string
}

export interface YimaiAuditLog {
  id: number
  time: string
  operatorName: string
  operatorRole: string
  action: string
  module: string
  targetId: number | string
  targetLabel: string
  venue: string
  detail: string
  /** 操作来源 IP 与设备（后端记录） */
  ip?: string
  userAgent?: string
}

export interface YimaiCustomer {
  id: number
  name: string
  /** 完整手机号（内部系统全量显示） */
  phone?: string
  phoneTail: string
  venue: '绿地店' | '东部店'
  source: string
  /** 随心瑜导出数据中的会籍顾问；为空才是待分配 */
  consultant?: string
  mainCard: string
  remainTimes: number | null
  expireDate: string | null
  lastVisit: string | null
  layer: 'P0' | 'P1' | 'P2' | 'P3' | 'P4' | 'P5'
  status: string
  owner: string
  nextAction: string
  nextActionTime: string
  /** KeepYoga 等外部系统的唯一标识，用于导入去重 */
  externalId?: string
  /** —— 卓越店长训练营清单体系：出勤与库存 —— */
  attendM1?: number
  attendM2?: number
  attendM3?: number
  totalPurchased?: number | null
  /** —— 待续课工作流字段 —— */
  renewalPlan?: { time: string; amount: string; course: string; issue: string; intent: string }
  /** —— 出勤降低 —— */
  decline?: { reason: string; solution: string }
  /** —— 预流失/待复活 —— */
  stopReason?: string
  expectedReturn?: string
  lastTouch?: string
  needsHelp?: boolean
  inRevive?: boolean
  /** 30天续费评估 */
  evalScore?: number | null
  evalAt?: string
}

/** 清单规则阈值（来源：卓越店长训练营，阈值可调） */
export interface MemberRules {
  renewalThreshold: number
  vipThreshold: number
  declineMode: 'strict' | 'recent'
  /** 预流失：N-M 天未到店（默认 15-30） */
  predropMin: number
  predropMax: number
  /** 待复活：超过 N 天未到店且有资产（默认 30） */
  reviveDays: number
}

export interface YimaiSyncSnapshot {
  fetchedAt: string
  fetchedBy: string
  counts: Record<
    string,
    {
      members: number | string
      visitors: number | string
      mcards: number | string
      contracts: number | string
    }
  >
  todayBookings: Record<string, number>
  trialBookings: Record<string, number>
}

interface YimaiState {
  version: number
  nextLeadId: number
  nextAuditId: number
  nextCustomerId: number
  leads: YimaiLead[]
  auditLogs: YimaiAuditLog[]
  snapshot: YimaiSyncSnapshot | null
  customers: YimaiCustomer[]
  rules: MemberRules
}

const SEED_VERSION = 5

const DEFAULT_RULES: MemberRules = {
  renewalThreshold: 10,
  vipThreshold: 100,
  declineMode: 'strict',
  predropMin: 15,
  predropMax: 30,
  reviveDays: 30
}

function seedCustomers(): YimaiCustomer[] {
  const base: YimaiCustomer[] = [
    {
      id: 1,
      name: '王雅琴',
      phoneTail: '2073',
      venue: '绿地店',
      source: '大众点评',
      mainCard: '精品白领年卡（3次/周）',
      remainTimes: 18,
      expireDate: '2026-11-08',
      lastVisit: '2026-08-24',
      layer: 'P0',
      status: '跟进中',
      owner: '婷婷',
      nextAction: '聊下一阶段目标铺垫续费',
      nextActionTime: '2026-08-27',
      attendM1: 6,
      attendM2: 5,
      attendM3: 4,
      totalPurchased: 120
    },
    {
      id: 2,
      name: '李梦',
      phoneTail: '5581',
      venue: '绿地店',
      source: '朋友介绍',
      mainCard: 'VIP私教200节',
      remainTimes: 66,
      expireDate: '2027-01-06',
      lastVisit: '2026-07-02',
      layer: 'P1',
      status: '跟进中',
      owner: '冰璐',
      nextAction: '恢复节奏邀约到店',
      nextActionTime: '2026-08-28',
      attendM1: 8,
      attendM2: 7,
      attendM3: 7,
      totalPurchased: 150
    },
    {
      id: 3,
      name: '张璐',
      phoneTail: '3390',
      venue: '东部店',
      source: '美团',
      mainCard: '全能小班一年卡',
      remainTimes: 42,
      expireDate: '2026-12-20',
      lastVisit: '2026-08-25',
      layer: 'P4',
      status: '跟进中',
      owner: '芷晴',
      nextAction: '安排私教体测评估',
      nextActionTime: '2026-08-29',
      attendM1: 5,
      attendM2: 6,
      attendM3: 6,
      totalPurchased: 60
    },
    {
      id: 4,
      name: '陈晓芸',
      phoneTail: '8826',
      venue: '绿地店',
      source: '小红书',
      mainCard: '私享定制小班',
      remainTimes: 1,
      expireDate: '2026-09-15',
      lastVisit: '2026-08-10',
      layer: 'P0',
      status: '跟进中',
      owner: '娟子',
      nextAction: '剩余1节沟通续费方案',
      nextActionTime: '2026-08-26',
      attendM1: 4,
      attendM2: 3,
      attendM3: 2,
      totalPurchased: 80
    },
    {
      id: 5,
      name: '刘思颖',
      phoneTail: '4417',
      venue: '东部店',
      source: '自然到店',
      mainCard: '—',
      remainTimes: null,
      expireDate: null,
      lastVisit: '2026-08-22',
      layer: 'P5',
      status: '体验完成',
      owner: '苏米',
      nextAction: '体验后48小时内报价跟进',
      nextActionTime: '2026-08-26',
      attendM1: 2,
      attendM2: 1,
      attendM3: 0,
      totalPurchased: 0
    },
    {
      id: 6,
      name: '赵一诺',
      phoneTail: '9052',
      venue: '绿地店',
      source: '抖音',
      mainCard: '—',
      remainTimes: null,
      expireDate: null,
      lastVisit: '2026-08-21',
      layer: 'P5',
      status: '已预约',
      owner: '婷婷',
      nextAction: '预约确认与到店准备',
      nextActionTime: '2026-08-27',
      attendM1: 3,
      attendM2: 2,
      attendM3: 2,
      totalPurchased: 0
    },
    {
      id: 7,
      name: '孙美琪',
      phoneTail: '6634',
      venue: '东部店',
      source: '美团',
      mainCard: '精品团课季卡',
      remainTimes: 6,
      expireDate: '2026-10-30',
      lastVisit: '2026-06-18',
      layer: 'P2',
      status: '跟进中',
      owner: '张青',
      nextAction: '确认频次下降原因',
      nextActionTime: '2026-08-27',
      attendM1: 9,
      attendM2: 6,
      attendM3: 3,
      totalPurchased: 45
    },
    {
      id: 8,
      name: '周雨彤',
      phoneTail: '1278',
      venue: '绿地店',
      source: '朋友介绍',
      mainCard: 'VIP私教50节',
      remainTimes: 12,
      expireDate: '2026-09-28',
      lastVisit: '2026-07-20',
      layer: 'P3',
      status: '跟进中',
      owner: '冰璐',
      nextAction: '过期余额卡邀约评估',
      nextActionTime: '2026-08-30',
      attendM1: 3,
      attendM2: 2,
      attendM3: 0,
      totalPurchased: 55
    },
    {
      id: 9,
      name: '吴佳宁',
      phoneTail: '7745',
      venue: '东部店',
      source: '大众点评',
      mainCard: '全能小班年卡',
      remainTimes: 88,
      expireDate: '2027-03-01',
      lastVisit: '2026-08-19',
      layer: 'P4',
      status: '跟进中',
      owner: '芷晴',
      nextAction: '小班转私教意向摸底',
      nextActionTime: '2026-08-31',
      attendM1: 6,
      attendM2: 6,
      attendM3: 5,
      totalPurchased: 110
    },
    {
      id: 10,
      name: '郑好',
      phoneTail: '3509',
      venue: '绿地店',
      source: '美团',
      mainCard: '—',
      remainTimes: null,
      expireDate: null,
      lastVisit: '2026-08-20',
      layer: 'P5',
      status: '新留资',
      owner: '未分配',
      nextAction: '新客24小时内首响',
      nextActionTime: '2026-08-26',
      attendM1: 0,
      attendM2: 0,
      attendM3: 0,
      totalPurchased: 0
    },
    {
      id: 11,
      name: '冯悦',
      phoneTail: '6821',
      venue: '绿地店',
      source: '小红书',
      mainCard: '精品团课年卡',
      remainTimes: 30,
      expireDate: '2026-12-11',
      lastVisit: '2026-08-23',
      layer: 'P0',
      status: '跟进中',
      owner: '娟子',
      nextAction: '60天到期启动续费铺垫',
      nextActionTime: '2026-08-28',
      attendM1: 7,
      attendM2: 7,
      attendM3: 6,
      totalPurchased: 95
    },
    {
      id: 12,
      name: '许静姝',
      phoneTail: '9913',
      venue: '东部店',
      source: '朋友介绍',
      mainCard: 'VIP私教月卡',
      remainTimes: 0,
      expireDate: '2026-08-31',
      lastVisit: '2026-08-15',
      layer: 'P0',
      status: '跟进中',
      owner: '苏米',
      nextAction: '临期沟通续费窗口',
      nextActionTime: '2026-08-27',
      attendM1: 8,
      attendM2: 4,
      attendM3: 4,
      totalPurchased: 35
    }
  ]
  return base.map(
    (c: YimaiCustomer, i: number): YimaiCustomer => ({
      ...c,
      consultant: c.consultant ?? (c.owner === '未分配' ? '' : c.owner),
      phone:
        c.phone ??
        '1' +
          [3, 5, 7, 8][i % 4] +
          String(50612300 + c.id * 13579)
            .padStart(9, '1')
            .slice(-9)
    })
  )
}

function now() {
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function seedLeads(): YimaiLead[] {
  return [
    {
      id: 1,
      leadDate: '2026-08-24',
      name: '康女士',
      phone: '13805745021',
      phoneTail: '5021',
      demand: '体验大器械',
      source: '抖音',
      venue: '绿地店',
      serviceTeacher: '',
      status: '新留资',
      grade: '',
      trialTime: '',
      trialTopic: '',
      trialTeacher: '',
      dealCard: '',
      dealAmount: null,
      redeemAmount: null,
      voucherCode: '106541651406498',
      remark: '抖音团购券未核销',
      createdBy: '阿玉',
      createdAt: '2026-08-24 10:21'
    },
    {
      id: 2,
      leadDate: '2026-08-24',
      name: 'summer',
      phone: '13705744022',
      phoneTail: '4022',
      demand: '体式提升',
      source: '转介绍',
      venue: '绿地店',
      serviceTeacher: '张芷晴',
      status: '已约体验',
      grade: 'B',
      trialTime: '2026-08-26 12:05',
      trialTopic: '内观流',
      trialTeacher: '张芷晴',
      dealCard: '',
      dealAmount: null,
      redeemAmount: 99,
      voucherCode: '101654267712226',
      remark: '朋友同报，关注内观流课程',
      createdBy: '阿玉',
      createdAt: '2026-08-24 10:35'
    },
    {
      id: 3,
      leadDate: '2026-08-25',
      name: '章月',
      phone: '13567542747',
      phoneTail: '2747',
      demand: '产后修复',
      source: '美团',
      venue: '东部店',
      serviceTeacher: '苏米',
      status: '已联系',
      grade: '',
      trialTime: '',
      trialTopic: '',
      trialTeacher: '',
      dealCard: '',
      dealAmount: null,
      redeemAmount: null,
      voucherCode: '',
      remark: '产后8个月，需评估后定课',
      createdBy: '阿玉',
      createdAt: '2026-08-25 09:12'
    },
    {
      id: 4,
      leadDate: '2026-08-22',
      name: '姜宝颖',
      phone: '13777067399',
      phoneTail: '7399',
      demand: '私教入门',
      source: '抖音直播',
      venue: '绿地店',
      serviceTeacher: '',
      status: '已体验',
      grade: 'A',
      trialTime: '2026-08-23 19:00',
      trialTopic: '私教3节体验',
      trialTeacher: 'Nico',
      dealCard: '',
      dealAmount: null,
      redeemAmount: null,
      voucherCode: '106575812337250',
      remark: '体验反馈好，待报价跟进',
      createdBy: '阿玉',
      createdAt: '2026-08-22 16:40'
    },
    {
      id: 5,
      leadDate: '2026-08-20',
      name: '竺爱华',
      phone: '13806672906',
      phoneTail: '2906',
      demand: '塑形减脂',
      source: '大众点评',
      venue: '绿地店',
      serviceTeacher: '芷晴',
      status: '已流失',
      grade: '',
      trialTime: '2026-08-21 10:00',
      trialTopic: '精品团课',
      trialTeacher: '婷婷',
      dealCard: '',
      dealAmount: null,
      redeemAmount: null,
      voucherCode: '',
      remark: '团购新客专享已退款，距离原因暂缓',
      createdBy: '阿玉',
      createdAt: '2026-08-20 14:02'
    },
    {
      id: 6,
      leadDate: '2026-08-25',
      name: '顾女士',
      phone: '13905741188',
      phoneTail: '1188',
      demand: '肩颈改善',
      source: '视频号',
      venue: '东部店',
      serviceTeacher: '',
      status: '新留资',
      grade: '',
      trialTime: '',
      trialTopic: '',
      trialTeacher: '',
      dealCard: '',
      dealAmount: null,
      redeemAmount: null,
      voucherCode: '',
      remark: '视频号私信留资，待首响',
      createdBy: '阿玉',
      createdAt: '2026-08-25 20:15'
    },
    {
      id: 7,
      leadDate: '2026-08-18',
      name: '王燕',
      phone: '15858401132',
      phoneTail: '1132',
      demand: '小班系统练习',
      source: '抖音周年庆直播',
      venue: '东部店',
      serviceTeacher: '黄敏',
      status: '已成交',
      grade: 'B',
      trialTime: '2026-08-19 18:30',
      trialTopic: '核心床小班',
      trialTeacher: '黄敏',
      dealCard: '全能小班36节半年卡',
      dealAmount: 4091,
      redeemAmount: 299,
      voucherCode: '106541659980001',
      remark: '直播当场下单',
      createdBy: '阿玉',
      createdAt: '2026-08-18 21:05'
    }
  ]
}
function diffDetail(
  before: Record<string, unknown>,
  after: Record<string, unknown>,
  labels: Record<string, string>
): string {
  const changes: string[] = []
  for (const key of Object.keys(labels)) {
    const b = before?.[key]
    const a = after[key]
    if (String(b ?? '') !== String(a ?? '')) {
      changes.push(`${labels[key]}：${String(b ?? '空') || '空'} → ${String(a ?? '空') || '空'}`)
    }
  }
  return changes.length ? changes.join('；') : '无字段变化'
}

const LEAD_LABELS: Record<string, string> = {
  name: '姓名',
  phoneTail: '电话尾号',
  demand: '需求/痛点',
  source: '来源',
  venue: '门店',
  serviceTeacher: '会籍顾问',
  status: '状态',
  grade: '客户分级',
  trialTime: '体验课时间',
  trialTopic: '体验课主题',
  trialTeacher: '授课老师',
  dealCard: '成交卡项',
  dealAmount: '成交金额',
  redeemAmount: '核销金额',
  phone: '手机号',
  voucherCode: '平台券码',
  remark: '备注'
}

export const useYimaiStore = defineStore(
  'yimaiStore',
  () => {
    const state = ref<YimaiState>({
      version: SEED_VERSION,
      nextLeadId: 100,
      nextAuditId: 1000,
      nextCustomerId: 5000,
      leads: seedLeads(),
      auditLogs: [],
      snapshot: null,
      customers: seedCustomers(),
      rules: { ...DEFAULT_RULES }
    })

    function ensureSeed() {
      if (state.value.version !== SEED_VERSION) {
        const old = state.value
        // 版本升级：基础种子刷新；保留KeepYoga导入的外部会员与审计/快照/规则
        const imported = (old.customers ?? []).filter((c) => c.externalId?.startsWith('ky:'))
        state.value = {
          version: SEED_VERSION,
          nextLeadId: 100,
          nextAuditId: old.nextAuditId,
          nextCustomerId: old.nextCustomerId || 5000,
          leads: seedLeads(),
          auditLogs: old.auditLogs,
          snapshot: old.snapshot,
          customers: [...seedCustomers(), ...imported],
          rules: old.rules ?? { ...DEFAULT_RULES }
        }
      }
    }

    function currentActor(): { operatorName: string; operatorRole: string; venue: string | null } {
      const u = useUserStore().getUserInfo
      const roleMap: Record<string, string> = {
        R_BOSS: '老板',
        R_MANAGER: '店长',
        R_MEDIA: '新媒体',
        R_SUPER: '开发'
      }
      const role = (u.roles ?? [])[0] ?? ''
      return {
        operatorName: u.userName ?? '未知',
        operatorRole: roleMap[role] ?? role,
        venue: u.venue ?? null
      }
    }

    function writeAudit(
      action: string,
      module: string,
      targetId: number | string,
      targetLabel: string,
      venue: string,
      detail: string
    ) {
      const actor = currentActor()
      state.value.auditLogs.unshift({
        id: state.value.nextAuditId++,
        time: now(),
        operatorName: actor.operatorName,
        operatorRole: actor.operatorRole,
        action,
        module,
        targetId,
        targetLabel,
        venue,
        detail
      })
    }

    function addLead(
      data: Omit<YimaiLead, 'id' | 'status' | 'createdBy' | 'createdAt'> & {
        status?: YimaiLead['status']
      }
    ) {
      ensureSeed()
      const actor = currentActor()
      const lead: YimaiLead = {
        ...data,
        id: state.value.nextLeadId++,
        status: data.status ?? '新留资',
        createdBy: actor.operatorName,
        createdAt: now()
      }
      state.value.leads.unshift(lead)
      writeAudit(
        '新增',
        '前端客资',
        lead.id,
        `${lead.name}（${lead.source}）`,
        lead.venue,
        `录入客资：需求[${lead.demand || '空'}]，目标门店[${lead.venue}]`
      )
      return lead.id
    }

    function updateLead(id: number, patch: Partial<YimaiLead>) {
      ensureSeed()
      const idx = state.value.leads.findIndex((l) => l.id === id)
      if (idx === -1) return false
      const before = { ...state.value.leads[idx] }
      const after = { ...before, ...patch }
      state.value.leads.splice(idx, 1, after)
      writeAudit(
        '修改',
        '前端客资',
        id,
        `${after.name}（${after.source}）`,
        after.venue,
        diffDetail(before, after, LEAD_LABELS)
      )
      return true
    }

    function decideApproval(id: number, decision: '初审通过' | '终审通过' | '驳回') {
      const label = `价格审批单 #${id}`
      writeAudit(
        decision,
        '价格审批',
        id,
        label,
        '双店',
        `审批决定：${decision}；成交标记需关联批准单号`
      )
    }

    function saveSnapshot(snap: YimaiSyncSnapshot) {
      state.value.snapshot = snap
      writeAudit(
        '同步',
        '数据同步',
        0,
        'KeepYoga实时快照',
        '双店',
        `获取双店计数与今日预约：绿地 ${snap.todayBookings['绿地店'] ?? 0} 条 / 东部 ${snap.todayBookings['东部店'] ?? 0} 条`
      )
    }

    function getLeadHistory(leadId: number): YimaiAuditLog[] {
      return state.value.auditLogs.filter((l) => l.module === '前端客资' && l.targetId === leadId)
    }

    function resetDemoData() {
      state.value = {
        version: SEED_VERSION,
        nextLeadId: 100,
        nextAuditId: state.value.nextAuditId,
        nextCustomerId: 5000,
        leads: seedLeads(),
        auditLogs: [],
        snapshot: null,
        customers: seedCustomers(),
        rules: { ...DEFAULT_RULES }
      }
    }

    /** KeepYoga 全量导入：按 externalId 去重 upsert，返回新增/更新数量 */
    function upsertImportedCustomers(rows: YimaiCustomer[]): { created: number; updated: number } {
      let created = 0
      let updated = 0
      for (const row of rows) {
        const idx = state.value.customers.findIndex(
          (c) => c.externalId && c.externalId === row.externalId
        )
        if (idx >= 0) {
          const cur = state.value.customers[idx]
          // 仅更新基础档案字段；本地经营字段（分层/负责人/下次动作）保留
          state.value.customers.splice(idx, 1, {
            ...cur,
            name: row.name || cur.name,
            phoneTail: row.phoneTail || cur.phoneTail,
            source: row.source || cur.source
          })
          updated++
        } else {
          state.value.customers.push(row)
          created++
        }
      }
      return { created, updated }
    }

    function auditImport(storeLabel: string, created: number, updated: number) {
      writeAudit(
        '同步',
        '数据同步',
        0,
        `${storeLabel} 全量导入客户池`,
        '双店',
        `新增 ${created} 条 · 更新 ${updated} 条（按外部ID去重）`
      )
    }

    const MEMBER_FIELD_LABELS: Record<string, string> = {
      renewalPlan: '续课计划',
      decline: '出勤降低处置',
      stopReason: '停课原因',
      expectedReturn: '预期复活时间',
      lastTouch: '最近有效沟通',
      needsHelp: '需管理层协助',
      inRevive: '进入待复活清单',
      evalScore: '30天续费评估分'
    }

    function updateMember(
      id: number,
      patch: Partial<YimaiCustomer>,
      actionLabel = '修改'
    ): boolean {
      ensureSeed()
      const idx = state.value.customers.findIndex((c) => c.id === id)
      if (idx === -1) return false
      const before = state.value.customers[idx]
      const after = { ...before, ...patch }
      state.value.customers.splice(idx, 1, after)
      const changes: string[] = []
      for (const key of Object.keys(MEMBER_FIELD_LABELS)) {
        const b = (before as Record<string, unknown>)[key]
        const a2 = (after as Record<string, unknown>)[key]
        if (JSON.stringify(b ?? '') !== JSON.stringify(a2 ?? ''))
          changes.push(MEMBER_FIELD_LABELS[key])
      }
      writeAudit(
        actionLabel,
        '会员管理',
        id,
        `${after.name}（${after.venue}）`,
        after.venue,
        `变更：${changes.join('、') || '无字段变化'}`
      )
      return true
    }

    function setMemberRules(rules: MemberRules) {
      state.value.rules = { ...rules }
      writeAudit(
        '修改',
        '会员管理',
        0,
        '清单规则阈值',
        '双店',
        `待续课阈值[${rules.renewalThreshold}] VIP阈值[${rules.vipThreshold}] 出勤下降口径[${rules.declineMode === 'strict' ? '连续三月递减' : '最近两月下降'}]`
      )
    }

    return {
      state,
      ensureSeed,
      currentActor,
      writeAudit,
      addLead,
      updateLead,
      decideApproval,
      saveSnapshot,
      upsertImportedCustomers,
      auditImport,
      updateMember,
      setMemberRules,
      getLeadHistory,
      resetDemoData
    }
  },
  {
    persist: {
      key: 'yimai-store',
      storage: localStorage
    }
  }
)
