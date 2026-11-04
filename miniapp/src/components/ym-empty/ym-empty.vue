<script setup lang="ts">
/** 空态 / 失败态：一个组件收口文案与重试动作 */
withDefaults(
  defineProps<{
    text?: string
    desc?: string
    actionText?: string
    icon?: string
    compact?: boolean
  }>(),
  { text: '暂无数据', desc: '', actionText: '', icon: 'file', compact: false }
)
const emit = defineEmits<{ (e: 'action'): void }>()
</script>

<template>
  <view class="ym-empty" :class="{ 'is-compact': compact }">
    <view class="ym-empty__icon">
      <wd-icon :name="icon" size="30px" color="#C8D0CB" />
    </view>
    <text class="ym-empty__text">{{ text }}</text>
    <text v-if="desc" class="ym-empty__desc">{{ desc }}</text>
    <view v-if="actionText" class="ym-empty__action ym-tap" @click="emit('action')">
      {{ actionText }}
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.ym-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10rpx;
  padding: 90rpx 40rpx;

  &.is-compact {
    padding: 48rpx 24rpx;
  }
}

.ym-empty__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 108rpx;
  height: 108rpx;
  margin-bottom: 4rpx;
  background: $ink-50;
  border-radius: 50%;
}

.ym-empty__text {
  font-size: 26rpx;
  color: $ink-500;
}

.ym-empty__desc {
  font-size: 22rpx;
  color: $ink-400;
  text-align: center;
}

.ym-empty__action {
  padding: 12rpx 32rpx;
  margin-top: 12rpx;
  font-size: 24rpx;
  color: $brand-600;
  background: $brand-50;
  border-radius: 999rpx;
}
</style>
