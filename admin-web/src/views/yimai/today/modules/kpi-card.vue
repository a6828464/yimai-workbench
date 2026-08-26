<template>
  <ElCard shadow="never" class="kpi-card">
    <div class="flex items-center justify-between">
      <span class="text-xs text-gray-500">{{ label }}</span>
      <ElIcon v-if="icon" :size="16" :style="{ color: accent }">
        <component :is="icon" />
      </ElIcon>
    </div>
    <div class="mt-2 text-2xl font-600 leading-7" :style="accent ? { color: accent } : {}">
      {{ prefix }}{{ displayValue }}<span v-if="suffix" class="text-sm font-400 ml-0.5">{{ suffix }}</span>
    </div>
    <div v-if="hint" class="mt-1 text-xs" :class="hintType === 'up' ? 'text-green-600' : hintType === 'down' ? 'text-red-500' : 'text-gray-400'">
      {{ hint }}
    </div>
  </ElCard>
</template>

<script setup lang="ts">
  defineOptions({ name: 'YimaiKpiCard' })

  interface Props {
    label: string
    value: number | string
    prefix?: string
    suffix?: string
    hint?: string
    hintType?: '' | 'up' | 'down'
    accent?: string
    icon?: Component
    loading?: boolean
  }

  const props = withDefaults(defineProps<Props>(), {
    prefix: '',
    suffix: '',
    hint: '',
    hintType: '',
    accent: '',
    loading: false
  })

  const displayValue = computed(() =>
    typeof props.value === 'number' ? props.value.toLocaleString() : props.value
  )
</script>

<style scoped lang="scss">
  .kpi-card {
    cursor: default;

    :deep(.el-card__body) {
      padding: 14px 16px;
    }
  }
</style>
