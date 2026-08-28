<!-- 矩形树图（树状图） -->
<template>
  <div ref="chartRef" :style="{ height: props.height }" v-loading="props.loading"> </div>
</template>

<script setup lang="ts">
  import { useChartOps, useChartComponent } from '@/hooks/core/useChart'
  import { getCssVar } from '@/utils/ui'
  import type { EChartsOption } from '@/plugins/echarts'
  import type { TreeMapChartProps, TreeMapDataItem } from '@/types/component/chart'

  defineOptions({ name: 'ArtTreeMapChart' })

  const props = withDefaults(defineProps<TreeMapChartProps>(), {
    height: useChartOps().chartHeight,
    loading: false,
    isEmpty: false,
    colors: () => useChartOps().colors,
    showTooltip: true,
    showLegend: false,
    legendPosition: 'bottom'
  })

  const colorPalette = computed(() => {
    const primary = getCssVar('--el-color-primary')
    const base = [primary, '#4ABEFF', '#14DEBA', '#FFAF20', '#FA8A6C', '#9C27B0', '#67C23A']
    return props.colors?.length ? props.colors : base
  })

  const seriesData = computed(() =>
    (props.data as TreeMapDataItem[]).map((item, index) => ({
      name: item.name,
      value: item.value,
      itemStyle: {
        color: colorPalette.value[index % colorPalette.value.length],
        borderColor: '#fff',
        borderWidth: 2,
        borderRadius: 4
      }
    }))
  )

  const { chartRef, getTooltipStyle } = useChartComponent({
    props,
    checkEmpty: () =>
      !Array.isArray(props.data) ||
      props.data.length === 0 ||
      props.data.every((d) => d.value === 0),
    watchSources: [() => props.data, () => props.colors],
    generateOptions: (): EChartsOption => ({
      tooltip: props.showTooltip
        ? getTooltipStyle('item', {
            formatter: (params: { name: string; value: unknown }) =>
              `${params.name}<br/>金额：¥${Number(params.value ?? 0).toLocaleString()}`
          })
        : undefined,
      series: [
        {
          type: 'treemap',
          roam: false,
          nodeClick: false,
          breadcrumb: { show: false },
          label: {
            show: true,
            color: '#fff',
            fontSize: 13,
            formatter: (params: { name: string; value: unknown }) =>
              `${params.name}\n¥${Number(params.value ?? 0).toLocaleString()}`
          },
          itemStyle: {
            borderColor: '#fff',
            borderWidth: 2,
            gapWidth: 2
          },
          data: seriesData.value
        }
      ]
    })
  })
</script>
