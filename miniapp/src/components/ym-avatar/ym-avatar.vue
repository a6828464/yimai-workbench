<script setup lang="ts">
/** 头像：有图用图，无图用姓氏首字（品牌浅绿底） */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    name?: string
    src?: string | null
    /** 尺寸 rpx */
    size?: number
    tone?: 'brand' | 'ink' | 'gold'
  }>(),
  { name: '', src: '', size: 88, tone: 'brand' }
)

const initial = computed(() => {
  const n = (props.name || '').trim()
  return n ? n.slice(0, 1) : '一'
})

const style = computed(() => ({
  width: `${props.size}rpx`,
  height: `${props.size}rpx`,
  fontSize: `${Math.round(props.size * 0.4)}rpx`
}))
</script>

<template>
  <view class="ym-avatar" :class="`is-${tone}`" :style="style">
    <image v-if="src" class="ym-avatar__img" :src="src" mode="aspectFill" />
    <text v-else class="ym-avatar__text">{{ initial }}</text>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.ym-avatar {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  font-weight: 600;
  background: $brand-100;
  border-radius: 50%;

  &.is-ink {
    color: $ink-700;
    background: $ink-100;
  }

  &.is-gold {
    color: $gold-600;
    background: $gold-100;
  }
}

.ym-avatar__text {
  font-size: inherit;
  color: $brand-700;
}

.ym-avatar__img {
  width: 100%;
  height: 100%;
}
</style>
