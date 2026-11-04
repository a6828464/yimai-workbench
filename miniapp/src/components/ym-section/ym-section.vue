<script setup lang="ts">
/** 区块容器：标题 + 可选右侧动作 + 内容插槽 */
withDefaults(
  defineProps<{
    title: string
    desc?: string
    actionText?: string
    /** 去掉外壳（贴在已有卡片里用） */
    bare?: boolean
  }>(),
  { desc: '', actionText: '', bare: false }
)
const emit = defineEmits<{ (e: 'action'): void }>()
</script>

<template>
  <view class="ym-sec" :class="{ 'is-bare': bare }">
    <view class="ym-sec__head">
      <view class="ym-sec__titles">
        <text class="ym-sec__title">{{ title }}</text>
        <text v-if="desc" class="ym-sec__desc">{{ desc }}</text>
      </view>
      <view v-if="actionText" class="ym-sec__action ym-tap" @click="emit('action')">
        <text>{{ actionText }}</text>
        <wd-icon name="arrow-right" size="12px" color="#8A948F" />
      </view>
    </view>
    <slot />
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.ym-sec {
  padding: 28rpx;
  margin: 20rpx 24rpx;
  background: $white;
  border-radius: $r-lg;
  box-shadow: $shadow-card;

  &.is-bare {
    padding: 0;
    margin: 0;
    background: transparent;
    box-shadow: none;
  }
}

.ym-sec__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 20rpx;
}

.ym-sec__titles {
  display: flex;
  flex-direction: column;
  gap: 4rpx;
}

.ym-sec__title {
  font-size: 30rpx;
  font-weight: 600;
  color: $ink-800;
}

.ym-sec__desc {
  font-size: 22rpx;
  color: $ink-400;
}

.ym-sec__action {
  display: flex;
  align-items: center;
  gap: 2rpx;
  font-size: 24rpx;
  color: $ink-400;
}
</style>
