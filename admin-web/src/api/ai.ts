import { useAiConfigStore, SERVER_CONFIGURED_PLACEHOLDER } from '@/store/modules/ai-config'
import { useYimaiStore } from '@/store/modules/yimai'
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
    const saved = await apiGet<
      { enabled?: boolean; providerLabel?: string; baseUrl?: string; model?: string; temperature?: number; apiKey?: string; configured?: boolean }
    >('/ai/config')
    const store = useAiConfigStore()
    if (saved) {
      Object.assign(store.config, {
        enabled: Boolean(saved.enabled),
        providerLabel: saved.providerLabel ?? store.config.providerLabel,
        baseUrl: saved.baseUrl ?? store.config.baseUrl,
        model: saved.model ?? store.config.model,
        temperature: typeof saved.temperature === 'number' ? saved.temperature : store.config.temperature,
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

// ==================== 朋友圈：选项口径（报告 7.3-7.4） ====================

export const MOMENTS_CATEGORIES: Record<string, string[]> = {
  客户案例: ['体验课案例', '1个月改变', '3个月改变', '1年改变', '转介绍', '体态改善', '动作纠正', '坚持打卡'],
  课堂日常: ['今天的私教课', '今天的小班课', '课堂氛围', '约课爆满', '早课状态', '晚课状态', '课堂细节', '课后复盘'],
  专业科普: ['常见误区', '动作解析', '减脂没效果', '肩颈腰背', '呼吸', '核心', '拉伸误区', '健康饮食', '训练频率'],
  个人品牌: ['教练感悟', '训练日常', '专业进修', '经营感悟', '训练理念', '为什么做教练', '团队日常'],
  生活方式: ['健康生活', '给自己一小时', '长期主义', '女性力量', '睡眠恢复', '办公室放松', '周末训练'],
  晒单反馈: ['客户反馈', '前后对比', '续课记录', '排课满档', '会员私信', '阶段成果'],
  活动推荐: ['体验课推荐', '体态评估', '新客福利', '老会员回归', '小班招募', '限时名额']
}

export const MOMENT_TONES = ['自然真实', '专业干货', '轻松幽默', '温暖治愈', '元气满满']
export const MOMENT_GOALS = ['分享生活（无目标）', '种草引流', '活动通知', '建立专业信任', '招募体验课']
export const MOMENT_EXPRESSIONS = ['说事理', '讲故事', '清单体', '对话体']
export const MOMENT_LENGTHS = [
  { label: '简短版 50-80字', min: 50, max: 80 },
  { label: '标准版 100-180字', min: 100, max: 180 },
  { label: '完整版 200-300字', min: 200, max: 300 }
]
export const EMOJI_LEVELS = ['不使用', '少量（约每50字1个）', '适量（约每30字1个）']

export interface MomentsPersona {
  name: string
  gender: string
  age: string
  role: string
  years: string
  position: string
  brand: string
  city: string
  audiences: string[]
}

export const PERSONA_ROLES = ['瑜伽普拉提教练', '健身教练', '康复教练', '场馆主', '管理层', '会籍顾问', '培训师']
export const AUDIENCE_OPTIONS = ['上班族', '宝妈', '零基础女性', '想减脂的人', '久坐肩颈人群', '产后妈妈', '想改善体态的人', '有训练经验但没效果的人']

export interface MomentsInput {
  persona: MomentsPersona
  category: string
  topic: string
  tone: string
  goal: string
  expression: string
  lengthLabel: string
  multiLine: boolean
  emojiLevel: string
  userInput: string
}

// ==================== 小红书：选项口径（报告 8.1-8.5） ====================

export const XHS_STRENGTHS = ['减脂塑形', '体态改善', '肩颈腰背', '核心训练', '器械普拉提', '产后恢复', '呼吸练习', '饮食管理', '私教陪跑']
export const XHS_STYLES = ['专业干练', '温柔陪伴', '真实接地气', '高级克制', '热情鼓励', '老板视角', '成熟稳重', '有生活感', '不爱硬销售', '适度转化', '专业严谨', '有力量感']
export const XHS_CONVERSIONS = ['不主动转化', '评论区留言', '私信咨询', '预约评估', '预约体验', '到店看看']

export interface XhsProfile {
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
}

export interface XhsInput {
  profile: XhsProfile
  topic: string
  points: string
}

// ==================== 提示词编排（报告 12.4：场景模板+人设+选题+输出结构） ====================

function personaText(p: MomentsPersona): string {
  return [
    p.name && `姓名：${p.name}`,
    p.gender && `性别：${p.gender}`,
    p.age && `年龄：${p.age}`,
    p.role && `身份角色：${p.role}`,
    p.years && `从业年限：${p.years}`,
    p.position && `职位：${p.position}`,
    p.brand && `品牌：${p.brand}`,
    p.city && `城市：${p.city}`,
    p.audiences.length && `主要客群：${p.audiences.join('、')}`
  ].filter(Boolean).join('；')
}

function buildMomentsMessages(input: MomentsInput): ChatMessage[] {
  const len = MOMENT_LENGTHS.find((l) => l.label === input.lengthLabel) ?? MOMENT_LENGTHS[1]
  const system = [
    '你是一名瑜伽普拉提馆的社交媒体内容助手，为教练生成朋友圈文案草稿。',
    '硬性规则：始终以教练/服务者第一人称视角撰写，不虚构具体会员隐私信息，不使用医疗诊断或疗效承诺用语，不过度促销。',
    `输出要求：一段可直接复制发布的朋友圈正文；长度控制在${len.min}-${len.max}字；`,
    input.multiLine ? '使用多短行分段排版。' : '使用自然段落。',
    input.emojiLevel === '不使用' ? '不使用表情符号。' : `表情符号使用：${input.emojiLevel}。`
  ].join('\n')

  const user = [
    personaText(input.persona),
    `内容分类：${input.category}${input.topic ? ` / ${input.topic}` : ''}`,
    `语气：${input.tone}；目标：${input.goal}；表达方式：${input.expression}`,
    input.userInput && `我想表达的内容：${input.userInput}`
  ].filter(Boolean).join('\n')

  return [
    { role: 'system', content: system },
    { role: 'user', content: user }
  ]
}

function buildXhsMessages(input: XhsInput): ChatMessage[] {
  const p = input.profile
  const system = [
    '你是小红书瑜伽普拉提赛道的内容策划，为账号生成笔记草稿。',
    '写作规范：标题带搜索感/场景感/问题感；正文开头快速命中目标读者，中段给出具体可收藏的方法信息，结尾轻转化；',
    '始终以教练/服务者视角，不伪造会员第一人称经历；不含医疗承诺与虚假效果保证。',
    '严格输出 JSON：{"title": string, "content": string, "tags": string[]}，tags 为话题标签数组（含#号），需覆盖行业词、问题词、人群词' +
      (p.localFocus ? '、同城词' : '') + '和本次选题词。'
  ].join('\n')

  const user = [
    `账号定位：${p.ipType}${p.accountName ? `（账号名：${p.accountName}）` : ''}`,
    `身份角色：${p.role || '瑜伽普拉提教练'}${p.gender ? `/${p.gender}` : ''}${p.age ? `/${p.age}` : ''}`,
    p.brand && `品牌：${p.brand}`,
    p.city && `城市：${p.city}${p.localFocus ? '（强化同城属性，自然融入城市、到店、体验课等词）' : ''}`,
    p.audiences.length && `目标客群（最多4项）：${p.audiences.slice(0, 4).join('、')}`,
    p.strengths.length && `擅长方向：${p.strengths.join('、')}`,
    `表达风格：${p.style}；默认转化方向：${p.conversion}`,
    `本次选题：${input.topic || '自由发挥一个近期课堂相关选题'}`,
    input.points && `补充要点：${input.points}`
  ].filter(Boolean).join('\n')

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
async function callLLM(messages: ChatMessage[]): Promise<string> {
  if (USE_BACKEND) {
    return chatLLMStream(messages)
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
  opts?: { onDelta?: (t: string) => void; maxTokens?: number }
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
      baseUrl: c.baseUrl,
      apiKey: c.apiKey,
      model: c.model,
      messages,
      temperature: c.temperature,
      maxTokens: opts?.maxTokens,
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
      const obj = JSON.parse(data) as { choices?: { delta?: { content?: string }; message?: { content?: string } }[] }
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
  const d = await apiPost<{ models?: string[]; code?: number; message?: string }>('/ai/models', { baseUrl, apiKey })
  if (d && d.code !== undefined && d.code !== 0) {
    throw new Error(d.message || '获取模型列表失败')
  }
  return d?.models ?? []
}

function parseJsonLoose(text: string): { title: string; content: string; tags: string[] } | null {
  const m = text.match(/\{[\s\S]*\}/)
  if (!m) return null
  try {
    const obj = JSON.parse(m[0])
    if (obj.title && obj.content) {
      return {
        title: String(obj.title),
        content: String(obj.content),
        tags: Array.isArray(obj.tags) ? obj.tags.map(String).slice(0, 10) : []
      }
    }
  } catch {
    /* ignore */
  }
  return null
}

// ==================== 本地降级生成 ====================

function fallbackMoments(input: MomentsInput): string {
  const lines: string[] = []
  const topic = input.topic || '今天的课堂'
  lines.push(`${topic}｜`)
  if (input.userInput) lines.push(input.userInput)
  lines.push(
    input.category === '专业科普'
      ? '很多人以为练得多才有效，其实练对了比练多了更重要。今天课堂上又帮一位学员找到了发力感，她说原来呼吸顺序对了，动作一下子轻松了。'
      : '一节课下来，大家的状态越来越好。进步从来不是突然发生的，是每一次认真练习累积出来的。'
  )
  if (input.persona.city) lines.push(`我们在${input.persona.city}，等你来一起练。`)
  else lines.push('想一起练的，评论区或私信找我。')
  return lines.join('\n\n')
}

function fallbackXhs(input: XhsInput): { title: string; content: string; tags: string[] } {
  const topic = input.profile.strengths[0] ?? '瑜伽日常'
  return {
    title: `${topic}怎么练才有效？新手必看的3个要点`,
    content: [
      `很多姐妹问${topic}到底怎么开始，这篇讲清楚👇`.replace(/👇/, '').trim(),
      '',
      '1️⃣ 先评估再训练：了解自己的体态和受限，比直接跟练更重要'.replace(/1️⃣/g, '①'),
      '2️⃣ 频率大于强度：每周2-3次规律练习，胜过一次猛练',
      '3️⃣ 找到发力感：呼吸和核心先到位，动作质量自然上来',
      '',
      input.profile.conversion !== '不主动转化' ? '想来体验的同学，可以私信我约一节体验课。' : '有问题评论区聊，看到都会回。'
    ].join('\n'),
    tags: ['#瑜伽', `#${topic}`, '#体态改善', input.profile.localFocus && input.profile.city ? `#${input.profile.city}瑜伽` : '', '#运动日常'].filter(Boolean)
  }
}

// ==================== 对外入口 ====================

function auditGenerate(platform: string, topic: string, source: 'llm' | 'fallback') {
  useYimaiStore().writeAudit('生成', '营销工具', 0, `${platform} · ${topic || '自由创作'}`, '双店', `生成方式：${source === 'llm' ? '大模型API' : '本地模板（未配置AI或调用失败）'}；主题[${topic || '无'}]`)
}

export async function generateMomentsCopy(input: MomentsInput): Promise<{ content: string; source: 'llm' | 'fallback'; warning?: string }> {
  const store = useAiConfigStore()
  let content = ''
  let source: 'llm' | 'fallback' = 'fallback'
  let warning: string | undefined
  if (store.isReady()) {
    try {
      content = await callLLM(buildMomentsMessages(input))
      content = content.trim().replace(/^["“]|["”]$/g, '')
      source = 'llm'
    } catch (e) {
      warning = `大模型调用失败，已用本地模板生成（${String(e).slice(0, 80)}）`
      content = fallbackMoments(input)
    }
  } else {
    warning = '尚未启用大模型，当前为本地模板草稿（超管可在「模型配置」接入API）'
    content = fallbackMoments(input)
  }
  store.logUsage('朋友圈', source, content.length)
  auditGenerate('朋友圈', input.topic, source)
  return { content, source, warning }
}

export async function generateXhsNote(input: XhsInput): Promise<{ title: string; content: string; tags: string[]; source: 'llm' | 'fallback'; warning?: string }> {
  const store = useAiConfigStore()
  let result = { title: '', content: '', tags: [] as string[] }
  let source: 'llm' | 'fallback' = 'fallback'
  let warning: string | undefined
  if (store.isReady()) {
    try {
      const raw = await callLLM(buildXhsMessages(input))
      const parsed = parseJsonLoose(raw)
      if (!parsed) throw new Error('JSON_PARSE_FAIL')
      result = parsed
      source = 'llm'
    } catch (e) {
      warning = `大模型调用失败，已用本地模板生成（${String(e).slice(0, 80)}）`
      result = fallbackXhs(input)
    }
  } else {
    warning = '尚未启用大模型，当前为本地模板草稿（超管可在「模型配置」接入API）'
    result = fallbackXhs(input)
  }
  store.logUsage('小红书', source, result.content.length)
  auditGenerate('小红书', input.topic, source)
  return { ...result, source, warning }
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

export const HIGH_RISK_KEYWORDS = ['孕', '术后', '疼痛', '椎间盘', '高血压', '心脏病', '糖尿病', '眩晕', '骨折']

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
  ].filter(Boolean).join('；')

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
      { name: '第1段 基础建立', duration: `${seg}周`, items: ['呼吸模式重建', '中立位与核心感知', '基础体式规范'] },
      { name: '第2段 能力提升', duration: `${seg}周`, items: [focus0 ? `${focus0}针对性练习` : '目标部位强化', '动作串联与流畅度', '小负荷力量导入'] },
      { name: '第3段 目标整合', duration: `${tail}周`, items: [(input.stageGoal || '阶段目标') + '拆解练习', '完整课堂流程演练', '复评与下一阶段建议'] }
    ],
    cautions: [
      '练习中出现疼痛、麻木或眩晕应立即停止并反馈教练',
      input.risks && input.risks !== '无特殊风险' && input.risks ? `存在「${input.risks}」情况，请先经医疗专业人员评估后再执行本计划` : '如有不适请及时与教练沟通调整'
    ]
  }
}

export async function generateTrainingPlan(input: TrainingInput): Promise<{ content: PlanContentLike; source: 'llm' | 'fallback'; warning?: string }> {
  const store = useAiConfigStore()
  let content: PlanContentLike
  let source: 'llm' | 'fallback' = 'fallback'
  let warning: string | undefined

  if (store.isReady()) {
    try {
      const raw = await callLLM(buildTrainingMessages(input))
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

  useYimaiStore().writeAudit('生成', '训练计划', 0, `训练计划 · ${input.memberName}`, '双店', `生成方式：${source === 'llm' ? '大模型API' : '本地模板'}；目标[${input.coreGoal}]`)
  return { content, source, warning }
}
