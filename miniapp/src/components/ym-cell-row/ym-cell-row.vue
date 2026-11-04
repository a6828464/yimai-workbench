<script setup lang="ts">
/** 通用信息行：左标题 / 右值 / 可选箭头，用于「我的」与详情页 */
withDefaults(
  defineProps<{
    label: string
    value?: string
    hint?: string
    arrow?: boolean
    divider?: boolean
    clickable?: boolean
  }>(),
  { value: '', hint: '', arrow: false, divider: false, clickable: false }
)
const emit = defineEmits<{ (e: 'click'): void }>()
</script>

<template>
  <view
    class="ym-row-item"
    :class="{ 'is-divider': divider, 'is-clickable': clickable || arrow }"
    @click="(clickable || arrow) && emit('click')"
  >
    <view class="ym-row-item__left">
      <slot name="left" />
      <text class="ym-row-item__label">{{ label }}</text>
      <text v-if="hint" class="ym-row-item__hint">{{ hint }}</text>
    </view>
    <view class="ym-row-item__right">
      <slot name="right">
        <text class="ym-row-item__value ym-ellipsis">{{ value }}</text>
      </slot>
      <wd-icon v-if="arrow" name="chevron-right" size="14px" color="#C0C8C3" />
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.ym-row-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  min-height: 96rpx;
  padding: 4rpx 0;

  &.is-divider {
    border-bottom: 2rpx solid $hairline;
  }

  &.is-clickable:active {
    opacity: 0.6;
  }
}

.ym-row-item__left {
  display: flex;
  flex: 1;
  gap: 14rpx;
  align-items: center;
  min-width: 0;
}

.ym-row-item__label {
  font-size: 28rpx;
  color: $ink-700;
}

.ym-row-item__hint {
  font-size: 22rpx;
  color: $ink-400;
}

.ym-row-item__right {
  display: flex;
  flex-shrink: 0;
  gap: 8rpx;
  align-items: center;
  max-width: 60%;
}

.ym-row-item__value {
  font-size: 26rpx;
  color: $ink-400;
  text-align: right;
}
</style>
