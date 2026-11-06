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
 *  - 引导图：配置里最多取 3 张，一张都没有就回退 `/static/hero.jpg`；
 *  - 视频号 / 直播未配置 → 显示占位卡片，配置后自动变成可点入口。
 */
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { copyText } from '@/utils/nav'
import { fetchMiniappConfig } from '@/api/miniapp'

interface GuideImage {
  /** 小程序包内静态图（/static/…）或 https 地址都行；后端/CDN 换图不必重新发版 */
  src: string
  caption: string
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
  /** 视频号直播 id（wx.openChannelsLive 用，通常是直播回放的 feedId）；空 = 未配置 */
  channelsLive: string
  /** 图文引导 1-3 张 */
  images: GuideImage[]
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
    // TODO(直播): 填直播 feedId；没有直播就留空
    channelsLive: '',
    images: [
      // TODO(到店指引): 换成真实引导图（前台/电梯/门头），1-3 张；也可换成 https 外链
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

const videoReady = computed(() => Boolean(current.value.channelsUser || current.value.channelsLive))

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

/** 引导图上限：配置里写多了也只取前 3 张（再多顾客也滑不完，包体/流量也不划算） */
const MAX_IMAGES = 3
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

/** 视频号直播 / 回放 */
function openLive(): void {
  const v = current.value
  if (!v.channelsLive) {
    videoPlaceholder()
    return
  }
  const api = channelsApi()
  if (!api?.openChannelsLive) {
    toast('当前微信版本不支持打开直播')
    return
  }
  api.openChannelsLive({
    finderUserName: v.channelsUser,
    nonceId: v.channelsLive,
    fail: () => toast('直播打不开，可稍后再试')
  })
}

/** 占位卡片：讲清楚「为什么这里点不动」以及配置在哪，免得被当成坏页面 */
function videoPlaceholder(): void {
  uni.showModal({
    title: '视频号待配置',
    // 这是给顾客看的文案：不提内部文件路径，配置说明写在文件头的 TODO 里
    content: '门店视频号与直播还在开通中，先按下面的图文指引到店即可；课程花絮与开播预告会陆续放进来。',
    showCancel: false,
    confirmText: '知道了',
    confirmColor: '#2E7D5B'
  })
}

/** 图片放大预览（包内静态图也能预览） */
function previewImage(index: number): void {
  const urls = current.value.images.map((img) => img.src)
  if (!urls.length) return
  uni.previewImage({
    urls,
    current: urls[index] ?? urls[0],
    fail: () => toast('图片预览失败')
  })
}
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

      <!-- 视频号 / 直播 -->
      <view class="vcard">
        <view class="vcard__head">
          <text class="vcard__title">视频号 · 直播</text>
          <text class="vcard__desc">
            {{ videoReady ? '课程花絮与开播预告都在视频号里' : '待配置视频号' }}
          </text>
        </view>
        <view v-if="videoReady" class="vcard__acts">
          <view v-if="current.channelsUser" class="vbtn ym-tap" @click="openChannels">
            <wd-icon name="play-circle" size="16px" color="#FFFFFF" />
            <text class="vbtn__text">看视频号</text>
          </view>
          <view v-if="current.channelsLive" class="vbtn vbtn--ghost ym-tap" @click="openLive">
            <text class="vbtn__text vbtn__text--brand">进直播间</text>
          </view>
        </view>
        <view v-else class="vcard__ph ym-tap" @click="videoPlaceholder">
          <text class="vcard__ph-text">
            这里会给到门店视频号与直播入口；目前还没配置，先按下面的图文指引到店即可。
          </text>
        </view>
      </view>

      <!-- 图文引导 -->
      <view class="imgs">
        <text class="imgs__label">到店路线</text>
        <view
          v-for="(img, i) in current.images"
          :key="img.src + i"
          class="imgcard ym-tap"
          @click="previewImage(i)"
        >
          <image class="imgcard__pic" :src="img.src" mode="aspectFill" />
          <view class="imgcard__cap">
            <text class="imgcard__text">{{ img.caption }}</text>
            <text class="imgcard__zoom">点击放大</text>
          </view>
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
  background: $card-bg;
  border-radius: $r-lg;
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
  padding: 32rpx 30rpx 28rpx;
  margin: 0 24rpx;
  color: $white;
  background: linear-gradient(135deg, $brand-700, $brand-500);
  border-radius: $r-lg;
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
  min-height: 196rpx;
  padding: 22rpx 16rpx;
  color: $white;
  background: linear-gradient(135deg, $brand-500, $brand-700);
  border-radius: $r-lg;
  box-shadow: 0 10rpx 24rpx rgba(28, 96, 71, 0.22);
}

.acts__item--plain {
  color: $brand-700;
  background: $white;
  border: 2rpx solid $hairline;
  box-shadow: $shadow-card;
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
  background: $card-bg;
  border-radius: $r-lg;
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
  background: $card-bg;
  border-radius: $r-lg;
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
