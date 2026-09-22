<!-- 表格组件 -->
<!-- 支持：el-table 全部属性、事件、插槽，同官方文档写法 -->
<!-- 扩展功能：分页组件、渲染自定义列、loading、表格全局边框、斑马纹、表格尺寸、表头背景配置 -->
<!-- 获取 ref：默认暴露了 elTableRef 外部通过 ref.value.elTableRef 可以调用 el-table 方法 -->
<template>
  <div class="art-table" :class="{ 'is-empty': isEmpty }" :style="containerStyle">
    <ElTable ref="elTableRef" v-loading="!!loading" v-bind="mergedTableProps">
      <template v-for="col in columns" :key="col.prop || col.type">
        <!-- 渲染全局序号列 -->
        <ElTableColumn v-if="col.type === 'globalIndex'" v-bind="{ ...col }">
          <template #default="{ $index }">
            <span>{{ getGlobalIndex($index) }}</span>
          </template>
        </ElTableColumn>

        <!-- 渲染展开行 -->
        <ElTableColumn v-else-if="col.type === 'expand'" v-bind="cleanColumnProps(col)">
          <template #default="{ row }">
            <component :is="col.formatter ? col.formatter(row) : null" />
          </template>
        </ElTableColumn>

        <!-- 渲染普通列 -->
        <ElTableColumn v-else v-bind="cleanColumnProps(col)">
          <template v-if="col.useHeaderSlot && col.prop" #header="headerScope">
            <slot
              :name="col.headerSlotName || `${col.prop}-header`"
              v-bind="{ ...headerScope, prop: col.prop, label: col.label }"
            >
              {{ col.label }}
            </slot>
          </template>
          <template v-if="col.useSlot && col.prop" #default="slotScope">
            <slot
              v-if="shouldRenderSlotScope(slotScope)"
              :name="col.slotName || col.prop"
              v-bind="{
                ...slotScope,
                prop: col.prop,
                value: col.prop ? slotScope.row[col.prop] : undefined
              }"
            />
          </template>
        </ElTableColumn>
      </template>

      <template v-if="$slots.default" #default><slot /></template>

      <template #empty>
        <div v-if="loading"></div>
        <ElEmpty v-else :description="emptyText" :image-size="120" />
      </template>
    </ElTable>

    <div
      class="pagination custom-pagination"
      v-if="showPagination"
      :class="mergedPaginationOptions?.align"
      ref="paginationRef"
    >
      <ElPagination
        v-bind="mergedPaginationOptions"
        :total="pagination?.total"
        :disabled="loading"
        :page-size="pagination?.size"
        :current-page="pagination?.current"
        @size-change="handleSizeChange"
        @current-change="handleCurrentChange"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
  import { ref, computed, nextTick, getCurrentInstance, useAttrs } from 'vue'
  import type { ElTable, TableProps } from 'element-plus'
  import { storeToRefs } from 'pinia'
  import { ColumnOption } from '@/types'
  import { useTableStore } from '@/store/modules/table'
  import { useCommon } from '@/hooks/core/useCommon'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import { useDevice } from '@/hooks/core/useDevice'

  defineOptions({ name: 'ArtTable' })

  const elTableRef = ref<InstanceType<typeof ElTable> | null>(null)
  const paginationRef = ref<HTMLElement>()
  const tableStore = useTableStore()
  const { isBorder, isZebra, tableSize, isFullScreen, isHeaderBackground } = storeToRefs(tableStore)
  const { isHandheld, isCompact } = useDevice()

  /** 分页配置接口 */
  interface PaginationConfig {
    /** 当前页码 */
    current: number
    /** 每页显示条目个数 */
    size: number
    /** 总条目数 */
    total: number
  }

  /** 分页器配置选项接口 */
  interface PaginationOptions {
    /** 每页显示个数选择器的选项列表 */
    pageSizes?: number[]
    /** 分页器的对齐方式 */
    align?: 'left' | 'center' | 'right'
    /** 分页器的布局 */
    layout?: string
    /** 是否显示分页器背景 */
    background?: boolean
    /** 只有一页时是否隐藏分页器 */
    hideOnSinglePage?: boolean
    /** 分页器的大小 */
    size?: 'small' | 'default' | 'large'
    /** 分页器的页码数量 */
    pagerCount?: number
  }

  /** ArtTable 组件的 Props 接口 */
  interface ArtTableProps extends TableProps<Record<string, any>> {
    /** 加载状态 */
    loading?: boolean
    /** 列渲染配置 */
    columns?: ColumnOption[]
    /** 分页状态 */
    pagination?: PaginationConfig
    /** 分页配置 */
    paginationOptions?: PaginationOptions
    /** 空数据表格高度 */
    emptyHeight?: string
    /** 空数据时显示的文本 */
    emptyText?: string
    /** 是否开启 ArtTableHeader，解决表格高度自适应问题 */
    showTableHeader?: boolean
  }

  const props = withDefaults(defineProps<ArtTableProps>(), {
    columns: () => [],
    fit: true,
    showHeader: true,
    stripe: undefined,
    border: undefined,
    size: undefined,
    emptyHeight: '100%',
    emptyText: '暂无数据',
    showTableHeader: true
  })
  const instance = getCurrentInstance()
  const attrs = useAttrs()

  const LAYOUT = {
    MOBILE: 'prev, pager, next, sizes, jumper, total',
    IPAD: 'prev, pager, next, jumper, total',
    DESKTOP: 'total, prev, pager, next, sizes, jumper'
  }

  const layout = computed(() => {
    if (isHandheld.value) {
      return LAYOUT.MOBILE
    } else if (isCompact.value) {
      return LAYOUT.IPAD
    } else {
      return LAYOUT.DESKTOP
    }
  })

  // 默认分页常量
  const DEFAULT_PAGINATION_OPTIONS: PaginationOptions = {
    pageSizes: [10, 20, 30, 50, 100],
    align: 'center',
    background: true,
    layout: layout.value,
    hideOnSinglePage: false,
    size: 'default',
    pagerCount: isCompact.value ? 5 : 7
  }

  // 合并分页配置
  const mergedPaginationOptions = computed(() => ({
    ...DEFAULT_PAGINATION_OPTIONS,
    ...props.paginationOptions
  }))

  // 边框 (优先级：props > store)
  const border = computed(() => props.border ?? isBorder.value)
  // 斑马纹
  const stripe = computed(() => props.stripe ?? isZebra.value)
  // 表格尺寸
  const size = computed(() => props.size ?? tableSize.value)
  // 数据是否为空
  const isEmpty = computed(() => props.data?.length === 0)

  // 使用表格高度计算 Hook
  //
  // 高度来源收敛到 hooks/core/useTableHeight.ts 一处：
  // 它量「本表格到页面根容器（.list-page）顶部」的占位，再扣掉表格下方的兄弟元素，
  // 得到本表可用的 max-height。这里不再自己维护 showTableHeader / 分页器高度的
  // 加法 —— 那种写法要求调用方把每一块占位都算准，漏一块表格就会盖住分页器。
  const { tableMaxHeight } = useTableHeight({
    // 直接复用 elTableRef：模板上只能挂一个 ref，而 hook 的 resolveElement
    // 已经会从组件实例取 $el，所以不需要再合成一个 setter。
    tableRef: elTableRef,
    // 全屏模式下由 .el-full-screen 容器接管高度，不再叠加 max-height
    disabled: computed(() => isFullScreen.value)
  })

  /**
   * 表格高度。
   *
   * 改造前这里默认返回 `'100%'`（占满 .art-table 容器），于是表格把卡片的全部高度吃掉，
   * 卡片里跟在表格后面的分页器被挤到卡片可视区之外 —— 实测客户经营池分页器 bottom=969、
   * 卡片可视底 884，必须再滚一屏才能翻页。
   *
   * 现在默认交给 `max-height`（由 useTableHeight 量出可用高度）：
   * 数据少时表格就是内容高度（不出现大片空白），数据多时到可用高度为止并内部滚动，
   * 分页器始终留在可视区内。
   *
   * 只有三种情况仍然显式给 `height`：
   * 1. 全屏：由 .el-full-screen 接管
   * 2. 空数据：需要一块固定高度的区域把空态居中（否则空表塌成 0 高）
   * 3. 调用方显式传了 height
   */
  const height = computed(() => {
    if (isFullScreen.value) return '100%'
    if (props.height) return props.height
    if (isEmpty.value && !props.loading) return props.emptyHeight
    return undefined
  })

  /**
   * 外层 .art-table 的高度。
   *
   * ⚠️ 不能写 `height: 100%`。`.art-table` 是 `.el-card__body` 的直接子级，
   * 而 card body 有 20px 上下内边距 —— `height: 100%` 拿到的是 **content box 高度**
   * （791px），再叠上 `margin-top: 10px` 就是 801px，比可视区还高，
   * 于是分页器被顶出卡片可视区（实测 customers 分页器 bottom=894 > 可视底 868，
   * 必须再滚一屏才能翻页）。
   *
   * 改成 `height: auto`：外层跟着内容走（表格 max-height + 分页器），
   * 表格该多高由 useTableHeight 的 max-height 决定，两者不互相打架。
   * 全屏模式下才需要撑满，那时由 .el-full-screen 容器给高度。
   */
  const containerStyle = computed(() =>
    isFullScreen.value ? { height: '100%' } : { height: 'auto' }
  )

  // 表头背景颜色样式
  const headerCellStyle = computed(() => ({
    background: isHeaderBackground.value
      ? 'var(--el-fill-color-lighter)'
      : 'var(--default-box-color)',
    ...(props.headerCellStyle || {}) // 合并用户传入的样式
  }))

  // 只有显式传入时才覆盖 ElTable 的原生默认值，避免继承的 Boolean props 把官方默认值冲掉。
  const hasExplicitTableProp = (propName: string): boolean => {
    const rawProps = (instance?.vnode.props || {}) as Record<string, unknown>
    const kebabName = propName.replace(/[A-Z]/g, (match) => `-${match.toLowerCase()}`)
    return propName in rawProps || kebabName in rawProps
  }

  const mergedTableProps = computed(() => ({
    ...attrs,
    ...props,
    height: height.value,
    // 自适应高度：由 useTableHeight 量出，页面无需传 max-height。
    // 显式传入 max-height 时以调用方为准（弹窗内的短表要按自己的规则收）。
    maxHeight: props.maxHeight ?? tableMaxHeight.value,
    stripe: stripe.value,
    border: border.value,
    size: size.value,
    headerCellStyle: headerCellStyle.value,
    // Element Plus 默认值为 true，未显式传入时不应被 ArtTable 覆盖成 false。
    selectOnIndeterminate: hasExplicitTableProp('selectOnIndeterminate')
      ? props.selectOnIndeterminate
      : undefined
  }))

  // 是否显示分页器
  const showPagination = computed(() => props.pagination && !isEmpty.value)

  // Element Plus 在部分场景会先用 $index = -1 进行预渲染。
  // 这对普通展示无影响，但会让 ElForm 错误注册出 lineList.-1.xxx 这类字段。
  const shouldRenderSlotScope = (slotScope: { $index?: number }) => {
    return slotScope.$index === undefined || slotScope.$index >= 0
  }

  // 清理列属性，移除插槽相关的自定义属性，确保它们不会被 ElTableColumn 错误解释
  const cleanColumnProps = (col: ColumnOption) => {
    const columnProps = { ...col }
    // 删除自定义的插槽控制属性
    delete columnProps.useHeaderSlot
    delete columnProps.headerSlotName
    delete columnProps.useSlot
    delete columnProps.slotName
    return columnProps
  }

  // 分页大小变化
  const handleSizeChange = (val: number) => {
    emit('pagination:size-change', val)
  }

  // 分页当前页变化
  const handleCurrentChange = (val: number) => {
    emit('pagination:current-change', val)
    scrollToTop() // 页码改变后滚动到表格顶部
  }

  const { scrollToTop: scrollPageToTop } = useCommon()

  // 滚动表格内容到顶部，并可以联动页面滚动到顶部
  const scrollToTop = () => {
    nextTick(() => {
      elTableRef.value?.setScrollTop(0) // 滚动 ElTable 内部滚动条到顶部
      scrollPageToTop() // 调用公共 composable 滚动页面到顶部
    })
  }

  // 全局序号
  const getGlobalIndex = (index: number) => {
    if (!props.pagination) return index + 1
    const { current, size } = props.pagination
    return (current - 1) * size + index + 1
  }

  const emit = defineEmits<{
    (e: 'pagination:size-change', val: number): void
    (e: 'pagination:current-change', val: number): void
  }>()

  // 表格高度由 useTableHeight 通过 ResizeObserver 自行观测（含表格父链），
  // 这里不再手工查找 #art-table-header / 分页器元素 —— 那种写法只能覆盖
  // 「表头 + 分页器」两块占位，页面里多一行提示就会漏算。

  defineExpose({
    scrollToTop,
    elTableRef
  })
</script>

<style lang="scss" scoped>
  @use './style';

  // ---------------------------------------------------------------------------
  // 覆盖 ./style 里的高度规则（t8 表格高度统一）
  //
  // 原 `./style` 写的是 `.art-table { height: 100% }` + `.el-table { height: 100% }`。
  // 这套规则有个必现缺陷：`.art-table` 是 `.el-card__body` 的直接子级，而 card body
  // 有 20px 上下内边距 —— `height: 100%` 拿到的是 **content box 高度**（791px），
  // 再叠上 `margin-top: 10px` 就是 801px，比可视区还高，于是分页器被顶出卡片可视区
  // （实测客户经营池：分页器 bottom=969 > 卡片可视底 884，必须再滚一屏才能翻页）。
  //
  // 覆盖放在这里（而不是改 ./style）的原因：`@use` 的规则与下面同权重，
  // 后写的胜出，所以这里能覆盖掉；同时把「表格高度归属」收敛到组件自身，
  // 与 `containerStyle` / `useTableHeight` 一起读得明白。
  // ---------------------------------------------------------------------------
  .art-table {
    // 外层高度由 containerStyle 决定（默认 auto，全屏才 100%）
    height: auto;
    display: flex;
    flex-direction: column;
    min-height: 0;

    :deep(.el-table) {
      // 表格不 flex-grow：它的高度由 max-height（useTableHeight 量出）决定。
      // 若让它 flex: 1，表格会把分页器挤出卡片可视区。
      flex: 0 0 auto;
      min-height: 0;
    }
  }
</style>
