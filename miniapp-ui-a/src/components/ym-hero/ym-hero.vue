<script setup lang="ts">
/**
 * 深色品牌头（tab 页专用）
 *
 * 为什么自绘而不用原生导航栏：tab 页没有返回键，原生导航栏会把「品牌 + 问候 + 关键数字」
 * 切成一堆彼此割裂的横条；自绘后首屏能一屏讲完「今天我是谁、有多少事要做」。
 * 子页仍用原生导航栏（有返回键，系统行为更稳）。
 *
 * 胶囊安全区：导航行只放左对齐标题，右侧留出 200rpx 空位给微信胶囊；
 * 需要放操作按钮时走 extra 插槽（在导航行下方），永不与胶囊抢位置。
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    /** 顶部小字（品牌 / 门店） */
    eyebrow?: string
    /** 主标题 */
    title?: string
    /** 副标题（问候语等） */
    subtitle?: string
    /** 底部圆角：贴卡片的页面可关掉 */
    rounded?: boolean
    /** 紧凑模式（子页用） */
    compact?: boolean
  }>(),
  { eyebrow: '', title: '', subtitle: '', rounded: true, compact: false }
)

const statusBarHeight = computed(() => {
  try {
    const info = uni.getSystemInfoSync()
    return info.statusBarHeight ?? 20
  } catch {
    return 20
  }
})
</script>

<template>
  <view class="ym-hero" :class="{ 'is-compact': props.compact, 'is-rounded': props.rounded }">
    <view class="ym-hero__glow" />
    <view :style="{ height: statusBarHeight + 'px' }" />
    <view class="ym-hero__bar">
      <view class="ym-hero__text">
        <text v-if="props.eyebrow" class="ym-hero__eyebrow">{{ props.eyebrow }}</text>
        <text v-if="props.title" class="ym-hero__title">{{ props.title }}</text>
        <text v-if="props.subtitle" class="ym-hero__subtitle">{{ props.subtitle }}</text>
      </view>
      <slot name="badge" />
    </view>
    <view v-if="$slots.default" class="ym-hero__extra">
      <slot />
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.ym-hero {
  position: relative;
  overflow: hidden;
  padding: 0 32rpx 36rpx;
  color: #fff;
  background: linear-gradient(158deg, #0f1b16 0%, #163425 52%, #245f45 100%);

  &.is-rounded {
    border-bottom-right-radius: 44rpx;
    border-bottom-left-radius: 44rpx;
  }

  &.is-compact {
    padding-bottom: 24rpx;
  }
}

.ym-hero__glow {
  position: absolute;
  top: -180rpx;
  right: -140rpx;
  width: 520rpx;
  height: 520rpx;
  background: radial-gradient(circle, rgba(120, 200, 165, 0.32) 0%, rgba(120, 200, 165, 0) 68%);
  border-radius: 50%;
}

.ym-hero__bar {
  position: relative;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  /* 右侧 200rpx 留给微信胶囊，标题永不被压 */
  padding-right: 200rpx;
  margin-top: 16rpx;
}

.ym-hero__text {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  min-width: 0;
}

.ym-hero__eyebrow {
  font-size: 22rpx;
  letter-spacing: 3rpx;
  color: rgba(255, 255, 255, 0.6);
  text-transform: uppercase;
}

.ym-hero__title {
  font-size: 46rpx;
  font-weight: 600;
  letter-spacing: 1rpx;
  color: #fff;
}

.ym-hero__subtitle {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.72);
}

.ym-hero__extra {
  position: relative;
  margin-top: 28rpx;
}
</style>
