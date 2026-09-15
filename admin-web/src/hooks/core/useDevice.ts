/**
 * useDevice - 设备/视口判断
 *
 * 全站响应式判断统一走这里，不要在组件里再写 `width < 800` 这类魔法数字。
 *
 * ## 主要功能
 *
 * 1. 三档视口判断：手机 / 手持设备 / 小笔记本，取值见 `src/config/breakpoints.ts`
 * 2. 全局共享同一个 resize 监听，多个组件同时使用不会重复绑定
 * 3. 同时支持 orientationchange，避免部分安卓机旋转后宽度不更新
 *
 * ## 为什么不直接用 @vueuse 的 useWindowSize
 *
 * 组件里直接用 useWindowSize 会在每个组件各绑一个 resize 监听。卡片列表组件被十几个页面
 * 复用时，监听数量会随页面数量线性增长。这里做成模块级单例，全局只绑一次。
 *
 * 另外 @vueuse 的 useEventListener 会在组件 setup 作用域里自动注册清理，
 * 如果单例是在某个组件首次调用时创建的，那个组件卸载会把全局监听一起销毁 —— 这是个坑，
 * 所以这里直接手写 addEventListener，生命周期与模块一致。
 *
 * @module useDevice
 */

import { computed, readonly, ref, type ComputedRef, type Ref } from 'vue'
import { BREAKPOINTS } from '@/config/breakpoints'

/** 当前视口宽度（模块级单例，全局共享） */
const viewportWidth = ref(
  typeof window === 'undefined' ? BREAKPOINTS.COMPACT + 1 : window.innerWidth
)

/** 监听只绑一次 */
let listenerBound = false

function bindResizeListener(): void {
  if (listenerBound || typeof window === 'undefined') return
  listenerBound = true

  const sync = () => {
    viewportWidth.value = window.innerWidth
  }

  // passive：这些监听只读宽度，不阻止默认行为，避免滚动被拖慢
  window.addEventListener('resize', sync, { passive: true })
  // 部分安卓浏览器旋转屏幕后不触发 resize，需要单独兜一次
  window.addEventListener('orientationchange', sync, { passive: true })
  sync()
}

export interface UseDeviceReturn {
  /** 当前视口宽度 */
  width: Readonly<Ref<number>>
  /** 手机（≤640）：弹窗全屏、触控区放大、表格降级为卡片 */
  isMobile: ComputedRef<boolean>
  /** 手持设备（≤768）：表格降级为卡片列表、侧栏改抽屉 */
  isHandheld: ComputedRef<boolean>
  /** 小笔记本及以下（≤1180） */
  isCompact: ComputedRef<boolean>
  /** 宽屏（>768），表格可以正常展示 */
  isDesktop: ComputedRef<boolean>
}

export function useDevice(): UseDeviceReturn {
  bindResizeListener()

  const isMobile = computed(() => viewportWidth.value <= BREAKPOINTS.MOBILE)
  const isHandheld = computed(() => viewportWidth.value <= BREAKPOINTS.HANDHELD)
  const isCompact = computed(() => viewportWidth.value <= BREAKPOINTS.COMPACT)
  const isDesktop = computed(() => viewportWidth.value > BREAKPOINTS.HANDHELD)

  return {
    width: readonly(viewportWidth),
    isMobile,
    isHandheld,
    isCompact,
    isDesktop
  }
}
