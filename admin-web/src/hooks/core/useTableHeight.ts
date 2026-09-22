/**
 * useTableHeight - 表格高度自适应（全站唯一 owner）
 *
 * ## 这个文件解决什么问题
 *
 * 全站列表页曾经有两种写法并存：
 *
 * 1. **写死像素**：`max-height="520"` —— 窗口越高留白越多。1440x900 实测：
 *    人员操作日志表格高 520px、卡片底部空 119px；模型生成记录页空 472px。
 * 2. **各页自己抄一行 `calc(var(--art-full-height) - 180px)`** —— 只是把「写死 520」
 *    换成了「写死 180」。减项本该随页面结构变化（筛选条行数、提示行、分页器位置
 *    各页都不同），抄错一次就又是一次「表格高度不对」。
 *
 * 现在收敛成一处：**页面根容器 `.list-page` + 本 hook**。页面只需要
 *
 * ```ts
 * const { tableMaxHeight, tableRef } = useTableHeight()
 * ```
 *
 * 把 `tableRef` 绑到 ElTable 的 `ref`、`tableMaxHeight` 绑到 `:max-height`，
 * 不需要知道减项是多少，也不需要改任何数字就能适应新增的筛选控件。
 *
 * ## 减项是怎么来的（为什么不是常量）
 *
 * 同一套 `.list-page` 结构下，各页表格上方的元素数量并不相同：
 *
 * | 页面       | 表格上方                                        |
 * | ---------- | ----------------------------------------------- |
 * | 留资管理   | 筛选条 + 提示行                                  |
 * | 会员管理   | Tabs + 提示行 + 筛选条 + 提示行                  |
 * | 账号与角色 | 卡片标题行 + 筛选条                              |
 *
 * 所以这里**在运行时量**，量法分两段：
 *
 * - **上方**：`表格 rect.top - 容器内容区 top`（浏览器已把 padding / margin / 换行算进去）。
 * - **下方**：遍历表格之后的所有同级元素累加高度 + 外边距（含容器 padding-bottom）。
 *
 * ## 为什么下方必须用「兄弟元素累加」而不是「容器底 - 表格底」
 *
 * 后者依赖表格当前高度，会形成正反馈：表格量到 460 → 下方剩 200 → 目标高度 500 →
 * 表格变 500 → 下方剩 160 → 目标 540 → …永远收敛不到。兄弟累加只跟「表格下面有什么」
 * 有关，与表格自身高度无关，一次量准。
 *
 * ## 为什么用 ResizeObserver 而不是 window.resize
 *
 * 筛选条在窄屏会换行（`flex-wrap`），换行后高度变化**不触发** `window.resize`。
 * 只监听 window.resize 的写法在「拉宽/收窄窗口但高度没变」时会漏算，表格就会盖住分页器。
 *
 * ## 为什么量 rect 而不是 offsetTop
 *
 * 页面根容器 `.list-page` 自带 `overflow-y: auto`，内部滚动时 `offsetTop` 相对
 * offsetParent（更外层）反而是静态值 —— 但我们的容器就是滚动容器本身，
 * 用 `rect.top - containerRect.top + container.scrollTop` 才能抵消容器自身滚动。
 *
 * ## 首帧不闪
 *
 * `--art-full-height` 由 `useLayoutHeight` 写入 `<html>`，值形如 `calc(100vh - 75px)`。
 * setup 阶段先把它解析成像素当作初值，表格首帧就有正确高度；
 * onMounted 后再用实测值精修（把筛选条换行等真实布局算进去）。
 *
 * @module useTableHeight
 */

import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
  type ComputedRef,
  type Ref
} from 'vue'

/** 表格至少保留的高度：窗口再矮也不能把表头 + 一行挤没 */
const MIN_TABLE_HEIGHT = 160

/** 手持设备上限：与 src/config/breakpoints.ts 的 BREAKPOINTS.HANDHELD 一致 */
const HANDHELD_MAX = 768

/** 页面根容器 class（统一范式，见 assets/styles/core/app.scss） */
const PAGE_ROOT_CLASSES = ['list-page', 'art-full-height']

/**
 * 表格位于容器可视区之外时，兜底高度占视口的比例。
 *
 * 这类表格（如薪酬页「逐人明细」嵌在页面内层滚动区的卡片里）按「填满容器」算出来
 * 是负数 —— 它根本不在可视区内，谈不上填满。但也不能不约束：31 行的表会有 1170px 高，
 * 用户要滚两屏才看到表尾。
 *
 * 按视口比例给，随窗口变化，仍然是自适应的（不是固定 px）。
 *
 * 内容页（无 `--fill` 的 `.list-page`，整页滚动）的表格上限也用这个比例：
 * 那类页面同样「谈不上填满容器」（容器随内容增长，按填满算会形成正反馈，
 * 实测同步页每秒长 60px），也只能按视口比例约束。语义是同一个 ——
 * 「算不出填满高度时，退到视口的一个比例」。改造前同步页写死 `max-height="520"`
 * 对应 520 / 740 ≈ 0.7，所以这个比例与既有取值也是吻合的。
 */
const VIEWPORT_FALLBACK_RATIO = 0.7

/**
 * 把 CSS 高度值解析成像素。
 *
 * 只支持 `--art-full-height` 实际会出现的三种形态（`calc(100vh - Npx)` / `Npx` / `100vh`），
 * 不做通用 CSS 计算 —— 解析不出来就返回 0，让 onMounted 的实测接管。
 */
function parseCssHeight(value: string): number {
  const v = (value || '').trim()
  if (!v) return 0

  const vh = typeof window === 'undefined' ? 0 : window.innerHeight

  // calc(100vh - 75px) / calc(100vh - 75.5px)
  const calc = v.match(/^calc\(\s*100vh\s*-\s*([\d.]+)px\s*\)$/)
  if (calc) return Math.max(0, vh - parseFloat(calc[1]))

  // calc(100vh + 12px)
  const calcPlus = v.match(/^calc\(\s*100vh\s*\+\s*([\d.]+)px\s*\)$/)
  if (calcPlus) return Math.max(0, vh + parseFloat(calcPlus[1]))

  // 100vh / 100dvh
  if (/^100(d)?vh$/.test(v)) return vh

  // 520px / 520
  const px = v.match(/^([\d.]+)(px)?$/)
  if (px) return parseFloat(px[1])

  return 0
}

/** 读 `--art-full-height` 并解析成像素（拿不到返回 0） */
function readLayoutHeightVar(): number {
  if (typeof document === 'undefined') return 0
  const raw = getComputedStyle(document.documentElement).getPropertyValue('--art-full-height')
  return parseCssHeight(raw)
}

/** 取整，避免 subpixel 让 ResizeObserver 反复触发 */
function round(n: number): number {
  return Math.round(n)
}

/**
 * 把 ref 的值解析成真实的 DOM 元素。
 *
 * 页面里通常写 `<ElTable ref="tableRef">`，Vue 给到的是**组件实例**而不是元素
 * （`getComputedStyle` 会直接抛错）。组件根节点是单元素时用 `$el` 拿回来；
 * `v-if` 为假时 `$el` 是注释节点，这里一并挡掉。
 *
 * 也兼容直接绑在原生元素上的情况（`<div ref="tableRef">`）。
 */
function resolveElement(value: unknown): HTMLElement | undefined {
  if (value instanceof HTMLElement) return value
  if (value && typeof value === 'object' && '$el' in value) {
    const el = (value as { $el?: unknown }).$el
    if (el instanceof HTMLElement) return el
  }
  return undefined
}

/** 元素的外边距高度（上下） */
function verticalMargin(el: HTMLElement): number {
  const cs = getComputedStyle(el)
  return (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0)
}

/**
 * 累加「表格下方」到容器内容区底部的全部占位。
 *
 * 从表格出发逐层向上走到容器为止，每层累加两样东西：
 *
 * 1. **该层里排在 node 之后的兄弟元素**的高度 + 上下外边距
 *    （分页器就在这一层，外面还可能套了一层 `.list-pager`）
 * 2. **该层自身的 padding-bottom**
 *    （`.el-card__body` 默认有 20px 内边距，漏掉它表格就会把分页器挤出去）
 *
 * ⚠️ **不含容器自己的 padding-bottom**：调用方的 `containerHeight` 是按容器
 * content box 算的（已经扣掉上下 padding），这里再加一次就会重复扣，
 * 表格会白白矮 16px。改造中实测踩过这个坑。
 *
 * 注意这个函数**不读表格自身高度**，所以不会形成「表格变高 → 下方变小 → 目标变高」
 * 的正反馈，一次就能量准。
 */
function sumBelow(table: HTMLElement, container: HTMLElement): number {
  let total = 0
  let node: HTMLElement | null = table

  while (node && node !== container) {
    let sib: Element | null = node.nextElementSibling
    while (sib) {
      if (sib instanceof HTMLElement) {
        const cs = getComputedStyle(sib)
        // display:none 的元素不占位
        if (cs.display !== 'none') {
          total += sib.offsetHeight + verticalMargin(sib)
        }
      }
      sib = sib.nextElementSibling
    }

    // 这一层的底部内边距（卡片 body 的 20px 就在这里）
    total += parseFloat(getComputedStyle(node).paddingBottom) || 0
    node = node.parentElement
  }

  return total
}

/**
 * 收集「表格下方」的所有元素，供 ResizeObserver 观测。
 *
 * 分页器在窄屏会换行变高，若不被观测到，表格就不会让位，分页器会被挤出可视区。
 */
function collectBelowElements(table: HTMLElement, container: HTMLElement): HTMLElement[] {
  const out: HTMLElement[] = []
  let node: HTMLElement | null = table
  while (node && node !== container) {
    let sib: Element | null = node.nextElementSibling
    while (sib) {
      if (sib instanceof HTMLElement) out.push(sib)
      sib = sib.nextElementSibling
    }
    node = node.parentElement
  }
  return out
}

/**
 * 收集「表格上方」的所有元素，供 ResizeObserver 观测（collectBelowElements 的对称版本）。
 *
 * 表格上方的兄弟变高会改变表格顶部位置，也就是 `topReserve`，但**表格自身尺寸不变时
 * ResizeObserver 不会回调**：它们的共同祖先 `.el-card__body` 是
 * `overflow-y: auto` 的定高容器，内容变多只会让它滚动，不会改变它自己的盒子尺寸。
 * 于是 `max-height` 不重算、分页器被挤出可视区。
 *
 * 典型：会员管理页的 `.el-alert`（「部分判定规则当前未生效」）—— 里面的
 * `ul.reason-degraded-list > li` 增删时 alert 长高，表格必须让位。
 * 同样覆盖筛选条换行：筛选条本身就是表格上方的兄弟。
 *
 * 与 `sumBelow` 一样只读「表格之外」的尺寸，不会形成正反馈。
 */
function collectAboveElements(table: HTMLElement, container: HTMLElement): HTMLElement[] {
  const out: HTMLElement[] = []
  let node: HTMLElement | null = table
  while (node && node !== container) {
    let sib: Element | null = node.previousElementSibling
    while (sib) {
      if (sib instanceof HTMLElement) out.push(sib)
      sib = sib.previousElementSibling
    }
    node = node.parentElement
  }
  return out
}

/**
 * 比较两组「被观测元素」是否一致。
 *
 * 用于判断结构变化后是否需要重挂观察者：集合没变就不必重建。
 * 按元素身份比较，不是按数量 —— 数量相同但节点被换掉（如 v-if 重建）
 * 也必须重挂，否则观测的是已经脱离文档的旧节点。
 */
function sameElements(a: HTMLElement[], b: HTMLElement[]): boolean {
  return a.length === b.length && a.every((el, i) => el === b[i])
}

/** 找到页面根容器（.list-page / .art-full-height） */
function findPageRoot(el: HTMLElement): HTMLElement | null {
  let node: HTMLElement | null = el
  while (node && node !== document.body) {
    if (PAGE_ROOT_CLASSES.some((c) => node!.classList.contains(c))) return node
    node = node.parentElement
  }
  return null
}

/**
 * 找到「约束高度的容器」。
 *
 * 优先级：
 * 1. 页面根容器 `.list-page` / `.art-full-height`（列表页）
 * 2. 弹窗内的 `.el-dialog`（弹窗里的表格要按视口高度收，不能按会跟着长高的 body 收）
 * 3. 都没有 → 返回 null，调用方退化成「不约束」
 */
function findContainer(
  table: HTMLElement
): { el: HTMLElement; viewportBased: boolean; contentPage: boolean } | null {
  const root = findPageRoot(table)
  if (root) {
    // 页面根容器有两种形态（见 app.scss「统一页面范式」），必须区别对待：
    //
    //  - `.list-page--fill`：`height: var(--art-full-height)` + `overflow-y: auto`，
    //    是**定高**容器，高度与内容无关，可以按「填满容器」算。
    //  - 普通 `.list-page`（内容页）：没有高度约束，高度随内容增长。
    //
    // 后者如果也按「填满容器」算就会形成正反馈：
    // 表格变高 → 把根容器顶高 → 下一轮量到更大的容器高度 → 表格再变高……
    // 实测同步页每秒长 60px，30 秒后仍在长（306px → 2058px），页面被越撑越长。
    // 所以内容页改用「视口」当分母（见 measure）。
    const contentPage = getComputedStyle(root).overflowY === 'visible'
    return { el: root, viewportBased: contentPage, contentPage }
  }

  const dialog = table.closest('.el-dialog')
  if (dialog instanceof HTMLElement) return { el: dialog, viewportBased: true, contentPage: false }

  return null
}

export interface UseTableHeightOptions {
  /**
   * 表格 ref（通常直接绑在 `<ElTable ref="...">` 上，所以值是组件实例）。
   *
   * 一个页面有多张表（如同步页两张卡）时必须各传各的，
   * 否则两张表会互相覆盖测量结果。
   */
  tableRef?: Ref<unknown>
  /** 关掉自适应，始终返回 undefined（用于「本来就该跟内容一样高」的表） */
  disabled?: Ref<boolean> | boolean
}

export interface UseTableHeightReturn {
  /** 直接绑到 ElTable 的 `:max-height` */
  tableMaxHeight: ComputedRef<string | undefined>
  /**
   * 绑到 ElTable 的 `ref`。
   *
   * 类型写成 `Ref<unknown>` 是因为 ElTable 的模板 ref 拿到的**是组件实例**，
   * 写成 `Ref<HTMLElement>` 会让页面侧出现类型不兼容的报错。
   */
  tableRef: Ref<unknown>
  /** 手动重算一次（如 tab 切换、数据加载完成后） */
  recalc: () => void
}

/**
 * 表格高度自适应。
 *
 * ```vue
 * <div class="list-page art-full-height">
 *   <ElCard class="art-table-card">…</ElCard>
 * </div>
 * ```
 * ```ts
 * const { tableMaxHeight, tableRef } = useTableHeight()
 * ```
 * ```html
 * <ElTable ref="tableRef" :max-height="tableMaxHeight" … />
 * ```
 */
export function useTableHeight(options: UseTableHeightOptions = {}): UseTableHeightReturn {
  const tableRef = options.tableRef ?? ref<unknown>()

  /** 容器可用高度（内容区，已扣 padding） */
  const containerHeight = ref(readLayoutHeightVar())
  /** 表格上方占位 */
  const topReserve = ref(0)
  /** 表格下方占位 */
  const bottomReserve = ref(0)
  /** 表格是否位于页面内层滚动区（见 measure 里的说明） */
  const innerScrollerBelowFold = ref(false)
  /** 表格是否位于「内容页」（无 --fill 的 .list-page，整页滚动） */
  const contentPageHeight = ref(false)

  let observer: ResizeObserver | null = null
  let mutationWatcher: MutationObserver | null = null
  let remountTimer: ReturnType<typeof setTimeout> | 0 = 0
  /** 当前已观测的「表格上下兄弟」集合，供 watchStructure 判断是否需要重挂 */
  let observedSignature: HTMLElement[] = []
  let rafId = 0

  function measure(): void {
    const table = resolveElement(tableRef.value)
    if (!table || !table.isConnected) return

    const found = findContainer(table)
    if (!found) {
      // 没有约束容器（如独立卡片里的装饰表）：不约束，交给调用方的 disabled
      containerHeight.value = 0
      topReserve.value = 0
      bottomReserve.value = 0
      innerScrollerBelowFold.value = false
      contentPageHeight.value = false
      return
    }

    const { el: container, viewportBased, contentPage } = found
    const containerRect = container.getBoundingClientRect()
    const containerStyle = getComputedStyle(container)
    const padTop = parseFloat(containerStyle.paddingTop) || 0
    const padBottom = parseFloat(containerStyle.paddingBottom) || 0

    const tableRect = table.getBoundingClientRect()

    if (contentPage) {
      // 内容页（`.list-page` 无 `--fill`）：容器高度随内容增长，不能当分母，
      // 否则形成正反馈（表格变高 → 容器变高 → 表格再变高）。
      // 实测同步页每秒长 60px、30 秒仍在长（306px → 2058px），页面被越撑越长。
      //
      // 也不能用「视口剩余高度」（vh - 上方占位）当上限：内容页的东西可以排在
      // 表格上面很多，实测同步页上方已有 776px 占位，算出来只剩 59px，
      // 表格会被压到 160px 下限 —— 比改造前的写死 520px 还小。
      //
      // 内容页本来就是整页滚动，表格不需要挤进当前视口。所以给一个
      // **与滚动位置无关**的视口比例上限（0.7 × 视口高），既不会无限增长，
      // 也不会因为用户滚动而抖动。这与改造前同步页写死 `max-height="520"`
      // 的意图一致，只是把定值换成了随视口自适应。
      const vh = window.innerHeight
      topReserve.value = 0
      containerHeight.value = round(vh)
      bottomReserve.value = 0
      innerScrollerBelowFold.value = false
      contentPageHeight.value = true
      return
    }

    contentPageHeight.value = false

    if (viewportBased) {
      // 弹窗：容器是弹窗本身，高度随内容变化，不能当分母。
      // 用「视口高度 - 表格顶部到视口顶部的距离」做可用高度，
      // 再减掉表格下方的兄弟元素。弹窗顶部位置由 EP 的 margin-top 决定，与表格高度无关，
      // 所以这个式子不会形成正反馈。
      const vh = window.innerHeight
      topReserve.value = round(tableRect.top)
      containerHeight.value = round(vh)
      bottomReserve.value = round(sumBelow(table, container) + padBottom)
      // 弹窗底部到视口底部的距离也要留出来（弹窗居中/上边距带来的下边空档）
      const dialogBottomGap = vh - (containerRect.bottom + padBottom)
      if (dialogBottomGap > 0) bottomReserve.value += round(dialogBottomGap)
      return
    }

    // 列表页：容器高度由 --art-full-height 固定，直接量
    const scrollTop = container.scrollTop || 0
    const rawTop = tableRect.top - containerRect.top - padTop + scrollTop
    topReserve.value = Math.max(0, round(rawTop))
    containerHeight.value = Math.max(0, round(containerRect.height - padTop - padBottom))
    bottomReserve.value = round(sumBelow(table, container))

    // 表格是否落在页面内层滚动区里（而不是直接挂在 .list-page 下）。
    // 典型：薪酬页「逐人明细」—— .list-page 下先是一层 `.el-card__body { overflow-y:auto }`
    // 包住所有面板，表格在这个内层滚动区的最底部。
    // 这类表格按「填满 .list-page」算出来是负数（它压根不在可视区内），
    // 需要用视口比例兜底，否则只能不约束，31 行就有 1170px 高。
    const innerScroller = findInnerScroller(table, container)
    innerScrollerBelowFold.value = innerScroller !== null
  }

  /**
   * 从表格向上找「页面内层滚动区」：overflow-y 为 auto/scroll 且不是页面根容器的祖先。
   *
   * 找到说明表格在一个独立滚动区里，它离 .list-page 的顶部距离没有意义。
   */
  function findInnerScroller(table: HTMLElement, container: HTMLElement): HTMLElement | null {
    let n: HTMLElement | null = table.parentElement
    while (n && n !== container) {
      const oy = getComputedStyle(n).overflowY
      if (oy === 'auto' || oy === 'scroll') return n
      n = n.parentElement
    }

    return null
  }

  function scheduleMeasure(): void {
    if (typeof window === 'undefined') return
    if (rafId) cancelAnimationFrame(rafId)
    rafId = requestAnimationFrame(() => {
      rafId = 0
      measure()
    })
  }

  const tableMaxHeight = computed<string | undefined>(() => {
    if (options.disabled) {
      const off = typeof options.disabled === 'boolean' ? options.disabled : options.disabled.value
      if (off) return undefined
    }
    // 手持设备：宽表格一律换成 MobileCard 卡片列表，ElTable 不渲染。
    // 这里返回 undefined 只是兜底 —— 万一某页还留着表，也不要给它一个算不准的高度。
    if (typeof window !== 'undefined' && window.innerWidth <= HANDHELD_MAX) return undefined

    // 内容页（整页滚动）：表格不与视口高度挂钩，给一个与滚动位置无关的
    // 视口比例上限，替代改造前的写死 max-height。
    if (contentPageHeight.value && typeof window !== 'undefined') {
      return `${Math.max(MIN_TABLE_HEIGHT, round(window.innerHeight * VIEWPORT_FALLBACK_RATIO))}px`
    }

    if (containerHeight.value <= 0) return undefined

    const available = containerHeight.value - topReserve.value - bottomReserve.value
    if (!Number.isFinite(available) || available <= 0) {
      // 表格在内层滚动区里（如薪酬页「逐人明细」）：按「填满容器」算必然是负数，
      // 因为它不在可视区内。用视口比例兜底 —— 仍然是自适应的，不是固定 px。
      if (innerScrollerBelowFold.value && typeof window !== 'undefined') {
        return `${Math.max(MIN_TABLE_HEIGHT, round(window.innerHeight * VIEWPORT_FALLBACK_RATIO))}px`
      }

      // 还没量到（首帧 / 容器高度为 0）：返回 undefined 让表格按内容高度渲染，
      // 下一帧量到后自动补上 max-height。比给一个错误的固定值安全。
      return undefined
    }
    return `${Math.max(MIN_TABLE_HEIGHT, round(available))}px`
  })

  /**
   * 把 ResizeObserver 重新挂到当前表格上。
   *
   * 必须在**每次表格元素变化时**重挂，不能只在 onMounted 挂一次：
   * - `v-if="!isHandheld"`：手机上表格不渲染，ref 是 undefined
   * - 页签切换 / 弹窗开关：表格被销毁重建，旧观察目标已经脱离文档
   *
   * 旧实现只在 onMounted 挂一次，切页签后表格高度就再也不更新了。
   */
  function bindObserver(): void {
    observer?.disconnect()
    observer = null
    mutationWatcher?.disconnect()
    mutationWatcher = null

    if (typeof ResizeObserver === 'undefined') return

    const table = resolveElement(tableRef.value)
    if (!table || !table.isConnected) return

    observer = new ResizeObserver(scheduleMeasure)
    observer.observe(table)

    // 观测父链：筛选条换行、提示行增删都会改变表格的位置，
    // 但表格自身尺寸不变时 ResizeObserver 不会回调，所以要连祖先一起观测。
    const observed: HTMLElement[] = []
    let p: HTMLElement | null = table.parentElement
    let depth = 0
    while (p && depth < 4) {
      observer.observe(p)
      observed.push(p)
      p = p.parentElement
      depth += 1
    }

    // 观测表格下方的元素（分页器）：它在窄屏会换行变高，
    // 不被观测到表格就不会让位，分页器会被挤出可视区。
    const container = findContainer(table)
    if (container) {
      const below = collectBelowElements(table, container.el)
      const above = collectAboveElements(table, container.el)
      below.forEach((el) => observer?.observe(el))
      above.forEach((el) => observer?.observe(el))

      // 记录当前观测到的兄弟集合，供 watchStructure 判断是否需要重挂
      observedSignature = [...above, ...below]

      // 上面两组只覆盖「挂观察者那一刻已存在」的节点，但列表页的数据是异步来的：
      // `.el-alert`（v-if="degradedNotices.length"）、筛选条里的标签、分页器
      // 都可能在挂完之后才被渲染出来，成为表格的新兄弟。
      // 不重新挂观察者的话，这些后出现的节点永远不会被观测到 —— 实测
      // 会员管理页的 alert 就是这样：li 从 1 加到 7，max-height 纹丝不动。
      // 所以这里监听卡片 body 的结构变化，出现新兄弟时重挂一次。
      watchStructure(table, container.el)
    } else {
      observedSignature = observed
    }
  }

  /**
   * 监听「容器内结构变化」，在表格上下出现新兄弟节点时重新挂观察者。
   *
   * 只重挂观察者（幂等），并做了防抖；节点被移除（如 alert 关闭）同样会触发，
   * 因为那也会改变表格顶部位置。
   *
   * 用「兄弟集合签名」做闸门：只有集合真的变了才重挂。列表页加载期间
   * 分页器/空态等会反复增删，不做闸门会白白重建几十次 ResizeObserver。
   */
  function watchStructure(table: HTMLElement, container: HTMLElement): void {
    if (typeof MutationObserver === 'undefined') return

    // 观测表格的父链（到容器为止），childList 变化即代表兄弟增删
    const targets: HTMLElement[] = []
    let n: HTMLElement | null = table.parentElement
    while (n && n !== container) {
      targets.push(n)
      n = n.parentElement
    }
    targets.push(container)

    mutationWatcher = new MutationObserver(() => {
      if (remountTimer) clearTimeout(remountTimer)
      remountTimer = setTimeout(() => {
        remountTimer = 0
        // 观测集合没变：只需重算（如节点换位置），不必重建观察者
        const table = resolveElement(tableRef.value)
        const container = table ? findContainer(table) : null
        if (table && container) {
          const now = [
            ...collectAboveElements(table, container.el),
            ...collectBelowElements(table, container.el)
          ]
          if (sameElements(now, observedSignature)) {
            scheduleMeasure()
            return
          }
        }
        bindObserver()
        scheduleMeasure()
      }, 0)
    })

    targets.forEach((t) => mutationWatcher?.observe(t, { childList: true }))
  }

  // ref 变化（表格出现 / 重建）后重新挂观察者并立刻量一次
  watch(
    tableRef,
    () => {
      nextTick(() => {
        bindObserver()
        scheduleMeasure()
      })
    },
    { flush: 'post' }
  )

  onMounted(() => {
    window.addEventListener('resize', scheduleMeasure, { passive: true })

    nextTick(() => {
      bindObserver()
      scheduleMeasure()
      // 数据是异步来的：分页器高度、卡片内边距都会在数据到位后变化，
      // 下一帧再量一次兜住「首帧还没有数据」的情况
      setTimeout(scheduleMeasure, 0)
    })
  })

  onBeforeUnmount(() => {
    observer?.disconnect()
    observer = null
    mutationWatcher?.disconnect()
    mutationWatcher = null
    if (remountTimer) clearTimeout(remountTimer)
    remountTimer = 0
    window.removeEventListener('resize', scheduleMeasure)
    if (rafId) cancelAnimationFrame(rafId)
    rafId = 0
  })

  return { tableMaxHeight, tableRef, recalc: scheduleMeasure }
}
