<script setup lang="ts">
/**
 * 约课网页（web-view 容器）
 *
 * 两条通往约课的路：
 *  A. 长按小程序码（首页弹层）—— 零配置，一定能用
 *  B. 本页 web-view 直开 keepyoga 约课页 —— 体验最顺，但**有前置条件**
 *
 * 本页的前置条件（缺一白屏，不是代码问题）：
 *  1. mp 后台「开发管理 → 开发设置 → 业务域名」加入 `vcloud.keepyoga.com`
 *  2. 把微信给的校验文件传到该域名根目录：`https://vcloud.keepyoga.com/<校验文件名>`
 *     —— ⚠ 该域名属约课系统（KeepYoga 云），我们没有写权限，需要对方配合放一次，
 *        或确认他们已作为 SaaS 统一放好。
 *  3. 业务域名**不是** request 合法域名，两处分别配置，互不顶替。
 *
 * 校验没过时的表现：真机白屏、开发者工具勾了「不校验合法域名」则正常。
 * 所以首页把长按识别放在主路径，本页是增强，坏了不影响能约课。
 */
import { computed, ref } from 'vue'
import { onLoad as onPageLoad } from '@dcloudio/uni-app'

/** 门店 → 约课页 URL。venue_id 是硬对应关系，改之前先核对 */
const BOOK_URLS: Record<string, { name: string; url: string }> = {
  east: {
    name: '东部店',
    url: 'https://vcloud.keepyoga.com/weixin/getRedirectUrl.php?brand_id=M01_V1o4QXEzd0wyRmM9&themes=bamboo&venue_id=4250&r=78312'
  },
  green: {
    name: '绿地店',
    url: 'https://vcloud.keepyoga.com/weixin/getRedirectUrl.php?brand_id=M01_V1o4QXEzd0wyRmM9&themes=bamboo&venue_id=1&r=93210'
  }
}

const code = ref('east')
const info = computed(() => BOOK_URLS[code.value] ?? BOOK_URLS.east)

onPageLoad((options) => {
  armFail()
  const v = (options ?? {}).venue as string | undefined
  if (v && BOOK_URLS[v]) code.value = v
  uni.setNavigationBarTitle({ title: `${info.value.name} · 约课` })
})

/**
 * web-view 在开发者工具里默认不渲染真实网页，`@error` 也常常不回调
 * （真机才会走域名校验）。所以判定改成「超时未 load 即当作没通」，
 * 提示做成底部小条、不遮网页：真通了的页面照样看得见。
 */
const state = ref<'loading' | 'ok' | 'fail'>('loading')
let timer: ReturnType<typeof setTimeout> | null = null

function armFail() {
  clearTimer()
  timer = setTimeout(() => {
    if (state.value !== 'ok') state.value = 'fail'
  }, 3500)
}

function clearTimer() {
  if (timer) {
    clearTimeout(timer)
    timer = null
  }
}

function onLoaded() {
  state.value = 'ok'
  clearTimer()
}

function retry() {
  state.value = 'loading'
  armFail()
}

function copy() {
  uni.setClipboardData({
    data: info.value.url,
    success: () => uni.showToast({ title: '链接已复制', icon: 'none' })
  })
}
</script>

<template>
  <view class="book">
    <web-view :src="info.url" @load="onLoaded" />

    <!-- web-view 会盖住页面内容，这两枚兜底入口做成悬浮球贴在右下 -->
    <view v-if="state === 'fail'" class="book__tip">
      <text class="book__tip-text">网页没打开：业务域名可能还没放校验文件，回首页用「小程序码」长按识别更稳</text>
      <text class="book__tip-act" @click="retry">重试</text>
    </view>

    <view class="book__fab" @click="copy">
      <text class="book__fab-text">复制链接</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.book {
  height: 100vh;

  &__tip {
    position: fixed;
    left: 24rpx;
    right: 24rpx;
    bottom: 40rpx;
    z-index: 10;
    display: flex;
    align-items: center;
    gap: 18rpx;
    padding: 22rpx 26rpx;
    border-radius: 18rpx;
    background: rgba(255, 251, 242, 0.97);
    box-shadow: 0 8rpx 28rpx rgba(0, 0, 0, 0.16);
  }

  &__tip-text {
    flex: 1;
    font-size: 23rpx;
    color: #6b5417;
    line-height: 1.5;
  }

  &__tip-act {
    font-size: 24rpx;
    color: #17402f;
    font-weight: 600;
    padding-left: 6rpx;
  }

  &__fab {
    position: fixed;
    right: 24rpx;
    bottom: 140rpx;
    z-index: 20;
    padding: 18rpx 26rpx;
    border-radius: 999rpx;
    background: rgba(23, 64, 47, 0.88);
    box-shadow: 0 8rpx 24rpx rgba(0, 0, 0, 0.28);
  }

  &__fab-text {
    font-size: 24rpx;
    color: #fff;
  }
}
</style>
