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
    config: {
      baseUrl: '${STUB_BASE_URL}',
      apiKey: 'stub-key',
      // 缺省 stub-model；t2 读图用例用 __AI_MODEL__ 切到读图/不读图两类模型（既有用例不受影响）
      model: globalThis.__AI_MODEL__ || 'stub-model',
      temperature: 0.7
    },
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

/**
 * t12：`ai.ts` 现在读 `useUserStore().getUserInfo.roles` 来做运行时文案的角色分流。
 * 真实 store 会 `import { router } from '@/router'`（vue-router 建 hash 历史需 `location`），
 * 在 Node 下必炸 `location is not defined` —— 故必须在此替换成桩。
 * 角色由 `globalThis.__USER_ROLES__` 控制（缺省 []，即非超管）。
 */
const USER_STUB = `
export function useUserStore() {
  // __USER_STORE_THROW__：模拟 store 不可用（pinia 未安装 / 极早期调用），
  // 用于钉住 ai.ts 的**保守兜底方向**（不可用时必须回落非超管，而不是误判超管）。
  if (globalThis.__USER_STORE_THROW__ === true) {
    throw new Error('no active pinia')
  }
  return {
    getUserInfo: {
      roles: globalThis.__USER_ROLES__ || []
    },
    setUserInfo: () => {}
  }
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
    '\0ai-smoke:user': USER_STUB,
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
      if (
        /^@\/store\/modules\/user$/.test(norm) ||
        /src\/store\/modules\/user(\.ts)?$/.test(norm)
      ) {
        return '\0ai-smoke:user'
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
/** 非空时，下一次请求返回该 HTTP 状态 + 该响应体（用于失败分类用例），并在用后清空 */
let llmErrorResponse: { status: number; body: string } | null = null
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
    if (llmErrorResponse) {
      const { status, body } = llmErrorResponse
      llmErrorResponse = null
      return {
        ok: false,
        status,
        text: async () => body,
        json: async () => JSON.parse(body) as unknown
      } as unknown as Response
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({ choices: [{ message: { content: llmReplyText } }] })
    } as unknown as Response
  }
}

/** 让下一次调用失败于给定的 HTTP 状态与响应体（t8：上游失败分类用例） */
function setLLMError(status: number, body: string | Record<string, unknown>): void {
  llmErrorResponse = { status, body: typeof body === 'string' ? body : JSON.stringify(body) }
  ;(globalThis as Record<string, unknown>).__AI_READY__ = true
}

/** 让下一次生成走「已接入大模型」路径，并指定模型返回的原始文本 */
function setLLMReply(text: string): void {
  llmReplyText = text
  ;(globalThis as Record<string, unknown>).__AI_READY__ = true
}

function setAiReady(ready: boolean): void {
  ;(globalThis as Record<string, unknown>).__AI_READY__ = ready
}

/** 切换桩配置里的模型名（t2 读图能力用例专用；不设 = stub-model） */
function setAiModel(model: string | undefined): void {
  const g = globalThis as Record<string, unknown>
  if (model === undefined) delete g.__AI_MODEL__
  else g.__AI_MODEL__ = model
}

/** 最近一次请求体（t2：核对 attachments 是否真的下发了） */
function lastBody(): Record<string, unknown> {
  return JSON.parse(fetchCalls[fetchCalls.length - 1]?.body ?? '{}') as Record<string, unknown>
}

/** 两像素 PNG 的 dataURL（真 dataURL 形态，不打网络） */
const IMG_A =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
const IMG_B =
  'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwcJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPDs0NDT/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='

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
  check(
    'R1 未截断的短文本仍按原逻辑判通过（未扩大打击面）',
    divShort[0].passed === true,
    divShort[0].detail
  )
  const divShortSame = ai.auditVariantDiversity([
    '今天很愉快，练完很轻松。',
    '今天很愉快，练完很轻松。'
  ])
  check('R1 未截断的重复短文本仍判不通过', divShortSame[0].passed === false, divShortSame[0].detail)

  // ---------- R2：口径钉住 ----------
  check(
    'R2 口径钉住：©️®️™️✅⭐ = 5（©️®️™️ 计入，属有意取舍）',
    ai.countEmoji('©️®️™️✅⭐') === 5,
    String(ai.countEmoji('©️®️™️✅⭐'))
  )
  check(
    'R2 口径钉住：©®™ = 3（不带 VS16 也计）',
    ai.countEmoji('©®™') === 3,
    String(ai.countEmoji('©®™'))
  )
  check(
    'R2 口径理由：❤️=1、⚠️=1（故不采用 Emoji_Presentation 单一口径，否则漏成 0 → 假绿）',
    ai.countEmoji('❤️') === 1 && ai.countEmoji('⚠️') === 1,
    `${ai.countEmoji('❤️')}/${ai.countEmoji('⚠️')}`
  )
  check(
    'R2 典型 emoji 仍正常计数：✅=1、⭐=1',
    ai.countEmoji('✅') === 1 && ai.countEmoji('⭐') === 1
  )
  const strictDrafts: MomentsDraft[] = [
    { id: 'real', label: '真实日常版', content: '今天课上的一幕 ©️\n#教练日常', reply: '回一句' },
    {
      id: 'pro',
      label: '专业信任版',
      content: '关于呼吸顺序\n#训练频率比强度重要',
      reply: '回一句'
    },
    {
      id: 'convert',
      label: '轻转化版',
      content: '体验课这事\n#想预约体验可以找我',
      reply: '回一句'
    }
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

/**
 * t2 回归锁（**仅新增**，不改动上面既有断言）：朋友圈「图片素材」走真多模态载荷。
 *  V1 无图 → 提示词含「图片信息：无」，且直接路径请求体键序逐字节不变（无 attachments）
 *  V2 有图 → 捕获请求体 attachments = dataURL 数组（≤3），提示词逐图「图1/图2」+「共N张」，
 *           user 段追加「已随请求附带 N 张」
 *  V3 质检「图片信息」三态：无图通过 / 有图有说明通过 / 有图无说明通过（且不放行空提示词）
 *  V4 读图能力判定：词表命中即支持；无关模型判不支持（不猜）
 *  V5 visionHint：带图 + 不读图模型 → 如实提醒（不承诺"已识别"）；支持读图 / 无图 → 不给提示
 *  V6 诚实性：只声明张数没带载荷（旧调用）时提示词说「共N张」但绝不声称已附带图片
 */
async function checkT2VisionPayload(ai: Engine): Promise<void> {
  section('23. t2 多模态载荷（无图请求体不变 / 有图真下发 dataURL / 逐图强关联 / 读图提醒）')

  // ---------- V4：读图能力判定（显式词表） ----------
  const notVision = ['deepseek-chat', 'qwen-plus', 'qwen-max', 'moonshot-v1-8k', 'glm-4-plus']
  const yesVision = [
    'gpt-4o',
    'gpt-4o-mini',
    'gpt-4.1',
    'qwen3-vl-max',
    'qwen-vl-plus',
    'glm-4v',
    'glm-4v-plus',
    'claude-3-5-sonnet',
    'gemini-1.5-pro',
    'doubao-1.5-vision',
    'internvl2',
    'minicpm-v',
    'yi-vision',
    'step-1v',
    'hunyuan-vision',
    'mimo-vl',
    'deepseek-vl2'
  ]
  check(
    'V4 不读图模型判不支持（deepseek-chat / qwen-plus / qwen-max / moonshot / glm-4-plus）',
    notVision.every((m) => ai.resolveMomentsVisionModel(m).supportsVision === false),
    notVision.map((m) => `${m}=${ai.resolveMomentsVisionModel(m).supportsVision}`).join(',')
  )
  check(
    'V4 读图模型判支持（含契约点名的 gpt-4o / qwen3-vl-max / glm-4v）',
    yesVision.every((m) => ai.resolveMomentsVisionModel(m).supportsVision === true),
    yesVision.filter((m) => !ai.resolveMomentsVisionModel(m).supportsVision).join(',') ||
      yesVision.join(',')
  )
  check(
    'V4 大小写不敏感（GPT-4O / Qwen3-VL-Max / GLM-4V）',
    ai.resolveMomentsVisionModel('GPT-4O').supportsVision &&
      ai.resolveMomentsVisionModel('Qwen3-VL-Max').supportsVision &&
      ai.resolveMomentsVisionModel('GLM-4V').supportsVision
  )
  check(
    'V4 空模型名判不支持（不默认放行）',
    ai.resolveMomentsVisionModel('').supportsVision === false
  )
  check(
    'V4 词表是单一事实来源（导出常量非空，调用点不散写 if）',
    Array.isArray(ai.MOMENTS_VISION_MODEL_TOKENS) &&
      ai.MOMENTS_VISION_MODEL_TOKENS.length >= 8 &&
      ai.MOMENTS_VISION_MODEL_TOKENS.every((r) => r instanceof RegExp)
  )

  // ---------- V1：无图路径（提示词 + 请求体逐字节不变） ----------
  const NO_IMG: MomentsInput = {
    ...MOMENTS_CASE,
    imageSummary: undefined,
    imageCount: undefined,
    imageUrls: undefined
  }
  const noImgMessages = ai.buildMomentsMessages(NO_IMG)
  check(
    'V1 无图 → system 含「图片信息：无」',
    noImgMessages[0].content.includes('图片信息：无'),
    noImgMessages[0].content
      .split('\n')
      .filter((l) => l.includes('图片信息'))
      .join('|')
  )
  check(
    'V1 无图 → 不出现「图片信息：共N张」也不出现逐图行',
    !/图片信息：共\d+张/.test(noImgMessages[0].content) && !/图1：/.test(noImgMessages[0].content),
    noImgMessages[0].content
      .split('\n')
      .filter((l) => l.startsWith('图片信息'))
      .join('|')
  )
  check(
    'V1 无图 → user 段不含强关联声明',
    !noImgMessages[1].content.includes('本次已随请求附带'),
    noImgMessages[1].content.split('\n').slice(-2).join('|')
  )

  setAiReady(true)
  setAiModel(undefined)
  setLLMReply(
    JSON.stringify({ drafts: [{ id: 'real', content: '无图文案\n#教练日常', reply: '回一句' }] })
  )
  const noImgRes = await ai.generateMomentsCopy(NO_IMG)
  const noImgBody = lastBody()
  check(
    'V1 无图 → 请求体无 attachments 键',
    !Object.prototype.hasOwnProperty.call(noImgBody, 'attachments'),
    Object.keys(noImgBody).join(',')
  )
  check(
    'V1 无图 → 请求体键序逐字节不变（model/messages/temperature）',
    JSON.stringify(Object.keys(noImgBody)) === '["model","messages","temperature"]',
    JSON.stringify(Object.keys(noImgBody))
  )
  check(
    'V1 无图 → 请求体原始字符串逐字节等于改动前形态（前后缀 + 无 attachments 子串）',
    (() => {
      const raw = fetchCalls[fetchCalls.length - 1]?.body ?? ''
      return (
        raw.startsWith('{"model":"stub-model","messages":[{"role":"system"') &&
        raw.endsWith('],"temperature":0.7}') &&
        !raw.includes('attachments')
      )
    })(),
    (fetchCalls[fetchCalls.length - 1]?.body ?? '').slice(-40)
  )
  check(
    'V1 无图 → 提示词仍随结果返回（system+user）',
    noImgRes.prompt.length === 2 && noImgRes.prompt[0].content.includes('图片信息：无')
  )
  check('V1 无图 → 不给读图提醒', noImgRes.visionHint === undefined, String(noImgRes.visionHint))

  // ---------- V2：有图路径（真 dataURL 下发 + 逐图强关联） ----------
  const WITH_IMG_NONOTE: MomentsInput = {
    ...MOMENTS_CASE,
    imageSummary: '',
    imageCount: 2,
    imageUrls: [IMG_A, IMG_B]
  }
  const withImgMessages = ai.buildMomentsMessages(WITH_IMG_NONOTE)
  const withImgSystem = withImgMessages[0].content
  check(
    'V2 有图 → system 含「图片信息：共2张」',
    withImgSystem.includes('图片信息：共2张'),
    withImgSystem.split('\n').find((l) => l.startsWith('图片信息')) ?? ''
  )
  check(
    'V2 有图 → 逐图列出「图1：」「图2：」',
    withImgSystem.includes('图1：') && withImgSystem.includes('图2：'),
    withImgSystem.split('\n').find((l) => l.startsWith('图片信息')) ?? ''
  )
  check(
    'V2 有图无说明 → 逐图占位「用户上传图片，未填说明」（不编造图片内容）',
    ai.MOMENTS_IMAGE_NO_NOTE === '用户上传图片，未填说明' &&
      withImgSystem.split(ai.MOMENTS_IMAGE_NO_NOTE).length - 1 === 2,
    String(withImgSystem.split(ai.MOMENTS_IMAGE_NO_NOTE).length - 1)
  )
  check(
    'V2 有图 → user 段追加「本次已随请求附带 2 张图片，正文必须与该图片内容强关联」',
    withImgMessages[1].content.includes('本次已随请求附带 2 张图片，正文必须与该图片内容强关联'),
    withImgMessages[1].content.split('\n').slice(-3).join('|')
  )
  check(
    'V2 有图 → 仍保留 MOMENTS_IMAGE_RULE（既有断言锚点不破）',
    withImgSystem.includes(ai.MOMENTS_IMAGE_RULE) &&
      withImgSystem.includes('不能把图片当成无关素材')
  )
  check(
    'V2 有图有说明 → 说明逐图归位（图1：课前体态照；图2：课后对比）',
    ai
      .buildMomentsMessages({ ...MOMENTS_CASE, imageSummary: '课前体态照；课后对比' })[0]
      .content.includes('图1：课前体态照；图2：课后对比'),
    ai
      .buildMomentsMessages({ ...MOMENTS_CASE, imageSummary: '课前体态照；课后对比' })[0]
      .content.split('\n')
      .find((l) => l.startsWith('图片信息')) ?? ''
  )

  setLLMReply(
    JSON.stringify({ drafts: [{ id: 'real', content: '有图文案\n#教练日常', reply: '回一句' }] })
  )
  await ai.generateMomentsCopy(WITH_IMG_NONOTE)
  const imgBody = lastBody()
  const sent = imgBody.attachments as string[] | undefined
  check('V2 有图 → 请求体 attachments 为数组', Array.isArray(sent), typeof imgBody.attachments)
  check(
    'V2 有图 → attachments 为 dataURL 且与入参逐张一致',
    Array.isArray(sent) &&
      sent.length === 2 &&
      sent[0] === IMG_A &&
      sent[1] === IMG_B &&
      sent.every((u) => /^data:image\//.test(u)),
    Array.isArray(sent) ? `${sent.length} 张 / 首张前缀 ${String(sent[0]).slice(0, 22)}` : '非数组'
  )
  check(
    'V2 有图 → attachments 上限 3 张（传 5 张只下发前 3 张）',
    await (async () => {
      setLLMReply(
        JSON.stringify({ drafts: [{ id: 'real', content: '有图文案\n#教练日常', reply: '回' }] })
      )
      await ai.generateMomentsCopy({
        ...MOMENTS_CASE,
        imageSummary: '',
        imageCount: 5,
        imageUrls: [IMG_A, IMG_A, IMG_A, IMG_A, IMG_A]
      })
      const b = lastBody()
      return Array.isArray(b.attachments) && (b.attachments as string[]).length === 3
    })(),
    '传 5 张'
  )
  check(
    'V2 有图 → 提示词里的张数与真实下发张数一致（都取上限 3）',
    ai
      .buildMomentsMessages({
        ...MOMENTS_CASE,
        imageSummary: '',
        imageCount: 5,
        imageUrls: [IMG_A, IMG_A, IMG_A, IMG_A, IMG_A]
      })[0]
      .content.includes('图片信息：共3张'),
    '传 5 张'
  )

  // ---------- V6：只声明张数、没带载荷（旧调用）必须诚实 ----------
  const declaredOnly: MomentsInput = {
    ...MOMENTS_CASE,
    imageSummary: '课前体态照',
    imageCount: 2,
    imageUrls: undefined
  }
  const declaredMessages = ai.buildMomentsMessages(declaredOnly)
  check(
    'V6 只声明张数 → 提示词说「共2张」并按说明逐图',
    declaredMessages[0].content.includes('图片信息：共2张。图1：课前体态照'),
    declaredMessages[0].content.split('\n').find((l) => l.startsWith('图片信息')) ?? ''
  )
  check(
    'V6 只声明张数 → user 段要求强关联，但**不**声称已附带图片（不撒谎）',
    declaredMessages[1].content.includes('本次配图 2 张，正文必须与该图片内容强关联') &&
      !declaredMessages[1].content.includes('本次已随请求附带'),
    declaredMessages[1].content.split('\n').slice(-3).join('|')
  )

  // ---------- V3：质检「图片信息」三态 ----------
  const drafts = draftsOf(ai)
  const labelOf = (input: MomentsInput, promptText: string): PromptCheck | undefined =>
    ai
      .auditMomentsResult(input, promptText, drafts)
      .promptChecks.find((c) => c.label === '图片信息')
  const noImgCheck = labelOf(NO_IMG, noImgMessages[0].content)
  check(
    'V3 无图 → 质检「图片信息」通过',
    !!noImgCheck && noImgCheck.passed === true,
    `${noImgCheck?.status ?? '缺失'}｜${noImgCheck?.detail ?? ''}`
  )
  const withNoteCheck = labelOf(
    declaredOnly,
    ai.buildMomentsMessages({ ...declaredOnly, imageSummary: '课前体态照；课后对比' })[0].content
  )
  check(
    'V3 有图有说明 → 质检「图片信息」通过且 detail 带说明',
    !!withNoteCheck && withNoteCheck.passed === true,
    `${withNoteCheck?.status ?? '缺失'}｜${withNoteCheck?.detail ?? ''}`
  )
  const noNoteCheck = labelOf(WITH_IMG_NONOTE, withImgSystem)
  check(
    'V3 有图无说明 → 质检「图片信息」通过（不得误报不通过）',
    !!noNoteCheck && noNoteCheck.passed === true,
    `${noNoteCheck?.status ?? '缺失'}｜${noNoteCheck?.detail ?? ''}`
  )
  check(
    'V3 检查项名称保持「图片信息」（视图/审计口径不漂移）',
    ai
      .auditMomentsResult(WITH_IMG_NONOTE, withImgSystem, drafts)
      .promptChecks.filter((c) => c.label === '图片信息').length === 1
  )
  const emptyPromptCheck = labelOf(WITH_IMG_NONOTE, '')
  check(
    'V3 反向锁：提示词为空时有图仍判不通过（三态不是恒通过）',
    !!emptyPromptCheck && emptyPromptCheck.passed === false,
    `${emptyPromptCheck?.status ?? '缺失'}｜${emptyPromptCheck?.detail ?? ''}`
  )
  const noImgEmptyCheck = labelOf(NO_IMG, '')
  check(
    'V3 反向锁：无图但提示词缺「图片信息：无」判不通过',
    !!noImgEmptyCheck && noImgEmptyCheck.passed === false,
    `${noImgEmptyCheck?.status ?? '缺失'}｜${noImgEmptyCheck?.detail ?? ''}`
  )
  check(
    'V3 既有口径不破：MOMENTS_CASE（有说明）质检「图片信息」仍通过',
    (() => {
      const c = ai
        .auditMomentsResult(MOMENTS_CASE, ai.buildMomentsMessages(MOMENTS_CASE)[0].content, drafts)
        .promptChecks.find((x) => x.label === '图片信息')
      return !!c && c.passed === true
    })()
  )

  // ---------- V5：读图提醒（如实，不承诺「已识别」） ----------
  setLLMReply(
    JSON.stringify({ drafts: [{ id: 'real', content: '有图文案\n#教练日常', reply: '回一句' }] })
  )
  setAiModel('deepseek-chat')
  const hintWeak = await ai.generateMomentsCopy(WITH_IMG_NONOTE)
  check(
    'V5 带图 + 不读图模型 → visionHint 如实提醒（含模型名 + 切换指引）',
    !!hintWeak.visionHint &&
      hintWeak.visionHint.includes('deepseek-chat') &&
      hintWeak.visionHint.includes('可能不支持读图') &&
      hintWeak.visionHint.includes('模型配置'),
    hintWeak.visionHint ?? '未给提醒'
  )
  check(
    'V5 visionHint 不承诺「已识别」（措辞只说可能不会被识别）',
    !!hintWeak.visionHint &&
      !hintWeak.visionHint.includes('已识别图片') &&
      hintWeak.visionHint.includes('不会被识别')
  )
  setLLMReply(
    JSON.stringify({ drafts: [{ id: 'real', content: '有图文案\n#教练日常', reply: '回一句' }] })
  )
  setAiModel('gpt-4o')
  const hintStrong = await ai.generateMomentsCopy(WITH_IMG_NONOTE)
  check(
    'V5 带图 + 读图模型 → 不给读图提醒（不误报）',
    hintStrong.visionHint === undefined,
    String(hintStrong.visionHint)
  )
  check(
    'V5 读图模型下图片仍照常下发（判定不影响载荷）',
    Array.isArray(lastBody().attachments) && (lastBody().attachments as string[]).length === 2
  )
  setLLMReply(
    JSON.stringify({ drafts: [{ id: 'real', content: '无图文案\n#教练日常', reply: '回一句' }] })
  )
  setAiModel('deepseek-chat')
  const hintNoImg = await ai.generateMomentsCopy(NO_IMG)
  check(
    'V5 无图 + 不读图模型 → 不给读图提醒（与图无关不发提醒）',
    hintNoImg.visionHint === undefined,
    String(hintNoImg.visionHint)
  )
  setAiModel(undefined)

  // ---------- 源码级：两条路径同构下发 attachments ----------
  const aiSource = fs.readFileSync(path.resolve(ROOT, 'src/api/ai.ts'), 'utf-8')
  check(
    'V7 两条路径（后端 /ai/chat 与演示直连）均按「非空才带 attachments」下发',
    (
      aiSource.match(
        /\.\.\.\((?:opts\?\.)?attachments && [\w.?]*attachments\.length\s*\?\s*\{\s*attachments[^}]*\}\s*:\s*\{\s*\}\)/g
      ) ?? []
    ).length === 2,
    String(
      (
        aiSource.match(
          /\.\.\.\((?:opts\?\.)?attachments && [\w.?]*attachments\.length\s*\?\s*\{\s*attachments[^}]*\}\s*:\s*\{\s*\}\)/g
        ) ?? []
      ).length
    )
  )
  check(
    'V7 generateMomentsCopy 把 imageUrls 交给 callLLM（第 3 参数）',
    /callLLM\(messages, 'marketing_moments', attachments\)/.test(aiSource)
  )

  // ---------- V7b：后端路径（生产真实路径）请求体实测 ----------
  // chatLLMStream 走后端代理 + SSE；桩 fetch 无 body.getReader()，必然抛 AI_NO_STREAM，
  // 但请求体已被 fetchCalls 捕获 —— 用它核对「生产路径」的 attachments 键开关。
  const callStreamBody = async (attachments?: string[]): Promise<Record<string, unknown>> => {
    setAiReady(true)
    try {
      await ai.chatLLMStream([{ role: 'user', content: 'hi' }], {
        featureType: 'marketing_moments',
        attachments
      })
    } catch {
      /* 桩无流式 body：预期抛错，只为捕获请求体 */
    }
    return lastBody()
  }
  const streamNoImg = await callStreamBody(undefined)
  check(
    'V7b 后端路径无图 → 请求体无 attachments 键，且仍是改动前的 4 个键（键序不变）',
    !Object.prototype.hasOwnProperty.call(streamNoImg, 'attachments') &&
      JSON.stringify(Object.keys(streamNoImg)) ===
        '["messages","temperature","featureType","stream"]',
    JSON.stringify(Object.keys(streamNoImg))
  )
  const streamEmpty = await callStreamBody([])
  check(
    'V7b 后端路径空数组 → 同样不带 attachments（非空才带）',
    !Object.prototype.hasOwnProperty.call(streamEmpty, 'attachments'),
    JSON.stringify(Object.keys(streamEmpty))
  )
  const streamWithImg = await callStreamBody([IMG_A, IMG_B])
  check(
    'V7b 后端路径有图 → 请求体 attachments = dataURL 数组（与演示路径同构）',
    Array.isArray(streamWithImg.attachments) &&
      (streamWithImg.attachments as string[]).length === 2 &&
      (streamWithImg.attachments as string[])[0] === IMG_A &&
      /^data:image\//.test((streamWithImg.attachments as string[])[0]),
    JSON.stringify(Object.keys(streamWithImg))
  )
  check(
    'V7b 后端路径 featureType 仍为 marketing_moments（白名单不变）',
    streamWithImg.featureType === 'marketing_moments' && streamWithImg.stream === true
  )
}

function draftsOf(ai: Engine): MomentsDraft[] {
  return ai.fallbackMomentsDrafts(MOMENTS_CASE)
}

function notesOf(ai: Engine): XhsNote[] {
  return ai.fallbackXhsNotes(XHS_CASE)
}

// ---------------------------------------------------------------------------
// t8（repair round 2）：可见性取数路径 / 上游失败分类 / 读图词表 / 新行为钉住
// ---------------------------------------------------------------------------

const VIEWS_DIR = path.join(ROOT, 'src/views/yimai/marketing/modules')

function readView(name: string): string {
  return fs.readFileSync(path.join(VIEWS_DIR, name), 'utf8')
}

/**
 * 模板区（`<template>` 开始到**最后一个** `</template>` 结束）的源码。
 *
 * 必须取 lastIndexOf：SFC 里 `<template #header>` 这类命名插槽也会以 `</template>`
 * 收尾，用 indexOf 会把区域截到第一个插槽就停 —— 扫描面变小 = 漏报（假绿）。
 *
 * **并剥掉 HTML 注释**：注释里的阈值（例如给维护者看的「明细里印着 50-120字」）
 * 不会渲染给任何角色，把它算成泄漏会产出假红 —— 而假红会逼人放宽断言，最终变假绿。
 */
function templateOf(source: string): string {
  const start = source.indexOf('<template>')
  const end = source.lastIndexOf('</template>')
  const region = start >= 0 && end > start ? source.slice(start, end) : source
  return region.replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * 阈值文本是否**只**出现在 `v-if="isSuper"` 门控的标签内。
 *
 * 实现方式：把模板按标签切成 token 流，维护一个「当前是否在 isSuper 元素内」的栈。
 * 这里不做完整 HTML 解析（视图是可信源码，非任意输入），只做足够严格的两件事：
 *   1) 任何以 `<div`/`<span`/`<template` 等开标签且同一标签内带 `v-if="isSuper"`
 *      （或 `v-if` 表达式含 `isSuper`）的位置压栈；
 *   2) 命中关键字时，检查此刻栈内是否存在 isSuper 层。
 */
function isSuperGatedHit(source: string, keyword: RegExp): { hits: number; gated: number } {
  const tpl = templateOf(source)
  const tagRe = /<\/?([A-Za-z][\w-]*)((?:"[^"]*"|'[^']*'|[^>"'])*?)(\/?)>/g
  let depth = 0
  let superDepth = -1
  let hits = 0
  let gated = 0
  let last = 0
  let m: RegExpExecArray | null
  const inspectText = (text: string): void => {
    const k = new RegExp(
      keyword.source,
      keyword.flags.includes('g') ? keyword.flags : `${keyword.flags}g`
    )
    let hit: RegExpExecArray | null
    while ((hit = k.exec(text)) !== null) {
      hits++
      if (superDepth >= 0 && superDepth <= depth) gated++
    }
    // `hit` 仅用于推进 lastIndex（循环条件），值本身不读取；显式置空避免 lint 误报未使用
    void hit
  }
  while ((m = tagRe.exec(tpl)) !== null) {
    inspectText(tpl.slice(last, m.index))
    last = tagRe.lastIndex
    const isClose = m[0].startsWith('</')
    const selfClose = m[3] === '/'
    const attrs = m[2] ?? ''
    const opensSuper = /v-if\s*=\s*"[^"]*\bisSuper\b/.test(attrs)
    if (isClose) {
      depth--
      if (superDepth > depth) superDepth = -1
    } else if (!selfClose) {
      depth++
      if (opensSuper) superDepth = depth
    } else if (opensSuper) {
      // 自闭合元素：其内部不可能有文本，忽略
    }
  }
  inspectText(tpl.slice(last))
  return { hits, gated }
}

/** 收集模板里所有出现在元素文本中的关键字命中（用于报告） */
function hitReport(source: string, keyword: RegExp, label: string): string {
  const { hits, gated } = isSuperGatedHit(source, keyword)
  return `${label}: ${gated}/${hits} 处于 isSuper 门控内`
}

async function checkT8RepairRound2(ai: Engine): Promise<void> {
  section('24. t8 修复轮（可见性取数路径 / 失败分类 / 读图词表 / imageCount 钉住）')

  // =========================================================================
  // F1：非超管可见文本不得出现阈值数值（取数路径口径，不是数 v-if 个数）
  // =========================================================================
  const views = ['moments-tool.vue', 'xhs-tool.vue', 'anti-fold-tool.vue']

  // 契约点名的四个字串 + 本轮新增的两个输出面口径（质检明细 / 阈值说明整段）
  const banned: { re: RegExp; label: string }[] = [
    { re: /50-120\s*字/, label: '50-120字' },
    { re: /1-3\s*次/, label: '1-3 次' },
    { re: /3-8\s*个/, label: '3-8 个' },
    { re: /不超过\s*800\s*字/, label: '不超过 800 字' },
    { re: /按\*\*每条\*\*独立计算|按单条独立计算|按\*\*单条\*\*独立计算/, label: '按单条独立计算' },
    { re: /平均每\s*\d+\s*字\s*1\s*个/, label: '平均每 N 字 1 个' },
    { re: /连续\s*10\s*字|连续\s*\d+\s*字相同/, label: '连续 N 字相同' },
    { re: /相同句占比不超过|占比不超过\s*\d+%/, label: '相同句占比 ≤40%' },
    { re: /篇幅偏差不超过/, label: '篇幅偏差 ±20%' }
  ]
  const leaks: string[] = []
  for (const view of views) {
    const src = readView(view)
    for (const { re, label } of banned) {
      const { hits, gated } = isSuperGatedHit(src, re)
      if (hits > gated) leaks.push(`${view} 的「${label}」有 ${hits - gated} 处未门控`)
    }
  }
  check(
    'R1 非超管可见文本不得出现任何阈值数值（50-120字 / 1-3 次 / 3-8 个 / 不超过 800 字 / 按单条独立计算 / 平均每 N 字 / 连续 N 字 / 占比 / 偏差）',
    leaks.length === 0,
    leaks.join('；') || views.map((v) => hitReport(readView(v), /50-120\s*字/, v)).join(' | ')
  )

  // 结果卡片里的质检面、质检按钮与抽屉必须整体门控（穷举「取数路径」）
  const gatedSurfaces: { view: string; re: RegExp; label: string }[] = [
    { view: 'moments-tool.vue', re: /质检报告/, label: '质检报告按钮' },
    { view: 'moments-tool.vue', re: /mk-check-list/, label: '卡片质检明细' },
    { view: 'xhs-tool.vue', re: /质检报告/, label: '质检报告按钮' },
    { view: 'xhs-tool.vue', re: /mk-check-list/, label: '卡片质检明细' },
    { view: 'anti-fold-tool.vue', re: /mk-check-list/, label: '逐版质检明细' }
  ]
  const notGated = gatedSurfaces.filter(({ view, re }) => {
    const { hits, gated } = isSuperGatedHit(readView(view), re)
    return hits > gated
  })
  check(
    'R1 三视图的质检按钮 / 抽屉 / 卡片明细全部 isSuper 门控（含 anti-fold）',
    notGated.length === 0,
    notGated.map((x) => `${x.view}:${x.label}`).join('；') || '全部门控'
  )

  // 必须保留的「守」（门控过度会伤用户）：来源标签与操作按钮
  const keepSurfaces: { view: string; re: RegExp; label: string }[] = [
    { view: 'moments-tool.vue', re: /大模型生成|本地模板草稿/, label: '来源标签' },
    { view: 'moments-tool.vue', re: />复制文案</, label: '复制文案' },
    { view: 'xhs-tool.vue', re: /复制封面/, label: '复制封面' },
    { view: 'anti-fold-tool.vue', re: /重新改写/, label: '重新改写' }
  ]
  const overGated = keepSurfaces.filter(({ view, re }) => {
    const { hits, gated } = isSuperGatedHit(readView(view), re)
    return hits > 0 && hits === gated
  })
  check(
    'R1 门控未过度：来源标签与操作按钮仍对非超管可见',
    overGated.length === 0,
    overGated.map((x) => `${x.view}:${x.label} 被误门控`).join('；') || '守住了'
  )

  // anti-fold 顶部说明：整句保留用户说明，只门控 thresholdText（t5 发现的那处）
  const antiFoldSrc = readView('anti-fold-tool.vue')
  // 复用门控扫描器（而非另写一个正则）：prettier 会把 `</span>` 的 `>` 折到下一行，
  // 手写 `<\/span>` 字面量匹配会假红 —— 一次假红就会逼人放宽断言，最终变假绿。
  const thresholdGated = isSuperGatedHit(antiFoldSrc, /\{\{\s*thresholdText\s*\}\}/)
  check(
    'R1 anti-fold 顶部说明的 thresholdText（40%/连续10字/±20%）已门控',
    thresholdGated.hits > 0 && thresholdGated.hits === thresholdGated.gated,
    hitReport(antiFoldSrc, /\{\{\s*thresholdText\s*\}\}/, 'thresholdText')
  )
  check(
    'R1 anti-fold 顶部说明仍保留非超管可见的用户说明（未整块藏掉）',
    /会按选定版本数各改写一遍/.test(templateOf(antiFoldSrc)) &&
      /每个版本都会逐项校验/.test(templateOf(antiFoldSrc))
  )

  // ---- 第二轮（真浏览器取证才暴露的第二类泄漏：模板里根本没有数字） ----
  // `MOMENT_LENGTHS[].label` = '标准版 50-120字'、`EMOJI_USAGE_MODES[].label` = '中频使用：50 个字一个'、
  // `XHS_LENGTHS[].display` = '标准内容 · 300-500字' 经 `<ElOption :label="…">` 渲染后，
  // 会作为**下拉框当前选中值**出现在非超管界面上 —— 静态扫描 .vue 源码一个字也扫不到。
  // 处置：展示层去数字（`momentsLengthLabel` / `emojiUsageLabel` / `xhsLengthLabel`），
  // 引擎常量保持原样（它们同时喂提示词，不能改）。
  const stripCases: [string, string, string][] = [
    ['标准版 50-120字', '标准版', '朋友圈长度档'],
    ['简短版 30-50字', '简短版', '朋友圈长度档'],
    ['完整版 100-150字', '完整版', '朋友圈长度档'],
    ['中频使用：50 个字一个', '中频使用', '朋友圈 emoji 档'],
    ['低频使用：100 个字一个', '低频使用', '朋友圈 emoji 档'],
    ['高频使用：25 个字一个', '高频使用', '朋友圈 emoji 档'],
    ['标准内容 · 300-500字', '标准内容', '小红书长度档'],
    ['短笔记 · 200-300字', '短笔记', '小红书长度档'],
    ['深度内容 · 650-800字', '深度内容', '小红书长度档']
  ]
  const badStrip = stripCases.filter(([raw, want, kind]) => {
    const fn = kind.startsWith('小红书')
      ? ai.xhsLengthLabel
      : kind.includes('emoji')
        ? ai.emojiUsageLabel
        : ai.momentsLengthLabel
    const got = fn(raw as never, false as never) as string
    return got !== want
  })
  check(
    'R1b 非超管档位名去掉数值区间（引擎常量保持原样，只在展示层剥离）',
    badStrip.length === 0,
    badStrip.map(([raw, want, kind]) => `${kind}:${raw}→期望${want}`).join('；') ||
      `${stripCases.length} 个档位全部正确`
  )
  const superKeeps = ai.momentsLengthLabel('标准版 50-120字' as never, true as never) as string
  const superKeepsEmoji = ai.emojiUsageLabel(
    '中频使用：50 个字一个' as never,
    true as never
  ) as string
  const superKeepsXhs = ai.xhsLengthLabel('标准内容 · 300-500字' as never, true as never) as string
  check(
    'R1b 超管仍看到完整档位名（证明不是把口径整体删掉，而是按角色分流）',
    superKeeps === '标准版 50-120字' &&
      superKeepsEmoji === '中频使用：50 个字一个' &&
      superKeepsXhs === '标准内容 · 300-500字',
    [superKeeps, superKeepsEmoji, superKeepsXhs].join(' | ')
  )
  check(
    'R1b 无数字的档位名不被误改（不使用 / 自定义字数 原样保留）',
    ai.emojiUsageLabel('不使用' as never, false as never) === '不使用' &&
      ai.momentsLengthLabel('自定义字数' as never, false as never) === '自定义字数'
  )
  // 承载**阈值**的三类档位下拉（长度 / emoji / 正文长度）必须走展示层函数；
  // 灵感主题、目标、选题模板等纯用户选项标签不含阈值，保持直绑即可（不扩大门控面）。
  const rawLabelBinds = ['moments-tool.vue', 'xhs-tool.vue'].filter((v) => {
    const tpl = templateOf(readView(v))
    return /ElOption[^>]*:label="(l|m|n)\.(label|display)"/.test(tpl)
  })
  check(
    'R1b 长度/emoji 档位下拉不再直接绑常量 label/display（必须经展示层函数）',
    rawLabelBinds.length === 0,
    rawLabelBinds.join('；') || '全部经展示层'
  )
  check(
    'R1b 三个视图的档位下拉确实用了展示层函数（正向锁，防止断言空转）',
    /emojiUsageLabel\(m\.label,\s*isSuper\)/.test(readView('moments-tool.vue')) &&
      /momentsLengthLabel\(l\.label,\s*isSuper\)/.test(readView('moments-tool.vue')) &&
      /emojiUsageLabel\(m\.label,\s*isSuper\)/.test(readView('xhs-tool.vue')) &&
      /xhsLengthLabel\(l\.display,\s*isSuper\)/.test(readView('xhs-tool.vue'))
  )

  // =========================================================================
  // F2/F3：上游失败分类 —— 「模型不支持读图」 vs 「其它上游异常」必须可分辨
  // =========================================================================
  // 用**读图型号**（gpt-4o）做桩，避免「模型可能不读图」的主动提醒混进来：
  // 这样 visionHint 一旦非空，就只可能来自失败分类本身。
  setAiModel('gpt-4o')
  // ① 后端代理的视觉 422：结构化标记 + 业务文案
  setLLMError(422, {
    code: 1,
    visionUnsupported: true,
    message: '当前模型不支持图片输入，请在「模型配置」切换到支持读图的模型后重试'
  })
  const visionFail = await ai.generateMomentsCopy({ ...MOMENTS_CASE, imageUrls: [IMG_A] })
  check(
    'F2 stub 返回 422 + visionUnsupported → visionHint 非空且含「不支持图片输入」',
    !!visionFail.visionHint && visionFail.visionHint.includes('不支持图片输入'),
    String(visionFail.visionHint)
  )
  check(
    'F2 视觉失败走本地模板（source=fallback，不伪造大模型产物）',
    visionFail.source === 'fallback' && visionFail.drafts.length === 3,
    `source=${visionFail.source} drafts=${visionFail.drafts.length}`
  )
  check(
    'F2 视觉失败的 warning 也如实说「不支持图片输入」，不说成通用异常',
    !!visionFail.warning && visionFail.warning.includes('不支持图片输入'),
    String(visionFail.warning)
  )

  // ② 其它上游异常：必须说「模型服务异常」，且**不得**冒充「模型不支持图片」
  setLLMError(500, { code: 1, message: 'upstream boom 上游炸了' })
  const otherFail = await ai.generateMomentsCopy({ ...MOMENTS_CASE, imageUrls: [IMG_A] })
  check(
    'F2 其它上游异常 → warning 说「模型服务异常」，不得冒充「不支持图片输入」',
    !!otherFail.warning &&
      otherFail.warning.includes('模型服务异常') &&
      !otherFail.warning.includes('不支持图片输入'),
    String(otherFail.warning)
  )
  check(
    'F2 其它上游异常 → 不设 visionHint（读图型号下两类提示可分辨）',
    !otherFail.visionHint,
    String(otherFail.visionHint)
  )

  // ③ 业务文案提取：优先 JSON 的 message 字段，80 字截断只作最后兜底。
  //    标记落在第 80 字之后、160 字之内 —— 走 80 字截断必丢标记，走业务文案则保留。
  const tailMarker = '完整文案标记TAILMARK'
  const longPlainMsg = `上游报错：${'铺'.repeat(100)}${tailMarker}`
  setLLMError(400, { code: 1, message: longPlainMsg })
  const longOther = await ai.generateMomentsCopy({ ...MOMENTS_CASE, imageUrls: [IMG_A] })
  check(
    'F3 非读图类的长业务文案完整带出（80 字之后的标记仍在 → 不是 String(e).slice(0,80)）',
    !!longOther.warning && longOther.warning.includes(tailMarker),
    String(longOther.warning)
  )
  check(
    'F3 业务文案取自 JSON.message（不含 AI_HTTP_ / LLM_HTTP_ 前缀）',
    !!longOther.warning &&
      !longOther.warning.includes('AI_HTTP_') &&
      !longOther.warning.includes('LLM_HTTP_'),
    String(longOther.warning)
  )

  // ④ 读图类长文案：warning 完整包含「不支持图片输入」，而非 80 字截断后恰好带上
  setLLMError(400, {
    code: 1,
    visionUnsupported: true,
    message: '当前模型不支持图片输入，请在「模型配置」切换到支持读图的模型后重试'
  })
  const longVision = await ai.generateMomentsCopy({ ...MOMENTS_CASE, imageUrls: [IMG_A] })
  check(
    'F3 上游 400 时 warning 完整包含「不支持图片输入」（不是 80 字截断后恰好带上）',
    !!longVision.warning && longVision.warning.includes('不支持图片输入'),
    String(longVision.warning)
  )
  setAiModel(undefined)

  // =========================================================================
  // F4：读图能力词表收窄漏判 —— 逐个预设模型断言判定结果
  // =========================================================================
  const presetExpect: { model: string; vision: boolean }[] = [
    // 预设里「确实不读图」的现役型号（漏判方向是「该提醒没提醒」，故不会误报为 false）
    { model: 'deepseek-chat', vision: false },
    { model: 'deepseek-reasoner', vision: false },
    { model: 'qwen-plus', vision: false },
    { model: 'qwen-max', vision: false },
    { model: 'qwen-turbo', vision: false },
    { model: 'moonshot-v1-8k', vision: false },
    { model: 'moonshot-v1-32k', vision: false },
    { model: 'glm-4-air', vision: false },
    { model: 'glm-4-plus', vision: false },
    { model: 'doubao-pro-32k', vision: false },
    // 读图型号（含本轮新增覆盖：gpt-5 / o3-o4 / claude 全系 / glm-4.1v / qwen2.5-vl / kimi-vl…）
    { model: 'gpt-4o-mini', vision: true },
    { model: 'gpt-4o', vision: true },
    { model: 'gpt-4.1', vision: true },
    { model: 'gpt-5', vision: true },
    { model: 'o3-mini', vision: true },
    { model: 'o4-mini', vision: true },
    { model: 'claude-sonnet-4', vision: true },
    { model: 'claude-opus-4', vision: true },
    { model: 'claude-3-5-sonnet', vision: true },
    { model: 'qwen-vl-plus', vision: true },
    { model: 'qwen3-vl-max', vision: true },
    { model: 'qwen2.5-vl-72b', vision: true },
    { model: 'glm-4v', vision: true },
    { model: 'glm-4v-plus', vision: true },
    { model: 'glm-4.1v', vision: true },
    { model: 'glm-4.5v', vision: true },
    { model: 'kimi-latest-vl', vision: true },
    { model: 'moonshot-v1-vision-preview', vision: true },
    { model: 'doubao-seed-1.6-vision', vision: true },
    { model: 'internvl2', vision: true },
    { model: 'minicpm-v', vision: true },
    { model: 'yi-vision', vision: true },
    { model: 'step-1v', vision: true },
    { model: 'hunyuan-vision', vision: true },
    { model: 'mimo-vl', vision: true }
  ]
  const wrong = presetExpect.filter(
    (c) => ai.resolveMomentsVisionModel(c.model).supportsVision !== c.vision
  )
  check(
    'F4 逐模型能力判定：预设与新增读图型号全部符合预期（漏判立即红）',
    wrong.length === 0,
    wrong
      .map(
        (c) =>
          `${c.model} 期望=${c.vision} 实测=${ai.resolveMomentsVisionModel(c.model).supportsVision}`
      )
      .join('；') || `${presetExpect.length} 个模型全部符合`
  )
  check(
    'F4 词表不误报：空串 / 纯文本型号不判读图',
    ai.resolveMomentsVisionModel('').supportsVision === false &&
      ai.resolveMomentsVisionModel('text-davinci-003').supportsVision === false
  )

  // =========================================================================
  // F7：imageCount 单独使用（旧口径）在新提示词下的行为被钉住
  // =========================================================================
  const legacyOnly = await (async (): Promise<MomentsResult> => {
    setLLMReply(JSON.stringify([{ id: 'real', content: '文案一', reply: '回复一' }]))
    return ai.generateMomentsCopy({ ...MOMENTS_CASE, imageCount: 2 } as MomentsInput)
  })()
  check(
    'F7 只传 imageCount（无 imageUrls）→ 不产生 attachments，且提示词不声称已附带图片',
    !legacyOnly.prompt.some((p) => p.content.includes('本次已随请求附带')),
    legacyOnly.prompt.map((p) => p.content.slice(0, 20)).join('|')
  )
  setAiReady(false)
}

/**
 * t11 加固（**仅新增**）：把「非超管不得看到口径数值」从人工走查升级为机器门禁。
 *
 * 为什么需要门禁
 *   `stripNumericRange`（ai.ts:879）是**前缀相关**的 —— 只对「前缀 + 区间」形状有效。
 *   纯区间档位名（`'200-300字'`）会被剥成 `''`，再经 `|| String(label)` 回落成**原 label**，
 *   于是非超管照样看到数字（t9 实测结论；captain 已公开更正原先「会变空白选项」的判断）。
 *   今天不触发（现 11 个真实档位名全部可辨识），但**没有任何机器门禁**保证将来不触发 ——
 *   本轮 F1 反复出问题（t4 漏 → t5 抓到 → t8 第一轮因「数字在常量里」假绿）的共同根因即此。
 *
 * 判据选择（重要，别被「简化」回 \d{2,}）
 *   契约字面写的是 `\d{2,}`（连续两位数字），但**本项目 F1 验收名单里被点名的两条阈值串
 *   `1-3 次` / `3-8 个` 恰好是两个单位数** —— `\d{2,}` 抓不到它们。故本守卫用 `/\d/`
 *   （含任何数字即判泄漏），它是 `\d{2,}` 的**严格超集**：能过 `/\d/` 的输出必然能过
 *   `\d{2,}`，因此既满足契约字面要求，又堵上单位数区间缺口。现 11 项在 `/\d/` 下 0 误伤
 *   （下方「防误伤」断言逐字节钉住，非肉眼判断）。
 *
 * 另一半「非空」为什么也保留
 *   `strip(...) || String(label)` 对非空 label 恒返回非空，故它对正常档位**不可证伪**；
 *   但它能抓住「有人加了空 label 常量」（见下方活性样本里的 `''`），零成本，故保留 ——
 *   只是守卫的实际效力 100% 落在数字那一半，判据强度必须压在数字那半边。
 *
 * 只加门禁、不改函数语义
 *   纯区间形状「让门禁喊出来」比「悄悄猜一个兜底 label」更安全（t9 判断，本任务采信）：
 *   `'200-300字'` 剥完没有任何可辨识的档位名，硬造一个（如「自定义字数」）属于编造口径。
 */
function checkT11NumericGuard(ai: Engine): void {
  section('25. t11 加固：非超管档位名不得泄漏口径数值（三常量族全量守卫，防将来新增纯区间档位名）')

  /** 泄漏判据：非超管展示结果里出现**任何**数字即视为泄漏（见本节头注释：严于契约字面的 \d{2,}） */
  const leaksDigits = (shown: string): boolean => /\d/.test(shown)
  /** 契约字面口径，仅用于下方「判据强度」断言做对照，**不作为**守卫判据 */
  const twoConsecutiveDigits = (shown: string): boolean => /\d{2,}/.test(shown)

  type Family = { name: string; raw: string[]; show: (raw: string) => string }
  const families: Family[] = [
    {
      name: 'MOMENT_LENGTHS[].label',
      raw: ai.MOMENT_LENGTHS.map((x) => x.label),
      show: (raw) => ai.momentsLengthLabel(raw, false)
    },
    {
      name: 'EMOJI_USAGE_MODES[].label',
      raw: ai.EMOJI_USAGE_MODES.map((x) => x.label),
      show: (raw) => ai.emojiUsageLabel(raw, false)
    },
    {
      name: 'XHS_LENGTHS[].display',
      raw: ai.XHS_LENGTHS.map((x) => x.display),
      show: (raw) => ai.xhsLengthLabel(raw, false)
    }
  ]

  // ---- ① 防「家族被删空 → 遍历型守卫空转」的假绿（遍历型断言的头号风险） ----
  // 只钉**下界**：每族非空、总数不少于今天的 11 项。
  // **不得**写成「=== 11」—— 那会让将来合法新增一个**干净**档位名也报「守卫覆盖面」红，
  // 与守卫职责（只对泄漏喊红）矛盾，且一次假红就会逼人放宽整条断言，最终变假绿。
  const totalItems = families.reduce((n, f) => n + f.raw.length, 0)
  check(
    'R1 守卫覆盖面：三常量族均非空、合计不少于 11 项（防「家族被删空 → 遍历型守卫空转」的假绿）',
    families.every((f) => f.raw.length > 0) && totalItems >= 11,
    families.map((f) => `${f.name}=${f.raw.length}`).join(' / ') + `（合计 ${totalItems}）`
  )

  // ---- ② 主守卫：逐项断言「非超管展示结果既非空、也不含任何数字」 ----
  const guardBad = families.flatMap((f) =>
    f.raw
      .map((raw) => ({ family: f.name, raw, shown: f.show(raw) }))
      .filter((x) => x.shown.trim().length === 0 || leaksDigits(x.shown))
      .map(
        (x) =>
          `${x.family} ${JSON.stringify(x.raw)} → ${JSON.stringify(x.shown)}（${
            x.shown.trim() === '' ? '剥成空串' : '仍含数字'
          }）`
      )
  )
  check(
    'R1 三常量族逐项守卫：非超管展示结果既非空、也不含任何数字（两个单位数的区间如「3-8 个」同样判泄漏）',
    guardBad.length === 0,
    guardBad.length
      ? `点名：${guardBad.join('；')}`
      : families.map((f) => `${f.name} ${f.raw.length} 项`).join(' / ') + ' 全部干净'
  )

  // ---- ③ 防误伤：现 11 项的非超管输出**逐字节**钉住（既证不误伤，也证没有过度剥离） ----
  // 只看结果不 pin 全量清单：将来新增一个**干净**档位名不应变红（只泄漏才红），
  // 故这里逐个「按 raw 查得到 + 输出逐字节相等」核对，而不是断言清单恰好等于这 11 项。
  const knownClean: [string, string, string][] = [
    ['MOMENT_LENGTHS[].label', '简短版 30-50字', '简短版'],
    ['MOMENT_LENGTHS[].label', '标准版 50-120字', '标准版'],
    ['MOMENT_LENGTHS[].label', '完整版 100-150字', '完整版'],
    ['EMOJI_USAGE_MODES[].label', '不使用', '不使用'],
    ['EMOJI_USAGE_MODES[].label', '低频使用：100 个字一个', '低频使用'],
    ['EMOJI_USAGE_MODES[].label', '中频使用：50 个字一个', '中频使用'],
    ['EMOJI_USAGE_MODES[].label', '高频使用：25 个字一个', '高频使用'],
    ['XHS_LENGTHS[].display', '短笔记 · 200-300字', '短笔记'],
    ['XHS_LENGTHS[].display', '标准内容 · 300-500字', '标准内容'],
    ['XHS_LENGTHS[].display', '详细内容 · 500-650字', '详细内容'],
    ['XHS_LENGTHS[].display', '深度内容 · 650-800字', '深度内容']
  ]
  const cleanBad = knownClean.flatMap(([family, raw, want]) => {
    const fam = families.find((f) => f.name === family)!
    if (!fam.raw.includes(raw))
      return [`${family} 里找不到 ${JSON.stringify(raw)}（档位名被改过？）`]
    const got = fam.show(raw)
    return got === want
      ? []
      : [`${family} ${JSON.stringify(raw)} → ${JSON.stringify(got)}，期望 ${JSON.stringify(want)}`]
  })
  check(
    'R1 防误伤：现 11 个真实档位名的非超管输出逐字节不变（0 误伤、且未过度剥离）',
    cleanBad.length === 0,
    cleanBad.length ? cleanBad.join('；') : `${knownClean.length} 项逐字节一致`
  )

  // ---- ④ 超管原样：门禁不得靠「把函数改坏」来达成 ----
  const superBad = families.flatMap((f) => {
    const showSuper =
      f.name === 'MOMENT_LENGTHS[].label'
        ? (raw: string) => ai.momentsLengthLabel(raw, true)
        : f.name === 'EMOJI_USAGE_MODES[].label'
          ? (raw: string) => ai.emojiUsageLabel(raw, true)
          : (raw: string) => ai.xhsLengthLabel(raw, true)
    return f.raw
      .filter((raw) => showSuper(raw) !== raw)
      .map((raw) => `${f.name} ${JSON.stringify(raw)}`)
  })
  check(
    'R1 超管仍看到完整档位名（逐项核对：门禁不是靠删口径达成的）',
    superBad.length === 0,
    superBad.length
      ? `超管输出被改动：${superBad.join('、')}`
      : `${families.reduce((n, f) => n + f.raw.length, 0)} 项超管原样`
  )

  // ---- ⑤ 判据强度被钉住：证明**实际部署的判据**（leaksDigits）确实严于契约字面的 \d{2,} ----
  // 否则将来有人把判据「简化」回 \d{2,} 会**静默**重新打开单位数区间的缺口。
  // 判据本身：这 5 个样本都含数字（真泄漏），但**都不含**连续两位数字 —— 所以只有 /\d/ 抓得到。
  // 关键：必须用**真实的 leaksDigits** 来判（而不是另写一个字面量），否则这条断言
  // 只在一组常量字符串上成立，部署判据被改弱也照样全绿（等于没钉住）。
  const singleDigitOnly = ['3-8 个', '1-3 次', '每5字1个', '限 5-8 张', '建议 3-8 个']
  /** 该样本是否「含数字但逃过 \d{2,}」——正是契约字面口径的缺口面 */
  const escapesContractLiteral = (s: string): boolean => /\d/.test(s) && !twoConsecutiveDigits(s)
  const gapSamples = singleDigitOnly.filter(escapesContractLiteral)
  const caughtByDeployed = singleDigitOnly.filter(leaksDigits)
  check(
    'R1 判据强度被钉住：部署判据对 5 个「单位数区间」样本全部喊红，而「连续两位数字」判据对它们全漏',
    gapSamples.length === singleDigitOnly.length &&
      caughtByDeployed.length === singleDigitOnly.length &&
      twoConsecutiveDigits('200-300字'),
    `部署判据抓到 ${caughtByDeployed.length}/${singleDigitOnly.length} 个（期望全抓）；` +
      `「连续两位数字」判据对其中 ${gapSamples.length}/${singleDigitOnly.length} 个会漏` +
      `（${gapSamples.map((s) => JSON.stringify(s)).join('、')}）`
  )

  // ---- ⑥ 活性：危险形状喂进**真实展示函数**后，守卫必须喊红（不是只在字面量上有效） ----
  const dangerousShapes = [
    '200-300字',
    '3-8 个',
    '1-3 次',
    '每5字1个',
    '限 5-8 张',
    '共 800 字',
    '100 个字一个',
    ''
  ]
  const notFlagged = dangerousShapes.filter((s) => {
    const shown = ai.momentsLengthLabel(s, false)
    return !(shown.trim().length === 0 || leaksDigits(shown))
  })
  check(
    'R1 活性：危险形状（纯区间 / 单位数区间 / 无前导「N 个字一个」/ 空 label）经真实展示函数后，守卫全部喊红',
    notFlagged.length === 0,
    notFlagged.length
      ? `漏判：${notFlagged.map((s) => JSON.stringify(s)).join('、')}`
      : `${dangerousShapes.length} 个样本全部命中`
  )
  info(
    `守卫样本实测：${dangerousShapes
      .map((s) => `${JSON.stringify(s)}→${JSON.stringify(ai.momentsLengthLabel(s, false))}`)
      .join('  ')}`
  )
}

/**
 * t12：**运行时文案**的角色分流门禁（t11 常量层门禁覆盖不到的那一层）。
 *
 * 为什么单列一节
 *   t11 的 `leaksDigits = /\d/` 遍历的是 `MOMENT_LENGTHS[].label` 等**常量族**；
 *   而「本地模板草稿」告警是 `generateMomentsCopy` 里**运行时拼装**的整段文案 ——
 *   `${len.min}-${len.max}` 直接插值进去，属另一层，常量层门禁结构上看不到它。
 *   t10 真浏览器终验正是抓到这条（默认降级路径，AI 未配置/调用失败时用户必然看到）。
 *
 * ⚠️ 判据必须区分「用户自己内容的事实」与「生成口径数值」
 *   契约字面写「非超管 warning 不含两位以上数字」，但契约同时要求保留「实测 N 字」——
 *   而实测字数就是 `82` / `123` 这种两位以上数字。**两者字面互斥**：照字面写 `/\d{2,}/`
 *   会造出一条永远红的断言（正是 t8 §7 / t11 反复警惕的假红→放宽→假绿链条）。
 *   故本节判据 = 先**剥离「实测 N 字」这段用户事实**，再断言**其余部分**不含任何数字
 *   （`/\d/`，与 t11 同强度）且不含区间连字符。口径在断言名里写明，避免被误读成放宽。
 */
async function checkT12RuntimeText(ai: Engine): Promise<void> {
  section('26. t12 运行时文案门禁：本地模板草稿告警的字数档区间只对超管可见（含降级路径实跑）')

  /** 设定当前角色（stub 版 user store 读这个全局） */
  const setRoles = (roles: string[]): void => {
    ;(globalThis as Record<string, unknown>).__USER_ROLES__ = roles
  }
  const asManager = (): void => setRoles(['R_MANAGER'])
  const asSuper = (): void => setRoles(['R_SUPER'])

  /** 「实测 N 字」这类**用户自己内容的事实**：允许出现数字，断言前先剥离 */
  const stripMeasuredFact = (text: string): string =>
    text.replace(/实测[\s\S]*?(?=（所选|），|，请|$)/, '实测[…]')
  /** 剥离用户事实后，其余部分不得含任何数字（与 t11 同强度判据） */
  const leaksDigitsOutsideMeasured = (warning: string): boolean =>
    /\d/.test(stripMeasuredFact(warning))
  /** 区间连字符（`50-120` / `300-500`）——无论出现在哪都必须判泄漏 */
  const leaksRangeHyphen = (warning: string): boolean => /\d+\s*[-~至]\s*\d+/.test(warning)
  /**
   * 从累积 warning 里切出 `localTemplateNote` 那一段。
   * warning 是 `appendWarning(前置说明, 本段)` 拼出来的；前置说明里也有「本地模板草稿」字样
   * 但**不带全角冒号**，故用带冒号的形式取「最后一次」出现的位置即可精确切出本段。
   */
  const noteOf = (warning: string): string => {
    const i = warning.lastIndexOf('本地模板草稿：')
    return i >= 0 ? warning.slice(i) : ''
  }

  /**
   * **改动前**的旧文案实现（从 `git show HEAD:admin-web/src/api/ai.ts` 逐字抄录后就地重算）。
   * 用途：超管侧「逐字节不变」的**真对拍** —— 用引擎返回的同一批 drafts 独立重算旧格式，
   * 与超管实际输出全等比对。不用写死字符串（实测字数随语料变，写死会假红）。
   */
  const refOldMomentsNote = (drafts: MomentsDraft[], min: number, max: number): string => {
    const chars = drafts.map((d) => Array.from(d.content.replace(/\s/g, '')).length)
    const detail = drafts.map((d, i) => `${d.label} ${chars[i]} 字`).join('、')
    const outside = chars.some((c) => c < min || c > max)
    return outside
      ? `本地模板草稿：字数不保证落在所选 ${min}-${max} 字区间（实测 ${detail}），请以此为草稿再编辑`
      : `本地模板草稿：实测 ${detail}（所选 ${min}-${max} 字），仍建议以此为草稿再编辑`
  }
  const refOldXhsNote = (notes: XhsNote[], display: string): string => {
    const chars = notes.map((n) => Array.from(n.content.replace(/\s/g, '')).length)
    return `本地模板草稿：正文字数不保证落在所选「${display}」区间（实测 ${chars.join('/')} 字），请以此为草稿再编辑`
  }

  // 用 standard 档（50-120）复现 captain 真浏览器抓到的那条 —— 与投诉原文同口径
  const stdCase: MomentsInput = { ...MOMENTS_CASE, lengthId: 'standard' }

  // ---------- A. 朋友圈降级路径（isReady=false，用户必然看到的默认分支） ----------
  setAiReady(false)
  asManager()
  const mgrMoments = await ai.generateMomentsCopy(stdCase)
  const mgrWarn = mgrMoments.warning ?? ''
  check(
    'R1 非超管降级 warning：剥离「实测 N 字」用户事实后，其余部分不含任何数字',
    !leaksDigitsOutsideMeasured(mgrWarn),
    `实测输出：${mgrWarn}`
  )
  check(
    'R1 非超管降级 warning：整体不含档位区间连字符（50-120 这类）',
    !leaksRangeHyphen(mgrWarn),
    `实测输出：${mgrWarn}`
  )
  check(
    'R1 非超管降级 warning：不含常量档位名展开（50-120字 / 30-50 / 100-150 等）',
    !/50-120|30-50|100-150/.test(mgrWarn),
    `实测输出：${mgrWarn}`
  )
  // 诚实性三类信息（item3）——非超管侧一条都不能少
  check(
    'R1 非超管降级 warning 诚实性不减：仍含「本地模板草稿」+「字数不保证」+「实测 N 字」',
    mgrWarn.includes('本地模板草稿') &&
      mgrWarn.includes('字数不保证') &&
      /实测[^\n]*\d+\s*字/.test(mgrWarn),
    `实测输出：${mgrWarn}`
  )
  check(
    'R1 非超管降级 warning 未被弱化成「生成成功」类措辞',
    !/生成成功|已完成|生成完毕/.test(mgrWarn) && mgrWarn.includes('草稿'),
    `实测输出：${mgrWarn}`
  )

  // ---------- B. 同路径超管反向锁 + 与改动前逐字节对拍（防「一刀切删掉」） ----------
  asSuper()
  const supMoments = await ai.generateMomentsCopy(stdCase)
  const supWarn = supMoments.warning ?? ''
  const supNote = noteOf(supWarn)
  check(
    'R1 超管降级 warning 反向锁：仍含档位区间数值（50-120）—— 防「一刀切删掉」',
    supWarn.includes('50-120') && leaksRangeHyphen(supWarn),
    `实测输出：${supWarn}`
  )
  check(
    'R1 超管朋友圈段落与「改动前」逐字节一致（用引擎返回的同一批 drafts 独立重算旧格式对拍）',
    supNote === refOldMomentsNote(supMoments.drafts, 50, 120),
    `超管实测=${supNote}\n旧格式重算=${refOldMomentsNote(supMoments.drafts, 50, 120)}`
  )
  check(
    'R1 非超管朋友圈段落 = 超管段落把区间替换为「档位区间」（仅此一处差异，其余逐字节相同）',
    noteOf(mgrWarn) === supNote.replace('所选 50-120 字区间', '所选档位区间'),
    `非超管=${noteOf(mgrWarn)}\n超管改=${supNote.replace('所选 50-120 字区间', '所选档位区间')}`
  )
  check(
    'R1 超管降级 warning：三类如实信息同样齐全（两角色都不是把告警删掉）',
    supWarn.includes('本地模板草稿') &&
      supWarn.includes('字数不保证') &&
      /实测[^\n]*\d+\s*字/.test(supWarn),
    `实测输出：${supWarn}`
  )

  // ---------- C. 非「超出区间」分支（落在区间内）也要分流 ----------
  // 该分支文案是「本地模板草稿：实测 X（所选 50-120 字），仍建议…」，同属运行时拼装
  const insideCase: MomentsInput = {
    ...MOMENTS_CASE,
    lengthId: 'custom',
    customLength: { min: 1, max: 1000 }
  }
  asManager()
  const insideMgr = await ai.generateMomentsCopy(insideCase)
  asSuper()
  const insideSup = await ai.generateMomentsCopy(insideCase)
  check(
    'R1 两个分支都分流：「落在区间内」分支同样非超管无区间数值、超管仍有',
    !leaksRangeHyphen(insideMgr.warning ?? '') && leaksRangeHyphen(insideSup.warning ?? ''),
    `非超管=${insideMgr.warning}｜超管=${insideSup.warning}`
  )
  check(
    'R1 「落在区间内」分支超管侧同样与改动前逐字节一致（对拍）',
    noteOf(insideSup.warning ?? '') === refOldMomentsNote(insideSup.drafts, 1, 1000),
    `实测=${noteOf(insideSup.warning ?? '')}`
  )

  // ---------- D. 小红书降级路径 ----------
  asManager()
  const mgrXhs = await ai.generateXhsNote(XHS_CASE)
  const mgrXhsWarn = mgrXhs.warning ?? ''
  check(
    'R1 非超管小红书降级 warning：不含档位区间（300-500字 这类）',
    !leaksRangeHyphen(mgrXhsWarn) && !/300-500|200-300|500-650|650-800/.test(mgrXhsWarn),
    `实测输出：${mgrXhsWarn}`
  )
  check(
    'R1 非超管小红书降级 warning 诚实性不减：仍含「本地模板草稿」+「字数不保证」+「实测 N 字」',
    mgrXhsWarn.includes('本地模板草稿') &&
      mgrXhsWarn.includes('字数不保证') &&
      /实测[^\n]*\d+\s*字/.test(mgrXhsWarn),
    `实测输出：${mgrXhsWarn}`
  )
  asSuper()
  const supXhs = await ai.generateXhsNote(XHS_CASE)
  const supXhsWarn = supXhs.warning ?? ''
  check(
    'R1 超管小红书降级 warning 反向锁：仍含档位区间与 display（标准内容 · 300-500字 这类数值）',
    leaksRangeHyphen(supXhsWarn) && /300-500/.test(supXhsWarn),
    `实测输出：${supXhsWarn}`
  )
  check(
    'R1 超管小红书段落与「改动前」逐字节一致（对拍）',
    noteOf(supXhsWarn) === refOldXhsNote(supXhs.notes, '标准内容 · 300-500字'),
    `实测=${noteOf(supXhsWarn)}`
  )
  check(
    'R1 非超管小红书段落 = 超管段落把「档位 display」替换为「档位」（仅此一处差异）',
    noteOf(mgrXhsWarn) ===
      noteOf(supXhsWarn).replace('所选「标准内容 · 300-500字」区间', '所选档位区间'),
    `非超管=${noteOf(mgrXhsWarn)}`
  )

  // ---------- E. 诚实性底线：两角色都必须说「字数不保证」（不得双双弱化） ----------
  check(
    'R1 两角色都必须保留「字数不保证」这类如实告警（不得两边一起弱化）',
    mgrWarn.includes('字数不保证') && supWarn.includes('字数不保证'),
    `非超管=${mgrWarn.includes('字数不保证')}｜超管=${supWarn.includes('字数不保证')}`
  )
  // 非超管段落仍须写明「不保证落在所选档位区间」这层意思（只是不带数值）
  check(
    'R1 非超管段落仍明说「不保证落在所选档位区间」这层意思（只是不带数值）',
    noteOf(mgrWarn).includes('字数不保证落在所选档位区间'),
    `非超管段落=${noteOf(mgrWarn)}`
  )

  // ---------- F. 保守兜底方向：store 不可用时必须回落「非超管」，而不是误判超管 ----------
  // 漏判只少露口径；反向（误判超管）才会泄漏。故兜底方向必须是「不显示」。
  ;(globalThis as Record<string, unknown>).__USER_STORE_THROW__ = true
  const throwRes = await ai.generateMomentsCopy(stdCase)
  const throwNote = noteOf(throwRes.warning ?? '')
  check(
    'R1 store 不可用（抛错）时保守回落非超管口径：不含区间数值，且告警仍在（不静默）',
    !leaksRangeHyphen(throwNote) && throwNote.includes('字数不保证'),
    `实测段落=${throwNote}`
  )
  delete (globalThis as Record<string, unknown>).__USER_STORE_THROW__

  setRoles([])
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
    // t2 多模态载荷（无图请求体不变 / 有图真下发 dataURL / 逐图强关联 / 读图提醒）
    await checkT2VisionPayload(ai)
    // t8 修复轮（可见性取数路径 / 失败分类 / 读图词表 / imageCount 旧口径）
    await checkT8RepairRound2(ai)
    // t11 加固：非超管档位名不得泄漏口径数值（三常量族全量守卫，防将来新增纯区间档位名）
    checkT11NumericGuard(ai)
    // t12 运行时文案门禁：本地模板草稿告警的字数档区间只对超管可见（含降级路径实跑）
    await checkT12RuntimeText(ai)
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
