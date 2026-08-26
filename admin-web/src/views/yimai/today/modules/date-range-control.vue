<template>
  <div class="ky-drc w-full md:w-auto">
    <!-- 桌面端：范围选择 -->
    <ElDatePicker
      class="ky-desktop-picker"
      :model-value="inner"
      type="daterange"
      value-format="YYYY-MM-DD"
      :clearable="false"
      :shortcuts="shortcuts"
      start-placeholder="开始日期"
      end-placeholder="结束日期"
      :editable="false"
      @update:model-value="onRange"
    />
    <!-- 移动端：两个单日期全宽并排 -->
    <div class="ky-mobile-pickers">
      <ElDatePicker
        class="ky-mobile-picker"
        :model-value="start"
        type="date"
        value-format="YYYY-MM-DD"
        :clearable="false"
        placeholder="开始日期"
        :editable="false"
        @update:model-value="(v: string) => emitChange(v, end)"
      />
      <span class="text-xs text-gray-400 shrink-0">至</span>
      <ElDatePicker
        class="ky-mobile-picker"
        :model-value="end"
        type="date"
        value-format="YYYY-MM-DD"
        :clearable="false"
        placeholder="结束日期"
        :editable="false"
        @update:model-value="(v: string) => emitChange(start, v)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
  defineOptions({ name: 'DateRangeControl' })

  interface ShortcutItem {
    text: string
    value: () => Date[]
  }

  const props = defineProps<{
    start: string
    end: string
    shortcuts?: ShortcutItem[]
  }>()

  const emit = defineEmits<{
    change: [range: [string, string]]
  }>()

  const inner = computed<[Date, Date]>(() => {
    const toDate = (s: string) => new Date(`${s}T00:00:00`)
    return [toDate(props.start), toDate(props.end)]
  })

  function onRange(v: unknown) {
    if (Array.isArray(v) && v.length === 2 && v[0] && v[1]) {
      emit('change', [String(v[0]), String(v[1])])
    }
  }

  function emitChange(s: string, e: string) {
    if (!s || !e) return
    if (new Date(s) > new Date(e)) {
      emit('change', [e, s])
      return
    }
    emit('change', [s, e])
  }

  watch(() => [props.start, props.end], () => {
    /* 外部重置时由 props 驱动，无需本地状态 */
  })
</script>

<style scoped lang="scss">
  .ky-mobile-pickers {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
  }

  .ky-mobile-picker {
    flex: 1;
    min-width: 0;
  }

  /* 桌面端隐藏移动版式 */
  @media only screen and (min-width: 769px) {
    .ky-mobile-pickers {
      display: none;
    }

    .ky-drc {
      width: auto;
    }
  }

  /* 移动端隐藏范围选择器 */
  @media only screen and (max-width: 768px) {
    .ky-desktop-picker {
      display: none !important;
    }

    :deep(.el-input__wrapper),
    :deep(.el-range-editor.el-input__wrapper) {
      width: 100%;
    }
  }
</style>

<style lang="scss">
  /* 弹层为 teleport 到 body 的全局节点：限制宽度避免小屏溢出 */
  @media only screen and (max-width: 768px) {
    body>.el-popper {

      .el-date-picker,
      .el-date-range-picker {
        max-width: calc(100vw - 16px);
      }

      .el-date-range-picker.has-sidebar {
        max-width: calc(100vw - 16px);
      }

      .el-picker-panel__body-wrapper {
        overflow-x: auto;
      }

      .el-date-range-picker__content {
        width: auto;
        min-width: 0;
      }

      .el-date-range-picker table {
        width: 100%;
      }
    }
  }
</style>
