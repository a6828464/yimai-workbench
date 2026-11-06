<script setup lang="ts">
/** 状态标签（业务语义色统一在此，页面不再各写一套色值） */
withDefaults(
  defineProps<{
    text?: string
    tone?: 'brand' | 'gold' | 'warn' | 'danger' | 'info' | 'muted'
    size?: 'sm' | 'md'
    plain?: boolean
  }>(),
  { text: '', tone: 'brand', size: 'sm', plain: false }
)
</script>

<template>
  <text class="ym-badge" :class="[`is-${tone}`, `is-${size}`, { 'is-plain': plain }]">
    <slot>{{ text }}</slot>
  </text>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.ym-badge {
  display: inline-flex;
  align-items: center;
  padding: 3rpx 12rpx;
  font-size: 20rpx;
  line-height: 1.5;
  color: $brand-600;
  background: $brand-100;
  border-radius: 999rpx;
  white-space: nowrap;

  &.is-md {
    padding: 6rpx 16rpx;
    font-size: 22rpx;
  }

  &.is-gold {
    color: $gold-600;
    background: $gold-100;
  }

  &.is-warn {
    color: $warn-600;
    background: $warn-100;
  }

  &.is-danger {
    color: $danger-600;
    background: $danger-100;
  }

  &.is-info {
    color: $info-500;
    background: $info-100;
  }

  &.is-muted {
    color: $ink-500;
    background: $ink-100;
  }

  &.is-plain {
    background: transparent;
    border: 2rpx solid currentcolor;
  }
}
</style>
