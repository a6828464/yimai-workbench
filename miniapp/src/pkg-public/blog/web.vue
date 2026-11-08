<script setup lang="ts">
/**
 * 官网页面 web-view（pkg-public/blog/web）—— 「到官网看看」的落点。
 *
 * 前置（2026-11-09 已配置）：www.nbyimai.com 已在 mp 后台登记为**业务域名**，
 * 验证文件已放到 WP 根目录。没配业务域名时 web-view 直接打不开（微信硬门槛）。
 *
 * 带 ?url= 参数（仅允许 www.nbyimai.com 域下的地址，防这个页变成任意外链浏览器）。
 * 失败态给一句能操作的话 + 「回博客」按钮。
 */
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'

const url = ref('')
const failed = ref(false)

onLoad((q) => {
  const raw = String((q as Record<string, unknown> | undefined)?.url ?? '').trim()
  // 域名白名单：只放行官网（decode 后再校验，防编码绕过）
  let target = ''
  try {
    const decoded = decodeURIComponent(raw)
    if (/^https:\/\/(www\.)?nbyimai\.com(\/|$)/i.test(decoded)) {
      target = decoded
    }
  } catch {
    target = ''
  }
  if (target) {
    url.value = target
  } else {
    failed.value = true
  }
})

function goBack(): void {
  uni.navigateBack({
    fail: () => uni.reLaunch({ url: '/pkg-public/guide/guide' })
  })
}
</script>

<template>
  <view class="ym-page bweb">
    <web-view v-if="url" :src="url" />

    <view v-else class="bweb__err">
      <text class="bweb__err-text">这个链接只能在官网打开</text>
      <view class="bweb__err-btn ym-tap" @click="goBack">
        <text class="bweb__err-btn-text">回博客</text>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.bweb {
  min-height: 100vh;
  background: #f2f4f2;

  &__err {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-top: 200rpx;
  }

  &__err-text {
    font-size: 27rpx;
    color: #7c8a82;
  }

  &__err-btn {
    margin-top: 44rpx;
    padding: 16rpx 56rpx;
    border: 1.5rpx solid #1c6047;
    border-radius: 4rpx;
  }

  &__err-btn-text {
    font-size: 25rpx;
    letter-spacing: 0.1em;
    color: #1c6047;
  }
}
</style>
