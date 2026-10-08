import { useAiConfigStore, SERVER_CONFIGURED_PLACEHOLDER } from '@/store/modules/ai-config'
import { useYimaiStore } from '@/store/modules/yimai'
import { saveMarketingHistory } from './my'
import { USE_BACKEND, API_BASE, getBackendToken, apiGet, apiPost } from './backend'

// ==================== AI 配置水合（多设备共用服务端配置） ====================

let aiHydrated = false

/**
 * 把保存在服务端数据库的 AI 配置载入前端 store。
 * 服务端不计密钥明文，用占位符表示已配置；任何设备打开工作台都会先水合，
 * 故首页 AI 助手、营销工具、训练计划都能直接使用同一份配置。
 */
export async function initAiConfig(force = false): Promise<void> {
  if (aiHydrated && !force) return
  if (!USE_BACKEND) {
    aiHydrated = true
    return
  }
  try {
    const saved = await apiGet<{
      enabled?: boolean
      providerLabel?: string
      baseUrl?: string
      model?: string
      temperature?: number
      apiKey?: string
      configured?: boolean
    }>('/ai/config')
    const store = useAiConfigStore()
    if (saved) {
      Object.assign(store.config, {
        enabled: Boolean(saved.enabled),
        providerLabel: saved.providerLabel ?? store.config.providerLabel,
        baseUrl: saved.baseUrl ?? store.config.baseUrl,
        model: saved.model ?? store.config.model,
        temperature:
          typeof saved.temperature === 'number' ? saved.temperature : store.config.temperature,
        apiKey: saved.configured ? SERVER_CONFIGURED_PLACEHOLDER : ''
      })
    }
  } catch {
    // 未登录 / 非超管：保留本地默认即可
  } finally {
    aiHydrated = true
  }
}

/** 是否已水合（供页面选择展示时机） */
export function isAiHydrated(): boolean {
  return aiHydrated
}

// ==================== 朋友圈 / 小红书 共用口径 ====================

/** 品牌与城市全系统写死，不再由用户填写 */
export const BRAND_NAME = '一麦'
export const BRAND_CITY = '宁波'

/** 身份角色（一麦组织岗位，v3.1.31 起删除「健身教练」） */
export const PERSONA_ROLES = ['会籍顾问', '全职老师', '门店前台', '管理层', '主理人']

/** 专业方向（个人中心与发圈人设共用，支持自定义添加） */
export const SPECIALTY_OPTIONS = [
  '瑜伽',
  '普拉提',
  '康复理疗',
  '减脂塑形',
  '体态调整',
  '孕产恢复',
  '冥想呼吸',
  '拉伸放松',
  '功能训练',
  '空中瑜伽'
]

/** 客户画像（v3.1.31 细化：贴近一麦宁波门店真实客群） */
export const AUDIENCE_OPTIONS = [
  '25-45岁久坐上班族',
  '肩颈腰背慢性酸痛人群',
  '体态问题人群（圆肩/骨盆前倾/XO腿）',
  '减脂塑形需求人群',
  '产后恢复妈妈（备孕/孕期/产后）',
  '失眠焦虑、需要放松解压的人群',
  '零基础大体重、运动入门人群',
  '有训练瓶颈的健身爱好者',
  '注重长期健康的中年女性',
  '银发活力人群（关节友好训练）',
  '备婚/重要场合前突击改善体态',
  '术后康复人群（需医疗评估）'
]

// ==================== 朋友圈：选项口径（报告 7.3-7.4） ====================

/**
 * 朋友圈选题目录 —— **本文件的单一事实来源**。
 * - 每项自带 `promptHint`（写作方向），是「像不像真人发的」第一质量杠杆；
 * - `MOMENTS_CATEGORIES` 保留为**派生视图**，视图层可分批迁移，避免一次性大改。
 */
export interface MomentsCategoryItem {
  /** kebab-case 稳定 id：label 可以改，id 不改，历史数据不被打断 */
  id: string
  label: string
  /** 写作方向，直接注入提示词的「写作方向：」行 */
  promptHint: string
}

export interface MomentsCategoryGroup {
  id: string
  label: string
  items: MomentsCategoryItem[]
}

export const MOMENTS_CATALOG: MomentsCategoryGroup[] = [
  {
    id: 'client',
    label: '客户案例',
    items: [
      {
        id: 'trial-case',
        label: '体验课案例',
        promptHint:
          '写一位第一次到店的学员：进门时的紧张或受限、课上具体做了什么、下课时的小变化，落到教练的观察。'
      },
      {
        id: 'month-1',
        label: '1个月改变',
        promptHint:
          '围绕一个月内可观察到的小变化写：动作稳了、能坚持了、睡眠或酸痛有改善，不夸大成体型剧变。'
      },
      {
        id: 'month-3',
        label: '3个月改变',
        promptHint:
          '写三个月的训练节奏与阶段目标推进，突出规律练习带来的体态或体能变化，给具体细节。'
      },
      {
        id: 'year-1',
        label: '1年改变',
        promptHint: '写长期练习带来的生活方式变化与身体认知，语气克制，不要把功劳归给某一次课。'
      },
      {
        id: 'referral',
        label: '转介绍',
        promptHint:
          '写一位老会员把朋友带来一起练的场景：她们的对话、顾虑、第一节课的反应，自然带出转介绍。'
      },
      {
        id: 'posture',
        label: '体态改善',
        promptHint:
          '用一个具体的体态细节（圆肩、骨盆前倾、颈前伸）讲清评估发现了什么、练习怎么调整、现在什么样。'
      },
      {
        id: 'correction',
        label: '动作纠正',
        promptHint:
          '记录一次课上纠正动作的过程：原来的代偿、给的提示词、学员听懂后的变化，突出教练的专业判断。'
      },
      {
        id: 'checkin',
        label: '坚持打卡',
        promptHint: '写学员的打卡节奏与坚持方式，重点在「怎么坚持下来的」，不要写成成绩汇报。'
      },
      {
        id: 'negative-case',
        label: '负面案例',
        promptHint:
          '用负面案例说明训练里需要配合的重点：不是批评客户，也不是把自己交给别人，而是教练和会员一起成长。'
      }
    ]
  },
  {
    id: 'class',
    label: '课堂日常',
    items: [
      {
        id: 'today-private',
        label: '今天的私教课',
        promptHint: '写一节私教课的现场：今天关注的身体问题、用了什么手法或辅具、学员当堂的反馈。'
      },
      {
        id: 'today-group',
        label: '今天的小班课',
        promptHint: '写小班课的群体状态：几个人、什么水平、课堂氛围和互相带动，语气轻松。'
      },
      {
        id: 'vibe',
        label: '课堂氛围',
        promptHint: '写课堂里的一个真实片段或对话，让读者感到舒服、不紧绷，可以有一点自嘲。'
      },
      {
        id: 'full-schedule',
        label: '约课爆满',
        promptHint: '写档期紧的客观情况与预约方式，不催不逼，用事实带出「要不要先占个位」。'
      },
      {
        id: 'morning',
        label: '早课状态',
        promptHint: '写早课的身体感受与一天状态的变化，允许口语和不完整表达。'
      },
      {
        id: 'evening',
        label: '晚课状态',
        promptHint: '写下班后进教室的疲惫与练完的放松对比，贴合久坐上班族。'
      },
      {
        id: 'detail',
        label: '课堂细节',
        promptHint: '抓一个课堂细节（呼吸声、辅具摆放、某个提示词）展开，小切口写出专业感。'
      },
      {
        id: 'review',
        label: '课后复盘',
        promptHint: '写课后复盘：今天反复出现的问题、下次要调整的思路，体现教练的持续打磨。'
      }
    ]
  },
  {
    id: 'professional',
    label: '专业科普',
    items: [
      {
        id: 'myth',
        label: '常见误区',
        promptHint: '拆一个最常见的训练误区，先讲大家怎么做错，再给正确做法与原因，结尾不推销。'
      },
      {
        id: 'move-analysis',
        label: '动作解析',
        promptHint: '拆解一个动作的发力顺序与常见代偿，写清「先做什么、再做什么」。'
      },
      {
        id: 'fatloss',
        label: '减脂没效果',
        promptHint: '解释减脂没效果的常见原因（频率、饮食、力量不足），给可执行的第一步。'
      },
      {
        id: 'neck-back',
        label: '肩颈腰背',
        promptHint: '讲肩颈腰背的日常养护与训练思路，明确不做医疗诊断，建议先评估。'
      },
      {
        id: 'breath',
        label: '呼吸',
        promptHint: '讲呼吸与核心、体态的关系，给一个当场就能试的小练习。'
      },
      {
        id: 'core',
        label: '核心',
        promptHint: '讲核心不只是腹肌，给两三个可以从零开始的感知练习。'
      },
      {
        id: 'stretch',
        label: '拉伸误区',
        promptHint: '讲拉伸的常见误区（越痛越好、拿拉伸代替力量），给更合理的练习顺序。'
      },
      {
        id: 'diet',
        label: '健康饮食',
        promptHint: '讲与训练配合的饮食原则，克制表达，不做治疗或疗效承诺。'
      },
      {
        id: 'frequency',
        label: '训练频率',
        promptHint: '讲频率与强度的关系，给出上班族能执行的每周安排。'
      }
    ]
  },
  {
    id: 'personal-brand',
    label: '个人品牌',
    items: [
      {
        id: 'insight',
        label: '教练感悟',
        promptHint: '写一句来自课堂或自己的真实感悟，落到具体的人和事，不要写成鸡汤总结。'
      },
      {
        id: 'routine',
        label: '训练日常',
        promptHint: '写教练自己今天练了什么、哪里还不顺，允许暴露不完美。'
      },
      {
        id: 'study',
        label: '专业进修',
        promptHint: '写一次进修或学习的具体收获，说明会怎么用在课堂里。'
      },
      {
        id: 'business',
        label: '经营感悟',
        promptHint: '写经营中的一次真实判断或取舍，体现主理人视角。'
      },
      {
        id: 'philosophy',
        label: '训练理念',
        promptHint: '讲清自己的训练理念，用课堂细节支撑，避免口号式排比。'
      },
      {
        id: 'why',
        label: '为什么做教练',
        promptHint: '写进入这一行的具体起因和现在的理由，口语化，不用宏大叙事。'
      },
      {
        id: 'team',
        label: '团队日常',
        promptHint: '写团队的一个片段（配合、备课、互相纠动作），让门店有人的温度。'
      }
    ]
  },
  {
    id: 'lifestyle',
    label: '生活方式',
    items: [
      {
        id: 'healthy-life',
        label: '健康生活',
        promptHint: '写与训练无关但支撑训练的生活片段（睡眠、走路、做饭），关联到身体状态。'
      },
      {
        id: 'one-hour',
        label: '给自己一小时',
        promptHint: '写给忙碌人群的「一小时」提案：具体时间、能做什么、练完是什么感觉。'
      },
      {
        id: 'long-term',
        label: '长期主义',
        promptHint: '讲长期练习的价值，用一个月或半年的具体变化说明，不说空话。'
      },
      {
        id: 'women-power',
        label: '女性力量',
        promptHint: '写女性在训练里获得的力量感与掌控感，语气真诚，不喊口号。'
      },
      {
        id: 'sleep',
        label: '睡眠恢复',
        promptHint: '写训练与睡眠、恢复的关系，给一个今晚就能做的小调整。'
      },
      {
        id: 'office-relax',
        label: '办公室放松',
        promptHint: '给久坐人群几个工位上能做的放松动作，写清做几个、多久一次。'
      },
      {
        id: 'weekend',
        label: '周末训练',
        promptHint: '写周末练一次的安排与感受，贴合上班族节奏。'
      }
    ]
  },
  {
    id: 'order',
    label: '晒单反馈',
    items: [
      {
        id: 'feedback',
        label: '客户反馈',
        promptHint: '引用一条（可脱敏的）会员反馈，配上你的回应与思考，不晒隐私信息。'
      },
      {
        id: 'before-after',
        label: '前后对比',
        promptHint: '写前后对比时讲清起点、过程和时间跨度，避免夸大结果。'
      },
      {
        id: 'renewal-record',
        label: '续课记录',
        promptHint: '写一位会员决定继续练的原因与她下一阶段的目标，自然体现续课的合理性。'
      },
      {
        id: 'schedule-full',
        label: '排课满档',
        promptHint: '用档期的客观情况说明需求，给出预约方式，不制造焦虑。'
      },
      {
        id: 'dm',
        label: '会员私信',
        promptHint: '写一条私信问答：她担心什么、你怎么判断、最后建议了什么。'
      },
      {
        id: 'milestone',
        label: '阶段成果',
        promptHint: '写一位会员完成阶段目标的节点，落到具体能力或体感变化。'
      }
    ]
  },
  {
    id: 'activity',
    label: '活动推荐',
    items: [
      {
        id: 'trial-recommend',
        label: '体验课推荐',
        promptHint: '写清体验课适合谁、一节课会经历什么、怎么报名，信息要明确。'
      },
      {
        id: 'assessment',
        label: '体态评估',
        promptHint: '写体态评估会看什么、能看出哪些问题、适合哪些人先做评估。'
      },
      {
        id: 'newcomer',
        label: '新客福利',
        promptHint: '写新客福利的适用人群与使用方式，不夸大优惠力度。'
      },
      {
        id: 'comeback',
        label: '老会员回归',
        promptHint: '写给停练一段时间的老会员：怎么温和复训、第一步做什么。'
      },
      {
        id: 'class-recruit',
        label: '小班招募',
        promptHint: '写小班的人数、时间、适合水平与剩余名额，信息可核查。'
      },
      {
        id: 'limited',
        label: '限时名额',
        promptHint: '写清名额数与截止时间，紧迫感来自事实而不是反复催促。'
      }
    ]
  },
  {
    id: 'sales',
    label: '销售转化',
    items: [
      {
        id: 'trial-invite',
        label: '体验课邀约',
        promptHint:
          '先讲这类人群的常见困扰，再给一个低门槛的第一步（约一节体验），结尾自然给出行动入口。'
      },
      {
        id: 'renew-invite',
        label: '续课邀约',
        promptHint: '以阶段成果或下阶段计划为切口，讲清继续规律练下去的理由，不催促。'
      },
      {
        id: 'referral-gift',
        label: '转介绍有礼',
        promptHint: '写转介绍的具体方式与对朋友的照顾，礼感要轻，不像拉人头。'
      },
      {
        id: 'flash-recruit',
        label: '限时活动招募',
        promptHint: '写活动的时间、人数、适合人群和报名方式，紧迫感来自名额事实。'
      },
      {
        id: 'member-day',
        label: '会员日促销',
        promptHint: '写会员日对老会员的专属安排，突出回馈，而不是打折叫卖。'
      },
      {
        id: 'price-value',
        label: '价格与价值解读',
        promptHint: '把价格换算成每次课与教练的投入，讲清价值构成，语气坦诚。'
      },
      {
        id: 'thanks',
        label: '成单感谢',
        promptHint: '写一位新会员决定开始的过程与你的回应，表达感谢但不谄媚。'
      },
      {
        id: 'full-proof',
        label: '满班见证',
        promptHint: '用满班或档期的事实做见证，落到「为什么这么多人愿意规律来练」。'
      },
      {
        id: 'bring-friend',
        label: '老带新福利',
        promptHint: '写老带新的使用方式与双方得到的价值，鼓励分享而不是拉人头。'
      },
      {
        id: 'sprint-recruit',
        label: '攻坚期学员招募',
        promptHint: '针对有明确目标（备婚、体检、体态）的人群招募攻坚期学员，写清周期与产出。'
      }
    ]
  }
]

/** 派生视图：视图层现用（组名 → 项 label 数组）；迁移完成后可只依赖 MOMENTS_CATALOG */
export const MOMENTS_CATEGORIES: Record<string, string[]> = Object.fromEntries(
  MOMENTS_CATALOG.map((g) => [g.label, g.items.map((i) => i.label)])
)

/** 按 id 查选题（含组信息）；未命中回落到「客户案例」组首项 */
export function findMomentsInspiration(
  id?: string
): MomentsCategoryItem & { groupId: string; groupLabel: string } {
  for (const g of MOMENTS_CATALOG) {
    for (const it of g.items) {
      if (it.id === id) return { ...it, groupId: g.id, groupLabel: g.label }
    }
  }
  const g0 = MOMENTS_CATALOG[0]
  return { ...g0.items[0], groupId: g0.id, groupLabel: g0.label }
}

/** 按 label 查选题（兼容旧的 `topic` 字符串入参）；未命中返回 null */
export function findMomentsInspirationByLabel(
  label?: string
): (MomentsCategoryItem & { groupId: string; groupLabel: string }) | null {
  if (!label) return null
  for (const g of MOMENTS_CATALOG) {
    for (const it of g.items) {
      if (it.label === label) return { ...it, groupId: g.id, groupLabel: g.label }
    }
  }
  return null
}

export const MOMENT_TONES = ['自然真实', '专业干货', '轻松幽默', '温暖治愈', '元气满满']
export const MOMENT_EXPRESSIONS = ['说事理', '讲故事', '清单体', '对话体']

// ---------- 目标 × 销售强度（三层结构：销售强度 / 转化动作 / 目标） ----------

/** 销售强度（源：小程序 moments-assistant 的 6 档，取值集不得自造） */
export type SalesLevelId = 'life' | 'presence' | 'booking' | 'activity' | 'direct' | 'limited'

/** 转化动作（源同上，取值集不得自造） */
export type ConversionActionId =
  | 'none'
  | 'assessment-or-trial'
  | 'get-materials'
  | 'consult-me'
  | 'book-class'
  | 'purchase'

export interface MomentsGoalDef {
  /** 稳定 id：本地降级与映射判断一律用 id，不再用 label 字符串匹配 */
  id: string
  /** 一麦现有文案，UI 展示不变 */
  label: string
  salesLevelId: SalesLevelId
  conversionActionId: ConversionActionId
  /** 结尾行动指令，天然携带 #；无目标时为空串 */
  actionInstruction: string
  /** 写法提醒（注入「目标写法提醒：」） */
  promptHint: string
}

/**
 * 一麦 7 档目标 → 销售强度 / 转化动作 / 行动指令 的完整映射。
 * 7 个 label 与历史数据一致（不得改动），三件套为一麦映射（源映射方向见规格 §1.11 草案）。
 */
export const MOMENT_GOALS_V2: MomentsGoalDef[] = [
  {
    id: 'life',
    label: '分享生活（无目标）',
    salesLevelId: 'life',
    conversionActionId: 'none',
    actionInstruction: '',
    promptHint: '无目标，只记录生活、课堂状态或真实感受，不要添加行动指令。'
  },
  {
    id: 'seed',
    label: '种草引流',
    salesLevelId: 'presence',
    conversionActionId: 'consult-me',
    actionInstruction: '#想了解可以直接找我',
    promptHint: '轻联系目标，先给有用的观察或方法，结尾自然留一个沟通入口，不要写得像硬销售。'
  },
  {
    id: 'activity-notice',
    label: '活动通知',
    salesLevelId: 'activity',
    conversionActionId: 'purchase',
    actionInstruction: '#想参加这次活动可以找我',
    promptHint: '围绕活动节点、适合人群和参与入口写，可以有明确但不生硬的转化。'
  },
  {
    id: 'build-trust',
    label: '建立专业信任',
    salesLevelId: 'presence',
    conversionActionId: 'assessment-or-trial',
    actionInstruction: '#想预约评估或体验可以找我',
    promptHint: '明确引导评估或体验，适合专业科普、客户案例和新客转化。'
  },
  {
    id: 'trial-recruit',
    label: '招募体验课',
    salesLevelId: 'booking',
    conversionActionId: 'assessment-or-trial',
    actionInstruction: '#想预约体验可以找我',
    promptHint: '写清体验课适合谁、一节课会经历什么，给出明确的预约入口。'
  },
  {
    id: 'renewal',
    label: '续课升单铺垫',
    salesLevelId: 'booking',
    conversionActionId: 'book-class',
    actionInstruction: '#想续课可以直接找我',
    promptHint: '以阶段成果和下阶段计划为切口，讲清继续规律练下去的理由，不催促。'
  },
  {
    id: 'wakeup',
    label: '沉默客户唤醒',
    salesLevelId: 'presence',
    conversionActionId: 'consult-me',
    actionInstruction: '#想聊聊可以直接找我',
    promptHint: '回忆对方曾提过的困扰，给一个低成本的第一步（聊两句或做次评估），语气像老朋友。'
  }
]

/** 派生视图：UI 现用的 label 数组（7 项，顺序与字面值不变） */
export const MOMENT_GOALS = MOMENT_GOALS_V2.map((g) => g.label)

/** 目标解析：id → label → 转化动作 → 销售强度 → 末位回落「分享生活」（对齐源码 O() 的容忍顺序） */
export function findMomentGoal(idOrLabel?: string): MomentsGoalDef {
  const k = (idOrLabel ?? '').trim()
  return (
    MOMENT_GOALS_V2.find((g) => g.id === k) ??
    MOMENT_GOALS_V2.find((g) => g.label === k) ??
    MOMENT_GOALS_V2.find((g) => g.conversionActionId === k) ??
    MOMENT_GOALS_V2.find((g) => g.salesLevelId === k) ??
    MOMENT_GOALS_V2[0]
  )
}

/**
 * 目标 → 推荐成稿版型（仅用于「只生成 1 条」与兼容旧单条调用点时的挑选）。
 * 依据转化动作强度：购买 → 轻转化版；约课/评估 → 专业信任版；其余 → 真实日常版。
 */
export function goalToDraftId(goal: MomentsGoalDef): MomentsDraftId {
  if (goal.conversionActionId === 'purchase') return 'convert'
  if (
    goal.conversionActionId === 'book-class' ||
    goal.conversionActionId === 'assessment-or-trial'
  ) {
    return 'pro'
  }
  return 'real'
}

// ---------- 长度档（一麦口径：30-50 / 50-120 / 100-150 + 自定义） ----------

export interface MomentLengthDef {
  id: 'short' | 'standard' | 'detailed'
  label: string
  min: number
  max: number
}

/** 值域为一麦既有口径（历史记录与运营习惯），新增 id 供 UI 绑定；自定义档见 MOMENT_LENGTH_CUSTOM */
export const MOMENT_LENGTHS: MomentLengthDef[] = [
  { id: 'short', label: '简短版 30-50字', min: 30, max: 50 },
  { id: 'standard', label: '标准版 50-120字', min: 50, max: 120 },
  { id: 'detailed', label: '完整版 100-150字', min: 100, max: 150 }
]

/** 「内容长度」下拉里的自定义档位标识，选中后配合 MomentsInput.customLength 生效 */
export const MOMENT_LENGTH_CUSTOM = '自定义字数'

/** 长度档解析：id → label → 数值范围匹配（兼容历史 label）→ 末位回落标准版 */
export function findMomentLength(idOrLabel?: string): MomentLengthDef {
  const k = (idOrLabel ?? '').trim()
  return (
    MOMENT_LENGTHS.find((l) => l.id === k) ??
    MOMENT_LENGTHS.find((l) => l.label === k) ??
    MOMENT_LENGTHS[1]
  )
}

/** 单条字数硬约束（源：小程序 buildPromptContext 的 F） */
export function buildLengthRule(range: string): string {
  return `“${range}”是每一条朋友圈的独立字数要求，不是3条合计；生成3条时，每条都必须分别落在这个范围内。`
}

// ---------- emoji 四档（带 charsPerSymbol，可计算、可质检） ----------

export interface EmojiUsageMode {
  id: 'none' | 'low' | 'medium' | 'high'
  label: string
  /** 每多少个字出现 1 个 emoji；none 为 0 */
  charsPerSymbol: number
  desc: string
}

export const EMOJI_USAGE_MODES: EmojiUsageMode[] = [
  {
    id: 'none',
    label: '不使用',
    charsPerSymbol: 0,
    desc: '整条内容不使用 emoji、颜文字或表情包式符号。'
  },
  {
    id: 'low',
    label: '低频使用：100 个字一个',
    charsPerSymbol: 100,
    desc: '只在少数重点处点缀，适合专业、克制、偏干货的内容。'
  },
  {
    id: 'medium',
    label: '中频使用：50 个字一个',
    charsPerSymbol: 50,
    desc: '默认选项。自然增加真人感，平均每 50 字约 1 个。'
  },
  {
    id: 'high',
    label: '高频使用：25 个字一个',
    charsPerSymbol: 25,
    desc: '表达更活泼，适合生活感、情绪感和轻松分享，但不能连续堆叠。'
  }
]

export const DEFAULT_EMOJI_USAGE_ID = 'medium'

/** 派生视图：UI 现用的 label 数组（4 项，顺序 none/low/medium/high） */
export const EMOJI_LEVELS = EMOJI_USAGE_MODES.map((m) => m.label)

/** 旧 label → id 兼容表：按「每 N 字 1 个」的数值就近映射（30 字最接近 high 的 25） */
const LEGACY_EMOJI_LEVEL_TO_ID: Record<string, string> = {
  不使用: 'none',
  '少量（约每50字1个）': 'medium',
  '适量（约每30字1个）': 'high'
}

/** emoji 档解析：id → 新 label → 旧 label → 默认 medium */
export function findEmojiUsageMode(idOrLabel?: string): EmojiUsageMode {
  const k = (idOrLabel ?? '').trim()
  const id = LEGACY_EMOJI_LEVEL_TO_ID[k] ?? k
  return (
    EMOJI_USAGE_MODES.find((m) => m.id === id) ??
    EMOJI_USAGE_MODES.find((m) => m.label === k) ??
    EMOJI_USAGE_MODES.find((m) => m.id === DEFAULT_EMOJI_USAGE_ID)!
  )
}

/** emoji 使用规则文案：none 走负向分支，其余走正向分支 */
export function buildEmojiRule(mode: EmojiUsageMode): string {
  if (mode.id === 'none') {
    return '不得使用表情符号。每条内容 emoji、颜文字、表情包式符号数量必须为0。'
  }
  return (
    `必须使用表情符号。当前频率为“${mode.label}”，表情要自然、克制，` +
    `平均每${mode.charsPerSymbol}字出现1个emoji或常见表情符号，不是只放1个就算完成；不要连续堆叠。`
  )
}

// ---------- 立场铁律 / 优先级链 / 去 AI 味 ----------

/** 立场铁律：教练/服务者视角，会员素材必须转写为教练观察 */
export const MOMENTS_STANCE_RULE =
  '立场铁律：始终以教练/服务者视角发声，不以会员、客户或享受服务者视角自述；' +
  '即使参考内容来自会员，也必须转写为教练的观察、判断和服务表达。'

/** 提示词遵守的最高优先级与冲突裁决顺序 */
export const MOMENTS_PRIORITY_CHAIN = [
  '提示词遵守是最高优先级：只要用户选择、个人设置、内容设置和底层提示词之间没有冲突，就必须严格遵守。' +
    '内容主题、字数范围、断行/空行、表情符号使用、井号键次数和目标都属于硬约束，不允许自行忽略或改写。',
  '优先级：用户本次修改意见 > 朋友圈内容层本次选择 > 个人人设层 > 底层提示词。'
].join('\n')

/** 去 AI 味儿（独立成段，不并入硬性规则行） */
export const MOMENTS_DE_AI_RULE =
  '特别说明：必须去 AI 味儿。不要写得过于工整、正确、完整、圆滑，' +
  '不要使用明显 AI 式总结、排比、万能句和模板感表达。要像一个真实的人在朋友圈自然表达。'

/** 语言风格配套（源：底层提示词的「允许口语、停顿感」一行） */
export const MOMENTS_LANGUAGE_RULE =
  '表达要像本人自然发朋友圈，允许口语、停顿感、轻微情绪和不完整表达，不要模板腔。'

// ---------- # 号：朋友圈重点标记（不是话题标签） ----------

export const MOMENTS_HASHTAG_RULE =
  '# 不是分隔符，是朋友圈重点标记；每条至少 1 次、最多 3 次。' +
  '优先用于结尾行动指令，也可用于开头的姓名、品牌、课程名、专有词或特别关键词；不要堆砌无意义话题。'

/** 附上本次目标的行动指令（无目标时说明 # 用于开头重点词） */
export function buildHashtagRule(actionInstruction: string): string {
  const a = (actionInstruction || '').trim()
  return a
    ? `${MOMENTS_HASHTAG_RULE}本次建议的结尾标记：${a}`
    : `${MOMENTS_HASHTAG_RULE}本次无行动指令，# 用于开头重点词。`
}

// ---------- 首条回复（标记独占一行，前后各空一行） ----------

export const FIRST_REPLY_MARKER = '【以下为首条回复】'

/** 合并正文与首条回复为「可直接粘贴发布」的形态（标记独占一行，前后各空一行） */
export function joinWithFirstReply(body: string, reply: string): string {
  return [body.trim(), FIRST_REPLY_MARKER, reply.trim()].filter(Boolean).join('\n\n')
}

/** 从合并文本里切出正文 / 首条回复 */
export function splitFirstReply(merged: string): {
  body: string
  reply: string
  hasReply: boolean
} {
  const s = String(merged ?? '')
  const i = s.indexOf(FIRST_REPLY_MARKER)
  if (i < 0) return { body: s.trim(), reply: '', hasReply: false }
  return {
    body: s.slice(0, i).trim(),
    reply: s.slice(i + FIRST_REPLY_MARKER.length).trim(),
    hasReply: true
  }
}

// ---------- 生成期防折叠（与独立的「防折叠改写工具」是两件事，互补保留） ----------

export const MOMENTS_ANTI_FOLD_RULES: string[] = [
  '防折叠写法已开启：以下规则优先用于降低朋友圈折叠风险，但不能违反用户本次选择和内容真实性。',
  '1. 前3行必须有价值：开头直接给出场景、观点、问题或结果，不能铺垫太久。',
  '2. 不要写成完整广告稿：要像真人随手发，允许短句、口语、停顿感和轻微不完整。',
  '3. 每条朋友圈只讲一个重点：不要同时堆案例、科普、促销和感悟。',
  '4. 减少整齐排比：避免过度工整的三段式、万能总结句和明显模板感表达。',
  '5. 控制显性销售词密度：可以转化，但硬销售词要少；强转化内容优先放在结尾短句或首条回复中。'
]

export function buildAntiFoldSection(enabled: boolean): string {
  return enabled ? MOMENTS_ANTI_FOLD_RULES.join('\n') : '未开启'
}

// ---------- 图片素材 ----------

export const MOMENTS_MAX_IMAGES = 3

export const MOMENTS_IMAGE_RULE =
  '图片强制要求：用户上传的图片是本条朋友圈要发布时搭配的图片，生成内容必须和图片内容有关联；不能把图片当成无关素材。'

// ---------- 成稿与结果类型 ----------

export type MomentsDraftId = 'real' | 'pro' | 'convert'

export const MOMENTS_DRAFT_META: {
  id: MomentsDraftId
  label: '真实日常版' | '专业信任版' | '轻转化版'
}[] = [
  { id: 'real', label: '真实日常版' },
  { id: 'pro', label: '专业信任版' },
  { id: 'convert', label: '轻转化版' }
]

/** 单条朋友圈成稿 */
export interface MomentsDraft {
  /** 稳定 id，供 UI 追踪与二次修改 */
  id: MomentsDraftId
  /** 差异化定位标签，仅用于 UI，不进入正文 */
  label: '真实日常版' | '专业信任版' | '轻转化版'
  /** 正文（序号与定位标题已剥离） */
  content: string
  /** 首条回复（不含 FIRST_REPLY_MARKER） */
  reply: string
  /** 模型自报的话题词，仅用于参考展示，不参与「# 1-3 次」计数 */
  tags?: string[]
}

/** 提示词层（只读查看用） */
export interface PromptLayer {
  role: 'system' | 'user'
  content: string
}

export interface MomentsResult {
  drafts: MomentsDraft[]
  source: 'llm' | 'fallback'
  warning?: string
  /** 本次实际提交给模型的提示词，供运营只读查看 */
  prompt: PromptLayer[]
  /**
   * @deprecated 兼容字段（保留期，勿新增消费）。
   * - **无生产消费者**：t3 视图已改用 `drafts[]`；视图/store 内无结果级 `.content` 读取（t11 落地后核实）。
   * - **仅为旧视图与历史收藏/本地草稿保留**：避免旧形态数据与未迁移调用点读取到 undefined。
   * - **退役条件（三条同时满足才可删）**：① `admin-web/scripts/ai-smoke.mts` 同步移除对本字段的断言（属 t10）；
   *   ② 全仓 grep 无消费点；③ 历经一个发布周期确认无历史草稿依赖。删除需先报 Captain（跨 owner 契约）。
   */
  content: string
  /** @deprecated 同 `content`：保留期字段，无生产消费者，退役条件见上 */
  reply: string
}

/**
 * 剥离模型自带的定位标题与序号前缀（「1、」「第X条」「## 」「【版本一】」「偏真实日常版：」等）。
 * 注意：只剥 `##` 以上的 Markdown 标题符，不碰正文里作重点标记的单个 `#`。
 */
export function stripDraftHeading(text: string): string {
  let s = String(text ?? '').trim()
  s = s
    .replace(/^```[a-zA-Z]*\s*/, '')
    .replace(/```$/, '')
    .trim()
  for (const p of [
    /^[ \t]*#{2,6}[ \t]*/gm,
    /^[ \t]*第[ \t]*[一二三四1234][ \t]*条[ \t]*[：:、.．]?[ \t]*/gm,
    /^[ \t]*[（(【]?[ \t]*(版本|方案)[ \t]*[一二三四1234][ \t]*[）)】]?[ \t]*[：:、.．]?[ \t]*/gm,
    /^[ \t]*[（(【]?[ \t]*偏?[ \t]*(真实日常|专业信任|转化表达|轻转化)[ \t]*版?[ \t]*[）)】]?[ \t]*[：:、.．]?[ \t]*/gm
  ]) {
    s = s.replace(p, '')
  }
  return s.replace(/^[ \t]*[1-9][ \t]*[、.．)）][ \t]*/, '').trim()
}

export interface MomentsPersona {
  name: string
  gender: string
  age: string
  role: string
  years: string
  specialties: string[]
  brand: string
  city: string
  audiences: string[]
  /** 由 derivePersonaDetail 推导的表达风格 */
  expressionStyle?: string[]
  /** 是否开启人设自动推导（关闭则只用用户手填值） */
  autoDerive?: boolean
}

export interface MomentsInput {
  persona: MomentsPersona
  category: string
  topic: string
  tone: string
  /** 目标 label（保留：历史记录与旧调用点仍在用） */
  goal: string
  expression: string
  lengthLabel: string
  /** lengthLabel 为「自定义字数」时生效的正文长度范围 */
  customLength?: { min: number; max: number }
  multiLine: boolean
  emojiLevel: string
  userInput: string
  /** 目标 id（优先于 goal，取值见 MOMENT_GOALS_V2） */
  goalId?: string
  /** 长度档 id（优先于 lengthLabel，取值见 MOMENT_LENGTHS） */
  lengthId?: string
  /** emoji 档 id（优先于 emojiLevel，取值见 EMOJI_USAGE_MODES） */
  emojiUsageId?: string
  /** 选题目录项 id（取值见 MOMENTS_CATALOG） */
  inspirationId?: string
  /** 生成期防折叠写法开关 */
  antiFoldEnabled?: boolean
  /** 图片说明（≤3 张图的文字描述） */
  imageSummary?: string
  /** 已上传图片张数，仅用于校验上限 3 */
  imageCount?: number
  /** 一次生成的成稿条数：1 或 3（默认 3） */
  draftCount?: number
}

// ==================== 小红书：选项口径（报告 8.1-8.5） ====================

/** 与朋友圈选题目录同构（避免两处双写） */
export type XhsTopicItem = MomentsCategoryItem
export type XhsTopicGroup = MomentsCategoryGroup

/** 组级写法说明（注入提示词的「小红书写法：」行） */
export const XHS_TOPIC_WRITING_GUIDE: Record<string, string> = {
  guide: '小红书写法：把方法写具体、可保存；先给结论再给步骤，标题里埋搜索关键词。',
  problem: '小红书写法：先共情问题，再给评估视角与训练方向，不做医疗承诺。',
  audience: '小红书写法：写清「这类人怎么开始」，用场景和节奏代替口号。',
  case: '小红书写法：讲过程与节点，用细节代替形容词，个人信息脱敏。',
  local:
    '小红书写法：面向同城搜索和本地生活转化。标题优先埋城市/商圈/附近/到店/体验/评估/避坑/推荐；' +
    '正文必须写清楚为什么适合附近的人、到店会经历什么、适合谁、怎么判断不踩雷。',
  sales: '小红书写法：先给价值再给入口，紧迫感来自事实（时间、名额），不制造焦虑。'
}

/** 小红书选题目录（**单一事实来源**：组/项 id + label + promptHint） */
export const XHS_TOPIC_CATALOG: XhsTopicGroup[] = [
  {
    id: 'guide',
    label: '干货教程',
    items: [
      {
        id: 'beginner-moves',
        label: '新手入门动作',
        promptHint: '给零基础一套可以照做的入门顺序，写清每个动作做几组、注意什么。'
      },
      {
        id: 'office-stretch',
        label: '办公室拉伸',
        promptHint: '给工位上就能做的拉伸，写清时长、频率和感受，别写成动作大全。'
      },
      {
        id: 'breath-core',
        label: '呼吸与核心',
        promptHint: '讲呼吸怎么带出核心发力，给一个当场可试的感知练习。'
      },
      {
        id: 'reformer',
        label: '普拉提器械科普',
        promptHint: '讲器械的作用与适合人群，用一次真实上课体验说明，不堆参数。'
      },
      {
        id: 'pose-pitfall',
        label: '体式避坑',
        promptHint: '列 2-3 个最容易做错的地方，讲清错在哪、怎么改，信息可保存。'
      },
      {
        id: 'home-list',
        label: '居家练习清单',
        promptHint: '给一份居家练习清单，写清顺序、时长与需要的空间。'
      },
      {
        id: 'plan-how',
        label: '训练计划怎么排',
        promptHint: '讲一周训练怎么排，给出上班族能执行的模板。'
      }
    ]
  },
  {
    id: 'problem',
    label: '问题改善',
    items: [
      {
        id: 'round-shoulder',
        label: '圆肩驼背',
        promptHint: '从日常姿势切入，讲评估怎么看、练什么能改善，不做医疗承诺。'
      },
      {
        id: 'pelvis',
        label: '骨盆前倾',
        promptHint: '讲骨盆前倾的常见表现与训练思路，先评估再安排动作。'
      },
      {
        id: 'hip-width',
        label: '假胯宽',
        promptHint: '讲假胯宽的成因与训练方向，语气坦诚，不给速成承诺。'
      },
      {
        id: 'neck-pain',
        label: '肩颈疼痛',
        promptHint: '讲肩颈紧张的放松与强化思路，明确不替代医疗诊断。'
      },
      {
        id: 'back-sore',
        label: '腰背酸',
        promptHint: '讲腰背酸的常见诱因与训练调整，给出第一步怎么做。'
      },
      {
        id: 'knee-friendly',
        label: '膝痛友好训练',
        promptHint: '给膝痛人群的友好训练选择与要避开的动作，强调先评估。'
      },
      {
        id: 'postpartum',
        label: '产后腹直肌',
        promptHint: '讲产后恢复的节奏与注意点，明确需医疗评估后再回到训练。'
      },
      {
        id: 'insomnia',
        label: '失眠放松',
        promptHint: '讲睡前放松与呼吸练习，写清做几分钟、怎么判断有没有效。'
      }
    ]
  },
  {
    id: 'audience',
    label: '人群场景',
    items: [
      {
        id: 'office-hour',
        label: '上班族一小时',
        promptHint: '写上班族一小时怎么安排训练与通勤，给出可复制的节奏。'
      },
      {
        id: 'mom-restart',
        label: '宝妈重启计划',
        promptHint: '写宝妈复训的节奏与心理顾虑，给一个低门槛的开始方式。'
      },
      {
        id: 'heavy-friendly',
        label: '大体重友好',
        promptHint: '写大体重人群的入门选择与关节保护，尊重且不评判。'
      },
      {
        id: 'midlife-first',
        label: '中年第一次练',
        promptHint: '写中年第一次练的顾虑与第一步安排，语气稳、不鸡血。'
      },
      {
        id: 'wedding',
        label: '备婚体态突击',
        promptHint: '写备婚期的时间安排与体态重点，写清周期和可实现预期。'
      },
      {
        id: 'senior',
        label: '银发活力',
        promptHint: '写中老年关节友好训练与安全注意点，强调评估与陪伴。'
      }
    ]
  },
  {
    id: 'case',
    label: '案例故事',
    items: [
      {
        id: 'case-3month',
        label: '学员3个月变化',
        promptHint: '写三个月训练的具体过程与变化节点，用细节代替形容词。'
      },
      {
        id: 'case-posture',
        label: '体态对比记录',
        promptHint: '讲清起点、过程和时间跨度，避免夸大对比效果。'
      },
      {
        id: 'case-dm',
        label: '会员私信',
        promptHint: '用一条私信问答带出问题与建议，个人信息脱敏。'
      },
      {
        id: 'case-return',
        label: '复训故事',
        promptHint: '写停练后复训的调整过程，重点在节奏与心理。'
      },
      {
        id: 'case-pain-free',
        label: '从疼痛到无痛',
        promptHint: '写疼痛缓解的过程，明确非医疗诊断，建议同步就医评估。'
      }
    ]
  },
  {
    id: 'local',
    label: '同城到店',
    items: [
      {
        id: 'local-daily',
        label: '宁波门店日常',
        promptHint: '写门店一天的真实片段（开课前、器械维护、课后收拾），落脚到人。'
      },
      {
        id: 'local-trial-flow',
        label: '体验课流程',
        promptHint: '写体验课从进门到结束会经历什么，让第一次来的人心里有数。'
      },
      {
        id: 'local-assessment',
        label: '体态评估现场',
        promptHint: '写评估现场的观察与沟通方式，说明会给出什么建议。'
      },
      {
        id: 'local-venue',
        label: '环境器械展示',
        promptHint: '写场馆环境与器械的实用信息（空间、器材、更衣、停车）。'
      },
      {
        id: 'local-invite',
        label: '宁波同城约练',
        promptHint: '写给宁波本地人的约练邀请，写清时间、地点、适合谁。'
      }
    ]
  },
  {
    id: 'sales',
    label: '销售转化',
    items: [
      {
        id: 'sales-trial',
        label: '体验课招募',
        promptHint: '写体验课适合谁、一节课能解决什么，给出报名方式。'
      },
      {
        id: 'sales-newcomer',
        label: '新客福利',
        promptHint: '写新客福利的适用范围与使用方式，不夸大优惠力度。'
      },
      {
        id: 'sales-limited',
        label: '限时优惠',
        promptHint: '写清优惠内容、时间与名额，紧迫感来自事实。'
      },
      {
        id: 'sales-member-day',
        label: '会员日大促',
        promptHint: '写会员日对老会员的安排，突出回馈而不是打折叫卖。'
      },
      {
        id: 'sales-referral',
        label: '转介绍活动',
        promptHint: '写转介绍的方式与双方得到的价值，鼓励分享。'
      },
      {
        id: 'sales-why-us',
        label: '为什么选我们',
        promptHint: '用课程、教练、流程的具体差异回答「为什么选我们」，不贬低同行。'
      },
      {
        id: 'sales-price',
        label: '价格与价值',
        promptHint: '把价格换算成每次课与教练的投入，讲清价值构成。'
      },
      {
        id: 'sales-bring-friend',
        label: '老带新奖励',
        promptHint: '写老带新奖励的规则与举例，规则清晰不绕。'
      }
    ]
  }
]

/** 派生视图：视图层现用（组名 → 项 label 数组） */
export const XHS_CATEGORIES: Record<string, string[]> = Object.fromEntries(
  XHS_TOPIC_CATALOG.map((g) => [g.label, g.items.map((i) => i.label)])
)

/** 按 id 查小红书选题；未命中回落「干货教程」组首项 */
export function findXhsTopic(id?: string): XhsTopicItem & { groupId: string; groupLabel: string } {
  for (const g of XHS_TOPIC_CATALOG) {
    for (const it of g.items) {
      if (it.id === id) return { ...it, groupId: g.id, groupLabel: g.label }
    }
  }
  const g0 = XHS_TOPIC_CATALOG[0]
  return { ...g0.items[0], groupId: g0.id, groupLabel: g0.label }
}

/** 按 label 查小红书选题（兼容旧调用点传 label）；未命中返回 null */
export function findXhsTopicByLabel(
  label?: string
): (XhsTopicItem & { groupId: string; groupLabel: string }) | null {
  if (!label) return null
  for (const g of XHS_TOPIC_CATALOG) {
    for (const it of g.items) {
      if (it.label === label) return { ...it, groupId: g.id, groupLabel: g.label }
    }
  }
  return null
}

// ---------- 账号定位：个人 IP / 门店 IP ----------

export type XhsIpType = 'personal-ip' | 'venue-ip'

export interface XhsIpDef {
  id: XhsIpType
  label: '个人 IP 定位' | '门店 IP 定位'
  desc: string
}

export const XHS_IP_TYPES: XhsIpDef[] = [
  {
    id: 'personal-ip',
    label: '个人 IP 定位',
    desc: '重点让用户认识教练本人，适合做专业观点、课堂观察、个人风格和长期信任。'
  },
  {
    id: 'venue-ip',
    label: '门店 IP 定位',
    desc: '重点让用户认识门店，适合做环境、课程、团队、案例、到店评估和本地预约。'
  }
]

export function findXhsIpType(id?: string): XhsIpDef {
  return XHS_IP_TYPES.find((t) => t.id === id) ?? XHS_IP_TYPES[0]
}

/** 两种 IP 的写法硬规则：定位真正改变写法（不只是打印一个标签） */
export const XHS_IP_WRITING_RULES: Record<XhsIpType, string> = {
  'personal-ip':
    '个人IP账号：重点建立“这个教练懂我、专业、真实、可信”，多写个人观察、专业判断、案例复盘、训练理念和陪伴感。',
  'venue-ip':
    '本地同城账号：自然加入城市、商圈、到店、体验课、评估、门店环境、真实会员、课程流程；让用户感觉这家店/这个教练就在身边，可以先评论或私信。'
}

/** 旧中文口径 ↔ id 兼容（视图层 v-model 保持中文字面值不变） */
export function resolveXhsIpType(p: { ipType?: string; ipTypeId?: XhsIpType }): XhsIpType {
  if (p.ipTypeId === 'personal-ip' || p.ipTypeId === 'venue-ip') return p.ipTypeId
  if (p.ipType === '门店IP' || p.ipType === 'venue-ip') return 'venue-ip'
  return 'personal-ip'
}

/** 同城场景判定：门店 IP / 开了同城 / 本次选题属于「同城到店」组，任一命中 */
export function isXhsLocalScenario(p: {
  ipType?: XhsIpType
  localFocus?: boolean
  topicGroupId?: string
}): boolean {
  return p.ipType === 'venue-ip' || !!p.localFocus || p.topicGroupId === 'local'
}

/** 从朋友圈人设推导小红书账号初始值（对齐小程序的 buildAccountFromMomentsProfile） */
export function deriveXhsAccountFromMoments(
  persona: MomentsPersona,
  opts?: { localFocus?: boolean }
): Partial<XhsProfile> {
  const venue = persona.role === '管理层' || persona.role === '主理人'
  return {
    ipTypeId: venue ? 'venue-ip' : 'personal-ip',
    ipType: venue ? '门店IP' : '个人IP',
    // 一麦的 city 恒为 BRAND_CITY，若照抄「city 非空则开同城」会让开关恒为 true，故显式传入
    localFocus: opts?.localFocus ?? false,
    accountName: persona.name,
    brand: persona.brand || BRAND_NAME,
    city: persona.city || BRAND_CITY,
    role: persona.role,
    gender: persona.gender,
    age: persona.age,
    strengths: persona.specialties,
    audiences: persona.audiences.slice(0, XHS_AUDIENCE_MAX)
  }
}

// ---------- 选择上限（视图层与提示词共用同一口径，消除静默丢弃） ----------

export const XHS_AUDIENCE_MAX = 4
export const XHS_STRENGTH_MAX = 6
export const XHS_STYLE_MAX = 4
export const XHS_POSITIONING_MAX = 3

// ---------- 同城标签：三要素 + 确定性补全 ----------

export const XHS_LOCAL_TAG_RULE =
  '同城标签硬规则：如果账号开启同城、账号是门店IP、或本次选择同城种草，' +
  '标签里必须包含城市/区域标签、品牌名/场馆名标签、行业属性词标签，' +
  `例如 #${BRAND_CITY} #${BRAND_NAME}瑜伽 #普拉提工作室。`

/** 行业属性词标签（按身份角色动态选，取值域为一麦瑜伽普拉提） */
export function xhsIndustryTags(role: string): string[] {
  const r = role || ''
  if (r.includes('普拉提')) return ['#普拉提']
  if (r.includes('瑜伽')) return ['#瑜伽']
  if (r.includes('康复')) return ['#康复', '#体态评估']
  return ['#私教', '#体态调整']
}

/**
 * 行业属性词的判断输入：身份角色 + 擅长方向。
 * 一麦的岗位名（会籍顾问/全职老师…）不含瑜伽/普拉提等项目词，只按角色判断会一律落到 #私教，
 * 故把「擅长方向」合并进来一起判断；`xhsIndustryTags` 的签名保持不变。
 */
export function industryKeywordSource(p: { role?: string; strengths?: string[] }): string {
  return [p.role || '', ...(p.strengths ?? [])].join(' ')
}

/**
 * 同城标签确定性补全：缺哪一类补哪一类（去重保序）。
 * 小程序只做质检报错、把修复交给重试；一麦没有重试回路，故改为系统确定性补齐。
 */
export function ensureLocalTags(
  tags: string[],
  p: {
    ipType?: XhsIpType
    localFocus?: boolean
    topicGroupId?: string
    brand?: string
    city?: string
    role?: string
    strengths?: string[]
  }
): string[] {
  const list = tags.map((t) => String(t || '').trim()).filter(Boolean)
  if (!isXhsLocalScenario(p)) return list
  const city = (p.city || BRAND_CITY).trim()
  const brand = (p.brand || BRAND_NAME).trim()
  const out = [...list]
  if (!out.some((t) => t.includes(city))) out.push(`#${city}`)
  if (!out.some((t) => t.includes(brand))) out.push(`#${brand}瑜伽`)
  const industry = xhsIndustryTags(industryKeywordSource(p))
  if (!industry.some((t) => out.includes(t))) out.push(industry[0])
  return out
}

// ---------- 输出结构：封面文案 / 标题 / 正文 / 标签 / 首评 ----------

export const XHS_COVER_RULE = '封面内容：只写适合放在封面或笔记图上的核心文字，不带任何表情符号。'
export const XHS_NO_EMOJI_IN_TITLE = '封面内容和笔记标题严禁出现表情符号。'
export const XHS_TEXT_ONLY_RULE =
  '只输出小红书内容文本，不生成图片文件；可以输出封面图、笔记图上可用的核心文字。'

export interface XhsNote {
  /** 笔记标题（禁表情符号） */
  title: string
  /** 封面/笔记图核心文字（禁表情符号） */
  coverText: string
  /** 正文（可按表情符号档位使用 emoji） */
  content: string
  /** 话题标签，每个以 # 开头 */
  tags: string[]
  /** 首评/置顶评论（一麦独有，小程序无此字段，不得删除） */
  reply: string
}

// ---------- 长度 / 标题 / 标签约束 ----------

export interface XhsLengthDef {
  id: 'short' | 'standard' | 'detailed' | 'deep'
  label: string
  range: string
  display: string
  min: number
  max: number
}

export const XHS_LENGTHS: XhsLengthDef[] = [
  {
    id: 'short',
    label: '短笔记',
    range: '200-300字',
    display: '短笔记 · 200-300字',
    min: 200,
    max: 300
  },
  {
    id: 'standard',
    label: '标准内容',
    range: '300-500字',
    display: '标准内容 · 300-500字',
    min: 300,
    max: 500
  },
  {
    id: 'detailed',
    label: '详细内容',
    range: '500-650字',
    display: '详细内容 · 500-650字',
    min: 500,
    max: 650
  },
  {
    id: 'deep',
    label: '深度内容',
    range: '650-800字',
    display: '深度内容 · 650-800字',
    min: 650,
    max: 800
  }
]

export const XHS_BODY_MAX_CHARS = 800
export const XHS_TITLE_MAX_CHARS = 20
export const XHS_TAG_MIN = 3
export const XHS_TAG_MAX = 8
export const XHS_NOTE_COUNT = 2
export const XHS_TAG_KEYWORDS = [
  '同城',
  '附近',
  '体验',
  '评估',
  '新手',
  '上班族',
  '肩颈',
  '体态',
  '减脂',
  '塑形',
  '普拉提',
  '瑜伽',
  '私教',
  '康复',
  '圆肩',
  '骨盆',
  '核心',
  '产后',
  '避坑',
  '动作',
  '课程',
  '教练'
]

/** 正文长度档解析：id → range → 默认标准内容 */
export function findXhsLength(idOrRange?: string): XhsLengthDef {
  const k = (idOrRange ?? '').trim()
  return (
    XHS_LENGTHS.find((l) => l.id === k) ?? XHS_LENGTHS.find((l) => l.range === k) ?? XHS_LENGTHS[1]
  )
}

export const XHS_CUSTOM_PROMPT_MAX_LENGTH = 800
export const XHS_BASE_PROMPT_MAX_LENGTH = 2600

export function normalizeXhsCustomPrompt(v: string): string {
  return String(v ?? '')
    .trim()
    .slice(0, XHS_CUSTOM_PROMPT_MAX_LENGTH)
}

/** 空值回落内置底层提示词占位（只读查看用；本次不做可编辑存储） */
export function normalizeXhsBasePrompt(v: string): string {
  const base = String(v ?? '').trim() || '一麦内置小红书底层提示词（只读）'
  return base.slice(0, XHS_BASE_PROMPT_MAX_LENGTH)
}

// ---------- 表达风格（朋友圈与小红书同域，单一定义） ----------

export const EXPRESSION_STYLES = [
  '专业干练',
  '温柔陪伴',
  '真实接地气',
  '高级克制',
  '热情鼓励',
  '老板视角',
  '成熟稳重',
  '有生活感',
  '不爱硬销售',
  '适度转化',
  '专业严谨',
  '有力量感'
]

/** 派生视图：视图层现用 */
export const XHS_STYLES = EXPRESSION_STYLES

export const XHS_CONVERSIONS = [
  '不主动转化',
  '评论区留言',
  '私信咨询',
  '预约评估',
  '预约体验',
  '到店看看'
]

export interface XhsProfile {
  /** 中文口径（视图层 v-model 绑定值，字面值保持不变） */
  ipType: '个人IP' | '门店IP'
  accountName: string
  role: string
  gender: string
  age: string
  audiences: string[]
  brand: string
  city: string
  localFocus: boolean
  strengths: string[]
  style: string
  conversion: string
  /** id 口径（新增，优先于 ipType） */
  ipTypeId?: XhsIpType
  /** 多选表达风格（新增，优先于 style） */
  styles?: string[]
  /** emoji 档位（复用朋友圈四档） */
  emojiUsageId?: string
}

export interface XhsInput {
  profile: XhsProfile
  category: string
  topic: string
  points: string
  /** 正文长度档 id（默认 standard） */
  lengthId?: string
  /** emoji 档位覆盖（优先于 profile.emojiUsageId） */
  emojiUsageId?: string
  /** 选题目录项 id（取值见 XHS_TOPIC_CATALOG） */
  topicId?: string
}

export interface XhsResult {
  notes: XhsNote[]
  source: 'llm' | 'fallback'
  warning?: string
  /** 本次实际提交给模型的提示词，供运营只读查看 */
  prompt: PromptLayer[]
  /**
   * @deprecated 兼容字段（保留期，勿新增消费）：`title/content/tags/reply` 四个均为 `notes[0]` 的同义投影。
   * - **无生产消费者**：t3 视图已改用 `notes[]`（含 coverText）；视图/store 无结果级旧字段读取（t11 落地后核实）。
   * - **仅为旧视图与历史收藏/本地草稿保留**，避免旧数据渲染成空块。
   * - **退役条件（三条同时满足才可删）**：① 冒烟门禁同步移除对应断言（属 t10）；② 全仓 grep 无消费点；
   *   ③ 历经一个发布周期确认无历史草稿依赖。删除需先报 Captain（跨 owner 契约）。
   */
  title: string
  /** @deprecated 同 `title`：保留期字段，无生产消费者，退役条件见上 */
  content: string
  /** @deprecated 同 `title`：保留期字段，无生产消费者，退役条件见上 */
  tags: string[]
  /** @deprecated 同 `title`：保留期字段，无生产消费者，退役条件见上 */
  reply: string
}

// ==================== 人设自动推导（角色 × 性别 × 年龄 × 职位 四维查表） ====================

export interface PersonaDeriveTable {
  /** 与 SPECIALTY_OPTIONS 取值同域 */
  specialties: string[]
  /** 与 AUDIENCE_OPTIONS 取值同域 */
  audiences: string[]
  /** 与 MOMENT_STYLES / XHS_STYLES 取值同域 */
  expressionStyle: string[]
}

const EMPTY_DERIVE: PersonaDeriveTable = { specialties: [], audiences: [], expressionStyle: [] }

/** 角色基础表：一麦组织岗位 → 专长/客群/表达风格（性别表缺失时的回落） */
export const PERSONA_ROLE_TABLE: Record<string, PersonaDeriveTable> = {
  会籍顾问: {
    specialties: ['体态评估', '减脂塑形', '孕产恢复'],
    audiences: ['25-45岁久坐上班族', '肩颈腰背慢性酸痛人群', '零基础大体重、运动入门人群'],
    expressionStyle: ['真实接地气', '热情鼓励', '有生活感']
  },
  全职老师: {
    specialties: ['瑜伽', '普拉提', '体态调整', '呼吸'],
    audiences: [
      '体态问题人群（圆肩/骨盆前倾/XO腿）',
      '失眠焦虑、需要放松解压的人群',
      '有训练瓶颈的健身爱好者'
    ],
    expressionStyle: ['专业干练', '温柔陪伴', '有力量感']
  },
  门店前台: {
    specialties: ['瑜伽', '拉伸放松', '冥想呼吸'],
    audiences: [
      '注重长期健康的中年女性',
      '银发活力人群（关节友好训练）',
      '失眠焦虑、需要放松解压的人群'
    ],
    expressionStyle: ['温柔陪伴', '真实接地气', '有生活感']
  },
  管理层: {
    specialties: ['体态评估', '功能训练', '减脂塑形'],
    audiences: [
      '25-45岁久坐上班族',
      '产后恢复妈妈（备孕/孕期/产后）',
      '备婚/重要场合前突击改善体态'
    ],
    expressionStyle: ['老板视角', '成熟稳重', '适度转化']
  },
  主理人: {
    specialties: ['瑜伽', '普拉提', '康复理疗', '冥想呼吸'],
    audiences: [
      '注重长期健康的中年女性',
      '体态问题人群（圆肩/骨盆前倾/XO腿）',
      '术后康复人群（需医疗评估）'
    ],
    expressionStyle: ['成熟稳重', '高级克制', '专业严谨']
  }
}

/** 角色 × 性别表（本批次已填语料；性别优先，缺失回落角色基础表） */
export const PERSONA_ROLE_GENDER_TABLE: Record<string, Record<string, PersonaDeriveTable>> = {
  会籍顾问: {
    女: {
      specialties: ['孕产恢复', '体态调整'],
      audiences: ['产后恢复妈妈（备孕/孕期/产后）', '注重长期健康的中年女性'],
      expressionStyle: ['温柔陪伴', '真实接地气']
    },
    男: {
      specialties: ['减脂塑形', '功能训练'],
      audiences: ['零基础大体重、运动入门人群', '有训练瓶颈的健身爱好者'],
      expressionStyle: ['专业干练', '热情鼓励']
    }
  },
  全职老师: {
    女: {
      specialties: ['普拉提', '孕产恢复'],
      audiences: ['体态问题人群（圆肩/骨盆前倾/XO腿）', '产后恢复妈妈（备孕/孕期/产后）'],
      expressionStyle: ['温柔陪伴', '专业严谨']
    },
    男: {
      specialties: ['功能训练', '体态调整'],
      audiences: ['有训练瓶颈的健身爱好者', '25-45岁久坐上班族'],
      expressionStyle: ['专业干练', '有力量感']
    }
  },
  门店前台: {
    女: {
      specialties: ['拉伸放松', '冥想呼吸'],
      audiences: ['银发活力人群（关节友好训练）', '注重长期健康的中年女性'],
      expressionStyle: ['温柔陪伴', '有生活感']
    },
    男: {
      specialties: ['拉伸放松', '康复理疗'],
      audiences: ['肩颈腰背慢性酸痛人群', '零基础大体重、运动入门人群'],
      expressionStyle: ['真实接地气', '专业严谨']
    }
  },
  管理层: {
    女: {
      specialties: ['体态评估', '减脂塑形'],
      audiences: ['25-45岁久坐上班族', '备婚/重要场合前突击改善体态'],
      expressionStyle: ['成熟稳重', '适度转化']
    },
    男: {
      specialties: ['功能训练', '康复理疗'],
      audiences: ['有训练瓶颈的健身爱好者', '肩颈腰背慢性酸痛人群'],
      expressionStyle: ['老板视角', '专业严谨']
    }
  },
  主理人: {
    女: {
      specialties: ['孕产恢复', '冥想呼吸'],
      audiences: ['注重长期健康的中年女性', '产后恢复妈妈（备孕/孕期/产后）'],
      expressionStyle: ['高级克制', '温柔陪伴']
    },
    男: {
      specialties: ['康复理疗', '功能训练'],
      audiences: ['术后康复人群（需医疗评估）', '有训练瓶颈的健身爱好者'],
      expressionStyle: ['老板视角', '成熟稳重']
    }
  }
}

/** 年龄表：语料分批补（表为空时安全返回空数组，不阻断生成） */
export const PERSONA_AGE_TABLE: Record<string, PersonaDeriveTable> = {}

/** 职位表：一麦「职位」与「角色」同域（PERSONA_ROLES），角色表已覆盖，此处留空以避免双写漂移 */
export const PERSONA_POSITION_TABLE: Record<string, PersonaDeriveTable> = {}

/** 保序去重拼接（先到先得） */
export function unionUnique(...lists: (string[] | undefined)[]): string[] {
  const seen = new Set<string>()
  const out: string[] = []
  for (const list of lists) {
    for (const v of list ?? []) {
      const k = String(v ?? '').trim()
      if (!k || seen.has(k)) continue
      seen.add(k)
      out.push(k)
    }
  }
  return out
}

/** 角色归一化：精确命中 → 含包含关系的别名 → 兜底 PERSONA_ROLES[0] */
export function normalizePersonaRole(role?: string): string {
  const r = (role ?? '').trim()
  if (!r) return PERSONA_ROLES[0]
  return (
    PERSONA_ROLES.find((x) => x === r) ??
    Object.keys(PERSONA_ROLE_TABLE).find((x) => r.includes(x) || x.includes(r)) ??
    PERSONA_ROLES[0]
  )
}

/** 职位归一化：一麦职位与角色同域，故与角色归一化同源 */
export function normalizePersonaPosition(position?: string): string {
  return normalizePersonaRole(position)
}

function normalizeGender(gender?: string): string {
  const g = (gender ?? '').trim()
  if (g.includes('女')) return '女'
  if (g.includes('男')) return '男'
  return ''
}

/** 四维推导（注意：签名不含 years —— 推导不使用从业年限，年限仅作为人设字段注入） */
export function derivePersonaDetail(p: {
  role?: string
  gender?: string
  age?: string
  position?: string
}): PersonaDeriveTable {
  const role = normalizePersonaRole(p.role)
  const position = normalizePersonaPosition(p.position ?? p.role)
  const gender = normalizeGender(p.gender)
  const base = (gender && PERSONA_ROLE_GENDER_TABLE[role]?.[gender]) || PERSONA_ROLE_TABLE[role]
  const byAge = PERSONA_AGE_TABLE[(p.age ?? '').trim()] ?? EMPTY_DERIVE
  const byPos = PERSONA_POSITION_TABLE[position] ?? EMPTY_DERIVE
  return {
    specialties: unionUnique(base?.specialties, byAge.specialties, byPos.specialties),
    audiences: unionUnique(base?.audiences, byAge.audiences, byPos.audiences),
    expressionStyle: unionUnique(
      base?.expressionStyle,
      byAge.expressionStyle,
      byPos.expressionStyle
    )
  }
}

// ==================== 提示词编排（场景模板+人设+选题+输出结构） ====================

function personaText(p: MomentsPersona): string {
  const derive = p.autoDerive === false ? EMPTY_DERIVE : derivePersonaDetail(p)
  const specialties = unionUnique(p.specialties, derive.specialties)
  const audiences = unionUnique(p.audiences, derive.audiences)
  const styles = unionUnique(p.expressionStyle, derive.expressionStyle)
  return [
    p.name && `姓名：${p.name}`,
    p.gender && `性别：${p.gender}`,
    p.age && `年龄：${p.age}`,
    p.role && `身份角色：${p.role}`,
    p.years && `从业年限：${p.years}`,
    specialties.length && `专业方向：${specialties.join('、')}`,
    `品牌：${p.brand || BRAND_NAME}`,
    `城市：${p.city || BRAND_CITY}`,
    audiences.length && `主要客群：${audiences.join('、')}`,
    styles.length && `表达风格：${styles.join('、')}`
  ]
    .filter(Boolean)
    .join('；')
}

/** 首条回复规则（正文结束后同一条内容里追加，不单独分字段） */
export const MOMENTS_FIRST_REPLY_RULE =
  `首条回复规则：每条朋友圈正文结束后，必须在同一个内容里追加一条自己回复自己的评论；格式固定为换行后写“${FIRST_REPLY_MARKER}”，下一行写回复内容。` +
  '首条回复不单独分字段，不要进入单独文本框；正文按用户选择字数生成，首条回复为额外补充，建议12-35字。'

/** 长度解析：自定义档 → id → label → 末位回落标准版 */
export function resolveMomentLength(input: {
  lengthLabel?: string
  lengthId?: string
  customLength?: { min: number; max: number }
}): MomentLengthDef {
  const custom =
    (input.lengthId === 'custom' || input.lengthLabel === MOMENT_LENGTH_CUSTOM) &&
    input.customLength
  if (custom && input.customLength!.max > input.customLength!.min) {
    const { min, max } = input.customLength!
    return { id: 'standard', label: `${min}-${max}字（自定义）`, min, max }
  }
  return findMomentLength(input.lengthId ?? input.lengthLabel)
}

function momentsImageLines(input: MomentsInput): string[] {
  const summary = (input.imageSummary ?? '').trim()
  const count = Number(input.imageCount ?? 0)
  if (count > 0 || summary) {
    return [
      `图片信息：${summary || `已上传${Math.min(count, MOMENTS_MAX_IMAGES)}张图片（无文字说明）`}`,
      MOMENTS_IMAGE_RULE
    ]
  }
  return ['图片信息：无']
}

/** 朋友圈提示词组装（供生成与质检共用；段落顺序：最高优先级 → 立场铁律 → 人设层 → 内容层 → 本次硬约束） */
export function buildMomentsMessages(input: MomentsInput): PromptLayer[] {
  const goal = findMomentGoal(input.goalId ?? input.goal)
  const inspiration = input.inspirationId
    ? findMomentsInspiration(input.inspirationId)
    : findMomentsInspirationByLabel(input.topic)
  const len = resolveMomentLength(input)
  const mode = findEmojiUsageMode(input.emojiUsageId ?? input.emojiLevel)
  const count = input.draftCount === 1 ? 1 : 3
  const draftId = goalToDraftId(goal)
  const draftLabel = MOMENTS_DRAFT_META.find((d) => d.id === draftId)?.label ?? '真实日常版'

  const contentLines = [
    `内容分类：${input.category}${input.topic ? ` / ${input.topic}` : ''}`,
    inspiration ? `灵感类型：${inspiration.groupLabel} / ${inspiration.label}` : '',
    inspiration ? `写作方向：${inspiration.promptHint}` : '',
    `语气：${input.tone}`,
    `目标：${goal.label}`,
    '目标说明：目标同时决定销售强度、转化感和结尾行动指令；分享生活（无目标）不加行动指令，其他目标可以在结尾自然给出行动入口。',
    `目标行动指令参考：${goal.actionInstruction || '无。不要添加行动指令。'}`,
    `目标写法提醒：${goal.promptHint}`,
    `表达方式：${input.expression}`,
    `内容长度：${len.min}-${len.max}字`
  ].filter(Boolean)

  const hardLines = [
    `生成数量：正好${count}条朋友圈成稿。`,
    count === 3
      ? '要求：生成3条原创朋友圈，分别偏真实日常、专业信任、转化表达。每一条都必须单独完整满足以上硬约束，不要搬运平台笔记。'
      : `要求：生成1条原创朋友圈，偏${draftLabel}。`,
    `单条字数：${
      count === 1 ? `本次只生成1条，该条必须落在 ${len.min}-${len.max} 字。` : ''
    }${buildLengthRule(`${len.min}-${len.max}字`)}`,
    input.multiLine
      ? '断行要求：必须多分行、多空行，每个段落之间留一个完整空行。'
      : '断行要求：不强制多空行，用自然段落即可。',
    `表情符号：${buildEmojiRule(mode)}`,
    `井号键：${buildHashtagRule(goal.actionInstruction)}`,
    MOMENTS_FIRST_REPLY_RULE,
    count === 3
      ? '输出格式：可以用1、2、3区分三条，但正文里不要出现“偏真实日常 / 偏专业信任 / 偏转化表达”这类标题，也不要使用 Markdown 标题符号。'
      : '',
    ...momentsImageLines(input),
    `防折叠规则：${buildAntiFoldSection(!!input.antiFoldEnabled)}`,
    MOMENTS_DE_AI_RULE,
    `严格输出 JSON（不要代码块、不要多余解释）：{"drafts":[{"id":"real|pro|convert","content":string,"reply":string}]}。` +
      `content 为朋友圈正文（不含“${FIRST_REPLY_MARKER}”标记），reply 为12-35字的首条回复。`
  ].filter(Boolean)

  const system = [
    `你是一名瑜伽普拉提馆（品牌「${BRAND_NAME}」，城市「${BRAND_CITY}」）的社交媒体内容助手，为${BRAND_NAME}的教练/顾问生成朋友圈文案草稿。`,
    '【最高优先级】',
    MOMENTS_PRIORITY_CHAIN,
    '【立场铁律】',
    MOMENTS_STANCE_RULE,
    MOMENTS_LANGUAGE_RULE,
    '硬性规则：不虚构具体会员隐私信息，不使用医疗诊断或疗效承诺用语，不过度促销。',
    '【个人人设层】',
    personaText(input.persona),
    '【朋友圈内容层】',
    ...contentLines,
    '【本次生成硬约束】',
    ...hardLines
  ].join('\n')

  const user = [
    '【本次选择回显】',
    `内容层：${input.category}${input.topic ? ` / ${input.topic}` : ''}｜语气：${input.tone}｜目标：${goal.label}｜表达方式：${input.expression}`,
    `硬约束：单条 ${len.min}-${len.max} 字｜emoji ${mode.label}｜井号键 1-3 次｜${count === 3 ? '3 条' : '1 条'}｜首条回复必须输出（${FIRST_REPLY_MARKER}）`,
    input.userInput && `我想表达的内容：${input.userInput}`
  ]
    .filter(Boolean)
    .join('\n')

  return [
    { role: 'system', content: system },
    { role: 'user', content: user }
  ]
}

/** 小红书 emoji 档：入参覆盖 → 账号设置 → 默认中频 */
export function resolveXhsEmojiMode(input: XhsInput): EmojiUsageMode {
  return findEmojiUsageMode(input.emojiUsageId ?? input.profile.emojiUsageId)
}

/** 小红书提示词组装（两种 IP 写法差异 + 同城三要素 + 封面/标题禁 emoji 均在 system 段） */
export function buildXhsMessages(input: XhsInput): PromptLayer[] {
  const p = input.profile
  const ip = resolveXhsIpType(p)
  const len = findXhsLength(input.lengthId)
  const mode = resolveXhsEmojiMode(input)
  const topic = input.topicId ? findXhsTopic(input.topicId) : findXhsTopicByLabel(input.topic)
  const local = isXhsLocalScenario({
    ipType: ip,
    localFocus: p.localFocus,
    topicGroupId: topic?.groupId
  })
  const guide = topic ? XHS_TOPIC_WRITING_GUIDE[topic.groupId] : ''
  const styles = (p.styles?.length ? p.styles : [p.style]).filter(Boolean)

  const system = [
    `你是小红书瑜伽普拉提赛道的内容策划，为「${BRAND_NAME}」（${BRAND_CITY}）的账号生成笔记草稿。`,
    '写作规范：标题带搜索感/场景感/问题感；正文开头快速命中目标读者，中段给出具体可收藏的方法信息，结尾轻转化。',
    '始终以教练/服务者视角，不伪造会员第一人称经历；不含医疗承诺与虚假效果保证。',
    `账号定位：${findXhsIpType(ip).label}。${XHS_IP_WRITING_RULES[ip]}`,
    XHS_TEXT_ONLY_RULE,
    XHS_COVER_RULE,
    XHS_NO_EMOJI_IN_TITLE,
    `生成数量：${XHS_NOTE_COUNT}篇。`,
    '输出要求：两篇风格要明显不同（一篇偏干货方法、一篇偏场景/案例）；封面内容、笔记标题、笔记正文、标签必须分开；封面内容和笔记标题严禁出现表情符号。',
    `内容长度：${len.range}。普通笔记正文不要超过${XHS_BODY_MAX_CHARS}字。`,
    `标题要求：像真实小红书标题，不超过${XHS_TITLE_MAX_CHARS}字，带搜索关键词，不带任何表情符号。`,
    `标签要求：${XHS_TAG_MIN}-${XHS_TAG_MAX}个，每个以 # 开头，需覆盖行业词、问题词、人群词${local ? '、同城词' : ''}和本次选题词；不能只写泛泛的#小红书笔记。`,
    `表情符号频率：${mode.label}。${buildEmojiRule(mode)}`,
    local
      ? XHS_LOCAL_TAG_RULE
      : '同城标签硬规则：本次未开启同城，标签按选题相关性给，不要硬塞城市词。',
    '首评要求：reply 为发布后置顶评论或首条自评，30-60字，给出下一步动作（私信、约评估、留言答疑等），与转化方向一致。',
    `严格输出 JSON（不要代码块、不要多余解释）：{"notes":[{"title":string,"coverText":string,"content":string,"tags":string[],"reply":string}]}`
  ].join('\n')

  const user = [
    `账号定位：${findXhsIpType(ip).label}${p.accountName ? `（账号名：${p.accountName}）` : ''}`,
    `身份角色：${p.role || '全职老师'}${p.gender ? `/${p.gender}` : ''}${p.age ? `/${p.age}` : ''}`,
    `品牌：${p.brand || BRAND_NAME}`,
    `城市：${p.city || BRAND_CITY}${local ? '（强化同城属性，自然融入城市、商圈、到店、体验课、评估、门店环境、课程流程）' : ''}`,
    p.audiences.length &&
      `目标客群（最多${XHS_AUDIENCE_MAX}项）：${p.audiences.slice(0, XHS_AUDIENCE_MAX).join('、')}`,
    p.strengths.length && `专业擅长：${p.strengths.join('、')}`,
    `表达风格：${styles.join('、')}；默认转化方向：${p.conversion}`,
    input.category && `选题分类：${input.category}`,
    topic && `写作方向：${topic.promptHint}`,
    guide && guide,
    `本次选题：${input.topic || topic?.label || '自由发挥一个近期课堂相关选题'}`,
    `内容长度：${len.display}；emoji：${mode.label}`,
    input.points && `补充要点：${input.points}`
  ]
    .filter(Boolean)
    .join('\n')

  return [
    { role: 'system', content: system },
    { role: 'user', content: user }
  ]
}

// ==================== LLM 调用（OpenAI 兼容 /chat/completions） ====================

interface ChatMessage {
  role: 'system' | 'user' | 'assistant'
  content: string
}

/**
 * 大模型调用统一入口
 * - 后端模式：经 Laravel /ai/chat 代理转发，流式输出（中转站对 stream 请求才结算 token）
 * - 演示模式：浏览器直连（仅对支持CORS的服务商可用）
 */
async function callLLM(messages: ChatMessage[], featureType = 'chat'): Promise<string> {
  if (USE_BACKEND) {
    return chatLLMStream(messages, { featureType })
  }
  await initAiConfig()
  const store = useAiConfigStore()
  if (!store.isReady()) throw new Error('AI_NOT_CONFIGURED')
  const c = store.config

  const resp = await fetch(`${c.baseUrl.replace(/\/$/, '')}/chat/completions`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${c.apiKey}`
    },
    body: JSON.stringify({
      model: c.model,
      messages,
      temperature: c.temperature
    })
  })
  if (!resp.ok) {
    const text = await resp.text().catch(() => '')
    throw new Error(`LLM_HTTP_${resp.status}: ${text.slice(0, 200)}`)
  }
  const data = await resp.json()
  const content = data?.choices?.[0]?.message?.content
  if (!content) throw new Error('LLM_EMPTY_RESPONSE')
  return String(content)
}

/**
 * 后端代理流式对话（SSE）。中转站等仅对 stream 请求返回用量/结算 token，
 * 因此统一走流式并本地累积。onDelta 用于逐字更新 UI。
 */
export async function chatLLMStream(
  messages: ChatMessage[],
  opts?: { onDelta?: (t: string) => void; maxTokens?: number; featureType?: string }
): Promise<string> {
  await initAiConfig()
  const store = useAiConfigStore()
  if (!store.isReady()) throw new Error('AI_NOT_CONFIGURED')
  const c = store.config

  const resp = await fetch(`${API_BASE}/ai/chat`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${getBackendToken()}`
    },
    body: JSON.stringify({
      messages,
      temperature: c.temperature,
      maxTokens: opts?.maxTokens,
      featureType: opts?.featureType ?? 'chat',
      stream: true
    })
  })
  return readSse(resp, opts?.onDelta)
}

/** 解析 SSE 流，累积并回调逐字内容 */
async function readSse(resp: Response, onDelta?: (t: string) => void): Promise<string> {
  if (!resp.ok) {
    const text = await resp.text().catch(() => '')
    throw new Error(`AI_HTTP_${resp.status}: ${text.slice(0, 200)}`)
  }
  const reader = resp.body?.getReader()
  if (!reader) throw new Error('AI_NO_STREAM')

  const decoder = new TextDecoder()
  let buffer = ''
  let full = ''
  const processLine = (line: string): void => {
    if (!line.startsWith('data:')) return
    const data = line.slice(5).trim()
    if (data === '[DONE]' || data === '') return
    try {
      const obj = JSON.parse(data) as {
        choices?: { delta?: { content?: string }; message?: { content?: string } }[]
      }
      const delta = obj?.choices?.[0]?.delta?.content ?? obj?.choices?.[0]?.message?.content ?? ''
      if (delta) {
        full += delta
        onDelta?.(delta)
      }
    } catch {
      /* 忽略心跳/空行 */
    }
  }

  while (true) {
    const { done, value } = await reader.read()
    if (done) break
    buffer += decoder.decode(value, { stream: true })
    // 同时按 \n 与 \r 分隔，兼容不同 SSE 实现
    let idx: number
    while ((idx = buffer.search(/[\n\r]/)) >= 0) {
      const line = buffer.slice(0, idx).trim()
      buffer = buffer.slice(idx + 1)
      if (line) processLine(line)
    }
  }
  // 结尾残余数据（无换行）也处理，避免漏掉最后一段
  if (buffer.trim()) processLine(buffer.trim())
  if (!full) throw new Error('LLM_EMPTY_RESPONSE')
  return full
}

/** 获取服务商可用模型列表（OpenAI 兼容 /models），走 Laravel 代理 */
export async function fetchAvailableModels(baseUrl: string, apiKey: string): Promise<string[]> {
  if (!USE_BACKEND) throw new Error('需要后端模式支持')
  const d = await apiPost<{ models?: string[]; code?: number; message?: string }>('/ai/models', {
    baseUrl,
    apiKey
  })
  if (d && d.code !== undefined && d.code !== 0) {
    throw new Error(d.message || '获取模型列表失败')
  }
  return d?.models ?? []
}

/** 从模型输出里抠出第一个 JSON 对象（容错：允许前后有解释文字或代码块） */
function looseJson(text: string): Record<string, unknown> | null {
  const m = String(text ?? '').match(/\{[\s\S]*\}/)
  if (!m) return null
  try {
    const obj = JSON.parse(m[0])
    return obj && typeof obj === 'object' ? (obj as Record<string, unknown>) : null
  } catch {
    return null
  }
}

/** 内部别名（不新增导出：导出面是两个 owner 的公共契约） */
type DraftMeta = (typeof MOMENTS_DRAFT_META)[number]

/**
 * 在同一响应内挑出**未被占用**的版型元数据：
 * 先认模型给的 id，其次按下标取 MOMENTS_DRAFT_META[i]，最后退到第一个未占用的版型。
 * 这样「模型把三条都标成 real」或「返回未知 id」都不会产出重复 id。
 */
function pickDraftMeta(rawId: string, index: number, used: Set<MomentsDraftId>): DraftMeta {
  const candidates: (DraftMeta | undefined)[] = [
    MOMENTS_DRAFT_META.find((d) => d.id === rawId),
    MOMENTS_DRAFT_META[index] ?? MOMENTS_DRAFT_META[0],
    ...MOMENTS_DRAFT_META
  ]
  const hit = candidates.find((c): c is DraftMeta => !!c && !used.has(c.id))
  const meta: DraftMeta = hit ?? MOMENTS_DRAFT_META[index] ?? MOMENTS_DRAFT_META[0]
  used.add(meta.id)
  return meta
}

/**
 * 朋友圈成稿解析（三级容错，旧格式不回退失败）：
 * 1）`{drafts:[...]}` 或裸数组；2）旧单对象 `{content, reply}`；3）纯文本整段作正文。
 *
 * **id 唯一性保证**：同一次解析结果的 `id` 一定互不相同（重复/未知 id 回退到下标版型），
 * 故 UI 可直接用 `id` 作 key；若模型返回的顺序本身不可信，展示 key 仍建议用 `d${i+1}`。
 */
function parseMomentsDraftsLoose(text: string, count: number): MomentsDraft[] {
  const raw = String(text ?? '')
    .trim()
    .replace(/^["“]|["”]$/g, '')
  const obj = looseJson(raw)
  let list: unknown[] | null = null
  if (obj) {
    if (Array.isArray(obj.drafts)) list = obj.drafts
    else if (Array.isArray(obj.notes)) list = obj.notes
    else if (obj.content) list = [obj]
  }
  if (!list) {
    const arr = raw.match(/\[[\s\S]*\]/)
    if (arr) {
      try {
        const parsed = JSON.parse(arr[0])
        if (Array.isArray(parsed)) list = parsed
      } catch {
        /* ignore */
      }
    }
  }
  const usedDraftIds = new Set<MomentsDraftId>()
  const drafts = (list ?? [])
    .map((item, i) => {
      const o = (item ?? {}) as Record<string, unknown>
      const content = stripDraftHeading(String(o.content ?? o.text ?? ''))
      if (!content) return null
      const meta = pickDraftMeta(String(o.id ?? ''), i, usedDraftIds)
      return {
        id: meta.id,
        label: meta.label,
        content,
        reply: stripDraftHeading(String(o.reply ?? o.firstReply ?? o.comment ?? '')),
        tags: Array.isArray(o.tags) ? o.tags.map(String) : undefined
      } as MomentsDraft
    })
    .filter((d): d is MomentsDraft => !!d)
    .slice(0, Math.max(1, count))
  if (drafts.length) return drafts
  const plain = stripDraftHeading(raw)
  return plain ? [{ id: 'real', label: '真实日常版', content: plain, reply: '' }] : []
}

/**
 * 小红书笔记解析（容忍 coverText 的多种别名，兜底为 title）：
 * 数组优先 → 旧单对象回落 → 纯文本回落。
 */
function parseXhsNotesLoose(text: string): XhsNote[] {
  const raw = String(text ?? '')
    .trim()
    .replace(/^["“]|["”]$/g, '')
  const obj = looseJson(raw)
  let list: unknown[] | null = null
  if (obj) {
    if (Array.isArray(obj.notes)) list = obj.notes
    else if (Array.isArray(obj.drafts)) list = obj.drafts
    else if (obj.title || obj.content) list = [obj]
  }
  const notes = (list ?? [])
    .map((item) => toXhsNote((item ?? {}) as Record<string, unknown>))
    .filter((n) => !!n)
  if (notes.length) return notes.slice(0, XHS_NOTE_COUNT)
  const plain = raw.trim()
  return plain
    ? [
        {
          title: plain.slice(0, XHS_TITLE_MAX_CHARS),
          coverText: '',
          content: plain,
          tags: [],
          reply: ''
        }
      ]
    : []
}

function toXhsNote(o: Record<string, unknown>): XhsNote | null {
  const title = String(o.title ?? '').trim()
  const content = String(o.content ?? '').trim()
  if (!title && !content) return null
  const coverRaw = o.coverText ?? o.cover_text ?? o['封面内容'] ?? o['封面文字'] ?? o['笔记图文字']
  const tagsRaw = o.tags ?? o.topics ?? o['话题标签']
  return {
    title,
    coverText: String(coverRaw ?? '').trim() || title,
    content,
    tags: Array.isArray(tagsRaw) ? tagsRaw.map(String).filter(Boolean).slice(0, XHS_TAG_MAX) : [],
    reply: String(o.reply ?? o.firstComment ?? o['首评'] ?? '').trim()
  }
}

/** 标签个数不足时补行业/选题词，超出时截断（不静默：调用方据 warnings 提示） */
function normalizeXhsTags(tags: string[], input: XhsInput): string[] {
  const p = input.profile
  const topic = input.topicId ? findXhsTopic(input.topicId) : findXhsTopicByLabel(input.topic)
  const base = tags.map((t) => (t.startsWith('#') ? t : `#${t}`)).filter((t) => t.length > 1)
  const filled = [...base]
  for (const t of xhsIndustryTags(industryKeywordSource(p))) {
    if (filled.length >= XHS_TAG_MIN) break
    if (!filled.includes(t)) filled.push(t)
  }
  if (filled.length < XHS_TAG_MIN && topic && !filled.includes(`#${topic.label}`))
    filled.push(`#${topic.label}`)
  return ensureLocalTags(filled, {
    ipType: resolveXhsIpType(p),
    localFocus: p.localFocus,
    topicGroupId: topic?.groupId,
    brand: p.brand,
    city: p.city,
    role: p.role,
    strengths: p.strengths
  }).slice(0, XHS_TAG_MAX)
}

// ==================== 本地降级生成 ====================

/** emoji 前缀门控：不使用档位时前缀消失 */
export function emojiPrefix(useEmojis: boolean, emoji: string): string {
  return useEmojis ? emoji : ''
}

/** 按「多分行」开关选连接符 */
export function joinLines(lines: string[], multiLine: boolean): string {
  return lines.filter(Boolean).join(multiLine ? '\n\n' : '\n')
}

/** 本地降级：3 版（真实日常 / 专业信任 / 轻转化），模板刻意写成口语短句，避免 AI 腔排比 */
export function fallbackMomentsDrafts(input: MomentsInput): MomentsDraft[] {
  const p = input.persona
  const goal = findMomentGoal(input.goalId ?? input.goal)
  const inspiration = input.inspirationId
    ? findMomentsInspiration(input.inspirationId)
    : findMomentsInspirationByLabel(input.topic)
  const mode = findEmojiUsageMode(input.emojiUsageId ?? input.emojiLevel)
  const useEmojis = mode.id !== 'none'
  const topic = (input.topic || inspiration?.label || '今天的课堂').trim()
  const extra = (input.userInput || '').trim()
  const city = p.city || BRAND_CITY
  const brand = p.brand || BRAND_NAME
  const convertTail =
    goal.id === 'life'
      ? ''
      : `如果你也想调整现在的状态，可以先从一次适合自己的开始。${goal.actionInstruction}`

  const drafts: MomentsDraft[] = [
    {
      id: 'real',
      label: '真实日常版',
      content: joinLines(
        [
          `${emojiPrefix(useEmojis, '📌')}${topic}`,
          extra || '今天课上的一幕，随手记一下。',
          '有位学员做到第三个呼吸才找到发力，她自己先愣了一下，然后笑了。',
          '身体其实一直在，只是之前没被叫醒。慢一点也没关系。',
          `#${topic.replace(/\s/g, '')}`
        ],
        input.multiLine
      ),
      reply: '你今天练完是什么感觉？评论区说说，我看到都会回。',
      tags: ['#教练日常', '#训练记录', '#长期主义']
    },
    {
      id: 'pro',
      label: '专业信任版',
      content: joinLines(
        [
          `${emojiPrefix(useEmojis, '✅')}关于${topic}，说点实在的`,
          '很多人以为练得多才有效。对上班族来说，一周两到三次规律练习，比周末猛练一次管用。',
          '今天给一位久坐的学员调了呼吸顺序，她自己能说出「吸气到肋骨、呼气把肚子收住」了。',
          '动作质量上来之后，才谈得上强度和进阶。',
          '#训练频率比强度重要'
        ],
        input.multiLine
      ),
      reply: '想练对又怕练错的话，可以先来做一次评估，看清问题再安排。',
      tags: ['#专业训练', '#体态调整', '#普拉提']
    },
    {
      id: 'convert',
      label: '轻转化版',
      content: joinLines(
        [
          `${emojiPrefix(useEmojis, '💬')}${topic}这个事，很多人卡在第一步`,
          extra || '带过的学员里，最常见的问题不是不努力，是不知道自己该练什么。',
          `如果你肩颈紧、久坐、想改善体态，可以先来${city}的门店看看，评估完再决定要不要练。`,
          `我们在${brand}，不催你办卡，先帮你看清问题。`,
          convertTail ? convertTail : '#想了解可以直接找我'
        ],
        input.multiLine
      ),
      reply: `${city}的姐妹想聊，私信我就行，我给你说说适合先练什么。`,
      tags: ['#适合自己的训练', '#体验课', `#${city}`]
    }
  ]
  return drafts
}

/** 素材四级回落：补充要点 → 选题 → 选题目录标签 → 兜底 */
export function xhsSourceText(input: XhsInput): string {
  const byLabel = findXhsTopicByLabel(input.topic)
  return (
    input.points ||
    input.topic ||
    byLabel?.label ||
    findXhsTopic(input.topicId).label ||
    '瑜伽日常'
  ).trim()
}

/** 本地降级：2 篇（一篇偏干货方法、一篇偏场景/案例），与 LLM 路径篇数一致 */
export function fallbackXhsNotes(input: XhsInput): XhsNote[] {
  const p = input.profile
  const src = xhsSourceText(input)
  const ip = resolveXhsIpType(p)
  const topic = input.topicId ? findXhsTopic(input.topicId) : findXhsTopicByLabel(input.topic)
  const local = isXhsLocalScenario({
    ipType: ip,
    localFocus: p.localFocus,
    topicGroupId: topic?.groupId
  })
  const city = p.city || BRAND_CITY
  const brand = p.brand || BRAND_NAME
  const baseTags = ensureLocalTags(['#瑜伽', `#${topic?.label || '体态调整'}`, '#体态改善'], {
    ipType: ip,
    localFocus: p.localFocus,
    topicGroupId: topic?.groupId,
    brand,
    city,
    role: p.role,
    strengths: p.strengths
  })
  const convertText =
    p.conversion !== '不主动转化'
      ? '想来体验的同学，可以私信我约一节体验课。'
      : '有问题评论区聊，看到都会回。'

  const note1: XhsNote = {
    title: src.length > 12 ? `${src.slice(0, 12)}，这样练更稳` : `${src}，这样练更稳`,
    coverText: `${topic?.label || '体态调整'}｜${src.length > 10 ? src.slice(0, 10) : src}`,
    content: [
      `很多姐妹问${src}到底怎么开始，这篇一次说清楚。`,
      '',
      `先说结论：${src}不是练得越多越好，是顺序对了才有效。`,
      '',
      '① 先评估，再训练。看自己的体态和受限在哪，比直接跟练重要。',
      '② 频率大于强度。每周 2-3 次规律练习，胜过周末猛练一次。',
      '③ 先找发力感。呼吸和核心到位，动作质量自然就上来了。',
      '',
      convertText
    ].join('\n'),
    tags: baseTags,
    reply:
      p.conversion === '不主动转化'
        ? '有问题评论区聊，看到都会回～'
        : `${city}的姐妹想改善${topic?.label || src}，可以私信我约一次体态评估，先看问题再给方案。`
  }

  const note2: XhsNote = {
    title: '别急着练，先看懂这个点',
    coverText: '先看懂，再训练',
    content: [
      local
        ? `在${city}带课这些年，我发现${src}这件事，卡住大多数人的不是动作，是节奏。`
        : `带课这些年，我发现${src}这件事，卡住大多数人的不是动作，是节奏。`,
      '',
      '见过太多人一上来就加量，两周之后肩颈更紧、腰更酸，然后觉得自己不适合练。',
      '',
      `其实第一步是把身体“读”一遍：哪些关节不动、哪一侧代偿、呼吸停在哪儿。${local ? `在${brand}，这一步我们会用一次评估课做完。` : ''}`,
      '',
      '顺序对了，后面的练习才有意义。',
      '',
      convertText
    ].join('\n'),
    tags: baseTags,
    reply: local
      ? `在${city}想过来的，私信说一声，我看看最近哪个时段适合你。`
      : '想聊聊自己的情况，评论区或私信都可以，我一条条回。'
  }

  return [note1, note2]
}

// ==================== 对外入口 ====================

function auditGenerate(platform: string, topic: string, source: 'llm' | 'fallback') {
  useYimaiStore().writeAudit(
    '生成',
    '营销工具',
    0,
    `${platform} · ${topic || '自由创作'}`,
    '双店',
    `生成方式：${source === 'llm' ? '大模型API' : '本地模板（未配置AI或调用失败）'}；主题[${topic || '无'}]`
  )
}

/** 合并多条 warning，避免后一条覆盖前一条（图片超限等提示不能被吞掉） */
function appendWarning(prev: string | undefined, next: string): string {
  return prev ? `${prev}；${next}` : next
}

/** 兼容旧单条视图：优先挑与所选目标强度匹配的那一版 */
function pickLegacyDraft(drafts: MomentsDraft[], input: MomentsInput): MomentsDraft {
  const want = goalToDraftId(findMomentGoal(input.goalId ?? input.goal))
  return drafts.find((d) => d.id === want) ?? drafts[0]
}

/**
 * 本地模板草稿的**字数如实性说明**：本地模板不按档位生成（不强行凑字数，避免更差的文案），
 * 因此不能假装满足字数硬约束 —— 这里给出实测字数，让用户一眼看出「这是草稿」。
 */
function localTemplateNote(drafts: MomentsDraft[], input: MomentsInput): string {
  const len = resolveMomentLength(input)
  const chars = drafts.map((d) => Array.from(d.content.replace(/\s/g, '')).length)
  const detail = drafts.map((d, i) => `${d.label} ${chars[i]} 字`).join('、')
  const outside = chars.some((c) => c < len.min || c > len.max)
  return outside
    ? `本地模板草稿：字数不保证落在所选 ${len.min}-${len.max} 字区间（实测 ${detail}），请以此为草稿再编辑`
    : `本地模板草稿：实测 ${detail}（所选 ${len.min}-${len.max} 字），仍建议以此为草稿再编辑`
}

/** 小红书本地模板草稿的字数如实性说明（同一口径） */
function localXhsTemplateNote(notes: XhsNote[], input: XhsInput): string {
  const len = findXhsLength(input.lengthId)
  const chars = notes.map((n) => Array.from(n.content.replace(/\s/g, '')).length)
  return `本地模板草稿：正文字数不保证落在所选「${len.display}」区间（实测 ${chars.join('/')} 字），请以此为草稿再编辑`
}

export async function generateMomentsCopy(input: MomentsInput): Promise<MomentsResult> {
  const store = useAiConfigStore()
  const messages = buildMomentsMessages(input)
  const count = input.draftCount === 1 ? 1 : 3
  let drafts: MomentsDraft[] = []
  let source: 'llm' | 'fallback' = 'fallback'
  let warning: string | undefined
  if (input.imageCount && input.imageCount > MOMENTS_MAX_IMAGES) {
    warning = `最多支持 ${MOMENTS_MAX_IMAGES} 张图片，第 ${MOMENTS_MAX_IMAGES + 1} 张起未纳入本次生成`
  }
  if (store.isReady()) {
    try {
      const raw = await callLLM(messages, 'marketing_moments')
      drafts = parseMomentsDraftsLoose(raw, count)
      if (!drafts.length) throw new Error('JSON_PARSE_FAIL')
      source = 'llm'
      if (drafts.length < count) {
        const returned = drafts.length
        const fb = fallbackMomentsDrafts(input)
        for (const d of fb) {
          if (drafts.length >= count) break
          if (!drafts.some((x) => x.id === d.id)) drafts.push(d)
        }
        warning = appendWarning(
          warning,
          `模型只返回 ${returned} 条，其余已用本地模板补齐（本地模板不保证字数档）`
        )
      }
      const fbReply = fallbackMomentsDrafts(input)[0].reply
      drafts = drafts.map((d) => (d.reply ? d : { ...d, reply: fbReply }))
    } catch (e) {
      warning = appendWarning(
        warning,
        `大模型调用失败，已用本地模板生成（${String(e).slice(0, 80)}）`
      )
      drafts = fallbackMomentsDrafts(input)
      warning = appendWarning(warning, localTemplateNote(drafts, input))
    }
  } else {
    warning = appendWarning(
      warning,
      '尚未启用大模型，当前为本地模板草稿（超管可在「模型配置」接入API）'
    )
    drafts = fallbackMomentsDrafts(input)
    warning = appendWarning(warning, localTemplateNote(drafts, input))
  }
  const chars = drafts.reduce((n, d) => n + d.content.length + d.reply.length, 0)
  store.logUsage('朋友圈', source, chars)
  auditGenerate('朋友圈', input.topic, source)
  for (const d of drafts) {
    saveMarketingHistory({
      platform: '朋友圈',
      title: `${input.topic || d.content.slice(0, 20)}·${d.label}${input.antiFoldEnabled ? '（防折叠）' : ''}`,
      content: d.content,
      reply: d.reply,
      source
    })
  }
  if (USE_BACKEND && source === 'fallback') {
    apiPost('/model-generations/fallback', {
      featureType: 'marketing_moments',
      outputPreview: drafts
        .map((d) => d.content)
        .join('\n---\n')
        .slice(0, 1000),
      errorMessage: warning
    }).catch(() => undefined)
  }
  const legacy = pickLegacyDraft(drafts, input)
  return { drafts, source, warning, prompt: messages, content: legacy.content, reply: legacy.reply }
}

// ==================== 朋友圈防折叠改写（同文案换号发布，避免被折叠成相似内容） ====================

/** 质检阈值：与 buildAntiFoldMessages 的提示词规则一一对应 */
export const ANTI_FOLD_THRESHOLDS = {
  /** 首句必须完全不同（规则 1：首句是折叠检测的重点） */
  firstSentenceMustChange: true,
  /** 相同句占比上限（规则 2「至少60%的句子换一种说法重写」→ 反向 40%） */
  maxSameSentenceRatio: 0.4,
  /** 最长连续相同片段上限（不含）（规则 2「不得出现连续10个及以上相同的字」） */
  maxCommonSubstring: 10,
  /** 篇幅偏差上限（规则 5「±20%以内」，上下限同时受约束） */
  maxLengthDeviation: 0.2
} as const

/**
 * 防折叠质检的输入上限（字）：LCS 为 O(n·m)，10k 字单次 ≈600ms、5 版 × 10k ≈12s。
 * 超限时的行为是**显式**的：只校验前 N 字，并把「文本过长 / 仅校验前 N 字 / 请分段改写」
 * 作为可见结论写进 `warnings`（`passed` 一并置 false），**不静默降级成近似检测**。
 */
export const ANTI_FOLD_MAX_AUDIT_CHARS = 2000

/** 按上限截取待校验文本；truncated 由调用方转成可见结论 */
function capForAudit(text: string): { value: string; truncated: boolean } {
  return text.length > ANTI_FOLD_MAX_AUDIT_CHARS
    ? { value: text.slice(0, ANTI_FOLD_MAX_AUDIT_CHARS), truncated: true }
    : { value: text, truncated: false }
}

export const ANTI_FOLD_DEFAULT_VERSIONS = 3
export const ANTI_FOLD_MAX_VERSIONS = 5

export type AntiFoldCheckLabel =
  | '首句是否更换'
  | '相同句占比'
  | '最长连续相同片段'
  | '篇幅偏差'
  | '版本间差异'

export interface AntiFoldCheck {
  label: AntiFoldCheckLabel
  passed: boolean
  status: '通过' | '不通过'
  detail: string
  /** 实测值（比例项为百分数整数，如 32 表示 32%） */
  value?: number
  /** 阈值（同上量纲），UI 可显示「当前 32 / 上限 40」 */
  threshold?: number
}

export interface AntiFoldVariant {
  /** 稳定 id，如 'v1'；同时作为 UI key */
  id: string
  label: string
  content: string
  /** 模型自报的改写说明 */
  notes: string
  checks: AntiFoldCheck[]
  /** checks 全通过 */
  passed: boolean
  warnings: string[]
}

export interface AntiFoldResult {
  variants: AntiFoldVariant[]
  source: 'llm'
  warning?: string
  /** 本次实际提交给模型的提示词，只读展示 */
  prompt: PromptLayer[]
  /**
   * @deprecated 兼容字段（保留期，勿新增消费）：`variants[0]` 的同义投影（失败时为空串）。
   * - **无生产消费者**：t3 视图已改用 `variants[]`（逐版 checks）；视图/store 无结果级旧字段读取。
   * - **仅为旧视图保留**，且旧视图依赖「空串 ⇒ 改写失败」的判定语义（勿改成 undefined）。
   * - **退役条件（三条同时满足才可删）**：① 冒烟门禁同步移除对应断言（属 t10）；② 全仓 grep 无消费点；
   *   ③ 历经一个发布周期确认无历史草稿依赖。删除需先报 Captain（跨 owner 契约）。
   */
  content: string
  /** @deprecated 同 `content`：保留期字段，无生产消费者，退役条件见上 */
  notes: string
}

export interface AntiFoldInput {
  /** 会被折叠的原文案 */
  content: string
  /** 可选补充要求（如：语气更口语一点 / 突出限时福利） */
  extra?: string
  /** 一次生成的版本数，夹逼到 [1, ANTI_FOLD_MAX_VERSIONS]，默认 3 */
  versions?: number
}

// ---------- 质检纯函数（无网络、无随机、输入输出确定） ----------

/** 最长连续相同片段长度（LCS DP，O(n·m)） */
export function longestCommonSubstring(a: string, b: string): number {
  const s1 = String(a ?? '')
  const s2 = String(b ?? '')
  if (!s1 || !s2) return 0
  let prev = new Array<number>(s2.length + 1).fill(0)
  let best = 0
  for (let i = 1; i <= s1.length; i++) {
    const cur = new Array<number>(s2.length + 1).fill(0)
    for (let j = 1; j <= s2.length; j++) {
      if (s1[i - 1] === s2[j - 1]) {
        cur[j] = prev[j - 1] + 1
        if (cur[j] > best) best = cur[j]
      }
    }
    prev = cur
  }
  return best
}

/** 中文句子切分：按句末标点与换行切分并去空 */
export function splitSentences(text: string): string[] {
  return String(text ?? '')
    .split(/[。！？!?；;\n]+/)
    .map((s) => s.trim())
    .filter(Boolean)
}

/** 句子归一化：去 emoji → 全角转半角 → 去空白 → 去标点 → 小写 */
export function normalizeSentence(s: string): string {
  let t = String(s ?? '')
  try {
    t = t.replace(/\p{Extended_Pictographic}/gu, '')
  } catch {
    /* 环境不支持 Unicode 属性转义时跳过 emoji 剥离 */
  }
  return t
    .replace(/[\uFF01-\uFF5E]/g, (ch) => String.fromCharCode(ch.charCodeAt(0) - 0xfee0))
    .replace(/\u3000/g, ' ')
    .replace(/[\s]+/g, '')
    .replace(/[，。！？、；：""''《》【】（）,.!?;:"'()[\]{}<>~`|/\\@#$%^&*+=_-]/g, '')
    .toLowerCase()
}

/** 取第一个非空句（首句是折叠检测的重点） */
export function firstSentence(text: string): string {
  const list = splitSentences(text)
  return list[0] ?? String(text ?? '').trim()
}

/**
 * emoji 语义的**字素簇**判定式（懒编译 + 缓存）：
 * 图形字符 ∨ 区域指示符（旗帜的一半）∨ keycap 组合符 U+20E3。
 * 用 `new RegExp` 而非字面量，便于在不支持 unicode-property 的环境里失败并可捕获。
 */
let emojiClusterPattern: RegExp | null | undefined
function emojiClusterRegex(): RegExp | null {
  if (emojiClusterPattern === undefined) {
    try {
      emojiClusterPattern = new RegExp(
        '(?:\\p{Extended_Pictographic})|(?:\\p{Regional_Indicator})|\\u20E3',
        'u'
      )
    } catch {
      emojiClusterPattern = null
    }
  }
  return emojiClusterPattern
}

/**
 * emoji 计数：按**字素簇**计数，覆盖三类此前会漏数（→「不使用」档假绿）的形态：
 * ① 旗帜：两个 Regional_Indicator 组成的簇（🇨🇳 记 1 个，不是按码位 0 个）；
 * ② keycap 组合：`1️⃣`/`#️⃣`（数字 + U+FE0F + U+20E3，记 1 个）；
 * ③ ZWJ 组合：`👨‍👩‍👧`（3 个图形码位 + 2 个 ZWJ，记 1 个，不是 3 个）。
 * 优先用 Intl.Segmenter 按字素簇切分（现代运行时均有完整 ICU）；
 * 不可用时回退到码位估算，并对 ZWJ 组合做「图形码位数 − ZWJ 数」的合并，避免多计。
 *
 * 【口径取舍（t14 R2 明确化，**勿再当 bug 来回改**）】
 * 判定基于 `Extended_Pictographic` 全集，因此 ©️ / ®️ / ™️ 这类「文本默认呈现」的符号**也计入**。
 * 实测：`'©️®️™️✅⭐' = 5`、`'©®™' = 3`（不带 VS16 也计）、`'❤️' = 1`、`'⚠️' = 1`。
 * 这是**有意取舍**：在「不使用表情符号」这类合规检查里，「多算一个」是用户可见的假红（改一下即可），
 * 而「少算」是静默假绿（真实表情被放过）—— 假绿才是底线问题，故宁可偏严。
 * 反面：若改用仅 `Emoji_Presentation` 的口径，则 ❤️ / ⚠️ 这类文本默认呈现、却常被当 emoji 使用的符号会变成 0
 * （实测），恰好制造假绿，因此**不采用**该口径。harness 里已用断言钉住这些数值。
 */
export function countEmoji(text: string): number {
  const s = String(text ?? '')
  if (!s) return 0
  const pattern = emojiClusterRegex()
  if (pattern) {
    try {
      const segmenter = new Intl.Segmenter(undefined, { granularity: 'grapheme' })
      let n = 0
      for (const { segment } of segmenter.segment(s)) {
        if (pattern.test(segment)) n++
      }
      return n
    } catch {
      /* 无 Intl.Segmenter → 走码位回退 */
    }
    try {
      const pictographic = (s.match(/\p{Extended_Pictographic}/gu) ?? []).length
      const zwj = (s.match(/\u200D/gu) ?? []).length
      const flags = Math.floor((s.match(/\p{Regional_Indicator}/gu) ?? []).length / 2)
      const keycaps = (s.match(/\u20E3/gu) ?? []).length
      return Math.max(0, pictographic - zwj) + flags + keycaps
    } catch {
      /* 落到最保守的码点区间回退 */
    }
  }
  return (
    s.match(
      // 用「择一」而非字符类包住 keycap 组合符（U+20E3 是组合记号，
      // 放进字符类会被 no-misleading-character-class 判为易误读，语义上也确实不是"一个普通字符"）
      /(?:[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{1F1E6}-\u{1F1FF}])|\u{20E3}/gu
    ) ?? []
  ).length
}

/**
 * 期望 emoji 个数区间：字数 c、每 N 字 1 个 → { min: max(1, floor(c/N)), max: max(1, ceil(c/N)) }。
 * 不使用档（charsPerSymbol === 0）与空文本短路返回 {0,0}。
 */
export function expectedEmojiRange(
  charCount: number,
  charsPerSymbol: number
): { min: number; max: number } {
  const c = Number(charCount) || 0
  const n = Number(charsPerSymbol) || 0
  if (!n || c <= 0) return { min: 0, max: 0 }
  return { min: Math.max(1, Math.floor(c / n)), max: Math.max(1, Math.ceil(c / n)) }
}

/** 防折叠质检（四项）：首句是否更换 / 相同句占比 / 最长连续相同片段 / 篇幅偏差 */
export function auditAntiFold(
  source: string,
  output: string
): { checks: AntiFoldCheck[]; passed: boolean; warnings: string[] } {
  const src = String(source ?? '').trim()
  const out = String(output ?? '').trim()
  const labels: AntiFoldCheckLabel[] = [
    '首句是否更换',
    '相同句占比',
    '最长连续相同片段',
    '篇幅偏差'
  ]
  if (!src || !out) {
    return {
      checks: labels.map((label) => ({
        label,
        passed: false,
        status: '不通过',
        detail: '改写结果为空'
      })),
      passed: false,
      warnings: ['改写结果为空']
    }
  }
  const checks: AntiFoldCheck[] = []

  // 超长输入的显式降级：内容类检查只看前 N 字；篇幅类用全长（O(1)，不受截断影响）
  const cappedSrc = capForAudit(src)
  const cappedOut = capForAudit(out)
  const tooLong = cappedSrc.truncated || cappedOut.truncated
  const srcC = cappedSrc.value
  const outC = cappedOut.value
  const capNote = `（文本超过 ${ANTI_FOLD_MAX_AUDIT_CHARS} 字，仅校验前 ${ANTI_FOLD_MAX_AUDIT_CHARS} 字）`

  const fs = firstSentence(srcC)
  const fo = firstSentence(outC)
  const firstOk = normalizeSentence(fs) !== normalizeSentence(fo)
  checks.push({
    label: '首句是否更换',
    passed: firstOk,
    status: firstOk ? '通过' : '不通过',
    detail: firstOk ? '首句已更换' : `首句未更换：「${fs.slice(0, 12)}…」`
  })

  const srcSet = new Set(splitSentences(srcC).map(normalizeSentence))
  const outSentenceList = splitSentences(outC)
  const outs = outSentenceList.length ? outSentenceList : [outC]
  const sameSentences = outs.filter((s) => srcSet.has(normalizeSentence(s)))
  const ratio = sameSentences.length / outs.length
  const ratioOk = ratio <= ANTI_FOLD_THRESHOLDS.maxSameSentenceRatio
  checks.push({
    label: '相同句占比',
    passed: ratioOk,
    status: ratioOk ? '通过' : '不通过',
    detail: ratioOk
      ? `相同句占比 ${Math.round(ratio * 100)}%（上限 40%）`
      : `相同句占比 ${Math.round(ratio * 100)}%（上限 40%）：${(sameSentences[0] ?? '').slice(0, 12)}…`,
    value: Math.round(ratio * 100),
    threshold: Math.round(ANTI_FOLD_THRESHOLDS.maxSameSentenceRatio * 100)
  })

  const common = longestCommonSubstring(srcC, outC)
  const commonOk = common < ANTI_FOLD_THRESHOLDS.maxCommonSubstring
  checks.push({
    label: '最长连续相同片段',
    passed: commonOk,
    status: commonOk ? '通过' : '不通过',
    detail:
      `与原文最长相同片段 ${common} 字（上限 ${ANTI_FOLD_THRESHOLDS.maxCommonSubstring} 字）` +
      (tooLong ? capNote : ''),
    value: common,
    threshold: ANTI_FOLD_THRESHOLDS.maxCommonSubstring
  })

  const deviation = Math.abs(out.length - src.length) / Math.max(src.length, 1)
  const devOk = deviation <= ANTI_FOLD_THRESHOLDS.maxLengthDeviation
  checks.push({
    label: '篇幅偏差',
    passed: devOk,
    status: devOk ? '通过' : '不通过',
    detail: `篇幅 ${out.length} 字 / 原文 ${src.length} 字，偏差 ${Math.round(deviation * 100)}%（上限 20%）`,
    value: Math.round(deviation * 100),
    threshold: Math.round(ANTI_FOLD_THRESHOLDS.maxLengthDeviation * 100)
  })

  const warnings = checks.filter((c) => !c.passed).map((c) => `[${c.label}] ${c.detail}`)
  if (tooLong) {
    // 可见结论：超长时**不能**宣称"已通过质检"，明确告知只校验了前 N 字
    warnings.unshift(
      `[文本过长] 原文 ${src.length} 字 / 改写 ${out.length} 字，超过精确校验上限 ${ANTI_FOLD_MAX_AUDIT_CHARS} 字；` +
        `仅校验前 ${ANTI_FOLD_MAX_AUDIT_CHARS} 字，请分段改写后再校验`
    )
  }
  return { checks, passed: warnings.length === 0, warnings }
}

/** 版本间差异质检：任一版本与其他版本的最长相同片段必须小于阈值 */
export function auditVariantDiversity(contents: string[]): AntiFoldCheck[] {
  const list = contents.map((c) => String(c ?? ''))
  // 超长按上限比较（每对 LCS 为 O(n·m)）；截断时**不得判通过**，与 auditAntiFold 的截断处理对称
  const capped = list.map((c) => capForAudit(c))
  const anyTruncated = capped.some((c) => c.truncated)
  const limit = ANTI_FOLD_THRESHOLDS.maxCommonSubstring
  return capped.map((cappedItem, i) => {
    let worst = 0
    for (let j = 0; j < capped.length; j++) {
      if (j === i) continue
      worst = Math.max(worst, longestCommonSubstring(cappedItem.value, capped[j].value))
    }
    // t14 R1：文本被截断 ⇒ 比较不完备 ⇒ 即使前缀内数值达标也不得判通过。
    // 反例（复核给出）：两篇 2218 字、共享 18 字片段落在第 2000 字之后 —— 前缀 LCS=0（看似达标），
    // 全长真实 LCS=18（≥ 阈值，真值应为不通过）；旧实现只按前缀判 passed 就把它静默放过了。
    const passed = worst < limit && !anyTruncated
    return {
      label: '版本间差异' as AntiFoldCheckLabel,
      passed,
      status: passed ? '通过' : '不通过',
      detail: anyTruncated
        ? `文本超过 ${ANTI_FOLD_MAX_AUDIT_CHARS} 字，仅比较前 ${ANTI_FOLD_MAX_AUDIT_CHARS} 字，` +
          `无法据此判定通过（请分段改写后再比较）；前缀内最长相同片段 ${worst} 字（上限 ${limit} 字）`
        : passed
          ? '与其他版本无长片段重复'
          : `与其他版本最长相同片段 ${worst} 字（上限 ${limit} 字）`,
      value: worst,
      threshold: limit
    }
  })
}

/** 找出在两个及以上版本里同时出现的 10 字片段（用于定向修复；去重、最多 8 条） */
export function sharedFragments(
  contents: string[],
  size: number = ANTI_FOLD_THRESHOLDS.maxCommonSubstring
): string[] {
  // 与质检同口径：超长文本只在同一上限内找重复片段（避免 O(n·m) 退化）
  const list = contents.map((c) => capForAudit(String(c ?? '')).value)
  const out: string[] = []
  for (let i = 0; i < list.length; i++) {
    const a = list[i]
    for (let p = 0; p + size <= a.length; p++) {
      const frag = a.slice(p, p + size)
      if (out.includes(frag)) continue
      if (list.some((b, j) => j !== i && b.includes(frag))) out.push(frag)
      if (out.length >= 8) return out
    }
  }
  return out
}

// ---------- 提示词 ----------

/** 版本数夹逼 */
export function clampVersions(v?: number): number {
  const n = Math.floor(Number(v) || ANTI_FOLD_DEFAULT_VERSIONS)
  return Math.min(Math.max(n, 1), ANTI_FOLD_MAX_VERSIONS)
}

function buildAntiFoldMessages(input: AntiFoldInput, count: number): PromptLayer[] {
  const system = [
    '你是朋友圈防折叠改写专家。同一条朋友圈文案在多个微信号重复发布时，微信会因内容高度相似把后续发布折叠成一行。你的任务：基于原文产出「骨子里一样、皮肤不同」的防折叠变体——意思不变、情绪气质不变、像同一个人写的，但文字表述差异足够大，绕过微信的相似内容检测。',
    '改写硬性规则：',
    '1. 开头句必须完全不同（首句是折叠检测的重点）。',
    '2. 至少60%的句子换一种说法重写；任何位置不得出现连续10个及以上相同的字。',
    '3. 核心信息完整保留：品牌/地点/价格/时间/联系方式（电话、微信号、链接、小程序口令）等原样保留，不增不减不改。',
    '4. 情绪、人设、口吻保持一致：不换风格、不升华主题、不增删关键卖点。',
    '5. 篇幅与原文相当（±20%以内），保留原文的分行排版风格。',
    '6. emoji可保留但调整位置，或替换为含义相近的emoji，密度与原文接近。',
    '7. 话题标签（#xx）可保留，但标签前后衔接的文字必须改写。',
    '8. 语气词与口头禅必须更换（如「哈哈哈」→「笑死」）；引号统一使用「」；不使用markdown。',
    `生成数量：一次生成 ${count} 个版本，分别用不同的改写策略（换开头 / 换句式 / 换切入角度）；${count} 个版本之间也必须互不相同，任意两个版本之间不得出现连续10个及以上相同的字。`,
    '严格输出 JSON（不要代码块、不要多余解释）：{"variants":[{"notes": string, "content": string}]}。',
    `content 为改写后的完整朋友圈正文；notes 为2句话以内的改写说明（做了哪些类型的改动）。共需 ${count} 个版本。`
  ].join('\n')

  const user = [
    `【原文】\n${input.content.trim()}`,
    input.extra?.trim() && `【补充要求】${input.extra.trim()}`
  ]
    .filter(Boolean)
    .join('\n\n')

  return [
    { role: 'system', content: system },
    { role: 'user', content: user }
  ]
}

/** 版本间重复片段的定向修复提示词（仅当版间差异不达标时追加一轮） */
function buildAntiFoldRepairMessages(
  contents: string[],
  fragments: string[],
  extra?: string
): PromptLayer[] {
  const system = [
    '你是朋友圈防折叠改写专家。下面给出的多个版本之间存在大段相同片段，微信会把它们判为相似内容并折叠。',
    '任务：只改写被点名重复的片段及其所在句子，保持每版的原意、气质和核心信息不变，版本之间必须互不相同。',
    `硬性要求：任意两个版本之间不得出现连续${ANTI_FOLD_THRESHOLDS.maxCommonSubstring}个及以上相同的字；品牌/地点/价格/时间/联系方式原样保留。`,
    `严格输出 JSON（不要代码块、不要多余解释）：{"variants":[{"notes":string,"content":string}]}，共 ${contents.length} 个版本，顺序与输入一致。`
  ].join('\n')
  const user = [
    ...contents.map((c, i) => `【版本${i + 1}】\n${c}`),
    `【必须改写的重复片段】\n${fragments.map((f) => `- ${f}`).join('\n')}`,
    extra?.trim() && `【补充要求】${extra.trim()}`
  ]
    .filter(Boolean)
    .join('\n\n')
  return [
    { role: 'system', content: system },
    { role: 'user', content: user }
  ]
}

/** 防折叠 JSON 解析（数组优先 → 单对象回落 → 纯文本回落） */
function parseAntiFoldVariantsLoose(
  text: string,
  count: number
): { content: string; notes: string }[] {
  const raw = String(text ?? '')
    .trim()
    .replace(/^["“]|["”]$/g, '')
  const obj = looseJson(raw)
  let list: unknown[] | null = null
  if (obj) {
    if (Array.isArray(obj.variants)) list = obj.variants
    else if (Array.isArray(obj.drafts)) list = obj.drafts
    else if (obj.content) list = [obj]
  }
  const out = (list ?? [])
    .map((item) => {
      const o = (item ?? {}) as Record<string, unknown>
      const content = String(o.content ?? o.text ?? '').trim()
      if (!content) return null
      return { content, notes: String(o.notes ?? o.note ?? '').trim() }
    })
    .filter((v): v is { content: string; notes: string } => !!v)
    .slice(0, Math.max(1, count))
  if (out.length) return out
  const plain = raw.trim()
  return plain ? [{ content: plain, notes: '' }] : []
}

/** 单次生成的版本数差评：版本间差异不通过的版本数 */
function diversityFailCount(variants: AntiFoldVariant[]): number {
  return variants.filter((v) => v.checks.some((c) => c.label === '版本间差异' && !c.passed)).length
}

/**
 * 朋友圈防折叠改写：仅支持大模型（本地模板无法做语义级改写）。
 * 未接入 AI 或调用失败时返回空 variants + warning，由调用方提示，不伪造结果。
 */
export async function generateAntiFoldCopy(input: AntiFoldInput): Promise<AntiFoldResult> {
  const store = useAiConfigStore()
  const count = clampVersions(input.versions)
  const messages = buildAntiFoldMessages(input, count)
  const empty = (warning: string): AntiFoldResult => ({
    variants: [],
    source: 'llm',
    warning,
    prompt: messages,
    content: '',
    notes: ''
  })
  if (!store.isReady()) {
    return empty('防折叠改写需要大模型支持，超管可在「模型配置」接入API后使用')
  }

  const assess = (parsed: { content: string; notes: string }[]): AntiFoldVariant[] => {
    const diversity = auditVariantDiversity(parsed.map((p) => p.content))
    return parsed.map((p, i) => {
      const report = auditAntiFold(input.content, p.content)
      const checks = parsed.length >= 2 ? [...report.checks, diversity[i]] : report.checks
      const failLines = checks.filter((c) => !c.passed).map((c) => `[${c.label}] ${c.detail}`)
      // auditAntiFold 的 warnings 里除失败项外还可能有「文本过长」这类显式说明，去重后一并保留
      const extraNotes = report.warnings.filter((w) => !failLines.includes(w))
      const warnings = [...failLines, ...extraNotes]
      return {
        id: `v${i + 1}`,
        label: `版本 ${i + 1}`,
        content: p.content,
        notes: p.notes,
        checks,
        passed: warnings.length === 0,
        warnings
      }
    })
  }

  try {
    const raw = await callLLM(messages, 'marketing_anti_fold')
    const parsed = parseAntiFoldVariantsLoose(raw, count)
    if (!parsed.length) throw new Error('LLM_EMPTY_RESPONSE')
    let variants = assess(parsed)
    let warning: string | undefined
    if (variants.length < count) warning = `期望 ${count} 个版本，模型返回 ${variants.length} 个`
    // 原文超长时给出结果级可见提示（质检按上限只覆盖前 N 字，不静默）
    if (input.content.trim().length > ANTI_FOLD_MAX_AUDIT_CHARS) {
      warning = appendWarning(
        warning,
        `原文超过 ${ANTI_FOLD_MAX_AUDIT_CHARS} 字，质检仅覆盖前 ${ANTI_FOLD_MAX_AUDIT_CHARS} 字，建议分段改写`
      )
    }

    // 版间差异不达标 → 定向修复一轮（最多一次，避免成本失控；失败则保留首轮并如实报不通过）
    if (variants.length >= 2 && diversityFailCount(variants) > 0) {
      const fragments = sharedFragments(variants.map((v) => v.content))
      if (fragments.length) {
        try {
          const repairRaw = await callLLM(
            buildAntiFoldRepairMessages(
              variants.map((v) => v.content),
              fragments,
              input.extra
            ),
            'marketing_anti_fold'
          )
          const repaired = assess(parseAntiFoldVariantsLoose(repairRaw, count))
          if (repaired.length && diversityFailCount(repaired) < diversityFailCount(variants)) {
            variants = repaired
          }
        } catch {
          /* 修复轮失败：保留首轮结果，warnings 已如实标注不通过项 */
        }
      }
    }

    const totalChars = variants.reduce((n, v) => n + v.content.length, 0)
    store.logUsage('朋友圈防折叠', 'llm', totalChars)
    auditGenerate('朋友圈防折叠', input.content.slice(0, 16), 'llm')
    variants.forEach((v) => {
      saveMarketingHistory({
        platform: '朋友圈',
        title: `防折叠改写·${v.label}`,
        content: v.content,
        source: 'llm'
      })
    })
    return {
      variants,
      source: 'llm',
      warning,
      prompt: messages,
      content: variants[0]?.content ?? '',
      notes: variants[0]?.notes ?? ''
    }
  } catch (e) {
    const warning = `大模型调用失败（${String(e).slice(0, 80)}）`
    if (USE_BACKEND) {
      apiPost('/model-generations/fallback', {
        featureType: 'marketing_anti_fold',
        outputPreview: '',
        errorMessage: warning
      }).catch(() => undefined)
    }
    return empty(warning)
  }
}

export async function generateXhsNote(input: XhsInput): Promise<XhsResult> {
  const store = useAiConfigStore()
  const messages = buildXhsMessages(input)
  let notes: XhsNote[] = []
  let source: 'llm' | 'fallback' = 'fallback'
  let warning: string | undefined
  if (store.isReady()) {
    try {
      const raw = await callLLM(messages, 'marketing_xhs')
      notes = parseXhsNotesLoose(raw)
      if (!notes.length) throw new Error('JSON_PARSE_FAIL')
      source = 'llm'
      const fb = fallbackXhsNotes(input)
      let truncatedTags = 0
      notes = notes.map((n) => {
        if (n.tags.length > XHS_TAG_MAX) truncatedTags++
        return {
          ...n,
          tags: normalizeXhsTags(n.tags, input),
          reply: n.reply || fb[0].reply
        }
      })
      if (truncatedTags) {
        warning = appendWarning(
          warning,
          `${truncatedTags} 篇笔记标签超过 ${XHS_TAG_MAX} 个，已按上限截断`
        )
      }
      if (notes.length < XHS_NOTE_COUNT) {
        const returned = notes.length
        for (const n of fb) {
          if (notes.length >= XHS_NOTE_COUNT) break
          if (!notes.some((x) => x.title === n.title)) notes.push(n)
        }
        warning = appendWarning(
          warning,
          `期望 ${XHS_NOTE_COUNT} 篇，模型返回 ${returned} 篇，其余已用本地模板补齐（本地模板不保证字数档）`
        )
      }
    } catch (e) {
      warning = appendWarning(
        warning,
        `大模型调用失败，已用本地模板生成（${String(e).slice(0, 80)}）`
      )
      notes = fallbackXhsNotes(input)
      warning = appendWarning(warning, localXhsTemplateNote(notes, input))
    }
  } else {
    warning = appendWarning(
      warning,
      '尚未启用大模型，当前为本地模板草稿（超管可在「模型配置」接入API）'
    )
    notes = fallbackXhsNotes(input)
    warning = appendWarning(warning, localXhsTemplateNote(notes, input))
  }
  if (!notes.length) notes = fallbackXhsNotes(input)
  const first = notes[0]
  store.logUsage(
    '小红书',
    source,
    notes.reduce((n, x) => n + x.content.length, 0)
  )
  auditGenerate('小红书', input.topic, source)
  notes.forEach((n, i) => {
    saveMarketingHistory({
      platform: '小红书',
      title: `${n.title || input.topic || n.content.slice(0, 20)}·版本${i + 1}`,
      content: [
        `【封面文案】${n.coverText}`,
        '',
        n.title,
        '',
        n.content,
        '',
        n.tags.join(' ')
      ].join('\n'),
      reply: n.reply,
      source
    })
  })
  if (USE_BACKEND && source === 'fallback') {
    apiPost('/model-generations/fallback', {
      featureType: 'marketing_xhs',
      outputPreview: `${first.title}\n${first.content}`.slice(0, 1000),
      errorMessage: warning
    }).catch(() => undefined)
  }
  return {
    notes,
    source,
    warning,
    prompt: messages,
    title: first.title,
    content: first.content,
    tags: first.tags,
    reply: first.reply
  }
}

// ==================== 训练计划（报告 9.6-9.7） ====================

export interface TrainingInput {
  memberName: string
  age: string
  gender: string
  height: string
  weight: string
  bodyFat: string
  focus: string
  coreGoal: string
  freq: string
  stageWeeks: string
  stageGoal: string
  risks: string
}

export const HIGH_RISK_KEYWORDS = [
  '孕',
  '术后',
  '疼痛',
  '椎间盘',
  '高血压',
  '心脏病',
  '糖尿病',
  '眩晕',
  '骨折'
]

export function detectHighRisk(risks: string): boolean {
  return HIGH_RISK_KEYWORDS.some((k) => (risks || '').includes(k))
}

function buildTrainingMessages(input: TrainingInput): ChatMessage[] {
  const system = [
    '你是瑜伽普拉提馆的资深教练助手，为教练生成训练计划草稿。',
    '硬性边界：不输出医疗诊断、疾病治疗、术后处方或疗效承诺；涉及疼痛、孕产、慢病等内容时在注意事项中提示转介医疗专业人员。',
    '严格输出 JSON：{"summary": string, "phases": [{"name": string, "duration": string, "items": string[]}], "cautions": string[]}。',
    'phases 数量与阶段周期匹配；items 为该阶段具体练习要点（每条一句话内）；cautions 为注意事项与风险提示。'
  ].join('\n')

  const user = [
    `会员：${input.memberName}，${input.gender}，${input.age}岁`,
    input.height ? `身高${input.height}cm` : '',
    input.weight ? `体重${input.weight}kg` : '',
    input.bodyFat ? `体脂率${input.bodyFat}%` : '',
    input.focus ? `关注要点：${input.focus}` : '',
    `核心目标：${input.coreGoal}`,
    `训练频率：${input.freq}`,
    `第一阶段周期：${input.stageWeeks}周`,
    `阶段目标：${input.stageGoal}`,
    `当前风险：${input.risks || '无特殊风险'}`
  ]
    .filter(Boolean)
    .join('；')

  return [
    { role: 'system', content: system },
    { role: 'user', content: user }
  ]
}

interface PlanContentLike {
  summary: string
  phases: { name: string; duration: string; items: string[] }[]
  cautions: string[]
}

function fallbackTraining(input: TrainingInput): PlanContentLike {
  const weeks = Number(input.stageWeeks) || 4
  const seg = Math.max(1, Math.round(weeks / 3))
  const tail = Math.max(1, weeks - seg * 2)
  const focus0 = input.focus ? input.focus.split(/[、，,]/)[0] : ''
  return {
    summary: `以「${input.coreGoal}」为核心的${weeks}周计划：先建立呼吸与中立位感知，再逐步加入力量与整合性练习，配合${input.freq}执行。`,
    phases: [
      {
        name: '第1段 基础建立',
        duration: `${seg}周`,
        items: ['呼吸模式重建', '中立位与核心感知', '基础体式规范']
      },
      {
        name: '第2段 能力提升',
        duration: `${seg}周`,
        items: [
          focus0 ? `${focus0}针对性练习` : '目标部位强化',
          '动作串联与流畅度',
          '小负荷力量导入'
        ]
      },
      {
        name: '第3段 目标整合',
        duration: `${tail}周`,
        items: [
          (input.stageGoal || '阶段目标') + '拆解练习',
          '完整课堂流程演练',
          '复评与下一阶段建议'
        ]
      }
    ],
    cautions: [
      '练习中出现疼痛、麻木或眩晕应立即停止并反馈教练',
      input.risks && input.risks !== '无特殊风险' && input.risks
        ? `存在「${input.risks}」情况，请先经医疗专业人员评估后再执行本计划`
        : '如有不适请及时与教练沟通调整'
    ]
  }
}

export async function generateTrainingPlan(
  input: TrainingInput
): Promise<{ content: PlanContentLike; source: 'llm' | 'fallback'; warning?: string }> {
  const store = useAiConfigStore()
  let content: PlanContentLike
  let source: 'llm' | 'fallback' = 'fallback'
  let warning: string | undefined

  if (store.isReady()) {
    try {
      const raw = await callLLM(buildTrainingMessages(input), 'training_plan')
      const m = raw.match(/\{[\s\S]*\}/)
      if (!m) throw new Error('JSON_PARSE_FAIL')
      const obj = JSON.parse(m[0])
      if (!obj.summary || !Array.isArray(obj.phases)) throw new Error('JSON_SHAPE_FAIL')
      content = {
        summary: String(obj.summary),
        phases: obj.phases.slice(0, 6).map((p: Record<string, unknown>) => ({
          name: String(p.name ?? ''),
          duration: String(p.duration ?? ''),
          items: Array.isArray(p.items) ? p.items.map(String).slice(0, 8) : []
        })),
        cautions: Array.isArray(obj.cautions) ? obj.cautions.map(String).slice(0, 8) : []
      }
      source = 'llm'
    } catch (e) {
      warning = `大模型调用失败，已用本地模板生成（${String(e).slice(0, 80)}）`
      content = fallbackTraining(input)
    }
  } else {
    warning = '尚未启用大模型，当前为本地模板草稿（超管可在「模型配置」接入API）'
    content = fallbackTraining(input)
  }

  useYimaiStore().writeAudit(
    '生成',
    '训练计划',
    0,
    `训练计划 · ${input.memberName}`,
    '双店',
    `生成方式：${source === 'llm' ? '大模型API' : '本地模板'}；目标[${input.coreGoal}]`
  )
  if (USE_BACKEND && source === 'fallback') {
    apiPost('/model-generations/fallback', {
      featureType: 'training_plan',
      outputPreview: content.summary.slice(0, 1000),
      errorMessage: warning
    }).catch(() => undefined)
  }
  return { content, source, warning }
}

// ==================== 生成后质检（前端选择 vs 实际提示词 vs 成稿） ====================
// 把提示词的「软约束」变成「可验证硬约束」：先断言选项真的进了提示词，再逐条核对成稿。

export interface PromptCheck {
  label: string
  passed: boolean
  status: '通过' | '不匹配'
  detail: string
}

export type DraftCheck = PromptCheck

export interface MomentsMatchReport {
  title: '测试与前端的匹配度'
  passed: boolean
  statusText: string
  summary: string
  promptChecks: PromptCheck[]
  draftChecks: DraftCheck[]
  issues: string[]
  issuePreview: string
}

export type XhsMatchReport = MomentsMatchReport

function mkCheck(label: string, passed: boolean, detail: string): PromptCheck {
  return { label, passed, status: passed ? '通过' : '不匹配', detail }
}

function buildMatchReport(
  promptChecks: PromptCheck[],
  draftChecks: DraftCheck[],
  chars: number
): MomentsMatchReport {
  const issues = [...promptChecks, ...draftChecks]
    .filter((c) => !c.passed)
    .map((c) => `[${c.label}] ${c.detail}`)
  const passed = issues.length === 0
  return {
    title: '测试与前端的匹配度',
    passed,
    statusText: passed ? '全部匹配' : `发现 ${issues.length} 项不匹配`,
    summary: `提示词 ${promptChecks.length} 项 / 成稿 ${draftChecks.length} 项；正文合计 ${chars} 字`,
    promptChecks,
    draftChecks,
    issues,
    issuePreview: issues.slice(0, 4).join('\n')
  }
}

/** 朋友圈生成后自检：12 项提示词断言 + 每条成稿 5-6 项硬约束核对 */
export function auditMomentsResult(
  input: MomentsInput,
  prompt: string,
  drafts: MomentsDraft[]
): MomentsMatchReport {
  const text = String(prompt ?? '')
  const goal = findMomentGoal(input.goalId ?? input.goal)
  const inspiration = input.inspirationId
    ? findMomentsInspiration(input.inspirationId)
    : findMomentsInspirationByLabel(input.topic)
  const len = resolveMomentLength(input)
  const mode = findEmojiUsageMode(input.emojiUsageId ?? input.emojiLevel)
  const count = input.draftCount === 1 ? 1 : 3

  const promptChecks: PromptCheck[] = [
    mkCheck(
      '生成数量',
      count === 3
        ? text.includes('正好3条朋友圈成稿') || text.includes('生成3条原创朋友圈')
        : text.includes('正好1条朋友圈成稿'),
      `期望 ${count} 条`
    ),
    mkCheck(
      '身份角色',
      !input.persona.role || text.includes(`身份角色：${input.persona.role}`),
      `身份角色：${input.persona.role || '未填写'}`
    ),
    mkCheck(
      '内容路径',
      inspiration
        ? text.includes(`灵感类型：${inspiration.groupLabel} / ${inspiration.label}`)
        : true,
      inspiration ? `${inspiration.groupLabel} / ${inspiration.label}` : '未选择选题（自由创作）'
    ),
    mkCheck('语气', text.includes(`语气：${input.tone}`), `语气：${input.tone}`),
    mkCheck('目标', text.includes(`目标：${goal.label}`), `目标：${goal.label}`),
    mkCheck(
      '表达方式',
      text.includes(`表达方式：${input.expression}`),
      `表达方式：${input.expression}`
    ),
    mkCheck(
      '单条字数',
      text.includes(`${len.min}-${len.max}字`) &&
        text.includes('每一条朋友圈') &&
        text.includes('不是3条合计'),
      `${len.min}-${len.max}字（按单条独立计算）`
    ),
    mkCheck(
      '多分行/多空行',
      input.multiLine ? text.includes('必须多分行、多空行') : text.includes('不强制多空行'),
      input.multiLine ? '已开启' : '未开启'
    ),
    mkCheck(
      '表情规则进入提示词',
      mode.id === 'none'
        ? text.includes('不得使用表情符号')
        : text.includes('必须使用表情符号') &&
            text.includes(`平均每${mode.charsPerSymbol}字出现1个`),
      mode.label
    ),
    mkCheck(
      '首条回复',
      text.includes('首条回复规则') &&
        text.includes(FIRST_REPLY_MARKER) &&
        text.includes('首条回复不单独分字段'),
      FIRST_REPLY_MARKER
    ),
    mkCheck(
      '用户补充',
      !input.userInput || text.includes(input.userInput),
      input.userInput ? '已带入' : '未填写'
    ),
    mkCheck(
      '图片信息',
      !input.imageSummary || text.includes(input.imageSummary),
      input.imageSummary || '未填写'
    )
  ]

  const draftChecks: DraftCheck[] = []
  drafts.forEach((d, i) => {
    const n = `第${i + 1}条`
    const chars = Array.from(d.content.replace(/\s/g, '')).length
    draftChecks.push(
      mkCheck(
        `${n}字数`,
        chars >= len.min && chars <= len.max,
        `${chars} 字（要求 ${len.min}-${len.max}）`
      )
    )
    if (input.multiLine) {
      draftChecks.push(mkCheck(`${n}多空行`, /\n\s*\n/.test(d.content), '段落之间需要空行'))
    }
    const emojiCount = countEmoji(d.content)
    if (mode.id === 'none') {
      draftChecks.push(
        mkCheck(`${n}表情符号数量`, emojiCount === 0, `检测到 ${emojiCount} 个，要求 0`)
      )
    } else {
      const range = expectedEmojiRange(chars, mode.charsPerSymbol)
      draftChecks.push(
        mkCheck(
          `${n}表情符号数量`,
          emojiCount >= range.min && emojiCount <= range.max,
          `检测到 ${emojiCount} 个，期望 ${range.min}-${range.max}`
        )
      )
    }
    const hashCount = (d.content.match(/#/g) ?? []).length
    draftChecks.push(
      mkCheck(`${n}井号键`, hashCount >= 1 && hashCount <= 3, `检测到 ${hashCount} 次，要求 1-3 次`)
    )
    draftChecks.push(
      mkCheck(
        `${n}无模型标题`,
        stripDraftHeading(d.content) === d.content && !/(^|\n)\s*#{2,6}\s*\S/.test(d.content),
        '正文不得带定位标题或 Markdown 标题'
      )
    )
    draftChecks.push(mkCheck(`${n}首条回复`, !!d.reply.trim(), d.reply.trim() ? '已生成' : '缺失'))
  })

  return buildMatchReport(
    promptChecks,
    draftChecks,
    drafts.reduce((n, d) => n + d.content.length, 0)
  )
}

/** 小红书生成后自检：提示词断言 + 每篇 5-6 项（标题/封面禁 emoji、字数、标签、同城三要素） */
export function auditXhsResult(input: XhsInput, prompt: string, notes: XhsNote[]): XhsMatchReport {
  const text = String(prompt ?? '')
  const p = input.profile
  const ip = resolveXhsIpType(p)
  const len = findXhsLength(input.lengthId)
  const topic = input.topicId ? findXhsTopic(input.topicId) : findXhsTopicByLabel(input.topic)
  const local = isXhsLocalScenario({
    ipType: ip,
    localFocus: p.localFocus,
    topicGroupId: topic?.groupId
  })
  const mode = resolveXhsEmojiMode(input)

  const promptChecks: PromptCheck[] = [
    mkCheck(
      '生成数量',
      text.includes(`生成数量：${XHS_NOTE_COUNT}篇`),
      `期望 ${XHS_NOTE_COUNT} 篇`
    ),
    mkCheck('账号定位写法', text.includes(XHS_IP_WRITING_RULES[ip]), findXhsIpType(ip).label),
    mkCheck(
      '封面文案规则',
      text.includes(XHS_COVER_RULE) && text.includes(XHS_NO_EMOJI_IN_TITLE),
      '封面内容/笔记标题禁表情符号'
    ),
    mkCheck(
      '正文长度档',
      text.includes(len.range) && text.includes(String(XHS_BODY_MAX_CHARS)),
      `${len.display}（正文不超过 ${XHS_BODY_MAX_CHARS} 字）`
    ),
    mkCheck(
      '标题字数上限',
      text.includes(String(XHS_TITLE_MAX_CHARS)),
      `${XHS_TITLE_MAX_CHARS} 字`
    ),
    mkCheck(
      '标签个数',
      text.includes(`${XHS_TAG_MIN}-${XHS_TAG_MAX}个`),
      `${XHS_TAG_MIN}-${XHS_TAG_MAX} 个`
    ),
    mkCheck('表情符号频率', text.includes(`表情符号频率：${mode.label}`), mode.label),
    mkCheck(
      '同城标签硬规则',
      local ? text.includes(XHS_LOCAL_TAG_RULE) : !text.includes(XHS_LOCAL_TAG_RULE),
      local ? '本次为同城场景' : '本次非同城场景'
    ),
    mkCheck('首评', text.includes('首评要求'), '30-60 字首评')
  ]

  const draftChecks: DraftCheck[] = [
    mkCheck(
      '生成数量',
      notes.length === XHS_NOTE_COUNT,
      `当前生成 ${notes.length} 篇，应生成${XHS_NOTE_COUNT}篇`
    )
  ]
  notes.forEach((n, i) => {
    const tag = `方案${i + 1}`
    draftChecks.push(
      mkCheck(
        `${tag}标题`,
        !!n.title && countEmoji(n.title) === 0 && n.title.length <= XHS_TITLE_MAX_CHARS,
        `「${n.title}」（${n.title.length} 字，emoji ${countEmoji(n.title)} 个）`
      )
    )
    draftChecks.push(
      mkCheck(
        `${tag}封面内容`,
        !!n.coverText && countEmoji(n.coverText) === 0,
        `「${n.coverText}」`
      )
    )
    draftChecks.push(
      mkCheck(
        `${tag}正文`,
        !!n.content && n.content.length <= XHS_BODY_MAX_CHARS,
        `${n.content.length} 字`
      )
    )
    const chars = Array.from(n.content.replace(/\s/g, '')).length
    draftChecks.push(
      mkCheck(
        `${tag}正文字数`,
        chars >= len.min && chars <= len.max,
        `${chars} 字（要求 ${len.min}-${len.max}）`
      )
    )
    const tagsOk =
      n.tags.length >= XHS_TAG_MIN &&
      n.tags.length <= XHS_TAG_MAX &&
      n.tags.every((t) => t.startsWith('#')) &&
      n.tags.some((t) => XHS_TAG_KEYWORDS.some((k) => t.includes(k)))
    draftChecks.push(mkCheck(`${tag}标签`, tagsOk, `${n.tags.length} 个：${n.tags.join(' ')}`))
    if (local) {
      const city = p.city || BRAND_CITY
      const brand = p.brand || BRAND_NAME
      const filled = ensureLocalTags(n.tags, {
        ipType: ip,
        localFocus: p.localFocus,
        topicGroupId: topic?.groupId,
        brand: p.brand,
        city: p.city,
        role: p.role,
        strengths: p.strengths
      })
      const hasCity = filled.some((t) => t.includes(city))
      const hasBrand = filled.some((t) => t.includes(brand))
      const hasIndustry = xhsIndustryTags(industryKeywordSource(p)).some((t) => filled.includes(t))
      draftChecks.push(
        mkCheck(
          `${tag}同城标签`,
          hasCity && hasBrand && hasIndustry,
          `城市标签=${hasCity}、品牌名标签=${hasBrand}、行业词标签=${hasIndustry}`
        )
      )
    }
  })

  return buildMatchReport(
    promptChecks,
    draftChecks,
    notes.reduce((n, x) => n + x.content.length, 0)
  )
}
