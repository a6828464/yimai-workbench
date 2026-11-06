<script setup lang="ts">
/**
 * 到店指引（首页「第一次来？点这里」的落地页）
 *
 * 流程：**先选门店 → 再看该店指引**（导航 / 电话 / 视频号 / 图文引导）。
 * 为什么是独立页面而不是首页弹层：首页是 `position:fixed` 的全屏海报，
 * 弹层里放地图按钮 + 图片预览 + 长文案会让首屏变成两套滚动逻辑；
 * 独立页还能被分享/被后端卡片直链（`/pkg-public/guide/guide?venue=绿地店`）。
 *
 * 本页**不要求登录**：它是给顾客看的（同首页），不能因为没登录就白屏。
 *
 * ## 数据来源（2026-11 起）
 * 门店档案（地址 / 电话 / 坐标 / 视频号 / 引导图）由**后台配置**下发：
 * `GET /miniapp/config`（免登录，见 `api/miniapp.ts`）。本文件里的 `DEFAULT_VENUES`
 * 只是「接口不可用时的本地默认值」，不再是事实来源。
 * 首屏策略：**先渲染默认值，接口回来再整体替换** —— 弱网 / 接口挂了顾客也立刻有东西可看，
 * 不会对着空白页等一次网络往返。
 *
 * ## 降级口径（与后台配置无关，始终生效）
 *  - 东部店这类「档案不全」的门店：地址空 → 给可执行文案 + 复制门店名；号码空 →
 *    **不渲染拨号按钮**（换成复制门店名），既不编数据也不让顾客点了白点；
 *  - **坐标**：空 / null / 命中占位值（29.8746/121.5496，容差 1e-4）→ 不调 `uni.openLocation`，
 *    改复制地址 + 提示「门店坐标待补充，地址已复制」——占位值是「真值」，只判空的守卫对它
 *    永不触发，所以必须显式比一次白名单（`PLACEHOLDER_COORDS`）；
 *  - 引导图：配置里最多取 9 张（与后端上限一致，前端不再二次截断到 3）；一张都没有就回退 `/static/hero.jpg`；
 *  - 视频号未配置 → 显示占位卡片，配置后自动变成可点入口。
 *
 * ## 2026-11 升级（馆主需求）
 *  1. **门店视频**：`venue.videoUrl`，放在视频号入口下方；只有配置了才渲染，不留空壳。
 *  2. **到店路线分三种**（开车 / 公共交通 / 步行），每种各自有 `videoUrl` 与 `images`，
 *     互不混排；都没有时显示「该路线指引待补充」。
 *  3. 图片上限 3 → 9。
 *  4. UI 上不再出现馆主点名要去掉的那两个字（`channelsLive` 字段保留，只是不再有入口）。
 *  5. **支持转发**：`onShareAppMessage` 带门店名 + 分包深链，客户点开就落到同一家店。
 *
 * ### `<video>` 的硬限制（别以为坏了）
 * 小程序 `<video>` 只能播**直链视频文件**（mp4 / m3u8 等 http(s) 地址）。
 * 视频号链接（`weixin://`、`channels.weixin.qq.com`、`finder.video.qq.com` 这类）**无法内嵌** ——
 * 塞进 `<video>` 只会黑屏转圈。所以 `directVideo()` 先用域名/协议判一次：
 * 判为「非直链」时退化成「点开看视频」按钮（复制链接让用户在微信里打开），而不是硬塞播放器。
 */
import { computed, ref } from 'vue'
import { onLoad, onShareAppMessage } from '@dcloudio/uni-app'
import { copyText } from '@/utils/nav'
import { fetchMiniappConfig } from '@/api/miniapp'

interface GuideImage {
  /** 小程序包内静态图（/static/…）或 https 地址都行；后端/CDN 换图不必重新发版 */
  src: string
  caption: string
}

/** 三种出行方式（BE2 契约里 routes 的键） */
type RouteKey = 'drive' | 'transit' | 'walk'

/**
 * 一种出行方式的指引：有视频播视频，没视频放这一段的图片，都没有就显示待补充。
 * 注意**不做 FALLBACK_IMAGE 兜底**：拿品牌海报冒充「步行路线」等于骗顾客，
 * 空就老老实实说待补充（与门店级 images 的处理不同）。
 */
interface GuideRoute {
  /** 后台配的文字说明（≤200 字，可能含换行）；空 = 不渲染这一块 */
  note: string
  videoUrl: string
  images: GuideImage[]
}

interface GuideVenue {
  key: string
  sub: string
  address: string
  /** 展示用带分隔符的号码；空 = 待补充（不做假号码） */
  phone: string
  /** 0 = 未配置（此时导航降级为「复制地址」，不会把顾客导到错误的点） */
  latitude: number
  longitude: number
  /** 微信视频号 username（wx.openChannelsUserProfile 用）；空 = 未配置 */
  channelsUser: string
  /**
   * 视频号 live id：**字段保留但不参与渲染**。
   * 馆主明确要求 UI 上不要出现那两个字，而这个字段唯一用途就是开播 / 回放，
   * 去词之后没有合适的按钮名，故整体下线该入口；后端仍会下发，将来做回放时再启用。
   */
  channelsLive: string
  /** 门店级引导图（前台/电梯/门头），上限 9 张 */
  images: GuideImage[]
  /** 门店级视频（放在视频号入口下方）；空 = 不渲染播放器 */
  videoUrl: string
  /** 到店路线：三种出行方式各自独立 */
  routes: Record<RouteKey, GuideRoute>
}

/**
 * 占位坐标白名单 —— **不是门店真实位置**，馆主提供真实坐标后删除这份常量。
 *
 * 为什么要有它：坐标是 number，占位值既不为 0 也不为 null，旧的 `if (!v.latitude)`
 * 空值守卫对它**永不触发**（那句「绝不拿占位坐标糊弄顾客」的注释因此和实现相反）。
 * 现在守卫改成「空值 **或** 命中这份集合」→ 降级为复制地址，宁可少一个功能，
 * 也不把顾客导到城市中心。命中判断留 1e-4 容差，避免浮点误差漏判。
 */
const PLACEHOLDER_COORDS: ReadonlyArray<{ lat: number; lng: number }> = [
  // 两家店现在填的都是这个点（宁波三江口一带的近似值，非门店位置）
  { lat: 29.8746, lng: 121.5496 }
]

/** 坐标是否可用：空值或占位值都算「没有坐标」（导航只认真实坐标） */
function hasRealCoords(v: GuideVenue): boolean {
  if (!v.latitude || !v.longitude) return false
  return !PLACEHOLDER_COORDS.some(
    (p) => Math.abs(p.lat - v.latitude) < 1e-4 && Math.abs(p.lng - v.longitude) < 1e-4
  )
}

/** 地址缺失时的可执行文案：不写「待补充」四个字，直接告诉顾客下一步能做什么 */
const ADDRESS_TBD_HINT = '地址待补，可复制门店名到手机地图搜索'

/**
 * 本地默认值 —— **只是兜底，不再是事实来源**（门店档案改由后台 `GET /miniapp/config` 下发）。
 *
 * 为什么留着这一份：这页是给顾客看的对外页，接口挂了 / 超时 / 返回空都**不能白屏**。
 * 首屏先用它渲染，接口回来再整体替换（见 loadRemoteConfig）；换成后端配置后，
 * 馆主在后台改地址电话就不用发版了。
 *
 * 门店键（`绿地店` / `东部店`）必须与后端 venue 列取值一致 —— 口径见 utils/venue.ts 的
 * KNOWN_VENUES（门店切换、请求参数都用同一套字面量）；首页 VENUES 的 key 也是这两个。
 *
 * ⚠️ TODO(馆主提供)：两家店 `latitude` / `longitude` 在这里仍是**占位值**
 * （29.8746/121.5496）。后台配置填了真实坐标后，这两行就没用了，可整段删掉；
 * 只要后台也填占位值，页面照样会命中 `PLACEHOLDER_COORDS` 降级成「复制地址」，不会导错。
 */
/** 空路线集合：本地默认值里三种出行方式都没有配置（后台填了才会显示内容） */
function emptyRoutes(): Record<RouteKey, GuideRoute> {
  return {
    drive: { note: '', videoUrl: '', images: [] },
    transit: { note: '', videoUrl: '', images: [] },
    walk: { note: '', videoUrl: '', images: [] }
  }
}

const DEFAULT_VENUES: GuideVenue[] = [
  {
    key: '绿地店',
    sub: '绿地缤纷城 · 4 楼',
    address: '宁波市江北区绿地缤纷城1号楼4楼',
    phone: '0574-87214863',
    // TODO(馆主提供)：绿地店真实坐标 —— 现在是占位值（29.8746/121.5496），
    // 需要馆主提供真实坐标后填这里，并把 PLACEHOLDER_COORDS 里的对应条目删掉
    latitude: 29.8746,
    longitude: 121.5496,
    // TODO(视频号): 填门店视频号 username，例如 'sphXXXXXXXXXXXXXX'
    channelsUser: '',
    // channelsLive 仍保留字段（后端会下发），但 UI 已不展示该入口 —— 见类型定义处的说明
    channelsLive: '',
    // 门店级视频：填 mp4/m3u8 直链才能内嵌；视频号链接会退化成「点开看视频」按钮
    videoUrl: '',
    routes: emptyRoutes(),
    images: [
      // TODO(到店指引): 换成真实引导图（前台/电梯/门头），最多 9 张；也可换成 https 外链
      { src: '/static/hero.jpg', caption: '到店后沿门厅指示牌进电梯，4 楼出电梯右手边即前台' }
    ]
  },
  {
    key: '东部店',
    sub: '江东 · 场馆',
    // TODO(馆主提供)：东部店档案地址待补 —— 空值时页面降级成「地址待补，可复制门店名」，
    // 馆主给出真实地址后填这里（不要随手编一个，顾客会照着跑空）
    address: '',
    // TODO(馆主提供)：东部店电话待补 —— 空值时**不渲染拨号按钮**（避免无效拨号），
    // 填上真实号码后按钮自动出现
    phone: '',
    // TODO(馆主提供)：东部店真实坐标 —— 现在是占位值（29.8746/121.5496），
    // 需要馆主提供真实坐标后填这里，并把 PLACEHOLDER_COORDS 里的对应条目删掉
    latitude: 29.8746,
    longitude: 121.5496,
    channelsUser: '',
    channelsLive: '',
    videoUrl: '',
    routes: emptyRoutes(),
    images: [{ src: '/static/hero.jpg', caption: '到店后前台出示本条指引，顾问带你熟悉场地' }]
  }
]

/** 已选门店的 key（'' = 还停在门店选择） */
const pickedKey = ref('')

/**
 * 当前渲染的门店清单：**先默认值，接口回来整体替换**。
 * 不 await 接口再渲染 —— 顾客首屏不能等网络（弱网下等于白屏）。
 */
const guideVenues = ref<GuideVenue[]>(DEFAULT_VENUES.map((v) => ({ ...v })))

/** 当前门店：永远有值（没选时落第一家），模板里不必到处判空 */
const current = computed<GuideVenue>(
  () => guideVenues.value.find((v) => v.key === pickedKey.value) ?? guideVenues.value[0]
)
/** 是否停在「选门店」这一步 */
const choosing = computed(() => pickedKey.value === '')

/**
 * 有没有视频号入口：**只看 channelsUser**。
 * channelsLive 不再参与判定 —— UI 已下线该入口（馆主要求去掉那两个字），
 * 若仍把它算进来，会出现「按钮组渲染出来了、点下去却是占位弹窗」的假交互。
 */
const videoReady = computed(() => Boolean(current.value.channelsUser))

/* ---------- 视频：直链 vs 视频号链接 ---------- */

/**
 * 明显无法内嵌的协议/域名（视频号与微信图文）。
 * 命中就不进 `<video>`，退化成「点开看视频」的按钮，避免播放器黑屏转圈。
 */
const NON_EMBEDDABLE_RE =
  /^(weixin:\/\/|wxa:\/\/)|channels\.weixin\.qq\.com|finder\.video\.qq\.com|mp\.weixin\.qq\.com/i

/**
 * 能否直接喂给 `<video>` 组件（mp4 / m3u8 这类直链文件）。
 *
 * 判定顺序（宁可多给一次「点开看」，也不要塞进播放器里失败）：
 *  1. 不是 http(s)（`weixin://` 这类私有协议）→ 不能内嵌；
 *  2. 命中视频号 / 微信域名 → 不能内嵌（`<video>` 播不了，只会黑屏）；
 *  3. 其余 http(s) → 认为可以（含没写扩展名的 CDN 直链，如 `?vid=123`）。
 *     万一填的其实是网页，`<video>` 会触发 `@error`，页面会提示改成复制链接。
 */
function directVideo(url: string): boolean {
  const u = url.trim()
  if (!/^https?:\/\//i.test(u)) return false
  return !NON_EMBEDDABLE_RE.test(u)
}

/** `<video>` 加载失败：给一句能操作的话，别留一个黑框让人以为页面坏了 */
function onVideoError(): void {
  toast('视频打不开，可复制链接到微信里打开')
}

/**
 * 非直链（视频号链接）的降级动作。
 * 小程序沙箱里既拉不起 `weixin://`，也开不了外部浏览器，最可靠的是把链接复制给用户，
 * 让他粘到微信聊天里点开 —— 比塞进 `<video>` 转圈强。
 */
function openExternalVideo(url: string): void {
  if (!url) return
  copyText(url, '视频链接已复制，粘贴到微信里即可打开')
}

/* ---------- 到店路线（三种出行方式） ---------- */

/** 分段定义：顺序即产品定义（馆主：第一个开车、第二个公共交通、第三个步行） */
const ROUTE_TABS: { key: RouteKey; label: string }[] = [
  { key: 'drive', label: '开车出行' },
  { key: 'transit', label: '公共交通' },
  { key: 'walk', label: '步行前往' }
]

/** 当前选中的出行方式；换门店时回到「开车出行」（不同店配置不同，默认第一段最稳） */
const routeTab = ref<RouteKey>('drive')

/** 当前门店 + 当前出行方式对应的那段指引（门店一定有 routes，emptyRoutes 兜底过） */
const activeRoute = computed<GuideRoute>(
  () => current.value.routes[routeTab.value] ?? { note: '', videoUrl: '', images: [] }
)

/* ---------- 路线文字说明（BE4 的 note） ---------- */

/**
 * 折叠阈值（字数）：约 6 行 × 17 字。
 *
 * 为什么按字数而不是实测高度：小程序里量文本高度要 `createSelectorQuery`（异步、
 * 每次切路线都要重算，还可能拿到 0），成本远高于收益。中文宽度稳定，
 * 100 字这个近似足够准，且**短 note（1-3 行）完全不会多出「展开」按钮**。
 */
const NOTE_FOLD_CHARS = 100

/** 当前路线的 note 是否展开（切路线时重置，见 selectRoute） */
const noteExpanded = ref(false)

/**
 * note 按换行切段。
 *
 * 为什么切段而不是整段塞一个 `<text>` 配 `white-space: pre-wrap`：
 * 后台是多行输入，切段后每个自然段是一个独立块，段落间距可控、换行 100% 保留，
 * 不依赖不同渲染层对 white-space 的支持差异。连续空行用 `\n+` 合并，避免出现空段落。
 */
const noteParagraphs = computed<string[]>(() =>
  activeRoute.value.note
    .split(/\n+/)
    .map((s) => s.trim())
    .filter(Boolean)
)

/** 这段 note 是否需要折叠（超长才出现「展开全文」） */
const noteNeedsFold = computed(() => activeRoute.value.note.length > NOTE_FOLD_CHARS)

/** 当前是否处于折叠态 */
const noteFolded = computed(() => noteNeedsFold.value && !noteExpanded.value)

function toggleNote(): void {
  noteExpanded.value = !noteExpanded.value
}

/** 分段标题：空态文案里要说清是哪一段没有内容，不能只写「待补充」 */
function routeLabel(key: RouteKey): string {
  return ROUTE_TABS.find((t) => t.key === key)?.label ?? '该路线'
}

/** 切换出行方式（模板里不直接给 ref 赋值，收口成一个函数便于以后加埋点/懒加载） */
function selectRoute(key: RouteKey): void {
  routeTab.value = key
  // 换一段就把「展开」状态复位：否则在开车段点了展开，切到步行段会莫名其妙是展开态
  noteExpanded.value = false
}

/** 当前门店坐标是否可用（占位值也算不可用）：按钮提示文案与 openMap 守卫共用同一条判据 */
const coordsReady = computed(() => hasRealCoords(current.value))

/** 当前门店有没有可播号码：没有就**不渲染**拨号按钮（先渲染再弹「待补充」是假交互） */
const phoneReady = computed(() => Boolean(current.value.phone.replace(/[^0-9+]/g, '')))

/** 导航按钮的副标题：如实反映「点下去会发生什么」，别写了「点开定位」却改成复制 */
const mapHint = computed(() => {
  if (coordsReady.value) return '点开定位'
  return current.value.address ? '坐标待补 · 复制地址' : '地址待补 · 复制门店名'
})

/* ---------- 后端配置 → 页面结构 ---------- */

/**
 * 引导图上限：与后端一致取 9 张（原来前端还截到 3，会把后台配的 4-9 张悄悄吃掉）。
 * 三处共用同一常量：门店级 images 与三种路线各自的 images。
 */
const MAX_IMAGES = 9
/**
 * 一张图都没有时的兜底（包内品牌海报）。
 * caption 必须**门店中立**：这张图两家店共用，写「4 楼前台」之类具体到某家店的话，
 * 东部店顾客会被误导（楼层未知）。
 */
const FALLBACK_IMAGE: GuideImage = {
  src: '/static/hero.jpg',
  caption: '门店引导图待补充，可先按上方地址到店，找不到就联系门店'
}

/** 容错取字符串：后台字段可能是 null / 数字，别让 `.trim()` 把页面打崩 */
function str(v: unknown): string {
  if (typeof v === 'string') return v.trim()
  if (v === null || v === undefined) return ''
  return String(v).trim()
}

/**
 * 容错取坐标：null / '' / 非数字都归一成 0。
 * 0 在 `hasRealCoords()` 里就是「没有坐标」→ openMap 走复制地址，不导错。
 */
function coord(v: unknown): number {
  if (v === null || v === undefined || v === '') return 0
  const n = Number(v)
  return Number.isFinite(n) ? n : 0
}

function toImage(raw: unknown): GuideImage | null {
  const o = (raw ?? {}) as Record<string, unknown>
  const src = str(o.src)
  // 没有 src 的图直接丢：渲染空 `<image>` 只会留一块灰
  return src ? { src, caption: str(o.caption) } : null
}

/**
 * 单段路线容错解析。
 * **不套 FALLBACK_IMAGE**：路线图空着就是没配，用品牌海报冒充「步行路线」是误导顾客；
 * 空态由模板显示「该路线指引待补充」。
 */
function toRoute(raw: unknown): GuideRoute {
  const o = (raw ?? {}) as Record<string, unknown>
  const images = Array.isArray(o.images)
    ? o.images.map(toImage).filter((img): img is GuideImage => img !== null).slice(0, MAX_IMAGES)
    : []
  // note 是纯文本：`str()` 已经把 null/undefined/数字统一成字符串并 trim，
  // 空串/全空白 → ''（模板按「没有 note」处理，整块不渲染）
  return { note: str(o.note), videoUrl: str(o.videoUrl), images }
}

/** routes 三个键各自解析；后台没配的键给空对象，模板按「待补充」渲染 */
function toRoutes(raw: unknown): Record<RouteKey, GuideRoute> {
  const o = (raw ?? {}) as Record<string, unknown>
  return {
    drive: toRoute(o.drive),
    transit: toRoute(o.transit),
    walk: toRoute(o.walk)
  }
}

/**
 * 接口单条 → 页面门店结构。
 * 缺 `key` 的条目丢弃（没法选、也没法深链）；其余字段缺了就让降级逻辑接手：
 * 地址空 → 复制门店名；号码空 → 不渲染拨号；坐标 0/占位 → 复制地址。
 */
function toGuideVenue(raw: unknown): GuideVenue | null {
  const o = (raw ?? {}) as Record<string, unknown>
  const key = str(o.key)
  if (!key) return null
  const images = Array.isArray(o.images)
    ? o.images.map(toImage).filter((img): img is GuideImage => img !== null).slice(0, MAX_IMAGES)
    : []
  return {
    key,
    sub: str(o.sub),
    address: str(o.address),
    phone: str(o.phone),
    latitude: coord(o.latitude),
    longitude: coord(o.longitude),
    channelsUser: str(o.channelsUser),
    channelsLive: str(o.channelsLive),
    // BE2 新增字段：门店级视频 + 三种路线。老后端不下发时这里就是空，模板不渲染即可
    videoUrl: str(o.videoUrl),
    routes: toRoutes(o.routes),
    images: images.length ? images : [{ ...FALLBACK_IMAGE }]
  }
}

/**
 * 拉后台配置并替换渲染源。
 *
 * 三条路径都要有确定行为（这页是顾客看的，任何一条都不能白屏）：
 *  1. 成功且有内容 → 整体替换（馆主改了地址/电话不用发版）；
 *  2. 失败 / 超时 / 返回空数组 / 全是坏数据 → **保持 DEFAULT_VENUES 不动**，连提示都不弹；
 *  3. 已经选了门店但新配置里没有它（后台下架）→ 退回选门店页，别继续展示错的店。
 */
async function loadRemoteConfig(): Promise<void> {
  try {
    const cfg = await fetchMiniappConfig()
    const mapped = cfg.venues
      .map(toGuideVenue)
      .filter((v): v is GuideVenue => v !== null)
    if (!mapped.length) return
    guideVenues.value = mapped
    if (pickedKey.value && !mapped.some((v) => v.key === pickedKey.value)) {
      pickedKey.value = ''
    }
  } catch {
    /* 接口挂了就用本地默认值：对外页面不弹错误，顾客该看的还在 */
  }
}

/** 视频号能力不在 @dcloudio/types 里（微信原生 API），这里做一层最小声明 + 存在性判断 */
interface ChannelsApi {
  openChannelsUserProfile?: (o: { finderUserName: string; fail?: (e: unknown) => void }) => void
  openChannelsLive?: (o: {
    finderUserName: string
    nonceId?: string
    fail?: (e: unknown) => void
  }) => void
}

function channelsApi(): ChannelsApi | null {
  // 小程序全局 `wx`；H5 / App 端没有这个对象，用 typeof 守住（不在不支持的端抛错）
  if (typeof globalThis === 'undefined') return null
  const g = globalThis as unknown as { wx?: ChannelsApi }
  return g.wx ?? null
}

function toast(title: string): void {
  uni.showToast({ title, icon: 'none' })
}

/**
 * 深链：/pkg-public/guide/guide?venue=东部店（门店名不认识就停在选择页）
 *
 * 先按**默认值**认一次门店（键是稳定的），再异步拉后台配置：
 *  - 接口回来后若配置里没有这家店，loadRemoteConfig 会把选择重置回选择页；
 *  - 不 await 接口 —— 首屏立刻有内容可看，网络慢不背锅。
 */
onLoad((options) => {
  const venue = String((options as Record<string, string> | undefined)?.venue ?? '').trim()
  const hit = guideVenues.value.find((v) => v.key === venue)
  if (hit) pickedKey.value = hit.key
  void loadRemoteConfig()
})

function choose(v: GuideVenue): void {
  pickedKey.value = v.key
}

function switchVenue(): void {
  pickedKey.value = ''
}

/**
 * 地图导航：坐标没配 **或还是占位值** → 复制地址，绝不调 openLocation。
 *
 * 旧写法只判 `if (!v.latitude || !v.longitude)`：占位坐标是真值，守卫永远不触发，
 * 于是「绝不拿占位坐标糊弄顾客」的注释与实现相反，点导航会被带到城市中心。
 */
function openMap(v: GuideVenue): void {
  if (!hasRealCoords(v)) {
    if (v.address) {
      copyText(v.address, '门店坐标待补充，地址已复制')
    } else {
      // 地址也没配（东部店现状）：不编地址，改复制门店名让顾客自己搜
      copyText(`一麦瑜伽·普拉提（${v.key}）`, '地址待补，门店名已复制')
    }
    return
  }
  uni.openLocation({
    latitude: v.latitude,
    longitude: v.longitude,
    name: `一麦瑜伽·普拉提（${v.key}）`,
    address: v.address || undefined,
    scale: 17,
    fail: () => toast('打开地图失败，可先复制地址给司机')
  })
}

/**
 * 号码待补时的兜底动作（替代拨号按钮）：复制门店名。
 * 只复制已有的门店名，不编造地址/电话 —— 顾客拿它去手机地图搜或问顾问都能用。
 */
function copyVenueName(v: GuideVenue): void {
  copyText(`一麦瑜伽·普拉提（${v.key}）`, '门店名已复制，可在手机地图搜索')
}

/** 门店电话：没配号码就直说，不做无效拨号（按钮层已按 phoneReady 不渲染，这里是二次兜底） */
function callVenue(v: GuideVenue): void {
  const phone = v.phone.replace(/[^0-9+]/g, '')
  if (!phone) {
    copyVenueName(v)
    return
  }
  uni.makePhoneCall({
    phoneNumber: phone,
    fail: () => {
      /* 用户取消拨号也会走 fail，不提示，免得多余弹窗 */
    }
  })
}

/** 视频号主页 */
function openChannels(): void {
  const v = current.value
  if (!v.channelsUser) {
    videoPlaceholder()
    return
  }
  const api = channelsApi()
  if (!api?.openChannelsUserProfile) {
    toast('当前微信版本不支持打开视频号')
    return
  }
  api.openChannelsUserProfile({
    finderUserName: v.channelsUser,
    fail: () => toast('视频号打不开，可稍后再试')
  })
}

/** 占位卡片：讲清楚「为什么这里点不动」，免得被当成坏页面（文案里不出现那两个字） */
function videoPlaceholder(): void {
  uni.showModal({
    title: '视频号待配置',
    // 这是给顾客看的文案：不提内部文件路径，配置说明写在文件头的 TODO 里
    content: '门店视频号还在开通中，先按下面的图文指引到店即可；课程花絮会陆续放进来。',
    showCancel: false,
    confirmText: '知道了',
    confirmColor: '#2E7D5B'
  })
}

/**
 * 图片放大预览（包内静态图也能预览）。
 *
 * 参数改成「传哪一组图」而不是只读门店级 images：现在页面上有两处图片组
 * （门店实景 + 当前出行路线的图），只读 current.images 会让路线图点开放大时
 * 弹出门店实景 —— 用户点的是路线图，看到的却是别的一组。
 */
function previewImage(images: GuideImage[], index: number): void {
  const urls = images.map((img) => img.src)
  if (!urls.length) return
  uni.previewImage({
    urls,
    current: urls[index] ?? urls[0],
    fail: () => toast('图片预览失败')
  })
}

/**
 * 转发给客户（馆主原话：想把「绿地店到店指引」直接发给客户，现在没办法分享）。
 *
 * 三个要点：
 *  1. `path` 必须是**分包路径** `/pkg-public/guide/guide` —— W7 分包后 `pages` 目录里已经没有这个页面，
 *     写成旧的 pages 路径分享出去，对方点开只会白屏；
 *  2. 还在「选门店」这一步时也要能分享 —— 此时没有选定门店，标题退化成品牌名、path 不带 venue，
 *     不能拿 `current`（默认第一家）冒充成用户选的那家；
 *  3. 带上 `?venue=` 后，接收端 onLoad 会展开同一家店（深链逻辑见 onLoad）。
 */
onShareAppMessage(() => {
  const v = choosing.value ? null : current.value
  const key = v?.key ?? ''
  const cover = v?.images?.[0]?.src ?? ''
  return {
    title: `${key || '一麦瑜伽'} · 到店指引`,
    path:
      `/pkg-public/guide/guide` +
      (key ? `?venue=${encodeURIComponent(key)}` : ''),
    // 有门店引导图就用它当封面；没有就不传（微信会用页面截图兜底）
    imageUrl: cover
  }
})

</script>

<template>
  <view class="ym-page guide">
    <!-- 第一步：选门店（两个大门店卡，拇指友好） -->
    <view v-if="choosing" class="pick">
      <text class="pick__title">你到哪家店？</text>
      <text class="pick__desc">选门店后给出地址、电话、到店路线和门店视频号</text>

      <view
        v-for="v in guideVenues"
        :key="v.key"
        class="pickcard ym-tap"
        @click="choose(v)"
      >
        <view class="pickcard__main">
          <text class="pickcard__name">{{ v.key }}</text>
          <text class="pickcard__sub">{{ v.sub }}</text>
        </view>
        <wd-icon name="arrow-right" size="16px" color="#8A948F" />
      </view>

      <text class="pick__tip">不确定先去哪家？两家都能约课，选离你近的一家就行。</text>
    </view>

    <!-- 第二步：门店引导 -->
    <template v-else>
      <view class="ghead">
        <text class="ghead__name">{{ current.key }}</text>
        <text class="ghead__sub">{{ current.sub }}</text>
        <!-- 地址为空时给可执行文案（复制门店名去手机地图搜），不写「待补充」四个字 -->
        <text class="ghead__addr" :class="{ 'ghead__addr--tbd': !current.address }">
          {{ current.address || ADDRESS_TBD_HINT }}
        </text>
      </view>

      <!-- 两个大动作：导航 / 电话。两者都带降级：
           占位坐标 → 复制地址（或门店名）；号码待补 → 不渲染拨号，换成复制门店名 -->
      <view class="acts">
        <view class="acts__item ym-tap" @click="openMap(current)">
          <wd-icon name="location" size="22px" color="#FFFFFF" />
          <text class="acts__text">地图导航</text>
          <text class="acts__hint">{{ mapHint }}</text>
        </view>
        <view
          v-if="phoneReady"
          class="acts__item acts__item--plain ym-tap"
          @click="callVenue(current)"
        >
          <wd-icon name="phone" size="22px" color="#1C6047" />
          <text class="acts__text acts__text--dark">门店电话</text>
          <text class="acts__hint acts__hint--dark">{{ current.phone }}</text>
        </view>
        <view v-else class="acts__item acts__item--plain ym-tap" @click="copyVenueName(current)">
          <wd-icon name="copy" size="22px" color="#1C6047" />
          <text class="acts__text acts__text--dark">复制门店名</text>
          <text class="acts__hint acts__hint--dark">电话待补 · 地图里搜</text>
        </view>
      </view>

      <!-- 视频号（UI 上不出现那两个字；channelsLive 字段仍在，只是没有入口） -->
      <view class="vcard">
        <view class="vcard__head">
          <text class="vcard__title">视频号</text>
          <text class="vcard__desc">
            {{ videoReady ? '课程花絮都在视频号里' : '待配置视频号' }}
          </text>
        </view>
        <view v-if="videoReady" class="vcard__acts">
          <view class="vbtn ym-tap" @click="openChannels">
            <wd-icon name="play-circle" size="16px" color="#FFFFFF" />
            <text class="vbtn__text">看视频号</text>
          </view>
        </view>
        <view v-else class="vcard__ph ym-tap" @click="videoPlaceholder">
          <text class="vcard__ph-text">
            这里会给到门店视频号入口；目前还没配置，先按下面的图文指引到店即可。
          </text>
        </view>
      </view>

      <!-- 门店视频：放在视频号入口下方。只有配了 videoUrl 才渲染，没配置不留空壳 -->
      <view v-if="current.videoUrl" class="videoblk">
        <text class="videoblk__label">门店视频</text>
        <!-- 直链（mp4/m3u8）才内嵌；视频号链接小程序播不了，退化成「点开看视频」按钮 -->
        <video
          v-if="directVideo(current.videoUrl)"
          class="videoblk__player"
          :src="current.videoUrl"
          :controls="true"
          :show-center-play-btn="true"
          object-fit="contain"
          @error="onVideoError"
        />
        <view
          v-else
          class="vbtn vbtn--wide ym-tap"
          @click="openExternalVideo(current.videoUrl)"
        >
          <wd-icon name="play-circle" size="16px" color="#FFFFFF" />
          <text class="vbtn__text">点开看门店视频</text>
        </view>
      </view>

      <!-- 门店实景：原来这块叫「到店路线」，现在「路线」专指下面三种出行方式，改名避免撞车 -->
      <view class="imgs">
        <text class="imgs__label">门店实景</text>
        <view
          v-for="(img, i) in current.images"
          :key="img.src + i"
          class="imgcard ym-tap"
          @click="previewImage(current.images, i)"
        >
          <image class="imgcard__pic" :src="img.src" mode="aspectFill" />
          <view class="imgcard__cap">
            <text class="imgcard__text">{{ img.caption }}</text>
            <text class="imgcard__zoom">点击放大</text>
          </view>
        </view>
      </view>

      <!-- 到店路线：三种出行方式各自独立渲染，绝不把三段的图混在一起 -->
      <view class="routes">
        <text class="imgs__label">到店路线</text>

        <view class="rtabs">
          <view
            v-for="t in ROUTE_TABS"
            :key="t.key"
            class="rtab ym-tap"
            :class="{ 'is-on': routeTab === t.key }"
            @click="selectRoute(t.key)"
          >
            <text class="rtab__text">{{ t.label }}</text>
          </view>
        </view>

        <!--
          路线导语（后台 note）：切换条下方、视频/图片上方。
          有值才渲染 —— 空 note 时整块不存在，不会留下 margin 造成的空行。
          用正文字号 + 宽松行高，视觉上是「导语」而不是标题（标题已经是上面的分段名）。
        -->
        <view v-if="noteParagraphs.length" class="rnote">
          <view class="rnote__body" :class="{ 'is-folded': noteFolded }">
            <text v-for="(p, i) in noteParagraphs" :key="i" class="rnote__p">{{ p }}</text>
          </view>
          <text v-if="noteNeedsFold" class="rnote__toggle ym-tap" @click="toggleNote">
            {{ noteExpanded ? '收起' : '展开全文' }}
          </text>
        </view>

        <!-- ① 这一段有视频：直链内嵌，非直链（视频号链接）退化成跳转按钮 -->
        <template v-if="activeRoute.videoUrl">
          <video
            v-if="directVideo(activeRoute.videoUrl)"
            class="videoblk__player"
            :src="activeRoute.videoUrl"
            :controls="true"
            :show-center-play-btn="true"
            object-fit="contain"
            @error="onVideoError"
          />
          <view
            v-else
            class="vbtn vbtn--wide ym-tap"
            @click="openExternalVideo(activeRoute.videoUrl)"
          >
            <wd-icon name="play-circle" size="16px" color="#FFFFFF" />
            <text class="vbtn__text">点开看这段路线视频</text>
          </view>
        </template>

        <!-- ② 没视频但有图：只渲染这一段的图 -->
        <template v-else-if="activeRoute.images.length">
          <view
            v-for="(img, i) in activeRoute.images"
            :key="img.src + i"
            class="imgcard ym-tap"
            @click="previewImage(activeRoute.images, i)"
          >
            <image class="imgcard__pic" :src="img.src" mode="aspectFill" />
            <view class="imgcard__cap">
              <text class="imgcard__text">{{ img.caption }}</text>
              <text class="imgcard__zoom">点击放大</text>
            </view>
          </view>
        </template>

        <!-- ③ 视频和图片都没有：说清是这一段没配，而不是页面坏了 -->
        <view v-else class="rtempty">
          <text class="rtempty__text">该路线指引待补充</text>
          <text class="rtempty__desc">
            {{ routeLabel(routeTab) }}暂时没有视频或图片，可先按上方地址导航到店，找不到就联系门店
          </text>
        </view>
      </view>

      <view class="switch ym-tap" @click="switchVenue">
        <text class="switch__text">换一家门店</text>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.guide {
  padding: 24rpx 0 calc(48rpx + env(safe-area-inset-bottom));
}

/* ---------- 选门店 ---------- */
.pick {
  display: flex;
  flex-direction: column;
  padding: 24rpx 28rpx;
}

.pick__title {
  font-size: 44rpx;
  font-weight: 600;
  color: $ink-800;
}

.pick__desc {
  margin-top: 10rpx;
  font-size: 25rpx;
  line-height: 1.6;
  color: $ink-400;
}

.pickcard {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 168rpx;
  padding: 28rpx 30rpx;
  margin-top: 24rpx;
  background: #fcfbf8;
  border-radius: 18rpx;
  box-shadow: $shadow-card;
}

.pickcard__main {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
  min-width: 0;
}

.pickcard__name {
  font-size: 38rpx;
  font-weight: 600;
  letter-spacing: 2rpx;
  color: $ink-800;
}

.pickcard__sub {
  font-size: 24rpx;
  color: $ink-400;
}

.pick__tip {
  margin-top: 28rpx;
  font-size: 22rpx;
  line-height: 1.6;
  color: $ink-300;
}

/* ---------- 门店头 ---------- */
.ghead {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
  padding: 36rpx 32rpx 32rpx;
  margin: 0 24rpx;
  color: $white;
  /* 渐变 → 纯深墨绿。135° 的双色渐变是最容易显「模板化」的用法：
     同色系两档在手机上几乎没有层次，只剩一层廉价的塑料感。
     纯色反而更稳、更像品牌物料；圆角同步从 28 收到 20，靠留白立秩序而不是靠圆润 */
  background: #0f1b16;
  border-radius: 20rpx;
}

.ghead__name {
  font-size: 42rpx;
  font-weight: 600;
  letter-spacing: 2rpx;
}

.ghead__sub {
  font-size: 24rpx;
  color: rgba($white, 0.72);
}

.ghead__addr {
  margin-top: 8rpx;
  font-size: 25rpx;
  line-height: 1.5;
  color: rgba($white, 0.9);
}

/* 地址待补时的降级文案：比真实地址弱一档，读起来像「提示」而不是「地址」 */
.ghead__addr--tbd {
  color: rgba($white, 0.78);
}

/* ---------- 导航 / 电话 ---------- */
.acts {
  display: flex;
  gap: 20rpx;
  margin: 24rpx 24rpx 0;
}

.acts__item {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6rpx;
  align-items: center;
  justify-content: center;
  /* 196 → 132rpx：原来这两个块占了小半屏，主操作不需要那么大，
     顾客来这一页是「找路」，不是「按按钮」 */
  min-height: 132rpx;
  padding: 20rpx 16rpx;
  color: $white;
  /* 渐变 → 纯色，并去掉那层绿色投影。
     给彩色按钮加同色投影会让它显得「浮起来又糊」，是廉价感的来源；
     保留纯品牌绿已经足够承重，层次交给留白 */
  background: $brand-700;
  border-radius: 16rpx;
}

.acts__item--plain {
  color: $brand-700;
  background: #fcfbf8;
  border: 1rpx solid $hairline;
  box-shadow: none;
}

.acts__text {
  margin-top: 6rpx;
  font-size: 30rpx;
  font-weight: 600;
  letter-spacing: 2rpx;
}

.acts__text--dark {
  color: $ink-800;
}

.acts__hint {
  font-size: 21rpx;
  color: rgba($white, 0.72);
}

.acts__hint--dark {
  color: $ink-400;
}

/* ---------- 视频号 ---------- */
.vcard {
  padding: 26rpx 28rpx;
  margin: 24rpx 24rpx 0;
  background: #fcfbf8;
  border-radius: 18rpx;
  box-shadow: $shadow-card;
}

.vcard__head {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}

.vcard__title {
  font-size: 30rpx;
  font-weight: 600;
  color: $ink-800;
}

.vcard__desc {
  font-size: 23rpx;
  color: $ink-400;
}

.vcard__acts {
  display: flex;
  gap: 16rpx;
  margin-top: 20rpx;
}

.vbtn {
  display: flex;
  flex: 1;
  gap: 8rpx;
  align-items: center;
  justify-content: center;
  height: 92rpx;
  color: $white;
  background: linear-gradient(135deg, $brand-500, $brand-700);
  border-radius: 999rpx;
}

.vbtn--ghost {
  background: $brand-50;
  border: 2rpx solid $brand-200;
}

.vbtn__text {
  font-size: 27rpx;
  font-weight: 500;
}

.vbtn__text--brand {
  color: $brand-700;
}

.vcard__ph {
  padding: 22rpx 20rpx;
  margin-top: 18rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

.vcard__ph-text {
  font-size: 23rpx;
  line-height: 1.6;
  color: $ink-500;
}

/* ---------- 图文引导 ---------- */
.imgs {
  margin: 24rpx 24rpx 0;
}

.imgs__label {
  display: block;
  padding: 0 4rpx 14rpx;
  font-size: 26rpx;
  font-weight: 600;
  color: $ink-700;
}

.imgcard {
  overflow: hidden;
  background: #fcfbf8;
  border-radius: 18rpx;
  box-shadow: $shadow-card;

  & + & {
    margin-top: 20rpx;
  }
}

.imgcard__pic {
  width: 100%;
  height: 320rpx;
  background: $ink-100;
}

.imgcard__cap {
  display: flex;
  gap: 16rpx;
  align-items: flex-start;
  justify-content: space-between;
  padding: 20rpx 24rpx;
}

.imgcard__text {
  flex: 1;
  min-width: 0;
  font-size: 24rpx;
  line-height: 1.55;
  color: $ink-600;
}

.imgcard__zoom {
  flex-shrink: 0;
  font-size: 21rpx;
  color: $brand-600;
}

/* ---------- 门店视频 / 路线视频 ---------- */
.videoblk {
  margin: 24rpx 24rpx 0;
}

.videoblk__label {
  display: block;
  padding: 0 4rpx 14rpx;
  font-size: 26rpx;
  font-weight: 600;
  color: $ink-700;
}

/* 固定 16:9 高度 + object-fit: contain：竖屏视频会留黑边，但绝不裁掉画面
   （裁掉的往往是门头/指示牌，正是顾客要看的那部分） */
.videoblk__player {
  width: 100%;
  height: 400rpx;
  background: #000;
  border-radius: 18rpx;
}

/* 满宽按钮：视频号链接的降级入口，与路线段里的跳转按钮共用同一个类 */
.vbtn--wide {
  flex: none;
  width: 100%;
}

/* ---------- 到店路线分段 ---------- */
.routes {
  margin: 24rpx 24rpx 0;
}

/* ---------- 路线导语（后台 note） ----------
   浅灰底 + 正文字号：和下面的视频/图片拉开层次，但不抢「分段标题」的位置 */
.rnote {
  padding: 20rpx 22rpx;
  margin-bottom: 20rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

/* 折叠态限高 6 行（25rpx × 1.75 ≈ 44rpx/行）：首屏仍能看到视频/图片的入口，
   想看全文点「展开全文」—— 既不压缩信息，也不让 200 字把首屏占满 */
.rnote__body.is-folded {
  max-height: 264rpx;
  overflow: hidden;
}

.rnote__p {
  display: block;
  font-size: 25rpx;
  line-height: 1.75;
  color: $ink-600;
  word-break: break-word;

  & + & {
    margin-top: 8rpx;
  }
}

.rnote__toggle {
  display: block;
  margin-top: 12rpx;
  font-size: 23rpx;
  color: $brand-600;
}

.rtabs {
  display: flex;
  gap: 12rpx;
  padding: 6rpx;
  margin-bottom: 20rpx;
  background: $ink-50;
  border-radius: 999rpx;
}

.rtab {
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  height: 76rpx;
  border-radius: 999rpx;

  /* 选中态用白底 + 轻阴影，与项目其它分段控件一致（不靠颜色堆对比度） */
  &.is-on {
    background: $white;
    box-shadow: $shadow-card;
  }
}

.rtab__text {
  font-size: 25rpx;
  color: $ink-500;
}

.rtab.is-on .rtab__text {
  font-weight: 600;
  color: $brand-700;
}

/* ---------- 路线指引待补充 ---------- */
.rtempty {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
  padding: 40rpx 28rpx;
  background: $ink-50;
  border-radius: 18rpx;
}

.rtempty__text {
  font-size: 27rpx;
  font-weight: 500;
  color: $ink-600;
}

.rtempty__desc {
  font-size: 23rpx;
  line-height: 1.6;
  color: $ink-400;
}

/* ---------- 换店 ---------- */
.switch {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 92rpx;
  margin: 28rpx 24rpx 0;
  background: $white;
  border: 2rpx solid $hairline;
  border-radius: 999rpx;
}

.switch__text {
  font-size: 27rpx;
  color: $ink-600;
}
</style>
