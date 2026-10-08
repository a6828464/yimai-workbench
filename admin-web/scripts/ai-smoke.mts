/**
 * 引擎冒烟门禁：朋友圈 / 小红书 / 防折叠三套生成引擎的确定性验证
 * ============================================================================
 *
 * 用途
 *   把 t2（I1 生成引擎与提示词升级）里建立的断言集变成**可复跑护栏**：
 *   ai.ts 从 971 行长到 3500+ 行后，任何一次「顺手改提示词文案 / 改枚举取值 /
 *   改解析容错」都可能静默破坏硬约束，靠人工看代码守不住。本脚本锁住：
 *     · 语料齐全性（朋友圈 62 项 / 小红书 39 项 promptHint 全非空、id 唯一）
 *     · 常量口径（长度档值域、目标 7 档、emoji 四档、旧 label→id 兼容映射）
 *     · 提示词硬约束逐条（立场铁律 / 单条字数 / emoji 换算 / # 1-3 次 /
 *       首条回复 / 生成期防折叠 5 条 / 去 AI 味 / 图片强关联）
 *     · 纯函数质检（防折叠四项 + 版本间差异、emoji 计数、期望区间）
 *     · 本地降级结构（朋友圈 3 版 / 小红书 2 篇）与「未接入 AI → warning 不伪造」
 *     · 解析层三级容错（JSON 数组 / 旧单对象 / 纯文本 / coverText 别名）
 *
 * 跑法
 *   cd admin-web && pnpm check:ai-smoke
 *   （等价：./node_modules/.bin/tsx scripts/ai-smoke.mts）
 *   任一断言失败 → 立即打印失败明细并以退出码 1 结束。
 *
 * 定位（重要）
 *   本脚本是**引擎纯函数与本地降级的确定性验证**，**不等于端到端 UI 验证**：
 *   它不启动浏览器、不渲染组件、不打真实大模型、不连后端。UI 交互、样式与
 *   「生成结果长什么样」仍需人工走查（见 t3/走查记录）。
 *
 * 零网络 / 零后端
 *   通过边界桩把「应用外壳」替换掉，被测代码只保留引擎本身：
 *     · '@/store/modules/ai-config' → 桩（isReady 由 __AI_READY__ 控制）
 *     · '@/store/modules/yimai'     → 桩（writeAudit 空实现）
 *     · './backend'                 → 桩（USE_BACKEND=false，apiGet/apiPost 空实现）
 *   因此 USE_BACKEND 恒为 false，不会走 Laravel 代理，也不会加载 axios；
 *   唯一被替换的 globalThis.fetch 只服务内置的「假大模型」应答，且**任何**
 *   非桩地址的请求都会直接抛错（见 assertNoRealNetwork）。
 *
 * 依赖
 *   仅使用仓库已有 devDependency：vite（createServer + ssrLoadModule，走应用
 *   自身的 TS/别名转换管线）与 tsx（运行本脚本）。**不引入任何新依赖**。
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer, type Plugin, type ViteDevServer } from 'vite'
import type {
  AntiFoldResult,
  MomentsDraft,
  MomentsInput,
  MomentsResult,
  PromptCheck,
  XhsInput,
  XhsNote,
  XhsResult
} from '../src/api/ai'

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(SCRIPT_DIR, '..')

/** 假大模型与本脚本共用同一个「桩后端」地址（真实网络请求一律抛错） */
const STUB_BASE_URL = 'http://stub.local'

// ---------------------------------------------------------------------------
// 输出与断言
// ---------------------------------------------------------------------------

const color = {
  reset: '\x1b[0m',
  bold: '\x1b[1m',
  red: '\x1b[31m',
  green: '\x1b[32m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  gray: '\x1b[90m'
}

let passCount = 0
const failures: string[] = []

function log(message = ''): void {
  console.log(message)
}

function section(title: string): void {
  log()
  log(`${color.bold}${color.blue}${title}${color.reset}`)
}

function pass(message: string): void {
  passCount++
  log(`  ${color.green}✓${color.reset} ${message}`)
}

function fail(message: string): void {
  failures.push(message)
  log(`  ${color.red}✗${color.reset} ${message}`)
}

function info(message: string): void {
  log(`  ${color.gray}·${color.reset} ${message}`)
}

/** 单条断言：失败时打印断言名 + 可选实测值，绝不只累加总数 */
function check(name: string, ok: boolean, actual = ''): void {
  if (ok) pass(name)
  else fail(`${name}${actual ? `  ← 实测：${actual}` : ''}`)
}

// ---------------------------------------------------------------------------
// 边界桩（把应用外壳与后端替换掉，只留被测引擎）
// ---------------------------------------------------------------------------

const AI_CONFIG_STUB = `
export const SERVER_CONFIGURED_PLACEHOLDER = '__configured__'
export function useAiConfigStore() {
  return {
    isReady: () => globalThis.__AI_READY__ === true,
    config: { baseUrl: '${STUB_BASE_URL}', apiKey: 'stub-key', model: 'stub-model', temperature: 0.7 },
    logUsage: () => {},
    addFavorite: () => {}
  }
}
`

const YIMAI_STUB = `
export function useYimaiStore() {
  return { writeAudit: () => {} }
}
`

const BACKEND_STUB = `
export const USE_BACKEND = false
export const API_BASE = '${STUB_BASE_URL}/api'
export function getBackendToken() { return 'stub-token' }
export async function apiGet() { return {} }
export async function apiPost() { return {} }
export async function apiPut() { return {} }
export async function apiDelete() { return {} }
`

/**
 * 注意：vite:alias 早于 pre 插件执行，本插件拿到的 store 导入**已经是解析后的
 * 绝对路径**，故必须同时兼容 '@/store/modules/x' 与 '/abs/src/store/modules/x.ts'。
 */
function boundaryStubPlugin(): Plugin {
  const virtuals: Record<string, string> = {
    '\0ai-smoke:ai-config': AI_CONFIG_STUB,
    '\0ai-smoke:yimai': YIMAI_STUB,
    '\0ai-smoke:backend': BACKEND_STUB
  }
  return {
    name: 'ai-smoke-boundary-stub',
    enforce: 'pre',
    resolveId(source: string) {
      const norm = source.replace(/\\/g, '/').replace(/\?.*$/, '')
      if (
        /^@\/store\/modules\/ai-config$/.test(norm) ||
        /src\/store\/modules\/ai-config(\.ts)?$/.test(norm)
      ) {
        return '\0ai-smoke:ai-config'
      }
      if (
        /^@\/store\/modules\/yimai$/.test(norm) ||
        /src\/store\/modules\/yimai(\.ts)?$/.test(norm)
      ) {
        return '\0ai-smoke:yimai'
      }
      if (/^\.\/backend$/.test(norm) || /src\/api\/backend(\.ts)?$/.test(norm)) {
        return '\0ai-smoke:backend'
      }
      return null
    },
    load(id: string) {
      return virtuals[id] ?? null
    }
  }
}

// ---------------------------------------------------------------------------
// 运行时替身：localStorage（my.ts 落历史）/ 假大模型 fetch
// ---------------------------------------------------------------------------

class FakeStorage {
  private mem = new Map<string, string>()

  get length(): number {
    return this.mem.size
  }

  key(index: number): string | null {
    return Array.from(this.mem.keys())[index] ?? null
  }

  getItem(key: string): string | null {
    return this.mem.has(key) ? (this.mem.get(key) as string) : null
  }

  setItem(key: string, value: string): void {
    this.mem.set(key, String(value))
  }

  removeItem(key: string): void {
    this.mem.delete(key)
  }

  clear(): void {
    this.mem.clear()
  }
}

let llmReplyText = ''
const fetchCalls: { url: string; body: string }[] = []

/** 安装假大模型：只应答桩地址，其它地址一律抛错（杜绝真实网络） */
function installFakeLLM(): void {
  const g = globalThis as Record<string, unknown>
  g.localStorage = new FakeStorage()
  g.sessionStorage = g.localStorage
  g.window = globalThis
  g.fetch = async (input: unknown, init?: { body?: unknown }) => {
    const url = String(
      typeof input === 'string'
        ? input
        : input instanceof URL
          ? input.href
          : ((input as { url?: string })?.url ?? '')
    )
    fetchCalls.push({ url, body: String(init?.body ?? '') })
    if (!url.startsWith(STUB_BASE_URL)) {
      throw new Error(`冒烟测试不允许真实网络请求：${url}`)
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({ choices: [{ message: { content: llmReplyText } }] })
    } as unknown as Response
  }
}

/** 让下一次生成走「已接入大模型」路径，并指定模型返回的原始文本 */
function setLLMReply(text: string): void {
  llmReplyText = text
  ;(globalThis as Record<string, unknown>).__AI_READY__ = true
}

function setAiReady(ready: boolean): void {
  ;(globalThis as Record<string, unknown>).__AI_READY__ = ready
}

// ---------------------------------------------------------------------------
// 载入被测引擎：走应用自身的 Vite 转换管线（TS + 路径别名 + define）
// ---------------------------------------------------------------------------

async function loadEngine(): Promise<{
  server: ViteDevServer
  ai: typeof import('../src/api/ai')
}> {
  const server = await createServer({
    configFile: false,
    root: ROOT,
    appType: 'custom',
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false, watch: null },
    optimizeDeps: { noDiscovery: true },
    define: { __APP_VERSION__: JSON.stringify('0.0.0-ai-smoke') },
    plugins: [boundaryStubPlugin()],
    resolve: {
      alias: {
        '@': path.resolve(ROOT, 'src'),
        '@views': path.resolve(ROOT, 'src/views'),
        '@imgs': path.resolve(ROOT, 'src/assets/images'),
        '@icons': path.resolve(ROOT, 'src/assets/icons'),
        '@utils': path.resolve(ROOT, 'src/utils'),
        '@stores': path.resolve(ROOT, 'src/store'),
        '@plugins': path.resolve(ROOT, 'src/plugins'),
        '@styles': path.resolve(ROOT, 'src/assets/styles')
      }
    }
  })
  const mod = (await server.ssrLoadModule(
    '/src/api/ai.ts'
  )) as unknown as typeof import('../src/api/ai')
  return { server, ai: mod }
}

// ---------------------------------------------------------------------------
// 断言集
// ---------------------------------------------------------------------------

type Engine = typeof import('../src/api/ai')

const MOMENTS_CASE: MomentsInput = {
  persona: {
    name: '小艾',
    gender: '女',
    age: '31-35岁',
    role: '全职老师',
    years: '3-5年',
    specialties: ['普拉提'],
    brand: '一麦',
    city: '宁波',
    audiences: ['25-45岁久坐上班族']
  },
  category: '客户案例',
  topic: '1个月改变',
  tone: '自然真实',
  goal: '招募体验课',
  expression: '讲故事',
  lengthLabel: '简短版 30-50字',
  multiLine: true,
  emojiLevel: '不使用',
  userInput: '',
  goalId: 'trial-recruit',
  inspirationId: 'month-1',
  emojiUsageId: 'none',
  antiFoldEnabled: true,
  imageSummary: '课前体态照',
  imageCount: 2,
  draftCount: 3
}

const XHS_CASE: XhsInput = {
  profile: {
    ipType: '门店IP',
    accountName: '一麦宁波',
    role: '全职老师',
    gender: '女',
    age: '31-35岁',
    audiences: [
      '25-45岁久坐上班族',
      '肩颈腰背慢性酸痛人群',
      '产后恢复妈妈（备孕/孕期/产后）',
      '失眠焦虑、需要放松解压的人群',
      '银发活力人群（关节友好训练）',
      '零基础大体重、运动入门人群'
    ],
    brand: '一麦',
    city: '宁波',
    localFocus: true,
    strengths: ['普拉提', '体态调整'],
    style: '温柔陪伴',
    conversion: '预约体验',
    ipTypeId: 'venue-ip',
    emojiUsageId: 'medium'
  },
  category: '问题改善',
  topic: '圆肩驼背',
  points: '',
  lengthId: 'standard',
  topicId: 'round-shoulder'
}

const ANTI_FOLD_SOURCE =
  '今天课上有个学员终于找到发力感了。她说肩颈松了很多。想约课的可以直接找我。'

function checkCatalogAndConstants(ai: Engine): void {
  section('1. 语料齐全性（promptHint 是「像不像真人发的」第一杠杆）')
  const momentsItems = ai.MOMENTS_CATALOG.flatMap((g) => g.items)
  check('朋友圈目录 8 组', ai.MOMENTS_CATALOG.length === 8, String(ai.MOMENTS_CATALOG.length))
  check('朋友圈目录 62 项', momentsItems.length === 62, String(momentsItems.length))
  check(
    '朋友圈 62 项 promptHint 全非空',
    momentsItems.every((i) => i.promptHint.trim().length >= 8),
    `${momentsItems.filter((i) => i.promptHint.trim().length < 8).length} 项为空或过短`
  )
  check('朋友圈目录 id 唯一', new Set(momentsItems.map((i) => i.id)).size === momentsItems.length)
  const xhsItems = ai.XHS_TOPIC_CATALOG.flatMap((g) => g.items)
  check('小红书目录 39 项', xhsItems.length === 39, String(xhsItems.length))
  check(
    '小红书 39 项 promptHint 全非空',
    xhsItems.every((i) => i.promptHint.trim().length >= 8),
    `${xhsItems.filter((i) => i.promptHint.trim().length < 8).length} 项为空或过短`
  )
  check('小红书目录 id 唯一', new Set(xhsItems.map((i) => i.id)).size === xhsItems.length)
  info(`朋友圈 ${momentsItems.length} 项 / 小红书 ${xhsItems.length} 项`)

  section('2. 常量口径（照抄源码的取值集 + 一麦自有的值域）')
  check(
    '长度档 3 档且带 id',
    ai.MOMENT_LENGTHS.length === 3 && ai.MOMENT_LENGTHS[1].id === 'standard',
    ai.MOMENT_LENGTHS.map((l) => l.id).join(',')
  )
  check(
    '长度档值域保持一麦口径 30-50 / 50-120 / 100-150',
    ai.MOMENT_LENGTHS[0].min === 30 &&
      ai.MOMENT_LENGTHS[0].max === 50 &&
      ai.MOMENT_LENGTHS[1].min === 50 &&
      ai.MOMENT_LENGTHS[1].max === 120 &&
      ai.MOMENT_LENGTHS[2].min === 100 &&
      ai.MOMENT_LENGTHS[2].max === 150,
    ai.MOMENT_LENGTHS.map((l) => `${l.min}-${l.max}`).join(' / ')
  )
  check(
    '目标 7 档且 label 视图同步',
    ai.MOMENT_GOALS_V2.length === 7 && ai.MOMENT_GOALS.length === 7
  )
  check(
    'emoji 四档且 label 视图同步',
    ai.EMOJI_USAGE_MODES.length === 4 && ai.EMOJI_LEVELS.length === 4
  )
  check(
    'findMomentGoal 末位回落分享生活',
    ai.findMomentGoal('').id === 'life',
    ai.findMomentGoal('').id
  )
  check('findMomentGoal 支持 label 反查', ai.findMomentGoal('续课升单铺垫').id === 'renewal')
  check(
    '旧 emoji label「适量（约每30字1个）」→ high',
    ai.findEmojiUsageMode('适量（约每30字1个）').id === 'high'
  )
  check(
    '旧 emoji label「少量（约每50字1个）」→ medium',
    ai.findEmojiUsageMode('少量（约每50字1个）').id === 'medium'
  )
  check('findMomentLength 兼容旧 label', ai.findMomentLength('标准版 50-120字').id === 'standard')
  check(
    '自定义字数档解析',
    ai.resolveMomentLength({
      lengthLabel: ai.MOMENT_LENGTH_CUSTOM,
      customLength: { min: 400, max: 500 }
    }).max === 500
  )
  check('图片上限 = 3', ai.MOMENTS_MAX_IMAGES === 3, String(ai.MOMENTS_MAX_IMAGES))
  check(
    '目标映射三件套齐全（销售强度 / 转化动作 / 行动指令）',
    ai.MOMENT_GOALS_V2.every(
      (g) => !!g.salesLevelId && !!g.conversionActionId && typeof g.actionInstruction === 'string'
    )
  )
}

function checkHardRules(ai: Engine): void {
  section('3. 硬约束文案（改文案即回归）')
  check(
    '立场铁律含服务者视角 + 会员素材转写',
    ai.MOMENTS_STANCE_RULE.includes('服务者视角') && ai.MOMENTS_STANCE_RULE.includes('转写')
  )
  check('去 AI 味文案', ai.MOMENTS_DE_AI_RULE.includes('去 AI 味儿'))
  check('长度规则含「不是3条合计」', ai.buildLengthRule('100-180字').includes('不是3条合计'))
  check(
    'emoji none 分支要求数量为 0',
    ai.buildEmojiRule(ai.findEmojiUsageMode('none')).includes('必须为0')
  )
  check(
    'emoji medium 分支按 50 字换算',
    ai.buildEmojiRule(ai.findEmojiUsageMode('medium')).includes('平均每50字出现1个')
  )
  check(
    '井号键规则含 1-3 次',
    ai.buildHashtagRule('#想约课可以直接找我').includes('至少 1 次、最多 3 次')
  )
  check(
    '井号键规则带出本次行动指令',
    ai.buildHashtagRule('#想约课可以直接找我').includes('#想约课可以直接找我')
  )
  check('无目标时说明 # 用于开头重点词', ai.buildHashtagRule('').includes('本次无行动指令'))
  check(
    '生成期防折叠 5 条',
    ai.MOMENTS_ANTI_FOLD_RULES.length === 6 &&
      ai.buildAntiFoldSection(true).includes('前3行必须有价值')
  )
  check('防折叠未开启 = 「未开启」', ai.buildAntiFoldSection(false) === '未开启')

  section('4. 首条回复（标记独占一行，前后各空一行）')
  const merged = ai.joinWithFirstReply('正文行一\n正文行二', '谢谢你看到这里')
  check('合并后标记独占一行且前后空行', merged.includes(`\n\n${ai.FIRST_REPLY_MARKER}\n\n`))
  const split = ai.splitFirstReply(merged)
  check(
    'splitFirstReply 与 joinWithFirstReply 回环',
    split.hasReply && split.body === '正文行一\n正文行二' && split.reply === '谢谢你看到这里'
  )
  const noReply = ai.splitFirstReply('只有正文')
  check('无标记时 hasReply=false 且正文完整', !noReply.hasReply && noReply.body === '只有正文')

  section('5. 剥标题（不得误伤正文里作重点标记的单个 #）')
  check('剥「1、」序号', ai.stripDraftHeading('1、今天课上的一幕') === '今天课上的一幕')
  check(
    '剥「偏真实日常版：」',
    ai.stripDraftHeading('偏真实日常版：今天课上的一幕') === '今天课上的一幕'
  )
  check('剥「## 专业信任版」', ai.stripDraftHeading('## 专业信任版\n今天课上') === '今天课上')
  check(
    '保留正文里的单个 # 重点标记',
    ai.stripDraftHeading('#想约课可以直接找我\n今天课上') === '#想约课可以直接找我\n今天课上'
  )

  section('6. 质检基元（emoji 计数与期望区间）')
  check('countEmoji 计数', ai.countEmoji('今天😊练完✨很轻松') === 2)
  check('countEmoji 无 emoji 为 0', ai.countEmoji('今天练完很轻松') === 0)
  check(
    'expectedEmojiRange：不使用档短路 {0,0}',
    JSON.stringify(ai.expectedEmojiRange(120, 0)) === '{"min":0,"max":0}'
  )
  check(
    'expectedEmojiRange：空文本短路 {0,0}',
    JSON.stringify(ai.expectedEmojiRange(0, 50)) === '{"min":0,"max":0}'
  )
  check(
    'expectedEmojiRange：105 字 / 每 50 字 1 个 → 2-3',
    JSON.stringify(ai.expectedEmojiRange(105, 50)) === '{"min":2,"max":3}'
  )
  check(
    'longestCommonSubstring 值正确',
    ai.longestCommonSubstring('abcdefghij', 'xxabcdefghij') === 10
  )
  check('splitSentences 切分', ai.splitSentences('第一句。第二句！\n第三句').length === 3)
  check('normalizeSentence 去标点与空白', ai.normalizeSentence(' 你好，世界。 ') === '你好世界')
  check('firstSentence 取首句', ai.firstSentence('开头这句。后面那句。') === '开头这句')
}

function checkAntiFoldPureFunctions(ai: Engine): void {
  section('7. 防折叠四项质检（纯函数边界）')
  const good =
    '今天课上，那个总说肩颈紧的学员，突然就找到发力感了。她说整个人松下来不少。想约课的随时找我。'
  const report = ai.auditAntiFold(ANTI_FOLD_SOURCE, good)
  check(
    'auditAntiFold 返回四项且顺序固定',
    report.checks.length === 4 &&
      report.checks.map((c: PromptCheck) => c.label).join(',') ===
        '首句是否更换,相同句占比,最长连续相同片段,篇幅偏差'
  )
  const copied = ai.auditAntiFold(ANTI_FOLD_SOURCE, ANTI_FOLD_SOURCE).checks
  check(
    '原文照抄：3 项不通过，篇幅偏差仍通过',
    copied.filter((c) => !c.passed).length === 3 && copied[3].passed === true
  )
  const empty = ai.auditAntiFold('', '')
  check(
    '空输入：四项全不通过且给出「改写结果为空」',
    empty.checks.every((c) => !c.passed) && empty.warnings[0] === '改写结果为空'
  )
  check(
    '相同句占比命中 100%',
    (ai.auditAntiFold(ANTI_FOLD_SOURCE, ANTI_FOLD_SOURCE).checks[1].value ?? 0) === 100
  )
  const tooLong = ai.auditAntiFold(
    '短句。',
    '这是一段特别长的文字内容用来测试篇幅偏差的上限判断逻辑是否生效同时也要覆盖下限的情况。'
  )
  check('篇幅偏差上限生效（超长不通过）', tooLong.checks[3].passed === false)
  const tooShort = ai.auditAntiFold(
    '这是一段相对长的原文，用来验证篇幅偏差的下限约束确实在起作用。',
    '短。'
  )
  check('篇幅偏差下限生效（过短不通过）', tooShort.checks[3].passed === false)

  section('8. 版本间差异（批量多版本的核心约束）')
  const samePair = ['今天很愉快，练完很轻松。', '今天很愉快，练完很轻松。']
  const div = ai.auditVariantDiversity(samePair)
  check(
    '两版完全相同 → 版本间差异不通过且给出实测值',
    div[0].passed === false && (div[0].value ?? 0) >= 10
  )
  check(
    '单版本 → 版本间差异通过（无对照）',
    ai.auditVariantDiversity(['只有一版'])[0].passed === true
  )
  check('sharedFragments 能定位重复片段', ai.sharedFragments(samePair).length > 0)
  check(
    'clampVersions 夹逼到 [1, 5]，缺省/0 回落默认 3',
    ai.clampVersions(9) === ai.ANTI_FOLD_MAX_VERSIONS &&
      ai.clampVersions(1) === 1 &&
      ai.clampVersions(0) === ai.ANTI_FOLD_DEFAULT_VERSIONS &&
      ai.clampVersions(undefined) === ai.ANTI_FOLD_DEFAULT_VERSIONS,
    [
      ai.clampVersions(9),
      ai.clampVersions(1),
      ai.clampVersions(0),
      ai.clampVersions(undefined)
    ].join(',')
  )
  check(
    '默认 3 版 / 上限 5 版',
    ai.ANTI_FOLD_DEFAULT_VERSIONS === 3 && ai.ANTI_FOLD_MAX_VERSIONS === 5
  )
}

function checkLocalFallbacks(ai: Engine): void {
  section('9. 本地降级：朋友圈 3 版')
  const drafts = ai.fallbackMomentsDrafts(MOMENTS_CASE)
  check('降级 3 版', drafts.length === 3, String(drafts.length))
  check(
    '版型 id 齐备且有序',
    drafts.map((d) => d.id).join(',') === 'real,pro,convert',
    drafts.map((d) => d.id).join(',')
  )
  check(
    '版型 label 齐备',
    drafts.map((d) => d.label).join(',') === '真实日常版,专业信任版,轻转化版',
    drafts.map((d) => d.label).join(',')
  )
  check(
    '每条降级正文 # 出现 1-3 次',
    drafts.every((d) => {
      const n = (d.content.match(/#/g) ?? []).length
      return n >= 1 && n <= 3
    }),
    drafts.map((d) => (d.content.match(/#/g) ?? []).length).join(',')
  )
  check(
    'none 档降级无 emoji',
    drafts.every((d) => ai.countEmoji(d.content) === 0)
  )
  check(
    '每版都带首条回复',
    drafts.every((d) => d.reply.trim().length >= 10)
  )
  check('转化版带出行动指令', drafts[2].content.includes('#想预约体验可以找我'), drafts[2].content)
  const someEmoji = ai.fallbackMomentsDrafts({ ...MOMENTS_CASE, emojiUsageId: 'medium' })
  check('medium 档降级带 emoji 前缀', ai.countEmoji(someEmoji[0].content) >= 1)

  section('10. 本地降级：小红书 2 篇')
  const notes = ai.fallbackXhsNotes(XHS_CASE)
  check('降级 2 篇', notes.length === 2, String(notes.length))
  check(
    '每篇都有封面文案',
    notes.every((n) => n.coverText.trim().length > 0)
  )
  check(
    '标题与封面文案无 emoji',
    notes.every((n) => ai.countEmoji(n.title) === 0 && ai.countEmoji(n.coverText) === 0)
  )
  check(
    '每篇都有首评',
    notes.every((n) => n.reply.trim().length > 0)
  )
  check(
    '标签 3-8 个且全部以 # 开头',
    notes.every(
      (n) => n.tags.length >= 3 && n.tags.length <= 8 && n.tags.every((t) => t.startsWith('#'))
    ),
    notes.map((n) => n.tags.length).join(',')
  )
  check(
    '标签含同城三要素（城市 / 品牌 / 行业）',
    notes[0].tags.some((t) => t.includes('宁波')) &&
      notes[0].tags.some((t) => t.includes('一麦')) &&
      notes[0].tags.some((t) => t.includes('普拉提')),
    notes[0].tags.join(' ')
  )
}

function checkPromptsAndReports(ai: Engine): void {
  section('11. 朋友圈提示词（system 段逐条硬约束）')
  const messages = ai.buildMomentsMessages(MOMENTS_CASE)
  const system = messages[0].content
  check('system/user 两段', messages[0].role === 'system' && messages[1].role === 'user')
  check('含立场铁律', system.includes(ai.MOMENTS_STANCE_RULE))
  check('含优先级裁决链', system.includes('用户本次修改意见 > 朋友圈内容层本次选择'))
  check('含单条独立字数', system.includes('不是3条合计') && system.includes('每一条朋友圈'))
  check(
    '含 emoji 档位换算或禁用',
    system.includes('平均每50字出现1个') || system.includes('必须为0')
  )
  check('含 # 1-3 次硬约束', system.includes('至少 1 次、最多 3 次'))
  check(
    '含首条回复标记与「不单独分字段」',
    system.includes(ai.FIRST_REPLY_MARKER) && system.includes('首条回复不单独分字段')
  )
  check(
    '含 3 条差异化生成要求',
    system.includes('正好3条朋友圈成稿') && system.includes('分别偏真实日常、专业信任、转化表达')
  )
  check(
    '含目标三行（说明 / 行动指令 / 写法提醒）',
    system.includes('目标行动指令参考：') && system.includes('目标写法提醒：')
  )
  check('含选题写作方向 promptHint', system.includes('围绕一个月内可观察到的小变化'))
  check('含防折叠 5 条（开关开启时）', system.includes('前3行必须有价值'))
  check('含去 AI 味', system.includes('必须去 AI 味儿'))
  check(
    '含图片强关联硬约束',
    system.includes('不能把图片当成无关素材') && system.includes('课前体态照')
  )
  check('含输出 JSON schema', system.includes('"drafts"'))
  check(
    '含定位标题禁令（防止标签写进正文）',
    system.includes('不要出现“偏真实日常 / 偏专业信任 / 偏转化表达”这类标题')
  )
  check(
    '防折叠开关关闭时写「未开启」',
    ai
      .buildMomentsMessages({ ...MOMENTS_CASE, antiFoldEnabled: false })[0]
      .content.includes('防折叠规则：未开启')
  )
  check(
    '单条模式提示词要求 1 条',
    ai
      .buildMomentsMessages({ ...MOMENTS_CASE, draftCount: 1 })[0]
      .content.includes('正好1条朋友圈成稿')
  )

  section('12. 朋友圈质检报告结构')
  const momentsReport = ai.auditMomentsResult(
    MOMENTS_CASE,
    `${messages[0].content}\n\n${messages[1].content}`,
    draftsOf(ai)
  )
  check(
    '报告字段完整',
    momentsReport.title === '测试与前端的匹配度' &&
      typeof momentsReport.statusText === 'string' &&
      Array.isArray(momentsReport.promptChecks) &&
      Array.isArray(momentsReport.draftChecks) &&
      Array.isArray(momentsReport.issues)
  )
  check(
    '提示词 12 项断言全通过',
    momentsReport.promptChecks.length === 12 && momentsReport.promptChecks.every((c) => c.passed),
    momentsReport.promptChecks
      .filter((c) => !c.passed)
      .map((c) => c.label)
      .join(',')
  )
  check(
    '逐条成稿含井号键检查项',
    momentsReport.draftChecks.some((c) => c.label.endsWith('井号键'))
  )
  check(
    '质检为纯函数（两次调用结果完全一致）',
    JSON.stringify(ai.auditMomentsResult(MOMENTS_CASE, system, draftsOf(ai))) ===
      JSON.stringify(ai.auditMomentsResult(MOMENTS_CASE, system, draftsOf(ai)))
  )

  section('13. 小红书提示词与质检报告')
  const xhsMessages = ai.buildXhsMessages(XHS_CASE)
  const xhsSystem = xhsMessages[0].content
  check(
    '含五段输出结构',
    xhsSystem.includes('coverText') &&
      xhsSystem.includes('"title"') &&
      xhsSystem.includes('"content"') &&
      xhsSystem.includes('"tags"') &&
      xhsSystem.includes('"reply"')
  )
  check('封面内容与标题禁 emoji', xhsSystem.includes('封面内容和笔记标题严禁出现表情符号'))
  check('门店 IP 写法进提示词', xhsSystem.includes('本地同城账号：自然加入城市、商圈、到店'))
  const personal = ai.buildXhsMessages({
    ...XHS_CASE,
    profile: { ...XHS_CASE.profile, ipTypeId: 'personal-ip', ipType: '个人IP' }
  })
  check('个人 IP 写法进提示词', personal[0].content.includes('个人IP账号：重点建立'))
  check(
    '同城三要素硬规则 + 城市/品牌示例',
    xhsSystem.includes('同城标签硬规则') &&
      xhsSystem.includes('#宁波') &&
      xhsSystem.includes('#一麦瑜伽')
  )
  check('正文长度档与 800 上限', xhsSystem.includes('300-500字') && xhsSystem.includes('800'))
  check(
    '固定 2 篇且要求风格明显不同',
    xhsSystem.includes('生成数量：2篇') && xhsSystem.includes('两篇风格要明显不同')
  )
  check('客群按 4 项截断（不再对模型撒谎）', personal[1].content.includes('目标客群（最多4项）'))
  const xhsReport = ai.auditXhsResult(
    XHS_CASE,
    `${xhsMessages[0].content}\n\n${xhsMessages[1].content}`,
    notesOf(ai)
  )
  check(
    '小红书报告结构完整',
    Array.isArray(xhsReport.promptChecks) &&
      xhsReport.draftChecks.length > 0 &&
      typeof xhsReport.statusText === 'string'
  )
  check(
    '小红书提示词断言全通过',
    xhsReport.promptChecks.every((c) => c.passed),
    xhsReport.promptChecks
      .filter((c) => !c.passed)
      .map((c) => c.label)
      .join(',')
  )
}

function checkXhsDerivations(ai: Engine): void {
  section('14. 同城标签补全与 IP 推导')
  check(
    'ensureLocalTags 补齐城市 / 品牌 / 行业三要素',
    ai
      .ensureLocalTags(['#瑜伽'], { ipType: 'venue-ip', role: '全职老师', strengths: ['普拉提'] })
      .join(',') === '#瑜伽,#宁波,#一麦瑜伽,#普拉提',
    ai
      .ensureLocalTags(['#瑜伽'], { ipType: 'venue-ip', role: '全职老师', strengths: ['普拉提'] })
      .join(',')
  )
  check(
    '无擅长方向时行业词回落 #私教',
    ai.ensureLocalTags([], { ipType: 'venue-ip', role: '全职老师' }).join(',') ===
      '#宁波,#一麦瑜伽,#私教'
  )
  check(
    '非同城场景原样返回（不硬塞城市词）',
    ai.ensureLocalTags(['#瑜伽'], { ipType: 'personal-ip', localFocus: false }).join(',') ===
      '#瑜伽'
  )
  check(
    'isXhsLocalScenario 三条件任一命中',
    ai.isXhsLocalScenario({ ipType: 'venue-ip' }) &&
      ai.isXhsLocalScenario({ localFocus: true }) &&
      ai.isXhsLocalScenario({ topicGroupId: 'local' }) &&
      !ai.isXhsLocalScenario({ ipType: 'personal-ip' })
  )
  check(
    'xhsIndustryTags 按角色/方向动态选',
    ai.xhsIndustryTags('普拉提教练')[0] === '#普拉提' &&
      ai.xhsIndustryTags('康复教练').includes('#康复')
  )
  check(
    'resolveXhsIpType 兼容中文与 id 两种形态',
    ai.resolveXhsIpType({ ipType: '门店IP' }) === 'venue-ip' &&
      ai.resolveXhsIpType({ ipTypeId: 'personal-ip' }) === 'personal-ip'
  )
  const derived = ai.deriveXhsAccountFromMoments({ ...MOMENTS_CASE.persona, role: '主理人' })
  check('主理人 → 门店 IP', derived.ipType === '门店IP' && derived.ipTypeId === 'venue-ip')
  check('localFocus 不照抄 city（保守默认 false，避免恒为 true）', derived.localFocus === false)
  check(
    '客群按 XHS_AUDIENCE_MAX 截断',
    (derived.audiences ?? []).length <= ai.XHS_AUDIENCE_MAX,
    String((derived.audiences ?? []).length)
  )

  section('15. 人设四维推导（语料分批补，空表不得阻断）')
  const detail = ai.derivePersonaDetail({
    role: '全职老师',
    gender: '女',
    age: '31-35岁',
    position: '全职老师'
  })
  check(
    '角色×性别表命中',
    detail.specialties.includes('普拉提') && detail.specialties.includes('孕产恢复')
  )
  check(
    '未知角色兜底到基础表',
    ai.derivePersonaDetail({ role: '不存在的岗位' }).specialties.length > 0
  )
  check(
    '年龄/职位表为空时不抛错、不阻断',
    Array.isArray(ai.derivePersonaDetail({ role: '', gender: '', age: '', position: '' }).audiences)
  )
}

async function checkEndToEnd(ai: Engine): Promise<void> {
  section('16. 端到端解析（假大模型；三级容错与旧字段兼容）')
  setLLMReply(
    JSON.stringify({
      drafts: [
        {
          id: 'real',
          content: '1、今天课上的一幕\n有个学员找到发力感了\n#教练日常',
          reply: '你今天练完什么感觉？'
        },
        {
          id: 'pro',
          content: '## 专业信任版\n关于呼吸顺序，说点实在的\n#训练频率比强度重要',
          reply: '想练对可以先来做评估。'
        },
        {
          id: 'convert',
          content: '体验课这事很多人卡在第一步\n#想预约体验可以找我',
          reply: '私信我就行。'
        }
      ]
    })
  )
  const resA: MomentsResult = await ai.generateMomentsCopy(MOMENTS_CASE)
  check(
    'JSON 数组 → 3 条成稿',
    resA.drafts.length === 3 && resA.source === 'llm',
    `${resA.drafts.length}/${resA.source}`
  )
  check(
    '剥序号 / ## / 定位标题',
    resA.drafts[0].content.startsWith('今天课上的一幕') &&
      resA.drafts[1].content.startsWith('关于呼吸顺序')
  )
  check('label 由元数据补齐', resA.drafts[2].label === '轻转化版', resA.drafts[2].label)
  check('首条回复保留', resA.drafts[0].reply === '你今天练完什么感觉？')
  check('旧字段 content = 与目标匹配的版型（pro）', resA.content === resA.drafts[1].content)
  check(
    'prompt 可只读查看（system + user）',
    Array.isArray(resA.prompt) && resA.prompt.length === 2
  )

  setLLMReply('{"content":"旧格式正文，只有一个对象","reply":"旧格式回复"}')
  const resB = await ai.generateMomentsCopy(MOMENTS_CASE)
  check(
    '旧单对象格式不回退失败（本地补齐到 3 条）',
    resB.drafts.length === 3 && resB.drafts[0].content === '旧格式正文，只有一个对象'
  )
  check(
    '条数不足时警告文案正确',
    (resB.warning ?? '').includes('模型只返回 1 条'),
    resB.warning ?? ''
  )
  setLLMReply('这段不是 JSON，模型直接写了正文。\n第二行。')
  const resC = await ai.generateMomentsCopy(MOMENTS_CASE)
  check(
    '纯文本回落不失败',
    resC.drafts.length === 3 && resC.drafts[0].content.includes('这段不是 JSON')
  )

  setLLMReply(
    JSON.stringify({
      notes: [
        {
          title: '圆肩驼背怎么练',
          coverText: '圆肩驼背｜先看这个',
          content: '正文一',
          tags: ['#瑜伽', '#体态'],
          reply: '私信我'
        },
        {
          title: '别急着练',
          封面文字: '先看懂，再训练',
          content: '正文二',
          tags: ['#普拉提'],
          reply: ''
        }
      ]
    })
  )
  const resD: XhsResult = await ai.generateXhsNote(XHS_CASE)
  check('小红书 2 篇解析', resD.notes.length === 2 && resD.source === 'llm')
  check(
    'coverText 多别名回落（封面文字）',
    resD.notes[1].coverText === '先看懂，再训练',
    resD.notes[1].coverText
  )
  check(
    '标签确定性补全（≥3 且含城市/品牌）',
    resD.notes[0].tags.length >= 3 &&
      resD.notes[0].tags.some((t) => t.includes('宁波')) &&
      resD.notes[0].tags.some((t) => t.includes('一麦')),
    resD.notes[0].tags.join(' ')
  )
  check('缺失首评用本地兜底', resD.notes[1].reply.length > 0)
  check('旧字段 = notes[0]', resD.title === resD.notes[0].title && resD.tags === resD.notes[0].tags)

  setLLMReply(
    JSON.stringify({
      variants: [
        {
          content: '那个总说肩颈紧的学员，今天突然通了。她说整个人松下来。想约的直接找我。',
          notes: '换开头'
        },
        {
          content: '她练完说整个人都轻了，肩颈也不那么紧。下一次想上课，可以直接私信我。',
          notes: '换句式'
        }
      ]
    })
  )
  const resE: AntiFoldResult = await ai.generateAntiFoldCopy({
    content: ANTI_FOLD_SOURCE,
    versions: 2
  })
  check('防折叠 2 版解析', resE.variants.length === 2 && resE.source === 'llm')
  check(
    'checks = 四项 + 版本间差异',
    resE.variants[0].checks.length === 5 &&
      resE.variants[0].checks.some((c) => c.label === '版本间差异')
  )
  check(
    '合规两版全部通过质检',
    resE.variants.every((v) => v.passed),
    JSON.stringify(resE.variants.map((v) => v.warnings))
  )
  check(
    '旧字段 = variants[0]',
    resE.content === resE.variants[0].content && resE.notes === '换开头'
  )

  section('17. 未接入大模型：warning 而非伪造结果')
  setAiReady(false)
  const resF = await ai.generateAntiFoldCopy({ content: ANTI_FOLD_SOURCE })
  check(
    '防折叠：空结果 + warning',
    resF.variants.length === 0 && !!resF.warning && resF.content === ''
  )
  const resG = await ai.generateMomentsCopy(MOMENTS_CASE)
  check(
    '朋友圈：本地 3 版 + warning',
    resG.source === 'fallback' && resG.drafts.length === 3 && !!resG.warning,
    `${resG.source}/${resG.drafts.length}`
  )
  const resH = await ai.generateXhsNote(XHS_CASE)
  check(
    '小红书：本地 2 篇 + warning',
    resH.source === 'fallback' && resH.notes.length === 2 && !!resH.warning,
    `${resH.source}/${resH.notes.length}`
  )

  section('18. 零网络 / 零后端（边界桩生效证明）')
  const badCalls = fetchCalls.filter((c) => !c.url.startsWith(STUB_BASE_URL))
  check('所有 fetch 调用都指向桩地址', badCalls.length === 0, badCalls.map((c) => c.url).join(','))
  check(
    '请求体携带 system+user 两条消息',
    fetchCalls.every((c) => JSON.parse(c.body).messages.length === 2)
  )
  check(
    '假大模型调用次数 = 本次生成的次数（5 次：朋友圈 3 + 小红书 1 + 防折叠 1）',
    fetchCalls.length === 5,
    String(fetchCalls.length)
  )
  info(`fetch 调用 ${fetchCalls.length} 次，全部指向 ${STUB_BASE_URL}（未触达真实网络）`)

  section('19. 源码级契约（后端白名单与上报门控，防「改一处坏一处」）')
  const aiSource = fs.readFileSync(path.resolve(ROOT, 'src/api/ai.ts'), 'utf-8')
  const knownFeatures = [
    'chat',
    'marketing_moments',
    'marketing_anti_fold',
    'marketing_xhs',
    'training_plan'
  ]
  const declaredFeatures = [...aiSource.matchAll(/featureType:\s*'([a-z_]+)'/g)].map((m) => m[1])
  check(
    'featureType 取值未漂移（必须落在 AiController 白名单内）',
    ['marketing_moments', 'marketing_xhs', 'marketing_anti_fold'].every((f) =>
      declaredFeatures.includes(f)
    ) && declaredFeatures.every((f) => knownFeatures.includes(f)),
    [...new Set(declaredFeatures)].join(',')
  )
  check(
    'fallback 上报受 USE_BACKEND 门控（桩环境下不触达后端）',
    /if \(USE_BACKEND && source === 'fallback'\)/.test(aiSource)
  )
  check(
    '三套生成均复用既有 featureType（无新增 → 无需改后端白名单）',
    new Set(declaredFeatures).size ===
      new Set(declaredFeatures.filter((f) => knownFeatures.includes(f))).size
  )

  section('20. 门禁自检')
  if (process.env.AI_SMOKE_SELFTEST === 'break') {
    info('已启用 AI_SMOKE_SELFTEST=break：故意注入一条失败断言，验证「失败明细 + 非零退出」')
    check(
      '坏样本必须被判为失败（本行应报红，否则说明门禁只会印绿灯）',
      false,
      'AI_SMOKE_SELFTEST=break'
    )
  }
  check(
    '断言总数不少于 127 项（防「空检查」）',
    passCount + failures.length >= 127,
    String(passCount + failures.length)
  )
}

/**
 * t13 回归锁（**仅新增**，不改动上面 141 项既有断言）：
 *  F1 同一响应内成稿 id 必须唯一（模型重复/未知 id 时的兜底）
 *  F2 旗帜 / keycap / ZWJ 组合必须计入 emoji，「不使用」档不得再出假绿
 *  F3 超长输入：不卡死 + 结论里带**可见**降级说明（不静默近似）
 *  F4 本地模板草稿必须如实说明「字数不保证落在所选区间」，质检仍如实标不通过
 */
async function checkRepairRound2(ai: Engine): Promise<void> {
  section('21. t13 回归锁（F1 id 唯一 / F2 emoji 计数 / F3 超长显式降级 / F4 模板如实性）')

  // ---- F1：模型把三条都标成同一 id ----
  setLLMReply(
    JSON.stringify({
      drafts: [
        { id: 'real', content: '第一条：今天课上的一幕\n#教练日常', reply: '回一' },
        { id: 'real', content: '第二条：关于呼吸顺序\n#训练频率比强度重要', reply: '回二' },
        { id: 'real', content: '第三条：体验课这事\n#想预约体验可以找我', reply: '回三' }
      ]
    })
  )
  const dupRes = await ai.generateMomentsCopy(MOMENTS_CASE)
  const dupIds = dupRes.drafts.map((d) => d.id)
  check(
    'F1 重复 id 响应 → 产物 id 仍唯一',
    dupRes.drafts.length === 3 && new Set(dupIds).size === dupIds.length,
    dupIds.join(',')
  )
  check(
    'F1 重复 id 按未占用版型补齐 real/pro/convert',
    dupIds.includes('real') && dupIds.includes('pro') && dupIds.includes('convert'),
    dupIds.join(',')
  )

  // ---- F1：模型返回未知 id ----
  setLLMReply(
    JSON.stringify({
      drafts: [
        { id: 'x1', content: '未知 id 一\n#教练日常', reply: '' },
        { id: 'x2', content: '未知 id 二\n#专业训练', reply: '' },
        { id: 'x3', content: '未知 id 三\n#体验课', reply: '' }
      ]
    })
  )
  const unknownRes = await ai.generateMomentsCopy(MOMENTS_CASE)
  const unknownIds = unknownRes.drafts.map((d) => d.id)
  check(
    'F1 未知 id 响应 → 按下标取版型且唯一',
    new Set(unknownIds).size === unknownIds.length && unknownIds.join(',') === 'real,pro,convert',
    unknownIds.join(',')
  )

  // ---- F2：旗帜 / keycap / ZWJ 组合的计数 ----
  check('F2 旗帜 🇨🇳 计 1 个', ai.countEmoji('🇨🇳加油') === 1, String(ai.countEmoji('🇨🇳加油')))
  check('F2 双旗帜计 2 个', ai.countEmoji('🇨🇳🇯🇵') === 2, String(ai.countEmoji('🇨🇳🇯🇵')))
  check('F2 keycap 1️⃣2️⃣ 计 2 个', ai.countEmoji('1️⃣2️⃣') === 2, String(ai.countEmoji('1️⃣2️⃣')))
  check(
    'F2 ZWJ 组合 👨‍👩‍👧 计 1 个（不按码位多计）',
    ai.countEmoji('👨‍👩‍👧') === 1,
    String(ai.countEmoji('👨‍👩‍👧'))
  )
  check('F2 纯文本仍为 0', ai.countEmoji('今天练完很轻松') === 0)
  info(
    `F2 实测计数：🇨🇳=${ai.countEmoji('🇨🇳')}、🇨🇳🇯🇵=${ai.countEmoji('🇨🇳🇯🇵')}、1️⃣2️⃣=${ai.countEmoji('1️⃣2️⃣')}、👨‍👩‍👧=${ai.countEmoji('👨‍👩‍👧')}、纯文本=${ai.countEmoji('今天练完很轻松')}`
  )

  // ---- F2 假绿回归锁：「不使用」档成稿含旗帜/keycap 必须判不通过 ----
  const noEmojiDrafts: MomentsDraft[] = [
    { id: 'real', label: '真实日常版', content: '今天课上的一幕 🇨🇳\n#教练日常', reply: '回一句' },
    {
      id: 'pro',
      label: '专业信任版',
      content: '关于呼吸顺序 1️⃣\n#训练频率比强度重要',
      reply: '回一句'
    },
    {
      id: 'convert',
      label: '轻转化版',
      content: '体验课这事\n#想预约体验可以找我',
      reply: '回一句'
    }
  ]
  const noneReport = ai.auditMomentsResult(
    { ...MOMENTS_CASE, emojiUsageId: 'none' },
    '任意提示词',
    noEmojiDrafts
  )
  const emojiChecks = noneReport.draftChecks.filter((c) => c.label.endsWith('表情符号数量'))
  check(
    'F2「不使用」档含旗帜/keycap → 质检判不通过（原实现会假绿）',
    emojiChecks.filter((c) => !c.passed).length === 2,
    emojiChecks.map((c) => `${c.passed ? '通过' : '不通过'}`).join(',')
  )
  check(
    'F2 同批纯文本成稿在该档仍通过（不是把全部都判红）',
    emojiChecks.filter((c) => c.passed).length === 1
  )
  check(
    'F2 expectedEmojiRange 在 0 档不退化为恒通过',
    JSON.stringify(ai.expectedEmojiRange(120, 0)) === '{"min":0,"max":0}'
  )

  // ---- F3：超长输入必须不卡死且结论可见 ----
  const longSrc = '今天课上有个学员找到发力感了。'.repeat(400)
  const longOut = '那个学员今天终于找到发力感。'.repeat(400)
  const startedAt = Date.now()
  const longReport = ai.auditAntiFold(longSrc, longOut)
  const elapsedMs = Date.now() - startedAt
  info(
    `超长样本：原文 ${longSrc.length} 字 / 改写 ${longOut.length} 字，质检耗时 ${elapsedMs}ms（精确校验上限 ${ai.ANTI_FOLD_MAX_AUDIT_CHARS} 字）`
  )
  check(
    'F3 超长输入仍返回四项结构',
    longReport.checks.length === 4,
    String(longReport.checks.length)
  )
  check(
    'F3 超长输入结论里带可见降级说明（文本过长 + 仅校验前 N 字）',
    longReport.warnings.some((w) => w.includes('文本过长') && w.includes('仅校验前')),
    longReport.warnings[0] ?? ''
  )
  check('F3 超长输入不得谎称通过（passed=false）', longReport.passed === false)
  check('F3 超长输入不卡死（耗时 < 3000ms）', elapsedMs < 3000, `${elapsedMs}ms`)
  check(
    'F3 版间差异对超长输入写明「按前 N 字比较」',
    ai
      .auditVariantDiversity([longSrc, longOut])[0]
      .detail.includes(String(ai.ANTI_FOLD_MAX_AUDIT_CHARS)),
    ai.auditVariantDiversity([longSrc, longOut])[0].detail
  )

  // ---- F4：本地模板草稿的如实性（模板语料不变，只改"说法"） ----
  setAiReady(false)
  const fbMoments = await ai.generateMomentsCopy(MOMENTS_CASE)
  check(
    'F4 朋友圈本地模板 warning 明说「本地模板草稿 + 字数不保证」',
    (fbMoments.warning ?? '').includes('本地模板草稿') &&
      (fbMoments.warning ?? '').includes('字数不保证'),
    fbMoments.warning ?? ''
  )
  const fbXhs = await ai.generateXhsNote(XHS_CASE)
  check(
    'F4 小红书本地模板 warning 明说「本地模板草稿 + 字数不保证」',
    (fbXhs.warning ?? '').includes('本地模板草稿') && (fbXhs.warning ?? '').includes('字数不保证'),
    fbXhs.warning ?? ''
  )
  check(
    'F4 质检仍如实标不通过（本地模板未被"洗白"）',
    ai
      .auditXhsResult(XHS_CASE, '任意提示词', fbXhs.notes)
      .draftChecks.some((c) => c.label.endsWith('正文字数') && !c.passed)
  )
  const padRes = await (async () => {
    setLLMReply('{"content":"旧格式单条正文","reply":"旧回复"}')
    return ai.generateMomentsCopy(MOMENTS_CASE)
  })()
  check(
    'F4 补齐路径的 warning 也标注「本地模板不保证字数档」',
    (padRes.warning ?? '').includes('本地模板不保证字数档'),
    padRes.warning ?? ''
  )
}

/**
 * t16 回归锁（**仅新增**，不动上面 161 项）：
 *  R1（t14 复核）`auditVariantDiversity` 的**截断降级必须判不通过**（与 `auditAntiFold` 对称，不许假通过）：
 *      判别用例 = 两篇 2218 字、共享 18 字片段落在第 2000 字之后（前缀 LCS=0 看似达标，全长真实 LCS=18）。
 *  R2（t14 复核）`countEmoji` 把 ©️®️™️ 计入属**有意口径**（偏严不放绿），用断言钉住，避免被当 bug 来回改。
 */
function checkT16RegressionLocks(ai: Engine): void {
  section('22. t16 回归锁（R1 截断不假通过 / R2 emoji 口径钉住）')

  // ---------- R1：判别性用例 ----------
  const shared18 = '甲乙丙丁戊己庚辛壬癸子丑寅卯辰巳午未'
  const longA = '甲'.repeat(2200) + shared18
  const longB = '乙'.repeat(2200) + shared18
  const cap = ai.ANTI_FOLD_MAX_AUDIT_CHARS
  check(
    'R1 判别用例构造正确（2218 字 / 2218 字）',
    longA.length === 2218 && longB.length === 2218,
    `${longA.length}/${longB.length}`
  )
  check(
    'R1 全长真实 LCS = 18（≥ 阈值 10 → 真值应为不通过）',
    ai.longestCommonSubstring(longA, longB) === 18,
    String(ai.longestCommonSubstring(longA, longB))
  )
  check(
    'R1 前缀内 LCS = 0（这正是旧实现假通过的成因）',
    ai.longestCommonSubstring(longA.slice(0, cap), longB.slice(0, cap)) === 0,
    String(ai.longestCommonSubstring(longA.slice(0, cap), longB.slice(0, cap)))
  )
  const divLong = ai.auditVariantDiversity([longA, longB])
  check(
    'R1 截断路径两项均判不通过（旧实现为 passed=true 假通过）',
    divLong.length === 2 && divLong.every((c) => !c.passed),
    divLong.map((c) => (c.passed ? '通过' : '不通过')).join(',')
  )
  check(
    'R1 detail 带可见说明「仅比较前 2000 字」+「无法据此判定通过」',
    divLong.every((c) => c.detail.includes(String(cap)) && c.detail.includes('无法据此判定通过')),
    divLong[0].detail
  )
  check(
    'R1 数值达标也不判通过（与 auditAntiFold 截断处理对称）',
    divLong.every((c) => (c.value ?? 0) < (c.threshold ?? 0) && c.passed === false),
    `value=${divLong[0].value} threshold=${divLong[0].threshold} passed=${divLong[0].passed}`
  )
  // 反向锁：修复不得扩大到「把所有版本间差异都判红」
  const divShort = ai.auditVariantDiversity(['今天很愉快，练完很轻松。', '她练完说整个人都轻了。'])
  check('R1 未截断的短文本仍按原逻辑判通过（未扩大打击面）', divShort[0].passed === true, divShort[0].detail)
  const divShortSame = ai.auditVariantDiversity(['今天很愉快，练完很轻松。', '今天很愉快，练完很轻松。'])
  check('R1 未截断的重复短文本仍判不通过', divShortSame[0].passed === false, divShortSame[0].detail)

  // ---------- R2：口径钉住 ----------
  check(
    'R2 口径钉住：©️®️™️✅⭐ = 5（©️®️™️ 计入，属有意取舍）',
    ai.countEmoji('©️®️™️✅⭐') === 5,
    String(ai.countEmoji('©️®️™️✅⭐'))
  )
  check('R2 口径钉住：©®™ = 3（不带 VS16 也计）', ai.countEmoji('©®™') === 3, String(ai.countEmoji('©®™')))
  check(
    'R2 口径理由：❤️=1、⚠️=1（故不采用 Emoji_Presentation 单一口径，否则漏成 0 → 假绿）',
    ai.countEmoji('❤️') === 1 && ai.countEmoji('⚠️') === 1,
    `${ai.countEmoji('❤️')}/${ai.countEmoji('⚠️')}`
  )
  check('R2 典型 emoji 仍正常计数：✅=1、⭐=1', ai.countEmoji('✅') === 1 && ai.countEmoji('⭐') === 1)
  const strictDrafts: MomentsDraft[] = [
    { id: 'real', label: '真实日常版', content: '今天课上的一幕 ©️\n#教练日常', reply: '回一句' },
    { id: 'pro', label: '专业信任版', content: '关于呼吸顺序\n#训练频率比强度重要', reply: '回一句' },
    { id: 'convert', label: '轻转化版', content: '体验课这事\n#想预约体验可以找我', reply: '回一句' }
  ]
  const strictChecks = ai
    .auditMomentsResult({ ...MOMENTS_CASE, emojiUsageId: 'none' }, '任意提示词', strictDrafts)
    .draftChecks.filter((c) => c.label.endsWith('表情符号数量'))
  check(
    'R2 口径联动：「不使用」档含 ©️ 也判不通过（偏严不放绿）',
    strictChecks.filter((c) => !c.passed).length === 1,
    strictChecks.map((c) => (c.passed ? '通过' : '不通过')).join(',')
  )
}

function draftsOf(ai: Engine): MomentsDraft[] {
  return ai.fallbackMomentsDrafts(MOMENTS_CASE)
}

function notesOf(ai: Engine): XhsNote[] {
  return ai.fallbackXhsNotes(XHS_CASE)
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

async function main(): Promise<void> {
  log()
  log(
    `${color.bold}引擎冒烟门禁${color.reset} ${color.gray}(admin-web · 纯函数与本地降级的确定性验证)${color.reset}`
  )

  installFakeLLM()
  const { server, ai } = await loadEngine()

  try {
    checkCatalogAndConstants(ai)
    checkHardRules(ai)
    checkAntiFoldPureFunctions(ai)
    checkLocalFallbacks(ai)
    checkPromptsAndReports(ai)
    checkXhsDerivations(ai)
    await checkEndToEnd(ai)
    // t13 回归锁：放在既有断言之后，保证「既有的 141 项」计数与顺序不受影响
    await checkRepairRound2(ai)
    // t16 回归锁（R1 截断不假通过 / R2 emoji 口径钉住）：同样是"只增不改"
    checkT16RegressionLocks(ai)
  } finally {
    await server.close()
  }

  log()
  if (failures.length > 0) {
    log(`${color.red}${color.bold}✗ 冒烟门禁失败：${failures.length} 项不通过${color.reset}`)
    failures.forEach((message, index) => log(`   ${index + 1}. ${message}`))
    log()
    process.exit(1)
  }
  log(`${color.green}${color.bold}✓ 冒烟门禁通过：${passCount} 项断言全部通过${color.reset}`)
  log(
    `${color.gray}  注：本门禁只覆盖引擎纯函数与本地降级（不启动浏览器、不打真实大模型），端到端 UI 仍需人工走查${color.reset}`
  )
  log()
}

main().catch((error) => {
  log()
  log(
    `${color.red}冒烟门禁执行异常：${error instanceof Error ? error.message : String(error)}${color.reset}`
  )
  if (error instanceof Error && error.stack) log(`${color.gray}${error.stack}${color.reset}`)
  process.exit(1)
})
