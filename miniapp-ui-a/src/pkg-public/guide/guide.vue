<script setup lang="ts">
/**
 * 到店指引（首页「第一次来？点这里」的落地页）· A 方案「静奢东方」
 *
 * 流程不变：**先选门店 → 再看该店指引**（导航 / 电话 / 视频号 / 图文引导）。
 * 本页**不要求登录**：它是给顾客看的（同首页），不能因为没登录就白屏。
 *
 * ## A 方案视觉（2026-11-07 按馆主拍板落地）
 *  - 选店步：纯排版大留白（金字距标签 + 衬线大标 + 竖排「静候光临」装饰），
 *    门店条与首页同款：竖向长条 + 发丝线分隔，非卡片；
 *  - 门店步：头图满宽沉浸（images[0] 渐变压字）+ 地址行 + 两个 1.5rpx 墨绿描边按钮
 *    + 宋式区块标题（小字 · 发丝线 · 宽字距）+ 下划线式路线 tab；
 *  - 香槟金只用于选店步的字距标签，其余全部墨绿 / 米灰 / 暖白；
 *  - 原 classic/editorial 双主题分支退役：本工程统一 A 视觉，`theme` 字段
 *    后端仍可下发，前端不再读取（保留在 api 层原样透传，不影响契约）。
 *
 * ## 数据来源（2026-11 起）
 * 门店档案（地址 / 电话 / 坐标 / 视频号 / 引导图）由**后台配置**下发：
 * `GET /miniapp/config`（免登录，见 `api/miniapp.ts`）。本文件里的 `DEFAULT_VENUES`
 * 只是「接口不可用时的本地默认值」，不再是事实来源。
 * 首屏策略：**先渲染默认值，接口回来再整体替换** —— 弱网 / 接口挂了顾客也立刻有东西可看。
 *
 * ## 降级口径（与后台配置无关，始终生效）
 *  - 东部店这类「档案不全」的门店：地址空 → 给可执行文案 + 复制门店名；号码空 →
 *    **不渲染拨号按钮**（换成复制门店名），既不编数据也不让顾客点了白点；
 *  - **坐标**：空 / null / 命中占位值（29.8746/121.5496，容差 1e-4）→ 不调 `uni.openLocation`，
 *    改复制地址 + 提示「门店坐标待补充，地址已复制」；
 *  - 引导图：配置里最多取 9 张（与后端上限一致）；一张都没有就回退实景默认图；
 *  - 视频号未配置 → 显示占位卡片，配置后自动变成可点入口。
 *
 * ## `<video>` 的硬限制（别以为坏了）
 * 小程序 `<video>` 只能播**直链视频文件**（mp4 / m3u8 等 http(s) 地址）。
 * 视频号链接（`weixin://`、`channels.weixin.qq.com` 这类）**无法内嵌** ——
 * 塞进 `<video>` 只会黑屏转圈。所以 `directVideo()` 先用域名/协议判一次：
 * 判为「非直链」时退化成「点开看视频」按钮（复制链接让用户在微信里打开）。
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
  /** 视频号 live id：字段保留（后端会下发），UI 不展示（馆主要求去掉那两个字） */
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
 * 坐标是 number，占位值既不为 0 也不为 null，空值守卫对它永不触发，
 * 所以必须显式比一次白名单（`PLACEHOLDER_COORDS`）；留 1e-4 容差防浮点漏判。
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
 * 门店键（`绿地店` / `东部店`）必须与后端 venue 列取值一致。
 *
 * ⚠️ TODO(馆主提供)：两家店 `latitude` / `longitude` 在这里仍是**占位值**
 * （29.8746/121.5496）。后台配置填了真实坐标后，这两行就没用了，可整段删掉。
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
    // TODO(馆主提供)：绿地店真实坐标 —— 现在是占位值（29.8746/121.5496）
    latitude: 29.8746,
    longitude: 121.5496,
    // TODO(视频号): 填门店视频号 username，例如 'sphXXXXXXXXXXXXXX'
    channelsUser: '',
    channelsLive: '',
    // 门店级视频：填 mp4/m3u8 直链才能内嵌；视频号链接会退化成「点开看视频」按钮
    videoUrl: '',
    routes: emptyRoutes(),
    images: [
      // TODO(到店指引): 换成真实引导图（前台/电梯/门头），最多 9 张；也可换成 https 外链
      {
        src: '/static/studio-oriental.png',
        caption: '到店后沿门厅指示牌进电梯，4 楼出电梯右手边即前台'
      },
      { src: '/static/hero.jpg', caption: '教室实景 · 自然光教室' }
    ]
  },
  {
    key: '东部店',
    sub: '江东 · 场馆',
    // TODO(馆主提供)：东部店档案地址待补 —— 空值时页面降级成「地址待补，可复制门店名」
    address: '',
    // TODO(馆主提供)：东部店电话待补 —— 空值时**不渲染拨号按钮**（避免无效拨号）
    phone: '',
    // TODO(馆主提供)：东部店真实坐标 —— 现在是占位值（29.8746/121.5496）
    latitude: 29.8746,
    longitude: 121.5496,
    channelsUser: '',
    channelsLive: '',
    videoUrl: '',
    routes: emptyRoutes(),
    images: [
      { src: '/static/studio-oriental.png', caption: '到店后前台出示本条指引，顾问带你熟悉场地' },
      { src: '/static/hero.jpg', caption: '教室实景 · 自然光教室' }
    ]
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
 * 沉浸头部的背景：用门店第一张实景图铺满，压一层纵向渐变让白色大字可读。
 * A 方案渐变收口到米灰 #f2f4f2（与页面底色一致，图「沉入」米灰世界）。
 * 没配图时返回空串 → 样式回落成纯深色，仍然成立（不是破图）。
 */
const heroStyle = computed(() => {
  const src = current.value?.images?.[0]?.src
  if (!src) return ''
  /* 八段渐变：深色层在 74% 收口，再经「灰 → 浅灰 → 底色」三档过渡（实测少于三档会有硬边）。 */
  return `background-image: linear-gradient(180deg, rgba(10,17,13,0.30) 0%, rgba(10,17,13,0.14) 26%, rgba(10,17,13,0.46) 54%, rgba(10,17,13,0.66) 74%, rgba(58,66,60,0.52) 84%, rgba(186,190,184,0.62) 92%, rgba(242,244,242,0.96) 97%, #f2f4f2 100%), url(${src})`
})

/**
 * 有没有视频号入口：**只看 channelsUser**。
 * channelsLive 不参与判定 —— UI 已下线该入口（馆主要求去掉那两个字）。
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
 * 判定顺序（宁可多给一次「点开看」，也不要塞进播放器里失败）：
 *  1. 不是 http(s)（`weixin://` 这类私有协议）→ 不能内嵌；
 *  2. 命中视频号 / 微信域名 → 不能内嵌（`<video>` 播不了，只会黑屏）；
 *  3. 其余 http(s) → 认为可以（含没写扩展名的 CDN 直链）。
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
 * 小程序沙箱里既拉不起 `weixin://`，也开不了外部浏览器，最可靠的是把链接复制给用户。
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
 * 为什么按字数而不是实测高度：小程序里量文本高度要 `createSelectorQuery`（异步、
 * 每次切路线都要重算，还可能拿到 0），成本远高于收益。中文宽度稳定，近似足够准。
 */
const NOTE_FOLD_CHARS = 100

/** 当前路线的 note 是否展开（切路线时重置，见 selectRoute） */
const noteExpanded = ref(false)

/**
 * note 按换行切段：切段后每个自然段是一个独立块，段落间距可控、换行 100% 保留，
 * 不依赖不同渲染层对 white-space 的支持差异。连续空行用 `\n+` 合并。
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

/** 切换出行方式（切段时把「展开」状态复位：在开车段点了展开，切到步行不应还是展开态） */
function selectRoute(key: RouteKey): void {
  routeTab.value = key
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
 * 一张图都没有时的兜底（包内实景图）。
 * caption 必须**门店中立**：这张图两家店共用，写「4 楼前台」之类具体到某家店的话，
 * 东部店顾客会被误导（楼层未知）。
 */
const FALLBACK_IMAGE: GuideImage = {
  src: '/static/studio-oriental.png',
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
 * 缺 `key` 的条目丢弃（没法选、也没法深链）；其余字段缺了就让降级逻辑接手。
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
    videoUrl: str(o.videoUrl),
    routes: toRoutes(o.routes),
    images: images.length ? images : [{ ...FALLBACK_IMAGE }]
  }
}

/**
 * 拉后台配置并替换渲染源。
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
}

function channelsApi(): ChannelsApi | null {
  // 小程序全局 `wx`；H5 / App 端没有这个对象，用 typeof 守住
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
    content: '门店视频号还在开通中，先按下面的图文指引到店即可；课程花絮会陆续放进来。',
    showCancel: false,
    confirmText: '知道了',
    confirmColor: '#2E7D5B'
  })
}

/**
 * 图片放大预览（包内静态图也能预览）。
 *
 * 参数是「传哪一组图」而不是只读门店级 images：页面上有两处图片组
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
 * 转发给客户（馆主原话：想把「绿地店到店指引」直接发给客户）。
 *  1. `path` 必须是**分包路径** `/pkg-public/guide/guide`；
 *  2. 还在「选门店」这一步时也能分享 —— 标题退化成品牌名、path 不带 venue；
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
    <!-- ========== 第一步：选门店（纯排版 · 大留白） ========== -->
    <view v-if="choosing" class="pick">
      <view class="pick__gold">
        <view class="pick__gold-line" />
        <text class="pick__gold-text">VISIT GUIDE</text>
      </view>
      <text class="pick__title">到店指引</text>
      <text class="pick__desc">选好门店，查看专属的到店路线</text>

      <!-- 竖排装饰：渐隐细线 + 竖排四字（右缘，与标题错层） -->
      <view class="pick__vert">
        <view class="pick__vert-line" />
        <text class="pick__vert-text">静候光临</text>
      </view>

      <view class="pick__label">
        <text class="pick__label-text">选择门店</text>
        <view class="pick__label-rule" />
      </view>

      <view class="pick__strips">
        <view
          v-for="v in guideVenues"
          :key="v.key"
          class="pickstrip ym-tap"
          @click="choose(v)"
        >
          <view class="pickstrip__main">
            <text class="pickstrip__name">{{ v.key }}</text>
            <text class="pickstrip__sub">{{ v.sub }}</text>
          </view>
          <view class="pickstrip__arrow">
            <view class="pickstrip__arrow-line" />
            <view class="pickstrip__arrow-head" />
          </view>
        </view>
      </view>

      <view class="pick__foot">
        <view class="pick__foot-line" />
        <text class="pick__foot-text">一麦瑜伽·普拉提</text>
      </view>
    </view>

    <!-- ========== 第二步：门店引导 ========== -->
    <template v-else>
      <!-- 沉浸头图：门店第一张实景 + 八段渐变沉入米灰；店名衬线大字压底部 -->
      <view class="ghead" :style="heroStyle">
        <view class="ghead__mark" />
        <text class="ghead__name">{{ current.key }}</text>
        <text class="ghead__sub">{{ current.sub }}</text>
      </view>

      <!-- 地址（图外浅底：地址是「要读清楚」的信息，放浅底比压照片上负责） -->
      <view class="addrline">
        <text class="addrline__text" :class="{ 'is-tbd': !current.address }">
          {{ current.address || ADDRESS_TBD_HINT }}
        </text>
        <text v-if="phoneReady" class="addrline__phone">
          门店电话 · {{ current.phone }}
        </text>
      </view>

      <!-- 两个大动作：导航 / 电话。都带降级（坐标占位→复制；号码待补→复制门店名） -->
      <view class="acts">
        <view class="acts__item ym-tap" @click="openMap(current)">
          <view class="acts__ico">
            <view class="acts__ico-nav" />
          </view>
          <text class="acts__text">导航前往</text>
          <text class="acts__hint">{{ mapHint }}</text>
        </view>
        <view
          v-if="phoneReady"
          class="acts__item acts__item--plain ym-tap"
          @click="callVenue(current)"
        >
          <view class="acts__ico">
            <view class="acts__ico-phone" />
          </view>
          <text class="acts__text">电话联系</text>
          <text class="acts__hint">{{ current.phone }}</text>
        </view>
        <view v-else class="acts__item acts__item--plain ym-tap" @click="copyVenueName(current)">
          <view class="acts__ico">
            <view class="acts__ico-copy" />
          </view>
          <text class="acts__text">复制门店名</text>
          <text class="acts__hint">电话待补 · 地图里搜</text>
        </view>
      </view>

      <!-- 视频号（细线分隔；UI 上不出现那两个字，channelsLive 字段保留但无入口） -->
      <view class="vcard">
        <view class="vcard__head">
          <text class="vcard__title">视频号</text>
          <view class="vcard__rule" />
        </view>
        <text class="vcard__desc">
          {{ videoReady ? '场馆日常与课程片段' : '待配置视频号' }}
        </text>
        <view v-if="videoReady" class="vcard__acts">
          <view class="vrow ym-tap" @click="openChannels">
            <view class="vrow__play"><view class="vrow__tri" /></view>
            <view class="vrow__txt">
              <text class="vrow__name">一麦瑜伽 · 视频号</text>
              <text class="vrow__sub">场馆日常 · 课程片段</text>
            </view>
            <view class="vrow__arrow">
              <view class="vrow__arrow-line" />
              <view class="vrow__arrow-head" />
            </view>
          </view>
        </view>
        <view v-else class="vcard__ph ym-tap" @click="videoPlaceholder">
          <text class="vcard__ph-text">
            这里会给到门店视频号入口；目前还没配置，先按下面的图文指引到店即可。
          </text>
        </view>
      </view>

      <!-- 门店视频：视频号入口下方；只有配了 videoUrl 才渲染，不留空壳 -->
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
        <view v-else class="vbtn vbtn--wide ym-tap" @click="openExternalVideo(current.videoUrl)">
          <text class="vbtn__text">点开看门店视频</text>
        </view>
      </view>

      <!-- 门店实景：图 + 图注（「路线」专指下面三种出行方式，避免撞车） -->
      <view class="imgs">
        <view class="imgs__labelrow">
          <text class="imgs__label">门店实景</text>
          <view class="imgs__rule" />
        </view>
        <view
          v-for="(img, i) in current.images"
          :key="img.src + i"
          class="fig ym-tap"
          @click="previewImage(current.images, i)"
        >
          <image class="fig__pic" :src="img.src" mode="aspectFill" />
          <view class="fig__cap">
            <view class="fig__dash" />
            <text class="fig__text">{{ img.caption }}</text>
            <text class="fig__zoom">点击放大</text>
          </view>
        </view>
      </view>

      <!-- 到店路线：三种出行方式各自独立渲染，绝不把三段的图混在一起 -->
      <view class="routes">
        <view class="imgs__labelrow">
          <text class="imgs__label">到店路线</text>
          <view class="imgs__rule" />
        </view>

        <!-- 下划线式 tab（A 方案语言：细线 + 留白，不用胶囊分段条） -->
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

        <!-- 路线导语（后台 note）：有值才渲染；长文折叠（阈值见 NOTE_FOLD_CHARS） -->
        <view v-if="noteParagraphs.length" class="rnote">
          <view class="rnote__body" :class="{ 'is-folded': noteFolded }">
            <text v-for="(p, i) in noteParagraphs" :key="i" class="rnote__p">{{ p }}</text>
          </view>
          <text v-if="noteNeedsFold" class="rnote__toggle ym-tap" @click="toggleNote">
            {{ noteExpanded ? '收起' : '展开全文' }}
          </text>
        </view>

        <!-- ① 这一段有视频：直链内嵌，非直链退化成跳转按钮 -->
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
          <view v-else class="vbtn vbtn--wide ym-tap" @click="openExternalVideo(activeRoute.videoUrl)">
            <text class="vbtn__text">点开看这段路线视频</text>
          </view>
        </template>

        <!-- ② 没视频但有图：只渲染这一段的图 -->
        <template v-else-if="activeRoute.images.length">
          <view
            v-for="(img, i) in activeRoute.images"
            :key="img.src + i"
            class="fig ym-tap"
            @click="previewImage(activeRoute.images, i)"
          >
            <image class="fig__pic" :src="img.src" mode="aspectFill" />
            <view class="fig__cap">
              <view class="fig__dash" />
              <text class="fig__text">{{ img.caption }}</text>
              <text class="fig__zoom">点击放大</text>
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
        <view class="switch__line" />
        <text class="switch__text">换一家门店</text>
        <view class="switch__line" />
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

/* ==========================================================================
 * A 方案「静奢东方」：米灰底 + 墨绿 + 香槟金（仅选店步标签）
 * 衬线大字（Songti SC）、发丝线分区、无卡片阴影（靠留白与细线立秩序）
 * ========================================================================== */

$gold: #c19a4b;
$serif: 'Songti SC', 'STSong', 'SimSun', serif;

.guide {
  padding: 0 0 calc(48rpx + env(safe-area-inset-bottom));
}

/* ---------- 第一步：选门店（纯排版 · 大留白） ---------- */
.pick {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 184rpx 62rpx 0;
}

.pick__gold {
  display: flex;
  align-items: center;
  gap: 20rpx;
}

.pick__gold-line {
  width: 44rpx;
  height: 1px;
  background: $gold;
}

.pick__gold-text {
  font-size: 19rpx;
  letter-spacing: 0.44em;
  color: $gold;
}

.pick__title {
  margin-top: 32rpx;
  font-family: $serif;
  font-weight: 300;
  font-size: 60rpx;
  letter-spacing: 0.22em;
  color: #1e2b25;
}

.pick__desc {
  margin-top: 28rpx;
  font-size: 24rpx;
  letter-spacing: 0.1em;
  color: #7c8a82;
}

/* 竖排装饰：渐隐细线 + 竖排四字 */
.pick__vert {
  position: absolute;
  right: 68rpx;
  top: 236rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 28rpx;
}

.pick__vert-line {
  width: 1px;
  height: 176rpx;
  background: linear-gradient(180deg, #c3cdc6, rgba(195, 205, 198, 0));
}

.pick__vert-text {
  writing-mode: vertical-rl;
  font-family: $serif;
  font-weight: 300;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #8a958e;
}

/* 宋式区块标题：小字 · 发丝线 · 宽字距 */
.pick__label {
  display: flex;
  align-items: center;
  gap: 24rpx;
  margin-top: 124rpx;
  margin-bottom: 28rpx;
}

.pick__label-text {
  font-family: $serif;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #5b6b62;
  white-space: nowrap;
}

.pick__label-rule {
  flex: 1;
  height: 1px;
  background: $hairline;
}

/* 门店条：竖向长条 + 发丝线分隔（与首页同款语言） */
.pick__strips {
  border-top: 1px solid $hairline;
  border-bottom: 1px solid $hairline;
}

.pickstrip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 30rpx 4rpx;

  & + & {
    border-top: 1px solid $hairline;
  }
}

.pickstrip__main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.pickstrip__name {
  font-family: $serif;
  font-weight: 400;
  font-size: 40rpx;
  letter-spacing: 0.1em;
  color: $ink-800;
}

.pickstrip__sub {
  margin-top: 12rpx;
  font-size: 21rpx;
  letter-spacing: 0.16em;
  color: $ink-400;
}

.pickstrip__arrow {
  position: relative;
  width: 64rpx;
  height: 24rpx;
  flex: none;
}

.pickstrip__arrow-line {
  position: absolute;
  left: 0;
  top: 50%;
  width: 48rpx;
  height: 1px;
  background: $brand-700;
  opacity: 0.55;
}

.pickstrip__arrow-head {
  position: absolute;
  right: 6rpx;
  top: 50%;
  width: 16rpx;
  height: 16rpx;
  border-top: 1px solid $brand-700;
  border-right: 1px solid $brand-700;
  transform: translateY(-50%) rotate(45deg);
  opacity: 0.55;
}

.pick__foot {
  margin-top: auto;
  padding: 72rpx 0 36rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 24rpx;
}

.pick__foot-line {
  width: 60rpx;
  height: 1px;
  background: #d5dcd6;
}

.pick__foot-text {
  font-size: 19rpx;
  letter-spacing: 0.42em;
  padding-left: 0.42em;
  color: #a6b1aa;
}

/* ---------- 门店头（沉浸式：整页唯一的「重」） ---------- */
.ghead {
  position: relative;
  display: flex;
  flex-direction: column;
  /* 内容贴底：店名压在图片下三分之一处，上方全部留给照片 */
  justify-content: flex-end;
  min-height: 600rpx;
  /* 底部 112rpx 专供渐变带：文字若压在这段上会被半透明米灰盖住 */
  padding: 40rpx 62rpx 112rpx;
  /* 满铺出血：左右不留白、不要圆角 */
  margin: 0;
  color: $white;
  background-color: #0f1b16;
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  overflow: hidden;
}

/* 店名上方的一小段白色细线（A 方案标志性的「线先于字」） */
.ghead__mark {
  width: 48rpx;
  height: 1px;
  background: rgba(255, 255, 255, 0.75);
  margin-bottom: 24rpx;
}

.ghead__name {
  font-family: $serif;
  /* 字重 400 + 大字号：靠尺寸和字距立层级，加粗反而变土 */
  font-weight: 400;
  font-size: 54rpx;
  letter-spacing: 0.14em;
  line-height: 1.15;
  text-shadow: 0 4rpx 40rpx rgba(0, 0, 0, 0.3);
}

.ghead__sub {
  margin-top: 18rpx;
  font-size: 22rpx;
  letter-spacing: 0.3em;
  color: rgba($white, 0.9);
}

/* ---------- 地址（图外浅底） ---------- */
.addrline {
  padding: 52rpx 62rpx 32rpx;
  border-bottom: 1rpx solid $hairline;
}

.addrline__text {
  font-size: 26rpx;
  line-height: 1.75;
  letter-spacing: 0.04em;
  color: $ink-600;
}

/* 地址待补时的降级文案：弱一档，读起来像「提示」而不是「地址」 */
.addrline__text.is-tbd {
  color: $ink-400;
  font-size: 24rpx;
}

.addrline__phone {
  display: block;
  margin-top: 14rpx;
  font-size: 22rpx;
  letter-spacing: 0.12em;
  color: $ink-400;
}

/* ---------- 导航 / 电话（1.5rpx 描边矩形） ---------- */
.acts {
  display: flex;
  gap: 24rpx;
  margin: 40rpx 62rpx 0;
}

.acts__item {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 10rpx;
  align-items: center;
  justify-content: center;
  min-height: 150rpx;
  padding: 24rpx 16rpx;
  /* 描边按钮：整页只有头部的照片是重的，按钮再实心就和照片抢重量 */
  color: #17241d;
  background: transparent;
  border: 1.5rpx solid #1c6047;
  border-radius: 8rpx;
}

.acts__item--plain {
  color: $ink-600;
  border: 1.5rpx solid #d5dcd6;
}

/* 纯 CSS 图标底（28px 视觉，60rpx 触达）：细线圆 + 内部形状 */
.acts__ico {
  width: 56rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

.acts__ico-nav {
  width: 24rpx;
  height: 24rpx;
  border: 1px solid currentColor;
  border-radius: 50% 50% 50 0;
  transform: rotate(-45deg);
  border-bottom-left-radius: 0;
  border-bottom-right-radius: 50%;
  position: relative;
}

.acts__ico-phone {
  width: 16rpx;
  height: 24rpx;
  border: 1px solid currentColor;
  border-radius: 4rpx;
  position: relative;

  &::before {
    content: '';
    position: absolute;
    top: 4rpx;
    left: 3rpx;
    right: 3rpx;
    height: 14rpx;
    border-top: 1px solid currentColor;
  }
}

.acts__ico-copy {
  width: 20rpx;
  height: 24rpx;
  border: 1px solid currentColor;
  border-radius: 3rpx;
  position: relative;

  &::before {
    content: '';
    position: absolute;
    top: -6rpx;
    left: -6rpx;
    width: 20rpx;
    height: 24rpx;
    border: 1px solid currentColor;
    border-radius: 3rpx;
    border-bottom: none;
    border-right: none;
  }
}

.acts__text {
  font-size: 28rpx;
  font-weight: 500;
  letter-spacing: 0.14em;
  color: #1c6047;
}

.acts__item--plain .acts__text {
  color: $ink-700;
}

.acts__hint {
  font-size: 21rpx;
  letter-spacing: 0.02em;
  color: $ink-400;
}

/* ---------- 视频号（细线分隔，无卡片底） ---------- */
.vcard {
  padding: 64rpx 62rpx 0;
  margin: 0;
}

.vcard__head {
  display: flex;
  align-items: center;
  gap: 24rpx;
}

.vcard__title {
  font-family: $serif;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #5b6b62;
  white-space: nowrap;
}

.vcard__rule {
  flex: 1;
  height: 1px;
  background: $hairline;
}

.vcard__desc {
  display: block;
  margin-top: 18rpx;
  font-size: 23rpx;
  color: $ink-400;
}

.vcard__acts {
  margin-top: 8rpx;
}

/* 视频号入口行：细线圆播放钮 + 名称 + 细箭头（A 方案语言） */
.vrow {
  display: flex;
  align-items: center;
  gap: 28rpx;
  padding: 26rpx 4rpx 8rpx;
}

.vrow__play {
  flex: none;
  width: 64rpx;
  height: 64rpx;
  border: 1px solid rgba(28, 96, 71, 0.45);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.vrow__tri {
  width: 0;
  height: 0;
  border-left: 14rpx solid #1c6047;
  border-top: 9rpx solid transparent;
  border-bottom: 9rpx solid transparent;
  margin-left: 5rpx;
}

.vrow__txt {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.vrow__name {
  font-family: $serif;
  font-size: 30rpx;
  letter-spacing: 0.08em;
  color: $ink-800;
}

.vrow__sub {
  font-size: 21rpx;
  letter-spacing: 0.14em;
  color: $ink-400;
}

.vrow__arrow {
  position: relative;
  width: 64rpx;
  height: 24rpx;
  flex: none;
}

.vrow__arrow-line {
  position: absolute;
  left: 0;
  top: 50%;
  width: 48rpx;
  height: 1px;
  background: #1c6047;
  opacity: 0.55;
}

.vrow__arrow-head {
  position: absolute;
  right: 6rpx;
  top: 50%;
  width: 16rpx;
  height: 16rpx;
  border-top: 1px solid #1c6047;
  border-right: 1px solid #1c6047;
  transform: translateY(-50%) rotate(45deg);
  opacity: 0.55;
}

.vcard__ph {
  padding: 26rpx 24rpx;
  margin-top: 12rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

.vcard__ph-text {
  font-size: 23rpx;
  line-height: 1.7;
  color: $ink-500;
}

/* ---------- 图文引导（门店实景 / 路线图共用 fig） ---------- */
.imgs,
.routes,
.videoblk {
  margin: 56rpx 62rpx 0;
}

.imgs__labelrow {
  display: flex;
  align-items: center;
  gap: 24rpx;
  margin-bottom: 24rpx;
}

.imgs__label {
  font-family: $serif;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #5b6b62;
  white-space: nowrap;
}

.imgs__rule {
  flex: 1;
  height: 1px;
  background: $hairline;
}

/* 图 + 图注：去卡片化（无底色/阴影），图注是「小字 + 短横线」 */
.fig {
  & + & {
    margin-top: 32rpx;
  }
}

.fig__pic {
  width: 100%;
  height: 400rpx;
  border-radius: 10rpx;
  background: $ink-100;
}

.fig__cap {
  display: flex;
  align-items: baseline;
  gap: 14rpx;
  padding: 18rpx 8rpx 0;
}

.fig__dash {
  flex: none;
  width: 28rpx;
  height: 1px;
  background: #c9d2cb;
  align-self: center;
}

.fig__text {
  flex: 1;
  min-width: 0;
  font-size: 24rpx;
  line-height: 1.6;
  color: $ink-500;
}

.fig__zoom {
  flex-shrink: 0;
  font-size: 21rpx;
  color: $brand-600;
}

/* ---------- 门店视频 / 路线视频 ---------- */
.videoblk__label {
  display: block;
  padding: 0 8rpx 24rpx;
  font-family: $serif;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #5b6b62;
}

/* 固定 16:9 高度 + object-fit: contain：竖屏视频会留黑边，但绝不裁掉画面
   （裁掉的往往是门头/指示牌，正是顾客要看的那部分） */
.videoblk__player {
  width: 100%;
  height: 400rpx;
  background: #000;
  border-radius: 10rpx;
}

/* 满宽按钮：视频号链接的降级入口（描边矩形，与导航按钮同一语言） */
.vbtn {
  display: flex;
  gap: 8rpx;
  align-items: center;
  justify-content: center;
  height: 92rpx;
  color: #1c6047;
  background: transparent;
  border: 1.5rpx solid #1c6047;
  border-radius: 8rpx;
}

.vbtn--wide {
  flex: none;
  width: 100%;
}

.vbtn__text {
  font-size: 27rpx;
  font-weight: 500;
  letter-spacing: 0.1em;
  color: #1c6047;
}

/* ---------- 路线导语（后台 note）：浅灰底 + 正文字号 ---------- */
.rnote {
  padding: 28rpx 32rpx;
  margin-bottom: 24rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

/* 折叠态限高 6 行（25rpx × 1.75 ≈ 44rpx/行）：首屏仍能看到视频/图片的入口 */
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
  margin-top: 16rpx;
  font-size: 23rpx;
  color: $brand-600;
}

/* ---------- 到店路线分段：下划线式 tab ---------- */
.rtabs {
  display: flex;
  gap: 64rpx;
  padding: 0;
  margin-bottom: 32rpx;
  background: transparent;
  border-bottom: 1rpx solid $hairline;
}

.rtab {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 72rpx;
  padding-bottom: 20rpx;
  border-radius: 0;

  /* 下划线标签：伪元素在 wxss 里可能被丢弃（本项目实测过），
     所以下划线用 border-bottom 而不是 ::after */
  &.is-on {
    border-bottom: 2rpx solid #1e2b25;

    .rtab__text {
      font-weight: 500;
      color: #1e2b25;
    }
  }
}

.rtab__text {
  font-size: 25rpx;
  letter-spacing: 0.1em;
  color: $ink-500;
}

/* ---------- 路线指引待补充 ---------- */
.rtempty {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  padding: 48rpx 36rpx;
  background: $ink-50;
  border-radius: 10rpx;
}

.rtempty__text {
  font-size: 27rpx;
  font-weight: 500;
  color: $ink-600;
}

.rtempty__desc {
  font-size: 23rpx;
  line-height: 1.7;
  color: $ink-400;
}

/* ---------- 换店：细字链（左右两根短横线） ---------- */
.switch {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 28rpx;
  margin: 64rpx 62rpx 0;
  height: 92rpx;
}

.switch__line {
  width: 52rpx;
  height: 1px;
  background: #d3dbd4;
}

.switch__text {
  font-size: 24rpx;
  letter-spacing: 0.3em;
  padding-left: 0.3em;
  color: $brand-700;
}
</style>
