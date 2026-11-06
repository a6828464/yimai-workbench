<script setup lang="ts">
/** 指标卡：工作台首屏 / 各板块顶部的数字块 */
withDefaults(
  defineProps<{
    label: string
    value: string | number
    unit?: string
    hint?: string
    /** 环比等趋势文案，如 +12% */
    trend?: string
    trendTone?: 'up' | 'down' | 'flat'
    tone?: 'light' | 'dark' | 'brand' | 'gold' | 'plain'
    /** 是否可点击（追加按压反馈） */
    clickable?: boolean
  }>(),
  { unit: '', hint: '', trend: '', trendTone: 'flat', tone: 'light', clickable: false }
)
</script>

<template>
  <view class="ym-stat" :class="[`is-${tone}`, { 'is-clickable': clickable }]">
    <view class="ym-stat__head">
      <text class="ym-stat__label">{{ label }}</text>
      <text
        v-if="trend"
        class="ym-stat__trend"
        :class="`is-${trendTone}`"
      >{{ trend }}</text>
    </view>
    <view class="ym-stat__value-row">
      <text class="ym-stat__value ym-num">{{ value }}</text>
      <text v-if="unit" class="ym-stat__unit">{{ unit }}</text>
    </view>
    <text v-if="hint" class="ym-stat__hint">{{ hint }}</text>
    <slot />
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.ym-stat {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  padding: 24rpx 24rpx 22rpx;
  background: $white;
  border-radius: $r-md;
  box-shadow: $shadow-card;

  &.is-clickable:active {
    opacity: 0.72;
  }

  &.is-brand {
    background: linear-gradient(150deg, #eff8f3, #ffffff 70%);
    border: 2rpx solid $brand-100;
    box-shadow: none;
  }

  &.is-gold {
    background: linear-gradient(150deg, #fbf5e9, #ffffff 70%);
    border: 2rpx solid #f1e5cc;
    box-shadow: none;
  }

  &.is-plain {
    background: $ink-50;
    box-shadow: none;
  }

  &.is-dark {
    background: linear-gradient(150deg, #163425, #2e7d5b);
    box-shadow: 0 10rpx 24rpx rgba(22, 52, 37, 0.24);

    .ym-stat__label,
    .ym-stat__hint,
    .ym-stat__unit {
      color: rgba(255, 255, 255, 0.72);
    }

    .ym-stat__value {
      color: #fff;
    }

    .ym-stat__trend {
      color: #fff;
      background: rgba(255, 255, 255, 0.16);
    }
  }
}

.ym-stat__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8rpx;
}

.ym-stat__label {
  font-size: 24rpx;
  color: $ink-500;
  letter-spacing: 0.5rpx;
}

.ym-stat__trend {
  padding: 2rpx 10rpx;
  font-size: 20rpx;
  color: $ink-500;
  background: $ink-50;
  border-radius: 999rpx;

  &.is-up {
    color: $brand-600;
    background: $brand-50;
  }

  &.is-down {
    color: $danger-600;
    background: $danger-100;
  }
}

.ym-stat__value-row {
  display: flex;
  align-items: baseline;
  gap: 6rpx;
}

.ym-stat__value {
  font-size: 52rpx;
  font-weight: 600;
  line-height: 1.15;
  color: $ink-800;
  letter-spacing: -0.5rpx;
}

.ym-stat__unit {
  font-size: 22rpx;
  color: $ink-400;
}

.ym-stat__hint {
  font-size: 21rpx;
  color: $ink-400;
}
</style>
