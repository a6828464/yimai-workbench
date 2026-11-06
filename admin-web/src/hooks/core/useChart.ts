/**
 * useChart - ECharts 图表管理
 *
 * 提供完整的 ECharts 图表生命周期管理和配置能力，简化图表开发流程。
 * 自动处理图表初始化、更新、销毁、主题切换、响应式调整等复杂逻辑。
 *
 * ## 核心功能
 *
 * 1. 图表生命周期管理 - 自动处理初始化、更新、销毁，支持延迟加载和可见性检测
 * 2. 主题自动适配 - 响应系统主题变化，自动更新图表样式和配色
 * 3. 响应式调整 - 监听窗口大小、菜单展开等变化，自动调整图表尺寸
 * 4. 空状态处理 - 优雅的空数据展示，自动显示"暂无数据"提示
 * 5. 样式配置统一 - 提供坐标轴、图例、提示框等统一的样式配置方法
 * 6. 性能优化 - 防抖处理、样式缓存、requestAnimationFrame 优化
 * 7. 高级组件抽象 - useChartComponent 提供更高层次的图表组件封装
 *
 * ## 使用示例
 *
 * ```typescript
 * // 基础用法
 * const {
 *   chartRef,
 *   initChart,
 *   updateChart,
 *   getAxisLineStyle,
 *   getTooltipStyle
 * } = useChart()
 *
 * onMounted(() => {
 *   initChart({
 *     xAxis: { type: 'category', data: ['Mon', 'Tue', 'Wed'] },
 *     yAxis: { type: 'value' },
 *     series: [{ data: [120, 200, 150], type: 'bar' }]
 *   })
 * })
 *
 * // 高级用法 - 组件抽象
 * const chart = useChartComponent({
 *   props,
 *   generateOptions: () => ({
 *     // ECharts 配置
 *   }),
 *   checkEmpty: () => data.value.length === 0,
 *   watchSources: [() => props.data]
 * })
 * ```
 *
 * @module useChart
 * @author Art Design Pro Team
 */

import type { EChartsOption } from '@/plugins/echarts'
import { storeToRefs } from 'pinia'
import { useSettingStore } from '@/store/modules/setting'
import { getCssVar } from '@/utils/ui'
import type { BaseChartProps, ChartThemeConfig, UseChartOptions } from '@/types/component/chart'

/**
 * echarts 异步边界（2026-11 t21）
 *
 * ## 为什么这里必须动态 import
 *
 * 改造前本文件顶部是 `import { echarts } from '@/plugins/echarts'`，
 * 而 4 份工作台（boss / manager / teacher / media dashboard）全部 import 这个
 * useChart —— 于是无论哪个角色登录、无论页面画不画图，**echarts 都在首屏依赖图里**，
 * 每人白付 597 KB raw / 197.7 KiB gz（t3/t12 实测）。
 *
 * 现在改成 `import()`：只有真正渲染图表的组件在挂载后才会触发下载。
 * `@/plugins/echarts` 模块顶部的 `echarts.use([...])` 是模块级副作用，
 * 动态 import 的 promise resolve 时注册已经完成 —— 所以**先注册、再 init**，
 * 不会出现「图表空白 / Component xxx is not exists」。
 */
type EChartsNamespace = typeof import('echarts/core')
type EChartsInstance = import('echarts/core').ECharts

let echartsNamespace: EChartsNamespace | null = null
let echartsLoading: Promise<EChartsNamespace> | null = null

/**
 * 加载 echarts（模块级单例，多次调用共享同一个 promise）。
 * 任意图表组件第一次挂载时调用一次即可。
 *
 * ## 失败必须重置单例（t25，R1 复核发现）
 *
 * `echartsLoading` 是模块级单例：如果 `import()` reject 之后**不把它清掉**，
 * 这个变量会一直持有一个 **rejected promise**，而 `echartsNamespace` 仍是 null。
 * 于是本次会话里**所有**图表的 `loadEcharts()` 都会立刻拿到同一个失败结果 ——
 * 一次网络抖动（弱网、CDN 504、被运营商劫持）就让用户「永久白图」，
 * 只能刷新页面才能自救。图表是工作台的主体内容，这个代价不能接受。
 *
 * 所以：reject 时把单例置回 null，下一次调用会**重新发起 import**（浏览器对失败的
 * 模块请求不会缓存成成功模块，重试是真实重试）。成功路径不受影响，成功后走
 * `echartsNamespace` 快路径，不会重复请求。
 */
export function loadEcharts(): Promise<EChartsNamespace> {
  if (echartsNamespace) return Promise.resolve(echartsNamespace)
  if (!echartsLoading) {
    echartsLoading = import('@/plugins/echarts')
      .then((mod) => {
        echartsNamespace = mod.echarts as EChartsNamespace
        return echartsNamespace
      })
      .catch((err) => {
        // 关键：不重置的话，一次失败会毒化整个会话的图表（永久白图）
        echartsLoading = null
        throw err
      })
  }
  return echartsLoading
}

/**
 * 线性渐变（纯对象形式）。
 *
 * 等价于 `new echarts.graphic.LinearGradient(x, y, x2, y2, stops)`，但**不需要**
 * 先加载 echarts —— 组件在生成 options 时就能同步构建，从而把 echarts 的加载
 * 推迟到真正 init 的那一刻。echarts 渲染层接受这种纯对象（graphic.LinearGradient
 * 内部也是先转成同样的对象），视觉效果一致。
 */
export interface LinearGradientColor {
  type: 'linear'
  x: number
  y: number
  x2: number
  y2: number
  colorStops: Array<{ offset: number; color: string }>
  global: boolean
}

export const linearGradient = (
  x: number,
  y: number,
  x2: number,
  y2: number,
  colorStops: Array<{ offset: number; color: string }>
): LinearGradientColor => ({ type: 'linear', x, y, x2, y2, colorStops, global: false })

// 图表主题配置
export const useChartOps = (): ChartThemeConfig => ({
  /** */
  chartHeight: '16rem',
  /** 字体大小 */
  fontSize: 13,
  /** 字体颜色 */
  fontColor: '#999',
  /** 主题颜色 */
  themeColor: getCssVar('--el-color-primary-light-1'),
  /** 颜色组 */
  colors: [
    getCssVar('--el-color-primary-light-1'),
    '#4ABEFF',
    '#EDF2FF',
    '#14DEBA',
    '#FFAF20',
    '#FA8A6C',
    '#FFAF20'
  ]
})

// 常量定义
const RESIZE_DELAYS = [50, 100, 200, 350] as const
const MENU_RESIZE_DELAYS = [50, 100, 200] as const
const RESIZE_DEBOUNCE_DELAY = 100

export function useChart(options: UseChartOptions = {}) {
  const { initOptions, initDelay = 0, threshold = 0.1, autoTheme = true } = options

  const settingStore = useSettingStore()
  const { isDark, menuOpen, menuType } = storeToRefs(settingStore)

  const chartRef = ref<HTMLElement>()
  let chart: EChartsInstance | null = null
  let intersectionObserver: IntersectionObserver | null = null
  let pendingOptions: EChartsOption | null = null
  let resizeTimeoutId: number | null = null
  let resizeFrameId: number | null = null
  let isDestroyed = false
  let emptyStateDiv: HTMLElement | null = null

  // 清理定时器的统一方法
  const clearTimers = () => {
    if (resizeTimeoutId) {
      clearTimeout(resizeTimeoutId)
      resizeTimeoutId = null
    }
    if (resizeFrameId) {
      cancelAnimationFrame(resizeFrameId)
      resizeFrameId = null
    }
  }

  // 使用 requestAnimationFrame 优化 resize 处理
  const requestAnimationResize = () => {
    if (resizeFrameId) {
      cancelAnimationFrame(resizeFrameId)
    }
    resizeFrameId = requestAnimationFrame(() => {
      handleResize()
      resizeFrameId = null
    })
  }

  // 防抖的resize处理（用于窗口resize事件）
  const debouncedResize = () => {
    if (resizeTimeoutId) {
      clearTimeout(resizeTimeoutId)
    }
    resizeTimeoutId = window.setTimeout(() => {
      requestAnimationResize()
      resizeTimeoutId = null
    }, RESIZE_DEBOUNCE_DELAY)
  }

  // 多延迟resize处理 - 统一方法
  const multiDelayResize = (delays: readonly number[]) => {
    // 立即调用一次，快速响应
    nextTick(requestAnimationResize)

    // 使用延迟时间，确保图表正确适应变化
    delays.forEach((delay) => {
      setTimeout(requestAnimationResize, delay)
    })
  }

  // 收缩菜单时，重新计算图表大小（仅在图表存在时监听）
  let menuOpenStopHandle: (() => void) | null = null
  let menuTypeStopHandle: (() => void) | null = null

  const setupMenuWatchers = () => {
    menuOpenStopHandle = watch(menuOpen, () => multiDelayResize(RESIZE_DELAYS))
    menuTypeStopHandle = watch(menuType, () => {
      nextTick(requestAnimationResize)
      setTimeout(() => multiDelayResize(MENU_RESIZE_DELAYS), 0)
    })
  }

  const cleanupMenuWatchers = () => {
    menuOpenStopHandle?.()
    menuTypeStopHandle?.()
    menuOpenStopHandle = null
    menuTypeStopHandle = null
  }

  // 主题变化时重新设置图表选项
  let themeStopHandle: (() => void) | null = null

  const setupThemeWatcher = () => {
    if (autoTheme) {
      themeStopHandle = watch(isDark, () => {
        // 更新空状态样式
        emptyStateManager.updateStyle()

        if (chart && !isDestroyed) {
          // 使用 requestAnimationFrame 优化主题更新
          requestAnimationFrame(() => {
            if (chart && !isDestroyed) {
              const currentOptions = chart.getOption()
              if (currentOptions) {
                updateChart(currentOptions as EChartsOption)
              }
            }
          })
        }
      })
    }
  }

  const cleanupThemeWatcher = () => {
    themeStopHandle?.()
    themeStopHandle = null
  }

  // 样式生成器 - 统一的样式配置
  const createLineStyle = (color: string, width = 1, type?: 'solid' | 'dashed') => ({
    color,
    width,
    ...(type && { type })
  })

  // 缓存样式配置以减少重复计算
  const styleCache = {
    axisLine: null as any,
    splitLine: null as any,
    axisLabel: null as any,
    lastDarkValue: isDark.value
  }

  const clearStyleCache = () => {
    styleCache.axisLine = null
    styleCache.splitLine = null
    styleCache.axisLabel = null
    styleCache.lastDarkValue = isDark.value
  }

  // 坐标轴线样式
  const getAxisLineStyle = (show: boolean = true) => {
    if (styleCache.lastDarkValue !== isDark.value) {
      clearStyleCache()
    }
    if (!styleCache.axisLine) {
      styleCache.axisLine = {
        show,
        lineStyle: createLineStyle(isDark.value ? '#444' : '#EDEDED')
      }
    }
    return styleCache.axisLine
  }

  // 分割线样式
  const getSplitLineStyle = (show: boolean = true) => {
    if (styleCache.lastDarkValue !== isDark.value) {
      clearStyleCache()
    }
    if (!styleCache.splitLine) {
      styleCache.splitLine = {
        show,
        lineStyle: createLineStyle(isDark.value ? '#444' : '#EDEDED', 1, 'dashed')
      }
    }
    return styleCache.splitLine
  }

  // 坐标轴标签样式
  const getAxisLabelStyle = (show: boolean = true) => {
    if (styleCache.lastDarkValue !== isDark.value) {
      clearStyleCache()
    }
    if (!styleCache.axisLabel) {
      const { fontColor, fontSize } = useChartOps()
      styleCache.axisLabel = {
        show,
        color: fontColor,
        fontSize
      }
    }
    return styleCache.axisLabel
  }

  // 坐标轴刻度样式（静态配置，无需缓存）
  const getAxisTickStyle = () => ({
    show: false
  })

  // 获取动画配置
  const getAnimationConfig = (animationDelay: number = 50, animationDuration: number = 1500) => ({
    animationDelay: (idx: number) => idx * animationDelay + 200,
    animationDuration: (idx: number) => animationDuration - idx * 50,
    animationEasing: 'quarticOut' as const
  })

  // 获取统一的 tooltip 配置
  const getTooltipStyle = (trigger: 'item' | 'axis' = 'axis', customOptions: any = {}) => ({
    trigger,
    backgroundColor: isDark.value ? 'rgba(0, 0, 0, 0.8)' : 'rgba(255, 255, 255, 0.9)',
    borderColor: isDark.value ? '#333' : '#ddd',
    borderWidth: 1,
    textStyle: {
      color: isDark.value ? '#fff' : '#333'
    },
    ...customOptions
  })

  // 获取统一的图例配置
  const getLegendStyle = (
    position: 'bottom' | 'top' | 'left' | 'right' = 'bottom',
    customOptions: any = {}
  ) => {
    const baseConfig = {
      textStyle: {
        color: isDark.value ? '#fff' : '#333'
      },
      itemWidth: 12,
      itemHeight: 12,
      itemGap: 20,
      ...customOptions
    }

    // 根据位置设置不同的配置
    switch (position) {
      case 'bottom':
        return {
          ...baseConfig,
          bottom: 0,
          left: 'center',
          orient: 'horizontal',
          icon: 'roundRect'
        }
      case 'top':
        return {
          ...baseConfig,
          top: 0,
          left: 'center',
          orient: 'horizontal',
          icon: 'roundRect'
        }
      case 'left':
        return {
          ...baseConfig,
          left: 0,
          top: 'center',
          orient: 'vertical',
          icon: 'roundRect'
        }
      case 'right':
        return {
          ...baseConfig,
          right: 0,
          top: 'center',
          orient: 'vertical',
          icon: 'roundRect'
        }
      default:
        return baseConfig
    }
  }

  // 根据图例位置计算 grid 配置
  const getGridWithLegend = (
    showLegend: boolean,
    legendPosition: 'bottom' | 'top' | 'left' | 'right' = 'bottom',
    baseGrid: any = {}
  ) => {
    const defaultGrid = {
      top: 15,
      right: 15,
      bottom: 8,
      left: 0,
      containLabel: true,
      ...baseGrid
    }

    if (!showLegend) {
      return defaultGrid
    }

    // 根据图例位置调整 grid
    switch (legendPosition) {
      case 'bottom':
        return {
          ...defaultGrid,
          bottom: 40
        }
      case 'top':
        return {
          ...defaultGrid,
          top: 40
        }
      case 'left':
        return {
          ...defaultGrid,
          left: 120
        }
      case 'right':
        return {
          ...defaultGrid,
          right: 120
        }
      default:
        return defaultGrid
    }
  }

  /**
   * 确保图表实例存在（异步）。
   *
   * echarts 走动态 import，所以「拿到实例」这件事本身是异步的：
   * 先 await 加载（此时 `echarts.use()` 已注册完毕），再 init。
   * 组件侧不用改：`initChart` / `updateChart` 仍是同步签名，内部走这里。
   */
  const ensureInstance = async (): Promise<EChartsInstance | null> => {
    if (chart) return chart
    if (isDestroyed || !chartRef.value) return null

    const echarts = await loadEcharts()
    // 加载期间组件可能已卸载（快速切页/切 tab），必须再判一次
    if (isDestroyed || !chartRef.value) return null

    if (!chart) {
      chart = echarts.init(chartRef.value)
      // 图表创建后立即设置监听器
      setupMenuWatchers()
      setupThemeWatcher()
    }
    return chart
  }

  // 创建IntersectionObserver
  const createIntersectionObserver = () => {
    if (intersectionObserver || !chartRef.value) return

    intersectionObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting && pendingOptions && !isDestroyed) {
            // 使用 requestAnimationFrame 确保在下一帧初始化图表
            requestAnimationFrame(() => {
              if (!isDestroyed && pendingOptions) {
                // echarts 是异步边界：先 await 加载（use() 注册在 import 完成时已生效）
                void ensureInstance()
                  .then((instance) => {
                    if (!instance || isDestroyed || !entry.target) return

                    // 触发自定义事件，让组件处理动画逻辑
                    const event = new CustomEvent('chartVisible', {
                      detail: { options: pendingOptions }
                    })
                    entry.target.dispatchEvent(event)

                    pendingOptions = null
                    cleanupIntersectionObserver()
                  })
                  .catch((error) => {
                    console.error('图表初始化失败:', error)
                  })
              }
            })
          }
        })
      },
      { threshold }
    )

    intersectionObserver.observe(chartRef.value)
  }

  // 清理IntersectionObserver
  const cleanupIntersectionObserver = () => {
    if (intersectionObserver) {
      intersectionObserver.disconnect()
      intersectionObserver = null
    }
  }

  // 检查容器是否可见
  const isContainerVisible = (element: HTMLElement): boolean => {
    const rect = element.getBoundingClientRect()
    return rect.width > 0 && rect.height > 0 && rect.top < window.innerHeight && rect.bottom > 0
  }

  // 图表初始化核心逻辑
  const performChartInit = (options: EChartsOption) => {
    if (chart && !isDestroyed) {
      chart.setOption(options)
      pendingOptions = null
      return
    }
    if (isDestroyed) return

    // 首次渲染：等 echarts 异步加载完成再 init + setOption
    void ensureInstance()
      .then((instance) => {
        if (!instance || isDestroyed) return
        instance.setOption(options)
        pendingOptions = null
      })
      .catch((error) => {
        console.error('图表初始化失败:', error)
      })
  }

  // 空状态管理器
  const emptyStateManager = {
    create: () => {
      if (!chartRef.value || emptyStateDiv) return

      emptyStateDiv = document.createElement('div')
      emptyStateDiv.style.cssText = `
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: ${isDark.value ? '#555555' : '#B3B2B2'};
        background: transparent;
        z-index: 10;
      `
      emptyStateDiv.innerHTML = `<span>暂无数据</span>`

      // 确保父容器有相对定位
      if (
        chartRef.value.style.position !== 'relative' &&
        chartRef.value.style.position !== 'absolute'
      ) {
        chartRef.value.style.position = 'relative'
      }

      chartRef.value.appendChild(emptyStateDiv)
    },

    remove: () => {
      if (emptyStateDiv && chartRef.value) {
        chartRef.value.removeChild(emptyStateDiv)
        emptyStateDiv = null
      }
    },

    updateStyle: () => {
      if (emptyStateDiv) {
        emptyStateDiv.style.color = isDark.value ? '#666' : '#999'
      }
    }
  }

  // 初始化图表
  const initChart = (options: EChartsOption = {}, isEmpty: boolean = false) => {
    if (!chartRef.value || isDestroyed) return

    const mergedOptions = { ...initOptions, ...options }

    try {
      if (isEmpty) {
        // 处理空数据情况 - 显示自定义空状态div
        if (chart) {
          chart.clear()
        }
        emptyStateManager.create()
        return
      } else {
        // 有数据时移除空状态div
        emptyStateManager.remove()
      }

      if (isContainerVisible(chartRef.value)) {
        // 容器可见，正常初始化
        if (initDelay > 0) {
          setTimeout(() => performChartInit(mergedOptions), initDelay)
        } else {
          performChartInit(mergedOptions)
        }
      } else {
        // 容器不可见，保存选项并设置监听器
        pendingOptions = mergedOptions
        createIntersectionObserver()
      }
    } catch (error) {
      console.error('图表初始化失败:', error)
    }
  }

  // 更新图表
  const updateChart = (options: EChartsOption) => {
    if (isDestroyed) return

    try {
      if (!chart) {
        // 如果图表不存在，先初始化
        initChart(options)
        return
      }
      chart.setOption(options)
    } catch (error) {
      console.error('图表更新失败:', error)
    }
  }

  // 处理窗口大小变化
  const handleResize = () => {
    if (chart && !isDestroyed) {
      try {
        chart.resize()
      } catch (error) {
        console.error('图表resize失败:', error)
      }
    }
  }

  // 销毁图表
  const destroyChart = () => {
    isDestroyed = true

    if (chart) {
      try {
        chart.dispose()
      } catch (error) {
        console.error('图表销毁失败:', error)
      } finally {
        chart = null
      }
    }

    // 清理所有监听器和资源
    cleanupMenuWatchers()
    cleanupThemeWatcher()
    emptyStateManager.remove()
    cleanupIntersectionObserver()
    clearTimers()
    clearStyleCache()
    pendingOptions = null
  }

  // 获取图表实例
  const getChartInstance = () => chart

  // 获取图表是否已初始化
  const isChartInitialized = () => chart !== null

  onMounted(() => {
    window.addEventListener('resize', debouncedResize)
  })

  onBeforeUnmount(() => {
    window.removeEventListener('resize', debouncedResize)
  })

  onUnmounted(() => {
    destroyChart()
  })

  return {
    isDark,
    chartRef,
    initChart,
    updateChart,
    handleResize,
    destroyChart,
    getChartInstance,
    isChartInitialized,
    emptyStateManager,
    getAxisLineStyle,
    getSplitLineStyle,
    getAxisLabelStyle,
    getAxisTickStyle,
    getAnimationConfig,
    getTooltipStyle,
    getLegendStyle,
    useChartOps,
    getGridWithLegend
  }
}

// 高级图表组件抽象
interface UseChartComponentOptions<T extends BaseChartProps> {
  /** Props响应式对象 */
  props: T
  /** 图表配置生成函数 */
  generateOptions: () => EChartsOption
  /** 空数据检查函数 */
  checkEmpty?: () => boolean
  /** 自定义监听的响应式数据 */
  watchSources?: (() => any)[]
  /** 自定义可视事件处理 */
  onVisible?: () => void
  /** useChart选项 */
  chartOptions?: UseChartOptions
}

export function useChartComponent<T extends BaseChartProps>(options: UseChartComponentOptions<T>) {
  const {
    props,
    generateOptions,
    checkEmpty,
    watchSources = [],
    onVisible,
    chartOptions = {}
  } = options

  const chart = useChart(chartOptions)
  const { chartRef, initChart, isDark, emptyStateManager } = chart

  // 检查是否为空数据
  const isEmpty = computed(() => {
    if (props.isEmpty) return true
    if (checkEmpty) return checkEmpty()
    return false
  })

  // 更新图表
  const updateChart = () => {
    nextTick(() => {
      if (isEmpty.value) {
        // 处理空数据情况 - 显示自定义空状态div
        if (chart.getChartInstance()) {
          chart.getChartInstance()?.clear()
        }
        emptyStateManager.create()
      } else {
        // 有数据时移除空状态div并初始化图表
        emptyStateManager.remove()
        initChart(generateOptions())
      }
    })
  }

  // 处理图表进入可视区域时的逻辑
  const handleChartVisible = () => {
    if (onVisible) {
      onVisible()
    } else {
      updateChart()
    }
  }

  // 存储监听器停止函数
  const stopHandles: (() => void)[] = []

  // 设置数据监听
  const setupWatchers = () => {
    // 监听自定义数据源
    if (watchSources.length > 0) {
      const stopHandle = watch(watchSources, updateChart, { deep: true })
      stopHandles.push(stopHandle)
    }

    // 监听主题变化
    const themeStopHandle = watch(isDark, () => {
      emptyStateManager.updateStyle()
      updateChart()
    })
    stopHandles.push(themeStopHandle)
  }

  // 清理所有监听器
  const cleanupWatchers = () => {
    stopHandles.forEach((stop) => stop())
    stopHandles.length = 0
  }

  // 设置生命周期
  const setupLifecycle = () => {
    onMounted(() => {
      updateChart()

      // 监听图表可见事件
      if (chartRef.value) {
        chartRef.value.addEventListener('chartVisible', handleChartVisible)
      }
    })

    onBeforeUnmount(() => {
      // 清理事件监听器
      if (chartRef.value) {
        chartRef.value.removeEventListener('chartVisible', handleChartVisible)
      }
      // 清理所有监听器
      cleanupWatchers()
      // 清理空状态div
      emptyStateManager.remove()
    })
  }

  // 初始化
  setupWatchers()
  setupLifecycle()

  return {
    ...chart,
    isEmpty,
    updateChart,
    handleChartVisible
  }
}
