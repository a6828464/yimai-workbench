<script setup lang="ts">
/**
 * 品牌首页（启动首屏）
 *
 * 定位：**对外门面 + 内部入口**。顾客打开看到的是品牌，不需要知道这是员工系统；
 * 只有底部 slogan 是内部人的入口（看见·包容·超越）。
 *
 * 为什么单独做一页而不是改工作台：
 *  - 顾客不该一进来就撞上「待办 / 任务 / 审批」；
 *  - 首页要全屏图，而工作台是 tab 页，tab 页有 tabBar 占位，做不出干净的全屏。
 *
 * 黑条修法（不是视觉调参）：
 *  - 根节点用 fixed 铺满视口，不靠 min-height:100vh 撑（它在有安全区的机型上永远差一截）；
 *  - page 背景同时给 background / backgroundColorTop / backgroundColorBottom 三处深色，
 *    露不出默认底色（深色模式下默认是黑的，这正是原来那条黑条的来源）；
 *  - 底部 padding 吃 safe-area-inset-bottom，Home 指示条不压字。
 */
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
// 弹窗配置：storage 走统一适配层（utils/storage 的 getJSON/setJSON，不直接碰 uni.*Sync）；
// 请求只要基础地址与 token —— 注意**不能**用 http.ts 的 http.get，它的 401 会 reLaunch 登录页
import { getJSON, setJSON } from '@/utils/storage'
import { API_BASE, getToken } from '@/utils/http'

const user = useUserStore()

/**
 * 约课入口的两种落地方式（二选一，见 goBook）：
 *  ① 填了 VITE_BOOK_APPID → navigateToMiniProgram 真跳转（最优，但需要对方给 AppID）；
 *  ② 没填 → 弹层放大门店小程序码，长按识别跳转（微信原生能力，不需要 AppID）。
 *
 * 门店顺序与码图、链接严格一一对应，改动时三处必须同改。
 */
const BOOK_APPID = (import.meta.env.VITE_BOOK_APPID ?? '') as string
/**
 * 跳转失败回执。
 *
 * navigateToMiniProgram 的失败有多种原因（未关联 / 未授权 / 用户取消 / AppID 错），
 * 全都塞进一个 toast 会被截断，所以失败时用 modal 显示**原文 errMsg**：
 * 真机上的一点截图就能定位是哪一类，不需要连开发者工具看 console。
 */
const jumpErr = ref('')

const BRAND_ID = 'M01_V1o4QXEzd0wyRmM9'

/**
 * 约课小程序（wx49cc065702f6beac）的进入路径 —— 由随心瑜工作人员给出。
 *
 * ⚠ navigateToMiniProgram 的 path **必须带完整 query**（brand_id + venue_id）。
 * 只传 appId 不传 path 会落到它的入口页，而入口页缺参数就报「内部参数错误」，
 * 这是 0.5.0 那次失败的真实原因，不是没关联。
 */
interface Venue {
  key: string
  sub: string
  qr: string
  link: string
  /** 约课页路径（path=reservation-reservation-index 表示直达预约模块） */
  bookPath: string
  /** 门店首页路径（不带 path 参数，落到该店首页） */
  homePath: string
}

/**
 * 生成进入路径。
 *
 * 刻意**不用模板字符串拼 query**（产物里会被压缩成难以肉眼核对的拼接），
 * 改成显式字符串常量：真机上出问题时可以直接 grep 产物确认到底带没带参数。
 */
function enterPath(venueId: string, booking: boolean): string {
  return booking
    ? 'pages/enter/index?path=reservation-reservation-index&brand_id=' + BRAND_ID + '&venue_id=' + venueId
    : 'pages/enter/index?brand_id=' + BRAND_ID + '&venue_id=' + venueId
}

const VENUES: Venue[] = [
  {
    key: '东部店',
    sub: '江东 · 场馆',
    qr: '/static/book-east.jpg',
    link: '#小程序://Xyoga预约/LD9iekS0cCHuc5h',
    bookPath: enterPath('4250', true),
    homePath: enterPath('4250', false)
  },
  {
    key: '绿地店',
    sub: '绿地 · 场馆',
    qr: '/static/book-green.jpg',
    link: '#小程序://Xyoga预约/SWccsoBp9d9RSPq',
    bookPath: enterPath('1', true),
    homePath: enterPath('1', false)
  }
]

/** 码弹层：null = 关闭 */
const sheetVenue = ref<Venue | null>(null)

/** 状态栏高度：自定义导航下必须自己让出，否则 logo 会顶到刘海/时间下方 */
const statusBarHeight = ref(0)
try {
  statusBarHeight.value = uni.getSystemInfoSync().statusBarHeight ?? 0
} catch {
  statusBarHeight.value = 20
}

const safeTop = computed(() => `${statusBarHeight.value + 24}px`)

onShow(() => {
  // 首页不强制登录：顾客也要能看。只做会话恢复，不发请求。
  if (!user.token) user.restore()
  // 页面 JS 模块在小程序里是**单例**：reLaunch 回首页不会重置模块级变量（cachedPopup 等），
  // 所以「本次是否已跳走 / 是否有挂起的跳转」必须每次可见时复位，
  // 否则员工从工作台 reLaunch 回品牌页后会被上一次的 hasEnteredWorkbench 卡住、再也不弹。
  // （once 的「看过」记录在 storage 里，不受这里影响。）
  hasEnteredWorkbench = false
  pendingEnterWorkbench = false
  // 弹窗：不 await —— 首帧先出品牌图，配置回来再盖弹窗；规则见下方「首页弹窗」区块
  maybeShowPopup()
})

function toast(title: string) {
  uni.showToast({ title, icon: 'none' })
}

/**
 * 约课主按钮：优先直开网页（少一次点选），再退到选门店。
 * 有 AppID 时用 navigateToMiniProgram —— 原生跳转体验最好，作最高优先。
 */
/** 跳约课小程序：path 必带 query，失败回退到小程序码 */
function jumpToBook(v: Venue) {
  if (!BOOK_APPID) {
    openSheet(v)
    return
  }
  uni.navigateToMiniProgram({
    appId: BOOK_APPID,
    path: v.bookPath,
    fail: (e: unknown) => {
      const err = e as { errMsg?: string; errCode?: number }
      jumpErr.value = `${err?.errCode ?? ''} ${err?.errMsg ?? ''}`.trim() || '未知错误'
      uni.showModal({
        title: '没能打开约课小程序',
        content: `${jumpErr.value}\n\n已改用小程序码，长按识别即可约课`,
        showCancel: false,
        success: () => openSheet(v)
      })
    }
  })
}

/** 点门店卡：定位到该门店的码 */
function openSheet(v: Venue) {
  sheetVenue.value = v
}

function closeSheet() {
  sheetVenue.value = null
}

/** 兜底路径：复制小程序链接，去微信聊天里发给自己再点 */
function copyLink() {
  if (!sheetVenue.value) return
  uni.setClipboardData({
    data: sheetVenue.value.link,
    success: () => toast('已复制，粘贴到微信发送后点击即可打开'),
    fail: () => toast('复制失败，可长按上方图片识别')
  })
}

/**
 * 第一次来：进独立的「到店指引」页（先选门店，再给导航/电话/视频号/图文）。
 * 不在首页做弹层：首页是 fixed 全屏海报，塞进地图按钮 + 图片预览会把首屏
 * 变成两套滚动逻辑；独立页还能被后端卡片直链（?venue=绿地店）。
 */
function goGuide() {
  uni.navigateTo({ url: '/pkg-public/guide/guide' })
}

/**
 * 底部 slogan 是内部入口：已登录进工作台，未登录去登录页。
 *
 * 馆主口径「员工也要先看弹窗再进工作台」落地在这里 —— **只改「已登录 → 工作台」这一条**，
 * 改走 enterWorkbench()（弹窗期间挂起、关掉再跳）；「未登录 → 登录页」保持原样、一行未动。
 */
function goStaff() {
  if (user.token) {
    enterWorkbench()
    return
  }
  uni.navigateTo({ url: '/pages/login/login' })
}

/**
 * 已登录员工 → 工作台，但**先让弹窗**。
 *
 * 三条硬约束：
 *  1. **超时兜底**：还在等配置时最多等 POPUP_CONFIG_BUDGET_MS（1200ms），到点就当「不弹」直接走。
 *     宁可少弹一次，也不能让员工卡在首页进不去 —— 配置接口慢/挂时首页必须照常可用。
 *  2. **once 已看过不阻塞**：结论已经出来（popupDecided=true）且当前没弹 → 立刻 reLaunch，
 *     这条路径与「没有弹窗功能之前」完全同速。
 *  3. **不改分流**：本函数只承载「已登录 → 工作台」；未登录、约课、到店指引三条分支不经过这里。
 *
 * 为什么不能直接 reLaunch：它会销毁页面栈，弹窗连同页面一起被带走，用户根本没机会看完。
 * 所以弹窗正盖着时只做标记（pendingEnterWorkbench），由 closePopup() 续跳。
 */
function enterWorkbench(): void {
  const toWorkbench = (): void => {
    // 走都走了就别再往回弹：晚到的配置回包不许在已销毁的页面上把弹窗打开
    hasEnteredWorkbench = true
    uni.reLaunch({ url: '/pages/workbench/workbench' })
  }

  // 情况 A：弹窗正盖着 → 关掉再跳
  if (popupVisible.value) {
    pendingEnterWorkbench = true
    return
  }

  // 情况 B：已经有结论（once 看过 / enabled=false / 没内容 / 接口失败）→ 零等待，立刻跳
  if (popupDecided) {
    toWorkbench()
    return
  }

  // 情况 C：还在等配置 → 有预算地等；预算到点或配置返回后再判一次
  void ensurePopupDecision().then(() => {
    if (popupVisible.value) {
      pendingEnterWorkbench = true
      return
    }
    toWorkbench()
  })
}

/* ==========================================================================
 * 首页弹窗（后台可配：图片 / 图文 + 开关 + 频率）
 *
 * 馆主原话：「首页一个弹窗，可以设置图片，也可以设置文字标题加内容，后台能控制开关，
 * 内容能设置，还能设频率——显示一次，还是每次打开都显示」。
 *
 * ## 一、落在哪个页面、给谁看（判断依据）
 * 本页**没有「进来自动 reLaunch」**：onShow 只做会话恢复（不发请求、不跳转）。
 * 落地后所有人（顾客 / 员工）都停在品牌页，直到有人主动点：
 *   - 底部 slogan  → goStaff()：**已登录走 enterWorkbench()，未登录去登录页**；
 *   - 门店卡       → jumpToBook()：约课小程序 / 小程序码弹层；
 *   - 「第一次来」 → goGuide()：到店指引页。
 *
 * 馆主拍板「员工也要先看弹窗再进工作台」之后的时序：
 * ```
 * onShow → 拉配置(≤1200ms 预算) → 该弹吗？
 *   是 → 显示弹窗 → 用户关闭 → reLaunch 工作台   （goStaff 点了但弹窗还没关，标记 pending）
 *   否 → 立刻 reLaunch（与「没有弹窗功能之前」完全同速）
 * ```
 * **只挂了「已登录 → 工作台」这一条分支**：未登录、约课、到店指引三条分支一行未动。
 *
 * ## 一·补、已知限制（不是 bug，别当缺陷报）
 * `frequency=once` 的「看过」记录写在**客户端 storage**（POPUP_SEEN_KEY），
 * 所以**换手机 / 清缓存 / 重装后同一条内容会再弹一次**。这是有意取舍：
 * 为一条宣传弹窗引入「用户维度已读表」（要登录态、要建表、要处理未登录顾客）不划算。
 * 馆主已知悉。若哪天要精确到人，需要后端提供 user 维度已读接口。
 *
 * ## 二、数据源
 * GET /miniapp/config（免登录）。**不走 utils/http.ts** —— 它的 401 分支是
 * 「清 token + reLaunch 登录页」，那是员工端语义；顾客没有 token，一 401 整个首页就没了。
 * 请求口径与 api/miniapp.ts 完全一致（statusCode 闸门 + 三形态拆包 + 不跳登录）。
 * ⚠️ 为什么没直接调 api/miniapp.ts 的 fetchMiniappConfig()：它的 unwrapConfig()
 * 只挑 venues / updatedAt **重组成新对象**，popup 会被丢掉（不是「没取到」，是返回值里没有这个键）。
 * 而 api/miniapp.ts 不在本任务 inScope，不能顺手改。BE2 已把 popup 加进后端响应，
 * 待 MiniappConfig 补上 `popup?: unknown` 并原样透传后，这里应改成：
 * `const p = normalizePopup((await fetchMiniappConfig()).popup)` 并删掉本文件的
 * fetchMiniappConfigRaw（一行改动，见交付说明里的 patch）。
 *
 * ## 三、失败姿态
 * 接口挂 / popup 缺失 / 字段类型不对 / 三项内容全空 → **一律不弹**，不 toast、不抛错。
 * 弹窗是锦上添花，绝不能因为它让首页白屏或报错 —— 更不能让员工卡在首页进不去工作台
 * （所以下面对「等配置」设了 1200ms 硬预算）。
 * ========================================================================== */

/** 弹窗配置（后端契约的字段全部按「可能缺、可能是脏类型」处理） */
interface HomePopup {
  title: string
  content: string
  imageUrl: string
  /** once = 同一版内容只弹一次；always = 每次进首页都弹 */
  frequency: 'once' | 'always'
}

/** 已读记录：**只占一个键**，值里存「已看过的那个内容版本的指纹」 */
const POPUP_SEEN_KEY = 'yimai-home-popup-seen'

/**
 * 「等配置」的硬预算：超过就当作「不弹」。
 *
 * 这条预算只为「员工点了进工作台、配置却还没回来」这一条路径服务 ——
 * 宁可少弹一次，也不能让人卡在首页打不开小程序（馆主口径）。
 */
const POPUP_CONFIG_BUDGET_MS = 1200

/**
 * 内容指纹（FNV-1a 32 位，base36 输出）。
 *
 * 为什么用「标题+正文+图片」的哈希，而不是配置的 updatedAt：
 *  - updatedAt 是**整份配置**的更新时间 —— 馆主只改了门店电话，它也会变，
 *    于是弹窗会对所有人莫名其妙重弹一次，而内容一个字都没改；
 *  - 内容哈希才能给出馆主要的语义：「**换了内容 → 老用户再看一次；没换 → 不再打扰**」。
 * 三个字段就是全部可见内容，任一个改动都会算出新指纹。
 */
function popupFingerprint(p: HomePopup): string {
  const raw = `${p.title}\u0000${p.content}\u0000${p.imageUrl}`
  let h = 0x811c9dc5
  for (let i = 0; i < raw.length; i += 1) {
    h ^= raw.charCodeAt(i)
    // Math.imul：按 32 位整数乘法回绕，避免 h 溢出成浮点导致哈希退化
    h = Math.imul(h, 0x01000193) >>> 0
  }
  return h.toString(36)
}

/** 这个版本看过了吗（storage 统一走 @/utils/storage，不直接调 uni.*Sync） */
function hasSeenPopup(fp: string): boolean {
  const rec = getJSON<{ fp?: unknown }>(POPUP_SEEN_KEY)
  return !!rec && typeof rec.fp === 'string' && rec.fp === fp
}

/** 记下「这个版本看过了」 */
function markPopupSeen(fp: string): void {
  setJSON(POPUP_SEEN_KEY, { fp, at: new Date().toISOString() })
}

/** 只把字符串当内容：null / number / 对象一律当空，免得页面上出现「[object Object]」 */
function popupText(v: unknown): string {
  return typeof v === 'string' ? v.trim() : ''
}

/**
 * 把后端 popup 归一成可渲染结构；任何一条不满足就返回 null（= 不弹）。
 *
 * 四类空值组合逐条处理（任务书要求）：
 *  1. 只有图（title/content 空）→ 纯图弹窗，文字块整块不渲染；
 *  2. 只有字（imageUrl 空）     → 纯文字弹窗，图片位不渲染；
 *  3. 图文都有                  → 上图下文（同一张卡片里上下排布；不做左右分栏，
 *                                  手机竖屏左右分栏会把正文挤成两三个字一行）；
 *  4. 三者全空                  → **返回 null，enabled=true 也不弹**
 *                                  （空弹窗比不弹更糟：用户只看到一块遮罩，会以为小程序坏了）。
 */
function normalizePopup(raw: unknown): HomePopup | null {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null
  const o = raw as Record<string, unknown>
  // enabled 必须是布尔 true：字符串 "true" / 数字 1 / undefined 全部当「关」。
  // 后台漏转类型时宁可少弹一次，也不要对着全量顾客弹一个本不该弹的东西。
  if (o.enabled !== true) return null
  const title = popupText(o.title)
  const content = popupText(o.content)
  const imageUrl = popupText(o.imageUrl)
  if (!title && !content && !imageUrl) return null
  // 未知 / 缺失频率一律按 once：风险偏好上「少打扰」优于「多打扰」
  const frequency: HomePopup['frequency'] = o.frequency === 'always' ? 'always' : 'once'
  return { title, content, imageUrl, frequency }
}

/**
 * 取整份小程序配置（原始对象，交给调用方自己挑字段）。
 *
 * 与 api/miniapp.ts 的 fetchMiniappConfig 同一套请求口径，只做两件事：
 *  1. statusCode 闸门：非 2xx 一律 reject，不把错误页当数据；
 *  2. 三形态拆包：`{code,data}` / `{ok:false}` / `{errno,emsg}`。
 * **401/403 不跳登录、不弹 toast** —— 这是顾客首屏，失败由调用方静默处理。
 * 5s 超时：宁可早点当「没有弹窗」，也不要让顾客对着遮罩干等 20s。
 */
function fetchMiniappConfigRaw(timeout = 5000): Promise<Record<string, unknown>> {
  return new Promise<Record<string, unknown>>((resolve, reject) => {
    const token = getToken()
    uni.request({
      url: API_BASE + '/miniapp/config',
      method: 'GET',
      timeout,
      header: {
        'Content-Type': 'application/json',
        // 必须声明 JSON：Laravel 对「不期望 JSON」的请求会把异常渲染成 302 到 HTML
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        // 已登录就带着（后端在免登录接口上忽略它）；顾客没有 token 时不发这个头
        ...(token ? { Authorization: `Bearer ${token}` } : {})
      },
      success: (res) => {
        const status = res.statusCode
        if (status < 200 || status >= 300) {
          reject(new Error(`miniapp config ${status}`))
          return
        }
        const body = (res.data ?? {}) as Record<string, unknown>
        if (typeof body.code === 'number' && body.code !== 0) {
          reject(new Error('miniapp config code'))
          return
        }
        if (body.ok === false) {
          reject(new Error('miniapp config ok'))
          return
        }
        if (body.errno !== undefined && String(body.errno) !== '0') {
          reject(new Error('miniapp config errno'))
          return
        }
        const data = body.data
        // data 缺席时容忍「直接返回对象」的老写法（与 api/miniapp.ts 同款兜底）
        resolve(
          data && typeof data === 'object' && !Array.isArray(data)
            ? (data as Record<string, unknown>)
            : body
        )
      },
      fail: () => reject(new Error('miniapp config request failed'))
    })
  })
}

/** 当前弹窗内容；null = 没有可展示的内容 */
const popupData = ref<HomePopup | null>(null)
/** 独立开关：关掉就整块 v-if 销毁节点（而不是 display:none），遮罩不会继续挡住页面点击 */
const popupVisible = ref(false)
/** 图片加载失败标记：坏图不留空白框 */
const popupImageFailed = ref(false)
/** once 模式下待落盘的指纹：**关闭时才写**（见 closePopup 的注释） */
let pendingSeenFp = ''
/** 已拉到的配置：一次会话只请求一次，always 复用同一份，反复 onShow 不打接口 */
let cachedPopup: HomePopup | null = null
/** 是否已经发起过请求：避免 onShow 密集触发时并发打接口 */
let popupFetchStarted = false
/** 「弹/不弹」的结论是否已出（配置回来或预算到点都算）—— enterWorkbench 靠它决定要不要等 */
let popupDecided = false
/** 决策 Promise：多处（onShow / 员工跳转）同时需要，只建一次 */
let popupDecisionPromise: Promise<void> | null = null
/** 员工点了「进工作台」但弹窗还盖着 → 关掉后由 closePopup 续跳 */
let pendingEnterWorkbench = false
/** 已经跳去工作台了：晚到的配置回包不许再在已销毁的页面上弹窗 */
let hasEnteredWorkbench = false

/** 真正把弹窗盖上屏（once 在这里做「看过就跳过」，always 直接盖） */
function revealPopup(p: HomePopup): void {
  // 已经跳走 / 正在跳 → 不弹（否则 reLaunch 后又在旧页面上把遮罩打开）
  if (hasEnteredWorkbench) return
  if (p.frequency === 'once') {
    const fp = popupFingerprint(p)
    if (hasSeenPopup(fp)) return
    pendingSeenFp = fp
  }
  popupImageFailed.value = false
  popupData.value = p
  popupVisible.value = true
}

/**
 * onShow 的入口：决定这次要不要弹。
 *
 * - 已经弹着 → 不重入（onShow 与异步回调可能叠在一起触发）；
 * - 配置已到手 → always 直接再弹一次，once 走「看没看过」的判据；
 * - 还没请求过 → 走 ensurePopupDecision()（**不 await**），拉回来自己决定要不要 reveal。
 */
function maybeShowPopup(): void {
  if (popupVisible.value || hasEnteredWorkbench) return
  if (cachedPopup) {
    if (cachedPopup.frequency === 'always') revealPopup(cachedPopup)
    return
  }
  if (popupFetchStarted) return
  void ensurePopupDecision()
}

/**
 * 产出「弹 / 不弹」的结论，带**硬预算**。
 *
 * 存在的理由：员工点「进工作台」时如果配置还没回来，不能无限等 ——
 * 1200ms 到点就当作「不弹」，让人先走。宁可少弹一次，不能让人打不开小程序。
 * 预算用 setTimeout 抢跑，而不是去调小 uni.request 的 timeout：
 * 顾客在品牌页停留时慢一点没关系（5s 内回来仍能弹），只有「员工要进门」这条路径才需要快。
 */
function ensurePopupDecision(): Promise<void> {
  if (popupDecisionPromise) return popupDecisionPromise
  popupFetchStarted = true
  popupDecisionPromise = new Promise<void>((resolve) => {
    let settled = false
    const finish = (): void => {
      if (settled) return
      settled = true
      popupDecided = true
      resolve()
    }
    setTimeout(finish, POPUP_CONFIG_BUDGET_MS)
    void loadPopup().finally(finish)
  })
  return popupDecisionPromise
}

/** 拉配置 → 归一 → 交给 revealPopup；任何异常都吞掉（首页不能因为弹窗配置挂掉） */
async function loadPopup(): Promise<void> {
  try {
    const cfg = await fetchMiniappConfigRaw()
    const p = normalizePopup(cfg.popup)
    if (!p) return
    cachedPopup = p
    revealPopup(p)
  } catch {
    /* 接口挂了 / 结构不对 → 静默：不弹、不 toast、不影响首页其它交互 */
  }
}

/**
 * 关闭弹窗。
 *
 * 两件事必须一起做：
 *  1. once 的语义是「**关闭**即视为已看过」（任务书口径），所以指纹在关闭时才落盘、
 *     而不是弹出时就写：顾客刚看到弹窗就被电话打断、没关就退出小程序，下次仍应看得到；
 *  2. 如果这次弹窗是「员工点进工作台」途中被叫出来的（pendingEnterWorkbench），
 *     关掉就要把剩下的跳转做完 —— 否则用户点了入口却发现停在原页，像是按钮坏了。
 */
function closePopup(): void {
  popupVisible.value = false
  if (pendingSeenFp) {
    markPopupSeen(pendingSeenFp)
    pendingSeenFp = ''
  }
  if (pendingEnterWorkbench) {
    pendingEnterWorkbench = false
    // 走都走了就别再往回弹：晚到的配置回包不许在已销毁的页面上把弹窗打开
    hasEnteredWorkbench = true
    uni.reLaunch({ url: '/pages/workbench/workbench' })
  }
}

/**
 * 图片加载失败：坏图不做空白占位；纯图弹窗（没有文字可看）直接关掉，
 * 免得顾客对着一张破图和一块遮罩无从下手。
 */
function onPopupImageError(): void {
  popupImageFailed.value = true
  const p = popupData.value
  if (p && !p.title && !p.content) closePopup()
}
</script>

<template>
  <view class="home">
    <image class="home__bg" src="/static/hero.jpg" mode="aspectFill" />
    <view class="home__veil" />

    <view class="home__inner" :style="{ paddingTop: safeTop }">
      <view class="home__brand">
        <image class="home__logo" src="/static/logo-white.png" mode="aspectFit" />
        <text class="home__name">一麦瑜伽·普拉提</text>
        <text class="home__en">YIMAI YOGA · PILATES</text>
      </view>

      <view class="home__actions">
        <view class="home__cta">
          <view class="home__cta-line" />
          <text class="home__cta-text">预约课程</text>
          <view class="home__cta-line" />
        </view>

        <view class="home__venues">
          <view v-for="v in VENUES" :key="v.key" class="home__venue" @click="jumpToBook(v)">
            <text class="home__venue-name">{{ v.key }}</text>
          </view>
        </view>

        <view id="btn-guide" class="home__guide" @click="goGuide">
          <text class="home__guide-text">第一次来？点这里</text>
        </view>
      </view>

      <view id="btn-staff" class="home__slogan" @click="goStaff">
        <text class="home__slogan-main">看见·包容·超越</text>
        <text class="home__slogan-sub">在一麦遇见更好的自己</text>
      </view>
    </view>

    <!-- 约课弹层：长按识别为主，复制链接兜底 -->
    <view v-if="sheetVenue" class="sheet" @click="closeSheet">
      <view class="sheet__panel" @click.stop>
        <text class="sheet__title">{{ sheetVenue.key }} · 约课</text>
        <text class="sheet__hint">长按下方小程序码识别</text>
        <image
          class="sheet__qr"
          :src="sheetVenue.qr"
          mode="aspectFit"
          show-menu-by-longpress
        />
        <view class="sheet__go" @click="jumpToBook(sheetVenue)">
          <text class="sheet__go-text">直接进入约课</text>
        </view>
        <text class="sheet__tip">长按图片 → 识别小程序</text>
        <text class="sheet__tip sheet__tip--weak">小程序码不需要配置，网页约课若白屏就用这个</text>
        <view class="sheet__copy" @click="copyLink">
          <text class="sheet__copy-text">复制约课链接</text>
        </view>
        <text class="sheet__close" @click="closeSheet">关闭</text>
      </view>
    </view>

    <!--
      首页弹窗（后台可配）：自绘遮罩 + 卡片。
      不用 uni.showModal —— 它只有纯文字，做不出图片，也控不了样式。
      z-index 高于约课弹层（.sheet = 99），两者万一同时存在，弹窗在最上层。
      遮罩层只挡滚动、不接管点击（不点遮罩关闭）：once 模式下一次误触就等于永远看不到了，
      关闭必须走显式的 × 或「知道了」。
    -->
    <view v-if="popupVisible && popupData" class="pp" @touchmove.stop.prevent>
      <view class="pp__card" @touchmove.stop>
        <!-- 右上角 × 是快捷关闭；主关闭入口是底部「知道了」，两者走同一个 closePopup -->
        <view class="pp__close ym-tap" @click="closePopup">
          <text class="pp__close-x">×</text>
        </view>

        <!--
          图片区：mode="widthFix" 会把高度写成内联样式，直接给 <image> 加 max-height 会被
          内联高度覆盖 —— 所以「别让长图撑爆」的限制必须加在**这层容器**上（max-height + hidden）。
        -->
        <view v-if="popupData?.imageUrl && !popupImageFailed" class="pp__imgbox">
          <image
            class="pp__img"
            :src="popupData?.imageUrl"
            mode="widthFix"
            @error="onPopupImageError"
          />
        </view>

        <!-- 文字块：两者都空时不渲染，弹窗自动变成纯图弹窗 -->
        <view v-if="popupData?.title || popupData?.content" class="pp__body">
          <text v-if="popupData?.title" class="pp__title">{{ popupData?.title }}</text>
          <!-- 正文限高：短正文自然收缩（不留空白），超过 120 字才切成固定高度启用滚动 -->
          <scroll-view
            v-if="popupData?.content"
            scroll-y
            class="pp__content"
            :class="{ 'is-long': (popupData?.content?.length ?? 0) > 120 }"
          >
            <text class="pp__content-text">{{ popupData?.content }}</text>
          </scroll-view>
        </view>

        <view class="pp__ok ym-tap" @click="closePopup">
          <text class="pp__ok-text">知道了</text>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.home {
  /* fixed 铺满视口，不用 100vh：带安全区的机型上 100vh 会短一截，剩下的就是那条黑边 */
  position: fixed;
  inset: 0;
  overflow: hidden;
  background: #0e1a16;

  &__bg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
  }

  /* 上暗下更暗：压住顶部状态栏文字与底部 logo，中间留白让光带透出来 */
  &__veil {
    position: absolute;
    inset: 0;
    background: linear-gradient(
      180deg,
      rgba(8, 20, 16, 0.55) 0%,
      rgba(8, 20, 16, 0.18) 38%,
      rgba(8, 20, 16, 0.55) 74%,
      rgba(8, 20, 16, 0.88) 100%
    );
  }

  &__inner {
    position: relative;
    height: 100%;
    display: flex;
    flex-direction: column;
    padding-left: 56rpx;
    padding-right: 56rpx;
    padding-bottom: calc(48rpx + constant(safe-area-inset-bottom));
    padding-bottom: calc(48rpx + env(safe-area-inset-bottom));
    box-sizing: border-box;
  }

  &__brand {
    padding-top: 40rpx;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  &__logo {
    /* 透明底直接压图：白色图形本身对比够，不再依赖 filter（小程序端支持不稳） */
    width: 190rpx;
    height: 190rpx;
  }

  &__name {
    margin-top: 30rpx;
    font-size: 60rpx;
    letter-spacing: 12rpx;
    color: #fff;
    font-weight: 500;
    text-shadow: 0 4rpx 24rpx rgba(0, 0, 0, 0.35);
  }

  &__en {
    margin-top: 12rpx;
    font-size: 20rpx;
    letter-spacing: 6rpx;
    color: rgba(255, 255, 255, 0.66);
  }

  &__actions {
    margin-top: auto;
  }

  /* 「预约课程」是分区标题，不是按钮：用细竖线 + 字距，避免被当成可点元素 */
  &__cta {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 18rpx;
    padding: 6rpx 0 22rpx;
  }

  /* 用小元素而不是 ::before/::after —— 后者在 wxss 编译时会被丢弃（实测产物里没有） */
  &__cta-line {
    width: 48rpx;
    height: 2rpx;
    background: rgba(255, 255, 255, 0.45);
  }

  &__cta-text {
    font-size: 28rpx;
    letter-spacing: 10rpx;
    color: rgba(255, 255, 255, 0.92);
    padding-left: 10rpx;
  }

  &__venues {
    display: flex;
    gap: 24rpx;
    margin-top: 24rpx;
  }

  &__venue {
    flex: 1;
    height: 120rpx;
    border-radius: 22rpx;
    background: rgba(255, 255, 255, 0.16);
    border: 2rpx solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  &__venue-name {
    font-size: 32rpx;
    color: #fff;
    letter-spacing: 6rpx;
  }

  &__guide {
    margin-top: 26rpx;
    height: 84rpx;
    border-radius: 999rpx;
    border: 2rpx solid rgba(255, 255, 255, 0.38);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  &__guide-text {
    font-size: 26rpx;
    color: rgba(255, 255, 255, 0.9);
    letter-spacing: 3rpx;
  }

  &__probe {
    margin-top: 20rpx;
    padding: 14rpx 18rpx;
    border-radius: 12rpx;
    background: rgba(0, 0, 0, 0.55);
  }

  &__probe-text {
    font-size: 20rpx;
    color: #ffd9a0;
    word-break: break-all;
  }

  &__slogan {
    margin-top: 48rpx;
    padding: 18rpx 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10rpx;
  }

  &__slogan-main {
    font-size: 22rpx;
    letter-spacing: 8rpx;
    color: rgba(255, 255, 255, 0.78);
  }

  &__slogan-sub {
    font-size: 18rpx;
    letter-spacing: 3rpx;
    color: rgba(255, 255, 255, 0.5);
  }
}

/* ---------- 首页弹窗（自绘遮罩，不用 uni.showModal） ---------- */
.pp {
  position: fixed;
  inset: 0;
  /* 高于约课弹层 .sheet 的 99：两者万一同时存在，弹窗压在最上面 */
  z-index: 199;
  background: rgba(6, 14, 11, 0.78);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48rpx 56rpx;
  box-sizing: border-box;

  &__card {
    position: relative;
    width: 100%;
    /* 卡片自身也有上限：极端情况下（图 + 长文 + 按钮）整屏放不下时先在这里收口 */
    max-height: 84vh;
    background: #f7f6f2;
    border-radius: 28rpx;
    /* 圆角裁掉图片直角，同时兜住任何溢出 */
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20rpx 60rpx rgba(0, 0, 0, 0.35);
  }

  /* 关闭按钮贴卡片右上角；半透明黑底保证压在任何底图上都看得见 */
  &__close {
    position: absolute;
    top: 16rpx;
    right: 16rpx;
    z-index: 2;
    width: 56rpx;
    height: 56rpx;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  &__close-x {
    color: #fff;
    font-size: 40rpx;
    line-height: 1;
    margin-top: -4rpx;
  }

  /* 图片容器：widthFix 把高度写成内联样式，上限只能卡在这层（见模板注释） */
  &__imgbox {
    max-height: 56vh;
    overflow: hidden;
    background: #fff;
  }

  &__img {
    display: block;
    width: 100%;
  }

  &__body {
    padding: 32rpx 36rpx 8rpx;
    display: flex;
    flex-direction: column;
  }

  &__title {
    font-size: 34rpx;
    font-weight: 700;
    line-height: 1.4;
    color: #17402f;
  }

  /* 正文限高：WeChat 的 scroll-view 官方要求「固定高度」，只给 max-height 在旧基础库上不可靠。
     所以按内容长度切换 —— 短正文不设高度（自然收缩、不留空白），超过 120 字才给 30vh 启用滚动。
     max-height 同时留着兜底：万一 is-long 没命中（例如一长串无空格英文），也不会撑破卡片。 */
  &__content {
    margin-top: 16rpx;
    max-height: 30vh;

    &.is-long {
      height: 30vh;
    }
  }

  &__content-text {
    font-size: 27rpx;
    line-height: 1.7;
    color: #4a5550;
    /* 后台文本里的换行要真的换行（text 默认会折叠空白） */
    white-space: pre-wrap;
  }

  &__ok {
    margin: 24rpx 36rpx 32rpx;
    height: 88rpx;
    border-radius: 999rpx;
    background: #17402f;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  &__ok-text {
    font-size: 28rpx;
    letter-spacing: 4rpx;
    color: #fff;
  }
}
</style>

<style lang="scss">
.sheet {
  position: fixed;
  inset: 0;
  z-index: 99;
  background: rgba(6, 14, 11, 0.72);
  display: flex;
  align-items: center;
  justify-content: center;

  &__panel {
    width: 640rpx;
    background: #f7f6f2;
    border-radius: 30rpx;
    padding: 44rpx 40rpx 34rpx;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  &__title {
    font-size: 34rpx;
    letter-spacing: 4rpx;
    color: #17402f;
    font-weight: 600;
  }

  &__hint {
    margin-top: 10rpx;
    font-size: 24rpx;
    color: #7b8781;
  }

  &__qr {
    width: 480rpx;
    height: 480rpx;
    margin-top: 22rpx;
    border-radius: 16rpx;
    background: #fff;
  }

  &__go {
    margin-top: 24rpx;
    width: 100%;
    height: 92rpx;
    border-radius: 999rpx;
    background: #17402f;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  &__go-text {
    font-size: 28rpx;
    letter-spacing: 4rpx;
    color: #fff;
  }

  &__tip {
    margin-top: 16rpx;
    font-size: 22rpx;
    color: #9aa39e;
  }

  &__tip--weak {
    margin-top: 8rpx;
    font-size: 20rpx;
    color: #b3bbb6;
  }

  &__copy {
    margin-top: 26rpx;
    width: 100%;
    height: 88rpx;
    border-radius: 999rpx;
    border: 2rpx solid #17402f;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  &__copy-text {
    font-size: 27rpx;
    color: #17402f;
  }

  &__close {
    margin-top: 20rpx;
    font-size: 25rpx;
    color: #8a9490;
  }
}
</style>
