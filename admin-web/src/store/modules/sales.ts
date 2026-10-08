import { defineStore } from 'pinia'
import { useYimaiStore } from './yimai'

export interface SalesStoreInfo {
  name: string
  industry: string
  slogan: string
  intro: string
  address: string
  phone: string
  /**
   * 合规说明（S18）：在 H5 分享页展示的一段对客文案
   * （案例授权声明 / 免责提示 / 展示口径说明）。
   *
   * ⚠️ 与 info 里其余 6 个键不同，后端对它有**类型与尺寸收口**：
   *  - 上限 500 字（`ShareController::COMPLIANCE_NOTICE_MAX_LENGTH`），前端同限；
   *  - 键缺失 / 空白（含全角空格与 U+00A0）/ 非字符串时，后端**不下发该键**（不是空串）。
   *    所以 H5 侧一律按「键不存在」处理缺省，不要用 `?? ''` 去构造空区块。
   *
   * 可选：老快照里没有这个键。
   */
  complianceNotice?: string
}

/** 合规说明长度上限，与后端 `ShareController::COMPLIANCE_NOTICE_MAX_LENGTH` 同源 */
export const COMPLIANCE_NOTICE_MAX_LENGTH = 500

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

/**
 * 分享码形态：16 位小写十六进制。
 *
 * 与服务端签发口径一致（Laravel 侧 ShareController::issueToken() =
 * bin2hex(random_bytes(8))）。历史上这里是编译期常量 'yimai-lvdi'，谁都能猜出来
 * 并直接请求公开接口；现在分享码一律随机，且后端模式下以服务端签发的值为权威。
 */
const SHARE_CODE_PATTERN = /^[0-9a-f]{16}$/

/** 本地兜底分享码（演示模式/尚未发布时用）：不再使用可猜的固定常量 */
function randomShareCode(): string {
  // 旧 WebView 可能没有 crypto，兜底也不能退回到可猜的固定值
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    const bytes = new Uint8Array(8)
    crypto.getRandomValues(bytes)
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  }
  return Array.from({ length: 16 }, () => Math.floor(Math.random() * 16).toString(16)).join('')
}

export function isIssuedShareCode(code: string): boolean {
  return SHARE_CODE_PATTERN.test(code)
}

/* ==========================================================================
 * 谈单工具 · 模板与双端版式（I3）
 *
 * 规格来源：docs/营销工具/01-小程序对照与差距规格.md §3.2(a)(b)(c)(d)(e)
 * 参考实现：SN 小程序逆向 …/pages/sales-tool/{home.js,viewer.js}
 *
 * ⚠️ 模板红线（源：pages/sales-tool/home.wxml:142 的原文承诺）
 *    「模板只控制视觉风格，展示文字全部来自门店填写。」
 *   → `SalesTemplate` **不得**携带任何文案字段。新增模板 = 只加视觉 token
 *     （将来补图片时也只加 `hero*Url`）。门店文字的唯一来源是 `basic.vue` 的
 *     6 个文本字段与 products / coaches / cases 三个模块 —— 换模板不得改动其中任何一个值，
 *     也不得覆盖 `SalesProduct.showPrice` 这类**内容层**开关。
 * ========================================================================== */

/** 双端版式（源：pages/sales-tool/home.js:374-383 `displayModes`） */
export type SalesDisplayMode = 'h5' | 'web'

export const SALES_DISPLAY_MODES: { id: SalesDisplayMode; name: string; desc: string }[] = [
  { id: 'h5', name: 'H5', desc: '会员手机竖屏' },
  { id: 'web', name: '网页版', desc: 'Pad / 电脑横屏' }
]

/**
 * 默认版式：H5 竖屏（源：`home.js:383` `viewMode: "h5"`）。
 *
 * 「默认」的含义是**不传 `mode` 时的兜底值** —— 存量客户链接（URL 里没有 mode）
 * 因此与升级前完全一致；只有显式 `?mode=web` 或宽视口才切到横屏版式。
 */
export const SALES_DEFAULT_DISPLAY_MODE: SalesDisplayMode = 'h5'

/** 视口宽度达到该值时（且 URL 未指定 mode）按网页横屏呈现 */
export const SALES_WIDE_MIN_WIDTH = 1024

export function isSalesDisplayMode(v: unknown): v is SalesDisplayMode {
  return v === 'h5' || v === 'web'
}

/**
 * 解析本次渲染使用的版式（三级）：
 *  1. URL 显式指定且合法 → 用它（预览与「发送网页版」走这条）；
 *  2. 未指定 → 按视口宽度自适应（≥ `SALES_WIDE_MIN_WIDTH` 视为网页横屏）；
 *  3. 拿不到视口信息（服务端渲染 / 测试）→ `SALES_DEFAULT_DISPLAY_MODE`。
 *
 * 第 2 条是 captain 裁定的「公开页按视口自适应宽版式」；第 3 条保证**默认值仍是 h5**。
 */
export function resolveSalesDisplayMode(raw: unknown, viewportWidth?: number): SalesDisplayMode {
  if (isSalesDisplayMode(raw)) return raw
  if (typeof viewportWidth === 'number' && viewportWidth >= SALES_WIDE_MIN_WIDTH) return 'web'
  return SALES_DEFAULT_DISPLAY_MODE
}

export type SalesSectionKey = 'products' | 'coaches' | 'cases' | 'location'

/**
 * 主列板块集合（源：pages/sales-tool/viewer.js:387-397）。
 *
 * 源码语义：竖屏**追加**「我的位置」入口，横屏**移除**它。
 * 一麦对应物是「到店信息」（地址 / 电话 / 到店 CTA）—— 竖屏作为正文末段出现；
 * 横屏时它被提到版式的**侧栏**（不是删掉），所以不出现在主列板块集合里。
 * 因此横屏客户同样能打电话、复制地址，只是它不再是正文的一「节」。
 */
export function salesSections(mode: SalesDisplayMode, hasLocation: boolean): SalesSectionKey[] {
  const base: SalesSectionKey[] = ['products', 'coaches', 'cases']
  return mode === 'h5' && hasLocation ? [...base, 'location'] : base
}

export interface SalesTemplate {
  id: string
  /** 展示名，如 '视觉一'（源：`name`） */
  name: string
  /** 气质描述，仅 UI 展示（源：`desc`） */
  desc: string
  /**
   * 横屏（网页版）主视觉图；源：`heroWebPath` / `heroPath`。
   *
   * 当前为空串：规格 §3.3 明确「I3 只落数据结构与占位模板，不生成/不下载图片」。
   * **渲染位已经接好**：`pickSalesHeroPath()` 有两个真实消费点（见其注释里的调用点清单），
   * 空串 → 不渲染 `<img>`，由色调 token 的渐变兜底；把 URL 填上即出真图，**不需要改组件**。
   */
  heroWebUrl: string
  /** 竖屏（H5）主视觉图；源：`heroH5Path` / `heroPath`。消费方式同上（`pickSalesHeroPath()`） */
  heroH5Url: string
  /** 按钮风格 token，由样式层解释；源：`buttonStyle` */
  buttonStyle: string
  /** 色调 class token，由样式层解释；源：`toneClass` */
  toneClass: string
}

/**
 * 可选模板（**只含视觉**，见上方红线）。
 *
 * 与小程序 15+ 套模板的差别：一麦暂无采购/拍摄的主视觉图，故先落 4 套**纯 token** 模板
 * （色调 + 按钮风格各 4 种）。补齐图片只需给 `hero*Url` 填值 —— 两个渲染位已接好
 * （见 `pickSalesHeroPath()` 注释里的调用点清单），组件与数据结构都不用动。
 */
export const SALES_TEMPLATES: SalesTemplate[] = [
  {
    id: 'yimaiTherapyGreen',
    name: '视觉一',
    desc: '深绿疗愈空间',
    heroWebUrl: '',
    heroH5Url: '',
    buttonStyle: 'pill',
    toneClass: 'therapy'
  },
  {
    id: 'yimaiWarmSand',
    name: '视觉二',
    desc: '暖砂柔光空间',
    heroWebUrl: '',
    heroH5Url: '',
    buttonStyle: 'slant',
    toneClass: 'warm'
  },
  {
    id: 'yimaiCoolModern',
    name: '视觉三',
    desc: '冷感现代空间',
    heroWebUrl: '',
    heroH5Url: '',
    buttonStyle: 'coolline',
    toneClass: 'cool'
  },
  {
    id: 'yimaiWoodRig',
    name: '视觉四',
    desc: '器械木调空间',
    heroWebUrl: '',
    heroH5Url: '',
    buttonStyle: 'touch',
    toneClass: 'wood'
  }
]

/** 默认模板：存量数据（无 `templateId`）与非法模板 id 都回落到它，保证老数据打开不白屏 */
export const DEFAULT_SALES_TEMPLATE_ID = 'yimaiTherapyGreen'

/** 模板分组（源：`home.js:372` 的 `themeGroups`；空分组不展示，与源码 `.filter(items.length)` 同义） */
export const SALES_TEMPLATE_GROUPS: {
  id: string
  label: string
  desc: string
  templateIds: string[]
}[] = [
  {
    id: 'yoga',
    label: '瑜伽',
    desc: '更柔和、轻盈、适合瑜伽场景',
    templateIds: ['yimaiTherapyGreen', 'yimaiWarmSand']
  },
  {
    id: 'pilates',
    label: '普拉提',
    desc: '器械、私教、场馆展示优先',
    templateIds: ['yimaiWoodRig']
  },
  {
    id: 'general',
    label: '通用',
    desc: '冷感 / 中性场景',
    templateIds: ['yimaiCoolModern']
  }
]

/**
 * 模板查表（**唯一入口**）。
 *
 * 模板 id 可能来自 URL query（`?t=…`），所以这里同时是**白名单**：
 * 未知 id 一律回落默认模板 —— 调用方拿到的永远是 `SALES_TEMPLATES` 里的真实对象，
 * 因此下游把 `toneClass` / `buttonStyle` 拼进 class 时不可能带进任意外部字符串。
 */
export function findSalesTemplate(id?: string | null): SalesTemplate {
  const hit = SALES_TEMPLATES.find((t) => t.id === id)
  return (
    hit ?? SALES_TEMPLATES.find((t) => t.id === DEFAULT_SALES_TEMPLATE_ID) ?? SALES_TEMPLATES[0]
  )
}

/** 类型守卫：同时承担「合法模板 id」的判定与 TS 收窄（供 buildSalesShareHash 等消费） */
export function isKnownSalesTemplateId(id: unknown): id is string {
  return typeof id === 'string' && SALES_TEMPLATES.some((t) => t.id === id)
}

/**
 * 双端主视觉选路（源：`pages/sales-tool/home.js:320-322` 的 `f()`）。
 * 源码是三级回落，一麦两级（语义一致）：横屏优先 `heroWebUrl`，竖屏优先 `heroH5Url`。
 *
 * **调用点（两处，改本函数或 `hero*Url` 字段时两处都要一起看）**：
 *   - `views/sales-share/index.vue` —— 客户侧 hero 主视觉（h5 / web 两条版式共用）
 *   - `views/yimai/sales/index.vue` —— 管理端「即时预览」小样
 * 两处都是 `v-if` 控空：返回空串时不渲染 `<img>`，由色调渐变兜底。
 */
export function pickSalesHeroPath(
  t: SalesTemplate | null | undefined,
  mode: SalesDisplayMode
): string {
  if (!t) return ''
  return mode === 'web' ? t.heroWebUrl || t.heroH5Url : t.heroH5Url || t.heroWebUrl
}

/** 样式 token → 安全 class 片段：只允许 `[a-z0-9-]`，避免任何源自 URL 的注入 */
function safeToken(v: unknown): string {
  return String(v ?? '').replace(/[^a-zA-Z0-9-]/g, '')
}

export function salesTemplateToneClass(t: SalesTemplate | null | undefined): string {
  const token = safeToken(t?.toneClass)
  return token ? `tone-${token}` : ''
}

export function salesTemplateButtonClass(t: SalesTemplate | null | undefined): string {
  const token = safeToken(t?.buttonStyle)
  return token ? `btn-${token}` : ''
}

/* ---------------------------------------------------------------- 学员案例 */

/**
 * 授权闸门（一麦独有，**不得**因「对齐小程序」删掉）。
 *
 * 与 `sales-share` 页面、发布 payload 两处各自独立过滤是**有意**的双保险：
 * 公开页与发布 payload 是两条路径，任何一条漏过滤都会泄露未授权案例。
 */
export function visibleCases(cases: SalesCase[] | null | undefined): SalesCase[] {
  return (Array.isArray(cases) ? cases : []).filter((c) => Boolean(c?.authorized))
}

export interface SalesCaseChip {
  /** 筛选键（教练=教练名；目标=门店填写的目标原文） */
  key: string
  label: string
  count: number
}

/**
 * 教练筛选（源：`viewer.js:479-483` `filterCasesByCoach`）。
 *
 * 源码按**教练名**匹配；一麦案例存的是 `coachId`，故按「coachId → 教练名」解析后匹配
 * （语义等价，且避免同名教练误判以外的新问题）。
 * 教练名为空 → 原样返回全部（源码同款「为空则不过滤」）。
 * 传了名字但解析不到任何教练：返回原集合而不是空集 —— 这是展示筛选而非安全闸门，
 * 不因数据缺失把客户看到的内容清空。
 */
export function filterCasesByCoach(
  cases: SalesCase[] | null | undefined,
  coachName?: string | null,
  coaches?: SalesCoach[] | null
): SalesCase[] {
  const list = Array.isArray(cases) ? cases : []
  const name = (coachName ?? '').trim()
  if (!name) return list
  const ids = new Set(
    (Array.isArray(coaches) ? coaches : [])
      .filter((c) => (c?.name ?? '').trim() === name)
      .map((c) => c.id)
  )
  if (!ids.size) return list
  return list.filter((c) => c?.coachId !== '' && c?.coachId != null && ids.has(c.coachId))
}

/** 某位教练的案例数（用于筛选项计数） */
export function countCasesByCoach(
  cases: SalesCase[] | null | undefined,
  coachName: string,
  coaches?: SalesCoach[] | null
): number {
  return filterCasesByCoach(cases, coachName, coaches).length
}

/**
 * 按目标筛选（**不引入任何预设枚举** —— 规格 §5.10 明确不做目标枚举，`goal` 保持自由文本）。
 *
 * 筛选键就是门店自己填的目标原文，因此既不限制运营表达，也不会出现
 * 「案例目标不在枚举里就搜不到」的问题。
 */
export function caseGoalChips(cases: SalesCase[] | null | undefined): SalesCaseChip[] {
  const map = new Map<string, SalesCaseChip>()
  for (const c of Array.isArray(cases) ? cases : []) {
    const goal = (c?.goal ?? '').trim()
    if (!goal) continue
    const hit = map.get(goal)
    if (hit) hit.count += 1
    else map.set(goal, { key: goal, label: goal, count: 1 })
  }
  return [...map.values()]
}

export function filterCasesByGoal(
  cases: SalesCase[] | null | undefined,
  goal?: string | null
): SalesCase[] {
  const list = Array.isArray(cases) ? cases : []
  const key = (goal ?? '').trim()
  if (!key) return list
  return list.filter((c) => (c?.goal ?? '').trim() === key)
}

/** 案例卡主标题（源：`home.js:1440` —— `goal` 是主标题） */
export function caseCardTitle(c: SalesCase | null | undefined, index: number): string {
  const goal = (c?.goal ?? '').trim()
  return goal || `案例${index + 1}`
}

/**
 * 案例卡副标题（源：`home.js:1440` 的 `case-meta` + `viewer.js:475` `getCaseDurationLabel`）。
 *
 * 一麦 `SalesCase` 没有「案例名」字段（后端 cases 元素白名单也无此键），
 * 故副标题取「指导教练」，教练未关联时回落最近一个阶段节点（「训练至第12周」）。
 */
export function caseCardMeta(c: SalesCase | null | undefined, coachName?: string | null): string {
  const name = (coachName ?? '').trim()
  if (name) return `指导教练：${name}`
  const stages = Array.isArray(c?.stages) ? c.stages : []
  for (let i = stages.length - 1; i > 0; i--) {
    const d = (stages[i]?.duration ?? '').trim()
    if (d) return `训练至${d}`
  }
  return ''
}

/* ---------------------------------------------------------------- 产品与价目 */

/**
 * 价目表默认表头 —— **单一定义**（I3 收口 T-11 / §5.11）。
 *
 * 此前 `products.vue:103` 用 3 列、store 的 seed 用 4 列，导致「新建产品」与
 * 「首次打开」两种表头并存。按 captain 裁定统一为 **4 列**：一麦卡项的有效期是核心信息，
 * 3 列是漏项；store 的两份 seed 本来就带「有效期」。
 */
export const SALES_DEFAULT_PRICE_COLS = ['卡项', '次数', '有效期', '标准价']

/** 新建产品的空壳（表头与行数都由 `SALES_DEFAULT_PRICE_COLS` 派生，杜绝再次漂移） */
export function emptySalesProduct(id: number): SalesProduct {
  return {
    id,
    name: '',
    desc: '',
    showPrice: true,
    cols: [...SALES_DEFAULT_PRICE_COLS],
    rows: [new Array(SALES_DEFAULT_PRICE_COLS.length).fill('')]
  }
}

export interface SalesPriceTable {
  cols: string[]
  rows: string[][]
}

/**
 * 单个产品的**独立**价目表（每个产品用自己的 `cols` / `rows`）。
 *
 * 返回 `null` 表示「不该渲染表格」，三种情形：
 *  1. `showPrice === false` —— 每产品独立显隐是一麦领先项，隐藏时客户看到
 *     「价目详情请到店咨询」（文案由页面提供），**不得**因为换模板而改变；
 *  2. 表头为空（编辑期删到 0 列）；
 *  3. 去空后没有任何有效行 —— 渲染一个只有表头的空表比「到店咨询」更糟。
 * 顺带把行补齐 / 截断到表头列数，避免编辑期列数不一致导致单元格错位。
 */
export function productPriceTable(p: SalesProduct | null | undefined): SalesPriceTable | null {
  if (!p || !p.showPrice) return null
  const cols = (Array.isArray(p.cols) ? p.cols : []).map((c) => String(c ?? ''))
  if (!cols.length) return null
  const rows = (Array.isArray(p.rows) ? p.rows : [])
    .map((r) => {
      const cells = (Array.isArray(r) ? r : []).map((c) => String(c ?? ''))
      while (cells.length < cols.length) cells.push('')
      return cells.slice(0, cols.length)
    })
    .filter((r) => r.some((c) => c.trim() !== ''))
  if (!rows.length) return null
  // 尾部「表头与所有行都为空」的列直接不渲染：编辑期留下的空列不该出现在客户页
  let width = cols.length
  while (
    width > 0 &&
    cols[width - 1].trim() === '' &&
    rows.every((r) => r[width - 1].trim() === '')
  ) {
    width -= 1
  }
  if (!width) return null
  return { cols: cols.slice(0, width), rows: rows.map((r) => r.slice(0, width)) }
}

/* ------------------------------------------------- 分享链接 / 草稿预览语义 */

export type SalesShareAction = 'preview' | 'publish'

/**
 * 是否草稿预览请求（`?preview=1`）。
 *
 * 预览 = **零副作用**（对齐源：`pages/sales-tool/home.js:390-398` 的 `previewReady`）：
 * 不 `publishShare`、不改 `share.enabled`、不写「发布」审计、不计访问量。
 */
export function isSalesPreviewQuery(v: unknown): boolean {
  const s = Array.isArray(v) ? v[0] : v
  return s === '1' || s === 'true'
}

/**
 * 草稿预览的第二道门：**只有本浏览器持有同一个分享码时才启用**。
 *
 * 为什么需要：预览会把数据源从「服务端快照」切成「本机草稿」。
 * 若任何访客在任何链接后加个 `?preview=1` 就能看到草稿分支，
 * 客户会在别人的正式链接上看到**本机**的演示/陈旧数据 —— 比看不到更糟。
 * 本机 store 的码在发布成功时被换成服务端签发的权威码（`setShareCode`），
 * 所以「URL 里的码 === 本机码」恰好等价于「这是我在自己浏览器里打开的自己的链接」。
 */
export function canUseDraftPreview(routeCode: string, localCode: string): boolean {
  return Boolean(routeCode) && routeCode === localCode
}

export interface SalesShareLinkOptions {
  code: string
  mode?: SalesDisplayMode
  templateId?: string
  /** 草稿预览（`?preview=1`） */
  preview?: boolean
}

/**
 * 拼分享页 hash（含版式 / 模板 / 预览参数）。
 *
 * **默认值一律不写进 URL** —— 这是「现有链接行为不变」的结构性保证：
 * 默认版式（h5）+ 默认模板 + 非预览 = `#/s/{code}`，与升级前逐字节相同。
 *
 * 为什么版式与模板走 URL 而不是快照：后端 `ShareController::sanitizeSalesPayload()`
 * 的顶层白名单只有 `share|info|products|coaches|cases`，且 `share` 容器被**重建**为
 * `['enabled','code','views']`（`ShareController.php:773`）—— 任何额外的视觉字段都会
 * 被静默剥掉。故视觉选择随链接传递（客户打开即生效），内容与权威码仍由服务端决定。
 */
export function buildSalesShareHash(opts: SalesShareLinkOptions): string {
  const qs = new URLSearchParams()
  if (opts.preview) qs.set('preview', '1')
  if (isSalesDisplayMode(opts.mode) && opts.mode !== SALES_DEFAULT_DISPLAY_MODE) {
    qs.set('mode', opts.mode)
  }
  // 只让**已知**模板 id 出链接：未知 id 由 findSalesTemplate 回落默认，不写进 URL
  if (isKnownSalesTemplateId(opts.templateId) && opts.templateId !== DEFAULT_SALES_TEMPLATE_ID) {
    qs.set('t', opts.templateId)
  }
  const q = qs.toString()
  return `#/s/${encodeURIComponent(String(opts.code ?? ''))}${q ? `?${q}` : ''}`
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
    {
      id: 1,
      name: '精品白领年卡',
      desc: '每周3次精品小班，适合久坐上班族建立规律练习。',
      showPrice: true,
      // 表头引用单一定义（SALES_DEFAULT_PRICE_COLS），不再各写一份字面量
      cols: [...SALES_DEFAULT_PRICE_COLS],
      rows: [
        ['年卡（3次/周）', '不限次·限每周3次', '12个月', '¥6800'],
        ['半年卡', '不限次·限每周3次', '6个月', '¥3980']
      ]
    },
    {
      id: 2,
      name: '私教课程',
      desc: '一对一评估定制，针对体态改善、产后修复与肩颈腰背。',
      showPrice: true,
      cols: [...SALES_DEFAULT_PRICE_COLS],
      rows: [
        ['VIP私教', '50节', '24个月', '¥22500'],
        ['私教小班', '10节', '12个月', '¥3200']
      ]
    },
    {
      id: 3,
      name: '新客体验',
      desc: '首次到店体验课，含体态评估与练习建议。',
      showPrice: false,
      // 非卡项产品的表头可以完全不同 —— 这正是「每个产品独立价目表」的意义，
      // 表头默认值只在**新建**产品时给出，不强制所有产品同构。
      cols: ['项目', '时长', '说明'],
      rows: [['体验课', '60分钟', '新客专享·需预约']]
    }
  ]
}

function seedCoaches(): SalesCoach[] {
  return [
    {
      id: 1,
      name: '婷婷',
      title: '资深教销导师',
      tags: ['体态改善', '续费沟通', '小班教学'],
      intro: '8年教龄，擅长把专业目标翻译成学员听得懂的练习计划。'
    },
    {
      id: 2,
      name: '黄敏',
      title: '普拉提主教练',
      tags: ['核心床', '产后修复', '呼吸'],
      intro: '器械普拉提方向，注重中立位与发力顺序的细节打磨。'
    },
    {
      id: 3,
      name: '冰璐',
      title: '私教导师',
      tags: ['减脂塑形', '私教陪跑'],
      intro: '陪伴式教学风格，擅长帮零基础学员建立信心与节奏。'
    },
    {
      id: 4,
      name: '芷晴',
      title: '小班教练',
      tags: ['内观流', '团课氛围'],
      intro: '课堂感染力强，内观流主题课广受会员好评。'
    }
  ]
}

function seedCases(): SalesCase[] {
  return [
    {
      id: 1,
      coachId: 1,
      goal: '改善骨盆前倾与肩颈紧张',
      desc: '办公室人群典型体态问题，通过12周小班+居家作业结合逐步改善。',
      authorized: true,
      stages: [{ duration: '' }, { duration: '第4周' }, { duration: '第12周' }]
    },
    {
      id: 2,
      coachId: 2,
      goal: '产后核心恢复',
      desc: '产后8个月妈妈，从呼吸重建开始到核心床训练，恢复腹部力量。',
      authorized: true,
      stages: [{ duration: '' }, { duration: '第6周' }]
    }
  ]
}

interface SalesState {
  info: SalesStoreInfo
  products: SalesProduct[]
  coaches: SalesCoach[]
  cases: SalesCase[]
  share: SalesShare
  /** 当前视觉模板 id（只影响展示视觉，不携带任何文案，见文件上方红线） */
  templateId: string
  /** 当前双端版式：h5 竖屏 / web 网页横屏 */
  displayMode: SalesDisplayMode
}

/** 版式展示名（UI 文案单一定义） */
export function salesDisplayModeName(mode: SalesDisplayMode): string {
  return SALES_DISPLAY_MODES.find((m) => m.id === mode)?.name ?? SALES_DISPLAY_MODES[0].name
}

export const useSalesStore = defineStore(
  'salesStore',
  () => {
    const state = ref<SalesState>({
      info: { ...SEED_INFO },
      products: seedProducts(),
      coaches: seedCoaches(),
      cases: seedCases(),
      // 分享码随机生成（不再用可猜的编译期常量）；后端模式下开启分享时
      // 以服务端签发并返回的 token 为准，见 setShareCode()
      share: { enabled: true, code: randomShareCode(), views: 0 },
      templateId: DEFAULT_SALES_TEMPLATE_ID,
      displayMode: SALES_DEFAULT_DISPLAY_MODE
    })

    function audit(action: string, targetLabel: string, detail: string) {
      try {
        useYimaiStore().writeAudit(
          action,
          '谈单工具',
          0,
          targetLabel,
          state.value.info.name.includes('东部') ? '东部店' : '绿地店',
          detail
        )
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
      audit(
        '修改',
        '产品与价目表',
        `共${products.length}个产品；价目展示开关：${
          products
            .filter((p) => p.showPrice)
            .map((p) => p.name)
            .join('、') || '无'
        }`
      )
    }

    function saveCoaches(coaches: SalesCoach[]) {
      state.value.coaches = coaches.map((c) => ({ ...c }))
      audit('修改', '推荐教练', `当前${coaches.length}位：${coaches.map((c) => c.name).join('、')}`)
    }

    function saveCases(cases: SalesCase[]) {
      state.value.cases = cases.map((c) => ({ ...c }))
      audit(
        '修改',
        '学员案例',
        `当前${cases.length}个案例；未授权案例不进入分享页：${cases.filter((c) => !c.authorized).length}个`
      )
    }

    function setShareEnabled(enabled: boolean) {
      state.value.share.enabled = enabled
      audit(
        '修改',
        '分享设置',
        `分享链接${enabled ? '已开启' : '已停用'}；访问量 ${state.value.share.views}`
      )
    }

    /**
     * 采用服务端签发/返回的分享码。
     *
     * 发布成功后后端会回传权威 token，这里落库，保证「预览/发送 H5」用的链接
     * 与公开接口认的码一致（分享页按 route.params.code === data.share.code 判定有效性，
     * 两者不一致会一直显示「链接已失效」）。
     * 传空表示丢弃当前码并重新随机生成（旧链接失效）。
     */
    function setShareCode(code: string) {
      const next = code.trim()
      state.value.share.code = next && isIssuedShareCode(next) ? next : randomShareCode()
      audit('修改', '分享设置', `分享码已更新，旧链接失效`)
    }

    function registerView() {
      state.value.share.views += 1
    }

    /**
     * 切换视觉模板。
     *
     * 只改 `templateId` 一个字段 —— **不触碰** info / products / coaches / cases 的任何值，
     * 也不触碰 `showPrice` 这类内容层开关。审计文案里写明这一点，便于事后核对
     * 「换模板没有连带改内容」（模板红线，见文件上方注释）。
     */
    function setTemplateId(id: string) {
      const next = findSalesTemplate(id).id
      if (state.value.templateId === next) return
      state.value.templateId = next
      audit(
        '修改',
        '分享设置',
        `视觉模板切换为「${findSalesTemplate(next).name}」（仅视觉，内容未改动）`
      )
    }

    /** 切换双端版式（h5 / web）。同样只影响呈现，不影响内容与服务端分享态。 */
    function setDisplayMode(mode: SalesDisplayMode) {
      if (!isSalesDisplayMode(mode) || state.value.displayMode === mode) return
      state.value.displayMode = mode
      audit('修改', '分享设置', `双端版式切换为「${salesDisplayModeName(mode)}」`)
    }

    return {
      state,
      updateInfo,
      saveProducts,
      saveCoaches,
      saveCases,
      setShareEnabled,
      setShareCode,
      setTemplateId,
      setDisplayMode,
      registerView
    }
  },
  {
    persist: {
      key: 'yimai-sales-store',
      storage: localStorage,
      // 历史持久化数据里存着可猜的固定分享码（'yimai-lvdi'）。升级后必须自愈，
      // 否则老浏览器上的 localStorage 会一直把那个常量当作对外链接的码。
      afterHydrate: ({ store }) => {
        const s = store as unknown as { state: SalesState }
        if (!isIssuedShareCode(s.state.share.code)) {
          s.state.share.code = randomShareCode()
        }
        // 本轮的模板 / 版式字段在升级前的持久化数据里不存在（undefined）。
        // 不修复会让 findSalesTemplate(undefined) → 默认模板（页面不白屏），
        // 但 `displayMode` 会以 undefined 参与 salesSections() 判断而落到横屏分支，
        // 所以这里显式归一，保证存量浏览器升级后仍是「H5 竖屏」。
        if (!isKnownSalesTemplateId(s.state.templateId)) {
          s.state.templateId = DEFAULT_SALES_TEMPLATE_ID
        }
        if (!isSalesDisplayMode(s.state.displayMode)) {
          s.state.displayMode = SALES_DEFAULT_DISPLAY_MODE
        }
      }
    }
  }
)
