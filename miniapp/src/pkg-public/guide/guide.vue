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
// 博客栏目（官网好文，后端代理 WP REST —— 见 api/blog.ts 头注释）
import { fetchBlogCards, type BlogCard } from '@/api/blog'
// absolutizeImg 需要：把后台下发的 /api/... 相对路径图补全成绝对 URL
import { API_BASE } from '@/utils/http'

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
  /** mp4/m3u8 等直链；空 = 没配 */
  videoUrl: string
  /** 视频号视频 ID（export/UzFf…）；与 videoUrl 各填各的，这里优先播 */
  finderVideo: string
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
  /**
   * 门店顶图（到店指引头部那张大图）。后台可单独配置；
   * 空串时由取图逻辑回落到 images[0]，已配过门店实景的店不用重填。
   */
  heroImage: string
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
    drive: { note: '', videoUrl: '', finderVideo: '', images: [] },
    transit: { note: '', videoUrl: '', finderVideo: '', images: [] },
    walk: { note: '', videoUrl: '', finderVideo: '', images: [] }
  }
}

const DEFAULT_VENUES: GuideVenue[] = [
  {
    key: '绿地店',
    sub: '绿地缤纷城 · 4 楼',
    address: '宁波市江北区绿地缤纷城1号楼4楼',
    phone: '0574-87214863',
    heroImage: '',
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
    heroImage: '',
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

/* ---------- 主题（BE5：editorial 默认 | oriental） ----------
 * 经典主题（深绿块头 + 方块按钮 + 卡片分区）已于 v3.16 弃用：
 * 馆主选定「编辑（B）」与「静奢东方（A）」两套，classic 不再提供。
 * 后端若还发 `classic`（存量配置），一律按 editorial 渲染 —— 见下面 guideTheme 的归一。
 */

/** 后台下发的原始主题值（未归一）；loadRemoteConfig 拿到配置后写入 */
const themeRaw = ref('')

/**
 * 主题归一。
 *
 * 契约只有 `editorial` / `oriental`，**缺省、classic 与任何未知值一律当 editorial**：
 * 这页是顾客扫码进来的对外页，宁可退回已定稿的默认主题，也不能因为后端写了个
 * 已弃用的值就渲染成没有样式的半成品。api 层刻意不校验（见 api/miniapp.ts 的注释），
 * 归一只有这一处。
 */
const guideTheme = computed<'editorial' | 'oriental'>(() =>
  themeRaw.value === 'oriental' ? 'oriental' : 'editorial'
)
const isOriental = computed(() => guideTheme.value === 'oriental')

/**
 * 沉浸头部的背景：用门店第一张实景图铺满，压一层纵向渐变让白色大字可读。
 *
 * 取图顺序：`heroImage`（后台单独配的门店顶图）→ `images[0]`（门店实景第一张）→ 空。
 * 早先用的是「就用 images[0]，不新开字段」，理由是少填一处；但馆主反馈顶图想单独换
 * （实景图是横向展示用的，顶图是竖幅大图，构图要求不同），所以补了 heroImage，
 * 并保留 images[0] 作为回落——已配过实景的店不用重填也能正常显示。
 * 都没配时返回空串 → 样式回落成纯深色，仍然成立（不是破图）。
 */
const heroStyle = computed(() => {
  const src = current.value?.heroImage || current.value?.images?.[0]?.src
  if (!src) return ''
  /* 八段渐变：深色层在 74% 收口，再经「灰 → 浅灰 → 底色」三档过渡。
       少于三档时，深色会直接跳到 #fcfbf8，在内容区交界处留一条可见硬边（实测过）。 */
  return `background-image: linear-gradient(180deg, rgba(10,17,13,0.30) 0%, rgba(10,17,13,0.14) 26%, rgba(10,17,13,0.46) 54%, rgba(10,17,13,0.66) 74%, rgba(58,66,60,0.52) 84%, rgba(186,190,184,0.62) 92%, rgba(252,251,248,0.96) 97%, #fcfbf8 100%), url(${src})`
})

/**
 * oriental 的沉浸头：同一张图、同一组渐变节点，只把**末两段换成米灰 #f2f4f2**，
 * 让图「沉入」静奢东方的底色（不是 editorial 的暖白 #fcfbf8）。
 * 数值直接取自 miniapp-ui-a 的定稿实现（那是馆主拍板的 A 方案），不重新调。
 */
const heroStyleOriental = computed(() => {
  const src = current.value?.heroImage || current.value?.images?.[0]?.src
  if (!src) return ''
  return `background-image: linear-gradient(180deg, rgba(10,17,13,0.30) 0%, rgba(10,17,13,0.14) 26%, rgba(10,17,13,0.46) 54%, rgba(10,17,13,0.66) 74%, rgba(58,66,60,0.52) 84%, rgba(186,190,184,0.62) 92%, rgba(242,244,242,0.96) 97%, #f2f4f2 100%), url(${src})`
})

/**
 * 有没有视频号入口：**只看 channelsUser**。
 * channelsLive 不再参与判定 —— UI 已下线该入口（馆主要求去掉那两个字），
 * 若仍把它算进来，会出现「按钮组渲染出来了、点下去却是占位弹窗」的假交互。
 */
const videoReady = computed(() => Boolean(current.value.channelsUser))

/* ---------- 视频：直链 vs 视频号链接 ---------- */

/**
 * 值看起来像「视频号视频 ID」而不是 URL。
 *
 * 馆主在视频号里点「复制视频 ID」拿到的那串（形如 `export/UzFfAgtgekIEAQAAAAAA...`
 * 或纯字母数字串），喂给 `<channel-video feed-id>` 就能在小程序里内嵌播放。
 *
 * 判定故意保守：只要是 http(s):// 或 / 开头就当 URL 走原逻辑，
 * 其余且长度 ≥ 10、无空格 → 当视频号 ID。宁可判错成 URL（走降级按钮），
 * 也不要把一段乱七八糟的文字塞进原生组件里（那会空白无提示）。
 */
function looksLikeFeedId(v: string): boolean {
  const s = (v || '').trim()
  if (!s || s.startsWith('/') || /^https?:\/\//i.test(s)) return false
  // 与后端 isAllowedVideoRef 同一套规则：`export/<id>` 或纯字母数字串。
  // 不要放宽成「任意含斜杠的串」，那会把相对路径也当成视频号 ID。
  return /^(export\/)?[A-Za-z0-9_-]{10,}$/.test(s)
}

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

/**
 * 三种出行方式**全都没配内容**时，整个「到店路线」区块不渲染。
 *
 * 为什么：空态时区块里只剩一句「该路线指引待补充」，而它上下还各有分隔线与切换条，
 * 真机上看起来就像页面里多挂了一个空壳（馆主就把它误认为「重复的到店路线」了）。
 * 没内容就不占版面，比留一个「待补充」更干净——顾客到店主要靠上面的导航按钮，
 * 路线只是补充信息。
 */
const hasAnyRouteContent = computed(() => {
  const r = current.value?.routes
  if (!r) return false
  return (['drive', 'transit', 'walk'] as const).some((k) => {
    const seg = r[k]
    return Boolean(
      seg && (seg.note?.trim() || seg.videoUrl || seg.finderVideo || (seg.images?.length ?? 0) > 0)
    )
  })
})

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
 * 图片地址归一（canonical owner：所有「拿来显示」的图都过这里）。
 *
 * 后台「上传」得来的图存的是**相对路径**（`/api/miniapp/uploads/xxx.jpg`）。
 * 在小程序里，`/` 开头的相对路径会被当成**包内文件**解析
 * （`<image src="/api/...">`、CSS `url(/api/...)`、`previewImage` 全都 404），
 * 页面上就只剩渐变压在墨绿底色上 —— 2026-11-07「顶图上传成功但小程序不显示」即此因。
 *
 * 规则：
 *  - `/api/...` 开头 → 拼 API_BASE 补全成绝对 URL（API_BASE 已含 /api，先剥掉防双拼）；
 *  - 其它 `/` 开头 → 包内静态图（/static/...），原样保留；
 *  - https 直链 → 原样保留。
 * 只管图片地址；视频引用（可能是视频号 ID，不是 URL）不走这里。
 */
function absolutizeImg(src: string): string {
  if (src.startsWith('/api/')) {
    const base = API_BASE.replace(/\/api\/?$/, '')
    return base + src
  }
  return src
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
  return src ? { src: absolutizeImg(src), caption: str(o.caption) } : null
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
  // finderVideo：后台拆栏后的「视频号视频 ID」；老后端不下发时按空处理（回落直链）
  return { note: str(o.note), videoUrl: str(o.videoUrl), finderVideo: str(o.finderVideo), images }
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
    // 顶图也要 absolutizeImg：它以 CSS background-image 的形式渲染，
    // 相对路径 /api/... 在 wxss 里一样解析不到（就是顶图只剩渐变底的原因）
    heroImage: absolutizeImg(str(o.heroImage)),
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
    // 主题与门店列表相互独立：即便 venues 为空（沿用本地默认值），后台选的主题也该生效。
    // 归一在 guideTheme 里做，这里只负责把「后端到底发了什么」原样存下来。
    themeRaw.value = cfg.theme ?? ''
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
  // 博客栏目（官网好文）：选门店页与门店详情页底部共用一份数据，加载失败静默不渲染
  void loadBlog()
})

/** 博客栏目数据（官网 WP 经后端代理）；空数组 = 今天不显示栏目 */
const blogCards = ref<BlogCard[]>([])

async function loadBlog(): Promise<void> {
  const r = await fetchBlogCards(3)
  blogCards.value = r.items
}

/** 点文章卡进站内文章页（pkg-public/blog/article，同分包） */
function openArticle(c: BlogCard): void {
  uni.navigateTo({ url: `/pkg-public/blog/article?id=${c.id}` })
}

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

/**
 * editorial 的「复制地址」独立入口。
 * 地址为空时退化成复制门店名 —— 与 openMap 的降级同一口径，
 * 不会出现「写着复制地址，复制出来是个空字符串」。
 */
function copyAddress(v: GuideVenue): void {
  if (v.address) copyText(v.address, '地址已复制')
  else copyVenueName(v)
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
  <view class="ym-page guide" :class="`guide--${guideTheme}`">
    <!-- 第一步：选门店。两套主题是两种语言（editorial 大留白排版 / oriental 竖排题签），各写一份 -->
    <block v-if="choosing">
      <block v-if="!isOriental">
        <view class="pick">
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

                <!-- 官网好文（置顶优先 + 最新补足，后端代理 WP；空 = 静默不渲染） -->
                <view v-if="blogCards.length" class="blog">
                  <view class="imgs__labelrow blog__head">
                    <text class="imgs__label">一麦 · 好文</text>
                    <view class="imgs__rule" />
                  </view>
                  <view
                    v-for="c in blogCards"
                    :key="c.id"
                    class="blogcard ym-tap"
                    @click="openArticle(c)"
                  >
                    <image v-if="c.cover" class="blogcard__cover" :src="c.cover" mode="aspectFill" />
                    <view class="blogcard__main">
                      <text class="blogcard__title">{{ c.title }}</text>
                      <text class="blogcard__date">{{ c.sticky ? '置顶 · ' : '' }}{{ c.date }}</text>
                    </view>
                  </view>
                </view>
        </view>
      </block>
      <block v-if="isOriental">
        <view class="pick">
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

                <!-- 官网好文（与 editorial 版同数据；空 = 静默不渲染） -->
                <view v-if="blogCards.length" class="blog">
                  <view class="imgs__labelrow blog__head">
                    <text class="imgs__label">一麦 · 好文</text>
                    <view class="imgs__rule" />
                  </view>
                  <view
                    v-for="c in blogCards"
                    :key="c.id"
                    class="blogcard ym-tap"
                    @click="openArticle(c)"
                  >
                    <image v-if="c.cover" class="blogcard__cover" :src="c.cover" mode="aspectFill" />
                    <view class="blogcard__main">
                      <text class="blogcard__title">{{ c.title }}</text>
                      <text class="blogcard__date">{{ c.sticky ? '置顶 · ' : '' }}{{ c.date }}</text>
                    </view>
                  </view>
                </view>

                <view class="pick__foot">
                  <view class="pick__foot-line" />
                  <text class="pick__foot-text">一麦瑜伽·普拉提</text>
                </view>
        </view>
      </block>
    </block>

    <!-- 第二步：门店引导（用独立 v-if 而不是 v-else：条件互斥，结果确定） -->
    <block v-if="!choosing">
      <!--
        头部与动作两套主题结构不同，各写一份（editorial：沉浸头 + B 方案按钮；oriental：静奢东方）。
        editorial 的 ghead 才接 heroStyle；oriental 用 heroStyleOriental（渐变末段沉入米灰）。
      -->
      <!-- editorial：沉浸头 + 地址外置 + B 方案按钮 -->
      <block v-if="!isOriental">
        <view class="ghead is-editorial" :style="heroStyle">
          <text class="ghead__label">YIMAI · PILATES</text>
          <text class="ghead__name">{{ current.key }}</text>
          <text class="ghead__sub">{{ current.sub }}</text>
        </view>

        <!-- 地址移到图外：图片底部要留渐变带，四行文字压在一起会挤，
             而且地址是「要读清楚」的信息，放在浅底上比压在照片上更负责 -->
        <view class="addrline">
          <text class="addrline__text" :class="{ 'is-tbd': !current.address }">
            {{ current.address || ADDRESS_TBD_HINT }}
          </text>
        </view>

        <view class="acts-ed">
          <!-- 主按钮：无坐标时文案直接变成「复制门店地址」——
               写了「地图导航」点下去却是复制，比改一行文案更让人意外 -->
          <view class="acts-ed__main ym-tap" @click="openMap(current)">
            <wd-icon name="location" size="15px" color="#FFFFFF" />
            <text class="acts-ed__main-text">{{ coordsReady ? '地图导航' : '复制门店地址' }}</text>
          </view>
          <!-- 两个纯文字入口。无坐标时「复制地址」不渲染：
               主按钮已经承担同一个降级动作，两个入口指向同一件事只会让人犹豫 -->
          <view class="acts-ed__sub">
            <view v-if="phoneReady" class="acts-ed__link ym-tap" @click="callVenue(current)">
              <wd-icon name="phone" size="12px" color="#8A948F" />
              <text class="acts-ed__link-text">拨打电话</text>
            </view>
            <view v-else class="acts-ed__link ym-tap" @click="copyVenueName(current)">
              <wd-icon name="copy" size="12px" color="#8A948F" />
              <text class="acts-ed__link-text">复制门店名</text>
            </view>
            <view v-if="coordsReady" class="acts-ed__link ym-tap" @click="copyAddress(current)">
              <wd-icon name="copy" size="12px" color="#8A948F" />
              <text class="acts-ed__link-text">复制地址</text>
            </view>
          </view>
        </view>
      </block>
      <block v-if="isOriental">
        <!--
          oriental：静奢东方（A 方案，移植自 miniapp-ui-a 定稿实现）
          ⚠️ 这段注释必须留在 template **内部**：v-else 与上面的 </template> 之间
          不能夹任何节点（包括注释）——uni-app 的小程序编译器不会跳过注释，
          一旦隔断，v-else 就认不到前面的 v-if，整个分支被当成普通块渲染，
          两个主题的内容会同时出现在真机上（v3.17.1 就是这么翻车的）。
        -->
              <!-- 沉浸头图：门店第一张实景 + 八段渐变沉入米灰；店名衬线大字压底部 -->
              <view class="ghead" :style="heroStyleOriental">
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
                  {{ videoReady ? '' : '待配置视频号' }}
                </text>
                <!-- 视频号视频：后台填了「视频号视频 ID」就直接内嵌播放，不用跳转 -->
                <channel-video
                  v-if="current.channelsLive"
                  :feed-id="current.channelsLive"
                  class="vcard__inline-video"
                />
                <view v-if="videoReady" class="vcard__acts">
                  <view class="vrow ym-tap" @click="openChannels">
                    <view class="vrow__play"><view class="vrow__tri" /></view>
                    <view class="vrow__txt">
                      <text class="vrow__name">一麦瑜伽 · 视频号</text>
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
                <channel-video
                  v-if="looksLikeFeedId(current.videoUrl)"
                  :feed-id="current.videoUrl"
                  class="videoblk__player"
                />
                <video
                  v-else-if="directVideo(current.videoUrl)"
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


              <!-- 门店实景：门店级引导图（门头 / 前台 / 电梯），与下面的「到店路线」分工不同 -->
              <view v-if="current.images.length" class="imgs">
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
                    <!-- 只把「短的」当图注：后台常把整段路线说明填在实景图注里，
                         那类文字和下面「到店路线」重复，超过 20 字不渲染 -->
                    <text v-if="(img.caption || '').length <= 20" class="fig__text">{{ img.caption }}</text>
                    <text class="fig__zoom">点击放大</text>
                  </view>
                </view>
              </view>

              <!-- 到店路线：三种出行方式各自独立渲染，绝不把三段的图混在一起；
                   三种都没配内容时整块不渲染（见 hasAnyRouteContent 的注释） -->
              <view v-if="hasAnyRouteContent" class="routes">
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

                <!-- ① 这一段有视频：优先视频号视频内嵌；直链内嵌；其余退化成跳转按钮 -->
                <block v-if="activeRoute.finderVideo || activeRoute.videoUrl">
                  <channel-video
                    v-if="activeRoute.finderVideo"
                    :feed-id="activeRoute.finderVideo"
                    class="videoblk__player"
                  />
                  <channel-video
                    v-else-if="looksLikeFeedId(activeRoute.videoUrl)"
                    :feed-id="activeRoute.videoUrl"
                    class="videoblk__player"
                  />
                  <video
                    v-else-if="directVideo(activeRoute.videoUrl)"
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
                </block>

                <!-- ② 没视频但有图：只渲染这一段的图 -->
                <block v-else-if="activeRoute.images.length">
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
                </block>

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

              <!-- 官网好文：门店详情最底（空 = 静默不渲染） -->
              <view v-if="blogCards.length" class="blog">
                <view class="imgs__labelrow blog__head">
                  <text class="imgs__label">一麦 · 好文</text>
                  <view class="imgs__rule" />
                </view>
                <view
                  v-for="c in blogCards"
                  :key="c.id"
                  class="blogcard ym-tap"
                  @click="openArticle(c)"
                >
                  <image v-if="c.cover" class="blogcard__cover" :src="c.cover" mode="aspectFill" />
                  <view class="blogcard__main">
                    <text class="blogcard__title">{{ c.title }}</text>
                    <text class="blogcard__date">{{ c.sticky ? '置顶 · ' : '' }}{{ c.date }}</text>
                  </view>
                </view>
              </view>
      </block>

      <!--
        编辑主题的后半段：视频号 → 门店实景 → 到店路线 → 换门店。

        ⚠️ 这段从 v3.16 的 oriental 移植起就**裸露在主题条件之外**，导致东方主题下
        它和 oriental 那套一起渲染 —— 真机上「门店实景 / 到店路线」出现两份就是这个原因。
        现在单独套一层 !isOriental，和上面那半段（头/地址/按钮）条件一致。
        （没有把两段合并成一个 block：搬 140 行风险高于收益，条件覆盖即可。）
      -->
      <block v-if="!isOriental">
      <!-- 视频号（UI 上不出现那两个字；channelsLive 字段仍在，只是没有入口） -->
      <view class="vcard">
        <view class="vcard__head">
          <text class="vcard__title">视频号</text>
          <text class="vcard__desc">
            {{ videoReady ? '' : '待配置视频号' }}
          </text>
          <!-- 视频号视频：后台填了「视频号视频 ID」就直接内嵌播放，不用跳转 -->
          <channel-video
            v-if="current.channelsLive"
            :feed-id="current.channelsLive"
            class="vcard__inline-video"
          />
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
        <channel-video
          v-if="looksLikeFeedId(current.videoUrl)"
          :feed-id="current.videoUrl"
          class="videoblk__player"
        />
        <video
          v-else-if="directVideo(current.videoUrl)"
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


      <!-- 门店实景：与「到店路线」分工不同——这里放门头/前台/电梯，路线区放怎么走 -->
      <view v-if="current.images.length" class="imgs">
        <text class="imgs__label">门店实景</text>
        <view
          v-for="(img, i) in current.images"
          :key="img.src + i"
          class="imgcard ym-tap"
          @click="previewImage(current.images, i)"
        >
          <image class="imgcard__pic" :src="img.src" mode="aspectFill" />
          <view class="imgcard__cap">
            <text v-if="(img.caption || '').length <= 20" class="imgcard__text">{{ img.caption }}</text>
            <text class="imgcard__zoom">点击放大</text>
          </view>
        </view>
      </view>

      <!-- 到店路线：三种出行方式各自独立渲染；三种都没配内容时整块不渲染（同 oriental 分支） -->
      <view v-if="hasAnyRouteContent" class="routes">
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

        <!-- ① 这一段有视频：优先视频号视频内嵌；直链内嵌；其余退化成跳转按钮 -->
        <block v-if="activeRoute.finderVideo || activeRoute.videoUrl">
          <channel-video
            v-if="activeRoute.finderVideo"
            :feed-id="activeRoute.finderVideo"
            class="videoblk__player"
          />
          <channel-video
            v-else-if="looksLikeFeedId(activeRoute.videoUrl)"
            :feed-id="activeRoute.videoUrl"
            class="videoblk__player"
          />
          <video
            v-else-if="directVideo(activeRoute.videoUrl)"
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
        </block>

        <!-- ② 没视频但有图：只渲染这一段的图 -->
        <block v-else-if="activeRoute.images.length">
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
        </block>

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

      <!-- 官网好文：门店详情最底（editorial 分支；空 = 静默不渲染） -->
      <view v-if="blogCards.length" class="blog">
        <view class="imgs__labelrow blog__head">
          <text class="imgs__label">一麦 · 好文</text>
          <view class="imgs__rule" />
        </view>
        <view
          v-for="c in blogCards"
          :key="c.id"
          class="blogcard ym-tap"
          @click="openArticle(c)"
        >
          <image v-if="c.cover" class="blogcard__cover" :src="c.cover" mode="aspectFill" />
          <view class="blogcard__main">
            <text class="blogcard__title">{{ c.title }}</text>
            <text class="blogcard__date">{{ c.sticky ? '置顶 · ' : '' }}{{ c.date }}</text>
          </view>
        </view>
      </view>
      </block>
    </block>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

/* oriental 专用的两个本地变量（tokens 里没有；只在这页用，不污染全局）
$serif: 'Songti SC', 'STSong', 'SimSun', serif;
$gold: #c19a4b;

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

/* ---------- 视频号 / 门店视频 ---------- */
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

/* ---------- 门店视频 / 路线视频 ---------- */
/* 固定 16:9 高度 + object-fit: contain：竖屏视频会留黑边，但绝不裁掉画面
   （裁掉的往往是门头/指示牌，正是顾客要看的那部分） */
/* 满宽按钮：视频号链接的降级入口，与路线段里的跳转按钮共用同一个类 */
.vbtn--wide {
  flex: none;
  width: 100%;
}

/* ==========================================================================
 * editorial 基线（B 方案沉浸式，v3.16 起是**默认主题**）
 *
 * 经典主题已于 v3.16 弃用，所以这一层从「覆盖层」提升为基线（无前缀）。
 * oriental 的全部样式收在 .guide--oriental 下，两者互不干扰，没有 display 覆盖。
 * ========================================================================== */

.ghead {
  position: relative;
  display: flex;
  flex-direction: column;
  /* 内容贴底：店名压在图片下三分之一处，上方全部留给照片 */
  justify-content: flex-end;
  min-height: 600rpx;
  /* 底部 56rpx 专供渐变带：文字若压在这段上会被半透明白盖住（这是位置问题，不是配色问题） */
  padding: 40rpx 56rpx 56rpx;
  /* 满铺出血：左右不留白、不要圆角。圆角会立刻把「沉浸」变回「卡片」 */
  margin: 0;
  color: $white;
  background-color: #0f1b16;
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  overflow: hidden;
}

.ghead__name {
  /* 字重 300 + 大字号：靠尺寸立层级，加粗反而变土 */
  font-size: 78rpx;
  font-weight: 300;
  letter-spacing: 0.06em;
  line-height: 1.08;
  text-shadow: 0 4rpx 40rpx rgba(0, 0, 0, 0.3);
}

.ghead__sub {
  margin-top: 14rpx;
  font-size: 24rpx;
  letter-spacing: 0.04em;
  color: rgba($white, 0.9);
}

.vcard {
  /* 去卡片：不再有独立底色/圆角/阴影，靠上下留白与细线跟相邻区块分开 */
  padding: 0;
  margin: 40rpx 56rpx 0;
  padding-top: 32rpx;
  border-top: 1rpx solid $hairline;
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

/*
  channel-video 是原生组件：宽高必须给死，否则可能渲染成 0 高（页面上看就是「什么都没有」）。
  馆主在后台填的「视频号视频 ID」就是从这里播出来的。
*/
.vcard__inline-video {
  display: block;
  width: 100%;
  height: 420rpx;
  margin-top: 20rpx;
  border-radius: 12rpx;
  overflow: hidden;
}

.vcard__acts {
  display: flex;
  gap: 16rpx;
  margin-top: 20rpx;
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

.imgs {
  margin: 40rpx 56rpx 0;
  padding-top: 32rpx;
  border-top: 1rpx solid $hairline;
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

.videoblk__player {
  width: 100%;
  height: 400rpx;
  background: #000;
  border-radius: 18rpx;
}

.routes {
  margin: 40rpx 56rpx 0;
  padding-top: 32rpx;
  border-top: 1rpx solid $hairline;
}

.rnote {
  padding: 20rpx 22rpx;
  margin-bottom: 20rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

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
  /* 胶囊分段条是「后台控件」的典型长相；改成下划线标签，
     和整页的「细线 + 留白」语言一致 */
  display: flex;
  gap: 44rpx;
  padding: 0;
  margin-bottom: 26rpx;
  background: transparent;
  border-radius: 0;
  border-bottom: 1rpx solid $hairline;
}

.rtab {
  /* 下划线标签：去掉胶囊底与选中白块，靠字色与一条 2rpx 下划线表态 */
  position: relative;
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  height: 64rpx;
  padding-bottom: 14rpx;
  border-radius: 0;

  &.is-on {
    background: transparent;
    box-shadow: none;

    /* 伪元素在 wxss 里可能被丢弃（本项目实测过 ::before 丢失），
       所以下划线用 border-bottom 而不是 ::after */
    border-bottom: 2rpx solid $ink-800;
  }
}

.rtab__text {
  font-size: 25rpx;
  color: $ink-500;
}

.rtab.is-on .rtab__text {
  font-weight: 500;
  color: $ink-800;
}

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

/* ==========================================================================
 * 官网好文栏目（选门店页底 + 门店详情页底，两主题共用一套卡片；
 * 数据空时整块不渲染，这里不会有空态样式）
 * ========================================================================== */
.blog {
  margin-top: 56rpx;
  padding: 0 24rpx;
}

/* 基础版 labelrow：oriental 已有前缀覆盖，这里给 editorial 下的博客栏兜底
   （editorial 的门店实景区用的是纯 imgs__label，没有 labelrow —— 博客栏是新引入者） */
.blog .imgs__labelrow {
  display: flex;
  align-items: center;
  gap: 20rpx;
}

.blog .imgs__rule {
  flex: 1;
  height: 1rpx;
  background: $hairline;
}

.blog__head {
  margin-bottom: 20rpx;
}

.blogcard {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 22rpx 24rpx;
  background: $white;
  border-radius: 16rpx;

  /* 卡片之间细缝，不用线分隔（与实景图卡的间隔语言一致） */
  & + & {
    margin-top: 16rpx;
  }
}

.blogcard__cover {
  width: 168rpx;
  height: 120rpx;
  flex: none;
  border-radius: 10rpx;
  background: $ink-50;
}

.blogcard__main {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  min-width: 0;
}

.blogcard__title {
  /* 两行截断：标题超过两行就用省略号，栏目卡是入口不是正文 */
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 27rpx;
  font-weight: 500;
  line-height: 1.45;
  color: $ink-800;
}

.blogcard__date {
  font-size: 21rpx;
  letter-spacing: 0.08em;
  color: $ink-400;
}

/* oriental 主题微调：白卡在米灰底上要去圆角、改细线描边，贴合「发丝线分区」语言 */
.guide--oriental .blogcard {
  border: 1rpx solid rgba(34, 51, 43, 0.1);
  border-radius: 4rpx;
  box-shadow: none;
}

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

/* ==========================================================================
 * editorial 专属：B 方案按钮（整宽主按钮 + 两个文字入口）
 *
 * 与 oriental 的 `.acts`（发丝线描边按钮）是**两套 DOM**，所以这里不需要任何
 * display 覆盖去和方块样式打架 —— 这是「结构差异用 v-if」换来的好处。
 * ========================================================================== */

.acts-ed {
  margin: 40rpx 56rpx 0;
  padding-bottom: 36rpx;
  /* 与下一区块（视频号）用细线分开，代替卡片阴影 */
  border-bottom: 1rpx solid $hairline;
}

.acts-ed__main {
  display: flex;
  gap: 12rpx;
  align-items: center;
  justify-content: center;
  /* 50px：拇指落点比 44px 更从容；再高就开始抢头部的注意力 */
  height: 50px;
  background: #17241d;
  border-radius: 4rpx;
}

.acts-ed__main-text {
  font-size: 15px;
  font-weight: 500;
  letter-spacing: 0.04em;
  color: $white;
}

.acts-ed__sub {
  display: flex;
  gap: 40px;
  align-items: center;
  justify-content: center;
  margin-top: 26rpx;
}

.acts-ed__link {
  display: flex;
  gap: 8rpx;
  align-items: center;
}

.acts-ed__link-text {
  font-size: 12px;
  color: $ink-400;
}

/* ==========================================================================
 * editorial 专属：图片出血
 *
 * 细线分区的左右基准是 56rpx（.imgs / .routes 的内边距），图片要贴到屏幕边缘，
 * 就用负 margin 把这 56rpx 抵回来 —— 比在每个父容器上写 padding: 0 再给
 * 其它子元素另加内边距更省事，也不会把标题与文字一起顶出边界。
 * ========================================================================== */

.imgs .imgcard,
.routes .imgcard {
  margin: 0 -56rpx;
  border-radius: 0;
  box-shadow: none;
}

/* 出血图之间紧贴，不留在卡片时代的 20rpx 缝 */
.imgs .imgcard + .imgcard,
.routes .imgcard + .imgcard {
  margin-top: 0;
}

/* 出血后图更高一些：满宽 460rpx 约等于 4:3 的门店实拍，不至于把人像裁成一条 */
.imgcard__pic {
  height: 460rpx;
}


/* ==========================================================================
 * oriental：静奢东方（A 方案，2026-11 移植自 miniapp-ui-a 的定稿实现）
 *
 * 与 editorial 的区别不是「换配色」，而是两套排版语言：
 *  - 米灰底 #f2f4f2 + 衬线（Songti SC）+ 发丝线分区（1px，无圆角无阴影）
 *  - 墨绿 #1c6047 用于文字与描边，香槟金 #c19a4b 只出现在小标签与短线上
 *  - 按钮是 1.5rpx 墨绿描边的直角框；路线 tab 是发丝线下划线（选中金色）
 *  - 选店页是大留白 + 衬线大标 + 右缘竖排「静候光临」
 *
 * 整段样式都收在 .guide--oriental 下：与 editorial 的基线（无前缀）互不干扰，
 * 也没有任何 display 覆盖 —— 换主题不会有样式互相打架。
 * ========================================================================== */

/* ==========================================================================
 * A 方案「静奢东方」：米灰底 + 墨绿 + 香槟金（仅选店步标签）
 * 衬线大字（Songti SC）、发丝线分区、无卡片阴影（靠留白与细线立秩序）
 * ========================================================================== */

$gold: #c19a4b;
$serif: 'Songti SC', 'STSong', 'SimSun', serif;

.guide--oriental {
  /* 米灰底：与 A 方案头图渐变的末段同色，让照片「沉入」页面而不是贴在上面。
     页面背景本来也是 #f2f4f2，这里显式写出来，是给以后改主题的人一个锚点 */
  background: #f2f4f2;
  padding: 0 0 calc(48rpx + env(safe-area-inset-bottom));
}

/* ---------- 第一步：选门店（纯排版 · 大留白） ---------- */
.guide--oriental .pick {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 184rpx 62rpx 0;
}

.guide--oriental .pick__gold {
  display: flex;
  align-items: center;
  gap: 20rpx;
}

.guide--oriental .pick__gold-line {
  width: 44rpx;
  height: 1px;
  background: $gold;
}

.guide--oriental .pick__gold-text {
  font-size: 19rpx;
  letter-spacing: 0.44em;
  color: $gold;
}

.guide--oriental .pick__title {
  margin-top: 32rpx;
  font-family: $serif;
  font-weight: 300;
  font-size: 60rpx;
  letter-spacing: 0.22em;
  color: #1e2b25;
}

.guide--oriental .pick__desc {
  margin-top: 28rpx;
  font-size: 24rpx;
  letter-spacing: 0.1em;
  color: #7c8a82;
}

/* 竖排装饰：渐隐细线 + 竖排四字 */
.guide--oriental .pick__vert {
  position: absolute;
  right: 68rpx;
  top: 236rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 28rpx;
}

.guide--oriental .pick__vert-line {
  width: 1px;
  height: 176rpx;
  background: linear-gradient(180deg, #c3cdc6, rgba(195, 205, 198, 0));
}

.guide--oriental .pick__vert-text {
  writing-mode: vertical-rl;
  font-family: $serif;
  font-weight: 300;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #8a958e;
}

/* 宋式区块标题：小字 · 发丝线 · 宽字距 */
.guide--oriental .pick__label {
  display: flex;
  align-items: center;
  gap: 24rpx;
  /*
    124 → 236rpx：右缘「静候光临」是绝对定位（top 236rpx，线 176rpx + 字约 200rpx，
    一直延伸到约 640rpx）。原来的 124rpx 让「选择门店」和门店条正好落进它的高度范围，
    真机上两者叠在一起。加到 236rpx 之后门店区整体下移，与竖排字错开。
  */
  margin-top: 236rpx;
  margin-bottom: 28rpx;
}

.guide--oriental .pick__label-text {
  font-family: $serif;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #5b6b62;
  white-space: nowrap;
}

.guide--oriental .pick__label-rule {
  flex: 1;
  height: 1px;
  background: $hairline;
}

/* 门店条：竖向长条 + 发丝线分隔（与首页同款语言） */
.guide--oriental .pick__strips {
  border-top: 1px solid $hairline;
  border-bottom: 1px solid $hairline;
}

.guide--oriental .pickstrip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 30rpx 4rpx;

  & + & {
    border-top: 1px solid $hairline;
  }
}

.guide--oriental .pickstrip__main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.guide--oriental .pickstrip__name {
  font-family: $serif;
  font-weight: 400;
  font-size: 40rpx;
  letter-spacing: 0.1em;
  color: $ink-800;
}

.guide--oriental .pickstrip__sub {
  margin-top: 12rpx;
  font-size: 21rpx;
  letter-spacing: 0.16em;
  color: $ink-400;
}

.guide--oriental .pickstrip__arrow {
  position: relative;
  width: 64rpx;
  height: 24rpx;
  flex: none;
}

.guide--oriental .pickstrip__arrow-line {
  position: absolute;
  left: 0;
  top: 50%;
  width: 48rpx;
  height: 1px;
  background: $brand-700;
  opacity: 0.55;
}

.guide--oriental .pickstrip__arrow-head {
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

.guide--oriental .pick__foot {
  margin-top: auto;
  padding: 72rpx 0 36rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 24rpx;
}

.guide--oriental .pick__foot-line {
  width: 60rpx;
  height: 1px;
  background: #d5dcd6;
}

.guide--oriental .pick__foot-text {
  font-size: 19rpx;
  letter-spacing: 0.42em;
  padding-left: 0.42em;
  color: #a6b1aa;
}

/* ---------- 门店头（沉浸式：整页唯一的「重」） ---------- */
.guide--oriental .ghead {
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
.guide--oriental .ghead__mark {
  width: 48rpx;
  height: 1px;
  background: rgba(255, 255, 255, 0.75);
  margin-bottom: 24rpx;
}

.guide--oriental .ghead__name {
  font-family: $serif;
  /* 字重 400 + 大字号：靠尺寸和字距立层级，加粗反而变土 */
  font-weight: 400;
  font-size: 54rpx;
  letter-spacing: 0.14em;
  line-height: 1.15;
  text-shadow: 0 4rpx 40rpx rgba(0, 0, 0, 0.3);
}

.guide--oriental .ghead__sub {
  margin-top: 18rpx;
  font-size: 22rpx;
  letter-spacing: 0.3em;
  color: rgba($white, 0.9);
}

/* ---------- 地址（图外浅底） ---------- */
.guide--oriental .addrline {
  padding: 52rpx 62rpx 32rpx;
  border-bottom: 1rpx solid $hairline;
}

.guide--oriental .addrline__text {
  font-size: 26rpx;
  line-height: 1.75;
  letter-spacing: 0.04em;
  color: $ink-600;
}

/* 地址待补时的降级文案：弱一档，读起来像「提示」而不是「地址」 */
.guide--oriental .addrline__text.is-tbd {
  color: $ink-400;
  font-size: 24rpx;
}

.guide--oriental .addrline__phone {
  display: block;
  margin-top: 14rpx;
  font-size: 22rpx;
  letter-spacing: 0.12em;
  color: $ink-400;
}

/* ---------- 导航 / 电话（1.5rpx 描边矩形） ---------- */
.guide--oriental .acts {
  display: flex;
  gap: 24rpx;
  margin: 40rpx 62rpx 0;
}

.guide--oriental .acts__item {
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

.guide--oriental .acts__item--plain {
  color: $ink-600;
  border: 1.5rpx solid #d5dcd6;
}

/* 纯 CSS 图标底（28px 视觉，60rpx 触达）：细线圆 + 内部形状 */
.guide--oriental .acts__ico {
  width: 56rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

.guide--oriental .acts__ico-nav {
  width: 24rpx;
  height: 24rpx;
  border: 1px solid currentColor;
  border-radius: 50% 50% 50 0;
  transform: rotate(-45deg);
  border-bottom-left-radius: 0;
  border-bottom-right-radius: 50%;
  position: relative;
}

.guide--oriental .acts__ico-phone {
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

.guide--oriental .acts__ico-copy {
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

.guide--oriental .acts__text {
  font-size: 28rpx;
  font-weight: 500;
  letter-spacing: 0.14em;
  color: #1c6047;
}

.guide--oriental .acts__item--plain .acts__text {
  color: $ink-700;
}

.guide--oriental .acts__hint {
  font-size: 21rpx;
  letter-spacing: 0.02em;
  color: $ink-400;
}

/* ---------- 视频号（细线分隔，无卡片底） ---------- */
.guide--oriental .vcard {
  padding: 64rpx 62rpx 0;
  margin: 0;
}

.guide--oriental .vcard__head {
  display: flex;
  align-items: center;
  gap: 24rpx;
}

.guide--oriental .vcard__title {
  font-family: $serif;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #5b6b62;
  white-space: nowrap;
}

.guide--oriental .vcard__rule {
  flex: 1;
  height: 1px;
  background: $hairline;
}

.guide--oriental .vcard__desc {
  display: block;
  margin-top: 18rpx;
  font-size: 23rpx;
  color: $ink-400;
}

.guide--oriental .vcard__acts {
  margin-top: 8rpx;
}

/* 视频号入口行：细线圆播放钮 + 名称 + 细箭头（A 方案语言） */
.guide--oriental .vrow {
  display: flex;
  align-items: center;
  gap: 28rpx;
  padding: 26rpx 4rpx 8rpx;
}

.guide--oriental .vrow__play {
  flex: none;
  width: 64rpx;
  height: 64rpx;
  border: 1px solid rgba(28, 96, 71, 0.45);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.guide--oriental .vrow__tri {
  width: 0;
  height: 0;
  border-left: 14rpx solid #1c6047;
  border-top: 9rpx solid transparent;
  border-bottom: 9rpx solid transparent;
  margin-left: 5rpx;
}

.guide--oriental .vrow__txt {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.guide--oriental .vrow__name {
  font-family: $serif;
  font-size: 30rpx;
  letter-spacing: 0.08em;
  color: $ink-800;
}

.guide--oriental .vrow__sub {
  font-size: 21rpx;
  letter-spacing: 0.14em;
  color: $ink-400;
}

.guide--oriental .vrow__arrow {
  position: relative;
  width: 64rpx;
  height: 24rpx;
  flex: none;
}

.guide--oriental .vrow__arrow-line {
  position: absolute;
  left: 0;
  top: 50%;
  width: 48rpx;
  height: 1px;
  background: #1c6047;
  opacity: 0.55;
}

.guide--oriental .vrow__arrow-head {
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

.guide--oriental .vcard__ph {
  padding: 26rpx 24rpx;
  margin-top: 12rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

.guide--oriental .vcard__ph-text {
  font-size: 23rpx;
  line-height: 1.7;
  color: $ink-500;
}

/* ---------- 图文引导（门店实景 / 路线图共用 fig） ---------- */
.guide--oriental .imgs,
.guide--oriental .routes,
.guide--oriental .videoblk {
  margin: 56rpx 62rpx 0;
}

.guide--oriental .imgs__labelrow {
  display: flex;
  align-items: center;
  gap: 24rpx;
  margin-bottom: 24rpx;
}

.guide--oriental .imgs__label {
  font-family: $serif;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #5b6b62;
  white-space: nowrap;
}

.guide--oriental .imgs__rule {
  flex: 1;
  height: 1px;
  background: $hairline;
}

/* 图 + 图注：去卡片化（无底色/阴影），图注是「小字 + 短横线」 */
.guide--oriental .fig {
  & + & {
    margin-top: 32rpx;
  }
}

.guide--oriental .fig__pic {
  width: 100%;
  height: 400rpx;
  border-radius: 10rpx;
  background: $ink-100;
}

.guide--oriental .fig__cap {
  display: flex;
  align-items: baseline;
  gap: 14rpx;
  padding: 18rpx 8rpx 0;
}

.guide--oriental .fig__dash {
  flex: none;
  width: 28rpx;
  height: 1px;
  background: #c9d2cb;
  align-self: center;
}

.guide--oriental .fig__text {
  flex: 1;
  min-width: 0;
  font-size: 24rpx;
  line-height: 1.6;
  color: $ink-500;
}

.guide--oriental .fig__zoom {
  flex-shrink: 0;
  font-size: 21rpx;
  color: $brand-600;
}

/* ---------- 门店视频 / 路线视频 ---------- */
.guide--oriental .videoblk__label {
  display: block;
  padding: 0 8rpx 24rpx;
  font-family: $serif;
  font-size: 22rpx;
  letter-spacing: 0.5em;
  color: #5b6b62;
}

/* 固定 16:9 高度 + object-fit: contain：竖屏视频会留黑边，但绝不裁掉画面
   （裁掉的往往是门头/指示牌，正是顾客要看的那部分） */
.guide--oriental .videoblk__player {
  width: 100%;
  height: 400rpx;
  background: #000;
  border-radius: 10rpx;
}

/* 满宽按钮：视频号链接的降级入口（描边矩形，与导航按钮同一语言） */
.guide--oriental .vbtn {
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

.guide--oriental .vbtn--wide {
  flex: none;
  width: 100%;
}

.guide--oriental .vbtn__text {
  font-size: 27rpx;
  font-weight: 500;
  letter-spacing: 0.1em;
  color: #1c6047;
}

/* ---------- 路线导语（后台 note）：浅灰底 + 正文字号 ---------- */
.guide--oriental .rnote {
  padding: 28rpx 32rpx;
  margin-bottom: 24rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

/* 折叠态限高 6 行（25rpx × 1.75 ≈ 44rpx/行）：首屏仍能看到视频/图片的入口 */
.guide--oriental .rnote__body.is-folded {
  max-height: 264rpx;
  overflow: hidden;
}

.guide--oriental .rnote__p {
  display: block;
  font-size: 25rpx;
  line-height: 1.75;
  color: $ink-600;
  word-break: break-word;

  & + & {
    margin-top: 8rpx;
  }
}

.guide--oriental .rnote__toggle {
  display: block;
  margin-top: 16rpx;
  font-size: 23rpx;
  color: $brand-600;
}

/* ---------- 到店路线分段：下划线式 tab ---------- */
.guide--oriental .rtabs {
  display: flex;
  gap: 64rpx;
  padding: 0;
  margin-bottom: 32rpx;
  background: transparent;
  border-bottom: 1rpx solid $hairline;
}

.guide--oriental .rtab {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 72rpx;
  padding-bottom: 20rpx;
  border-radius: 0;

  /* 下划线标签：伪元素在 wxss 里可能被丢弃（本项目实测过），
     所以下划线用 border-bottom 而不是 ::after。
     ⚠️ 与源文件的一处**有意差异**：miniapp-ui-a 原文选中线是墨色 #1e2b25，
     M7 明确要求「选中用香槟金细下划线」，这里改为 $gold（金只出现在小标签与短线，
     符合 A 方案「金不压主」的用法）。文字仍用墨色，避免金色字发灰。 */
  &.is-on {
    border-bottom: 2rpx solid $gold;

    .rtab__text {
      font-weight: 500;
      color: #1e2b25;
    }
  }
}

.guide--oriental .rtab__text {
  font-size: 25rpx;
  letter-spacing: 0.1em;
  color: $ink-500;
}

/* ---------- 路线指引待补充 ---------- */
.guide--oriental .rtempty {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  padding: 48rpx 36rpx;
  background: $ink-50;
  border-radius: 10rpx;
}

.guide--oriental .rtempty__text {
  font-size: 27rpx;
  font-weight: 500;
  color: $ink-600;
}

.guide--oriental .rtempty__desc {
  font-size: 23rpx;
  line-height: 1.7;
  color: $ink-400;
}

/* ---------- 换店：细字链（左右两根短横线） ---------- */
.guide--oriental .switch {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 28rpx;
  margin: 64rpx 62rpx 0;
  height: 92rpx;
}

.guide--oriental .switch__line {
  width: 52rpx;
  height: 1px;
  background: #d3dbd4;
}

.guide--oriental .switch__text {
  font-size: 24rpx;
  letter-spacing: 0.3em;
  padding-left: 0.3em;
  color: $brand-700;
}
</style>
