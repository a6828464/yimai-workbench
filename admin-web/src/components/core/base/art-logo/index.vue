<!-- 系统logo -->
<template>
  <div class="flex-cc">
    <img :style="logoStyle" src="@imgs/yimai-logo.png" alt="一麦" class="w-full h-full object-contain" />
  </div>
</template>

<script setup lang="ts">
  defineOptions({ name: 'ArtLogo' })

  interface Props {
    /** logo 大小 */
    size?: number | string
  }

  const props = withDefaults(defineProps<Props>(), {
    size: 36
  })

  const logoStyle = computed(() => ({ width: `${props.size}px` }))
</script>
