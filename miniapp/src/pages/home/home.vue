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

const pages = getCurrentPages()
const safeTop = computed(() => `${statusBarHeight.value + 24}px`)

onShow(() => {
  // 首页不强制登录：顾客也要能看。只做会话恢复，不发请求。
  if (!user.token) user.restore()
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
  uni.navigateTo({ url: '/pages/guide/guide' })
}

/** 底部 slogan 是内部入口：已登录直接进工作台，未登录去登录页 */
function goStaff() {
  if (user.token) {
    uni.reLaunch({ url: '/pages/workbench/workbench' })
    return
  }
  uni.navigateTo({ url: '/pages/login/login' })
}

// getCurrentPages 只是为了让模板里能引用，避免未使用告警
void pages
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
