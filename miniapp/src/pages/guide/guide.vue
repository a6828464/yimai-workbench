<script setup lang="ts">
/**
 * 到店指引（首页「第一次来？点这里」的落地页）
 *
 * 流程：**先选门店 → 再看该店指引**（导航 / 电话 / 视频号 / 图文引导）。
 * 为什么是独立页面而不是首页弹层：首页是 `position:fixed` 的全屏海报，
 * 弹层里放地图按钮 + 图片预览 + 长文案会让首屏变成两套滚动逻辑；
 * 独立页还能被分享/被后端卡片直链（`/pages/guide/guide?venue=绿地店`）。
 *
 * 本页**不要求登录**：它是给顾客看的（同首页），不能因为没登录就白屏。
 *
 * ## 配置口径（改这里就够了，不散到模板里）
 * `GUIDE_VENUES` 是唯一数据源：门店名、副标题、地址、电话、坐标、视频号、引导图。
 * 现状（2026-11）：
 *  - 绿地店：电话与地址取自随心瑜门店档案（真实值）；
 *  - 东部店：随心瑜档案里没有电话/地址，留空 → 页面显示「待补充」而不是假数据；
 *  - **坐标两家都是占位**（TODO 标注），上线前用「腾讯位置服务坐标拾取器」取真实值；
 *  - 视频号 / 直播未配置 → 显示占位卡片，配置 `channelsUser`（或 `channelsLive`）后自动变成可点入口。
 */
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { copyText } from '@/utils/nav'

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
 * 门店键（`绿地店` / `东部店`）必须与后端 venue 列取值一致 —— 口径见 utils/venue.ts 的
 * KNOWN_VENUES（门店切换、请求参数都用同一套字面量）；首页 VENUES 的 key 也是这两个。
 *
 * ⚠️ TODO(到店指引)：`latitude` / `longitude` 目前是**占位坐标**（宁波三江口一带）。
 * 上线前必须换成门店真实坐标（腾讯位置服务坐标拾取器 → 填 6 位小数），
 * 否则点「地图导航」会把人带到城市中心。
 *
 * 绿地店地址/电话来自随心瑜门店档案（057487214863 / 宁波市江北区绿地缤纷城1号楼4楼）；
 * admin-web 谈单工具的 seed 写的是「鄞州区…4层」，两处一致口径待馆主确认后统一。
 */
const GUIDE_VENUES: GuideVenue[] = [
  {
    key: '绿地店',
    sub: '绿地缤纷城 · 4 楼',
    address: '宁波市江北区绿地缤纷城1号楼4楼',
    phone: '0574-87214863',
    // TODO(到店指引): 换成绿地店真实坐标
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
    // TODO(到店指引): 东部店档案地址待补
    address: '',
    // TODO(到店指引): 东部店电话待补
    phone: '',
    // TODO(到店指引): 换成东部店真实坐标
    latitude: 29.8746,
    longitude: 121.5496,
    channelsUser: '',
    channelsLive: '',
    images: [{ src: '/static/hero.jpg', caption: '到店后前台出示本条指引，顾问带你熟悉场地' }]
  }
]

/** 已选门店的 key（'' = 还停在门店选择） */
const pickedKey = ref('')

/** 当前门店：永远有值（没选时落第一家），模板里不必到处判空 */
const current = computed<GuideVenue>(
  () => GUIDE_VENUES.find((v) => v.key === pickedKey.value) ?? GUIDE_VENUES[0]
)
/** 是否停在「选门店」这一步 */
const choosing = computed(() => pickedKey.value === '')

const videoReady = computed(() => Boolean(current.value.channelsUser || current.value.channelsLive))

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

/** 深链：/pages/guide/guide?venue=东部店（门店名不认识就停在选择页） */
onLoad((options) => {
  const venue = String((options as Record<string, string> | undefined)?.venue ?? '').trim()
  const hit = GUIDE_VENUES.find((v) => v.key === venue)
  if (hit) pickedKey.value = hit.key
})

function choose(v: GuideVenue): void {
  pickedKey.value = v.key
}

function switchVenue(): void {
  pickedKey.value = ''
}

/** 地图导航：坐标没配 → 复制地址（绝不拿占位坐标糊弄顾客） */
function openMap(v: GuideVenue): void {
  if (!v.latitude || !v.longitude) {
    if (v.address) copyText(v.address, '门店坐标待补充，地址已复制')
    else toast('门店坐标与地址都还没配置')
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

/** 门店电话：没配号码就直说，不做无效拨号 */
function callVenue(v: GuideVenue): void {
  const phone = v.phone.replace(/[^0-9+]/g, '')
  if (!phone) {
    toast('门店电话待补充')
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
        v-for="v in GUIDE_VENUES"
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
        <text class="ghead__addr">{{ current.address || '地址待补充' }}</text>
      </view>

      <!-- 两个大动作：导航 / 电话 -->
      <view class="acts">
        <view class="acts__item ym-tap" @click="openMap(current)">
          <wd-icon name="location" size="22px" color="#FFFFFF" />
          <text class="acts__text">地图导航</text>
          <text class="acts__hint">{{ current.latitude ? '点开定位' : '坐标待补充' }}</text>
        </view>
        <view class="acts__item acts__item--plain ym-tap" @click="callVenue(current)">
          <wd-icon name="phone" size="22px" color="#1C6047" />
          <text class="acts__text acts__text--dark">门店电话</text>
          <text class="acts__hint acts__hint--dark">{{ current.phone || '待补充' }}</text>
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
