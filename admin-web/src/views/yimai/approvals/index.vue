<template>
  <div class="list-page list-page--fill">
    <ElCard>
      <div class="filter-bar">
        <ElSelect
          v-model="searchForm.status"
          placeholder="审批状态"
          clearable
          class="f-xl"
          @change="handleSearch"
        >
          <ElOption v-for="s in STATUSES" :key="s" :label="s" :value="s" />
        </ElSelect>
        <ElButton @click="handleReset">重置</ElButton>
        <div class="filter-bar__spacer" />
        <ElButton type="primary" plain @click="openCreate">发起价格审批</ElButton>
      </div>

      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <span class="text-sm text-gray-400">未通过审批不能标记成交（硬机制）</span>
        </template>
      </ArtTableHeader>

      <!-- 宽表格带右固定操作列，手机上固定列会吃掉整个可见宽度，窄屏整体换卡片列表 -->
      <template v-if="!isHandheld">
        <ArtTable
          :loading="loading"
          :data="data"
          :columns="columns"
          :pagination="pagination"
          @pagination:size-change="handleSizeChange"
          @pagination:current-change="handleCurrentChange"
        />
      </template>

      <div v-else v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="item in cardRows"
          :key="item.id"
          :title="item.title"
          :subtitle="item.subtitle"
          :tags="item.tags"
          :metrics="item.metrics"
          :note="item.note.text"
          :note-label="item.note.label"
          :actions="item.actions"
        >
          <div class="approval-card__extra">
            <div v-if="item.flowHint" class="approval-card__hint">{{ item.flowHint }}</div>
            <div class="approval-card__meta">提交时间：{{ item.applyTime }}</div>
          </div>
        </MobileCard>
        <div v-if="!loading && !cardRows.length" class="m-card-list__empty">暂无数据</div>
      </div>

      <!-- 卡片列表不自带分页，这里复用表格同一套分页状态，否则手机上只能看第一页 -->
      <div v-if="isHandheld" class="mt-4 flex justify-end">
        <ElPagination
          :current-page="pagination.current"
          :page-size="pagination.size"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="handleCurrentChange"
        />
      </div>
    </ElCard>

    <!-- 发起价格审批 -->
    <ElDialog v-model="createDlg" title="发起价格审批" width="480px" destroy-on-close>
      <ElForm label-width="96px">
        <ElFormItem label="客户姓名"
          ><ElInput v-model="applyForm.customerName" placeholder="客户姓名" maxlength="20"
        /></ElFormItem>
        <ElFormItem label="卡项名称"
          ><ElInput v-model="applyForm.cardName" placeholder="如：VIP私教50节" maxlength="40"
        /></ElFormItem>
        <ElFormItem label="标准 / 申请价">
          <div class="flex items-center gap-2">
            <ElInputNumber
              v-model="applyForm.standardPrice"
              :min="0"
              :step="100"
              controls-position="right"
              class="f-lg"
              placeholder="标准价"
            />
            <span class="text-gray-400">→</span>
            <ElInputNumber
              v-model="applyForm.requestPrice"
              :min="0"
              :step="100"
              controls-position="right"
              class="f-lg"
              placeholder="申请价"
            />
          </div>
        </ElFormItem>
        <ElFormItem label="申请原因"
          ><ElInput
            v-model="applyForm.reason"
            type="textarea"
            :rows="2"
            maxlength="200"
            placeholder="写清让价背景，利于审批"
        /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="createDlg = false">取消</ElButton>
        <ElButton type="primary" :loading="applySaving" @click="doCreate">提交审批</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import { queryApprovals, decideApproval, createApproval } from '@/api/yimai'
  import type { YimaiApproval } from '@/api/yimai'
  import { ElMessage, ElTag } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'

  defineOptions({ name: 'YimaiApprovals' })

  const userStore = useUserStore()
  const isSuper = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_SUPER'))

  // 手持设备上用卡片列表代替宽表格（见下方 cardTags / cardMetrics / cardActions）
  const { isHandheld } = useDevice()

  const STATUSES = ['待店长初审', '待老板终审', '已通过', '已驳回', '已关联成交'] as const

  const STATUS_TAG: Record<string, 'danger' | 'warning' | 'info' | 'success' | 'primary'> = {
    待店长初审: 'warning',
    待老板终审: 'danger',
    已通过: 'success',
    已驳回: 'info',
    已关联成交: 'primary'
  }

  const searchForm = ref({ status: '' })

  function fmtPrice(v: number) {
    return `¥${v.toLocaleString()}`
  }

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    getData,
    replaceSearchParams,
    resetSearchParams,
    refreshData,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: queryApprovals,
      apiParams: { current: 1, size: 20 },
      columnsFactory: () => [
        {
          prop: 'customerName',
          label: '客户 / 申请人',
          minWidth: 140,
          formatter: (row: YimaiApproval) =>
            h('div', [
              h('p', { class: 'font-500' }, row.customerName),
              h(
                'p',
                { class: 'text-xs text-gray-400' },
                `申请老师 ${row.applicant} · ${row.applyTime}`
              )
            ])
        },
        { prop: 'cardName', label: '卡项', minWidth: 160 },
        {
          prop: 'standardPrice',
          label: '标准价 → 申请价',
          width: 170,
          sortable: true,
          formatter: (row: YimaiApproval) => {
            const discount =
              row.standardPrice > 0 ? ((row.requestPrice / row.standardPrice) * 10).toFixed(1) : '—'
            return h('div', [
              h('p', [
                h(
                  'span',
                  { class: 'text-gray-400 line-through mr-2' },
                  fmtPrice(row.standardPrice)
                ),
                h('span', { class: 'font-500 text-red-500' }, fmtPrice(row.requestPrice))
              ]),
              h(
                'p',
                { class: 'text-xs text-gray-400' },
                discount === '—' ? '标准价未设置' : `${discount}折`
              )
            ])
          }
        },
        { prop: 'reason', label: '申请原因', minWidth: 200 },
        {
          prop: 'status',
          label: '状态',
          width: 110,
          formatter: (row: YimaiApproval) =>
            h(ElTag, { size: 'small', type: STATUS_TAG[row.status] }, () => row.status)
        },
        {
          prop: 'operation',
          label: '操作',
          width: 130,
          fixed: 'right',
          formatter: (row: YimaiApproval) => {
            if (row.status === '待店长初审') {
              return h('div', { class: 'flex gap-1' }, [
                h(ArtButtonTable, {
                  type: 'edit',
                  title: '初审通过',
                  onClick: () => act(row, true)
                }),
                h(ArtButtonTable, { type: 'delete', title: '驳回', onClick: () => act(row, false) })
              ])
            }
            if (row.status === '待老板终审') {
              if (!isSuper.value) {
                return h('span', { class: 'text-xs text-gray-400' }, '等待超管终审')
              }
              return h('div', { class: 'flex gap-1' }, [
                h(ArtButtonTable, {
                  type: 'edit',
                  title: '终审通过',
                  onClick: () => act(row, true, true)
                }),
                h(ArtButtonTable, { type: 'delete', title: '驳回', onClick: () => act(row, false) })
              ])
            }
            if (row.status === '已通过') {
              if (!isSuper.value)
                return h('span', { class: 'text-xs text-gray-400' }, '等待关联成交')
              return h(ArtButtonTable, {
                type: 'edit',
                title: '关联成交',
                onClick: () => linkDeal(row)
              })
            }
            return h('span', { class: 'text-xs text-gray-400' }, '—')
          }
        }
      ]
    }
  })

  async function act(row: YimaiApproval, pass: boolean, finalStage = false) {
    if (!pass) {
      try {
        await decideApproval(row.id, '驳回')
        refreshData()
        ElMessage.warning('已驳回，决定已写入留痕日志')
      } catch (e) {
        console.error('[approvals.act]', e)
        ElMessage.error('操作失败，请稍后重试')
      }
      return
    }
    if (finalStage && !isSuper.value) {
      ElMessage.info('该单已到终审环节，需超管终审')
      return
    }
    const stage = row.status === '待店长初审' ? '初审' : '终审'
    try {
      await decideApproval(row.id, stage === '初审' ? '初审通过' : '终审通过')
      refreshData()
      ElMessage.success(`${stage}通过，决定已写入留痕日志`)
    } catch (e) {
      console.error('[approvals.act]', e)
      ElMessage.error('操作失败，请稍后重试')
    }
  }

  async function linkDeal(row: YimaiApproval) {
    try {
      await decideApproval(row.id, '关联成交')
      refreshData()
      ElMessage.success(`${row.customerName} 已标记「已关联成交」`)
    } catch (e) {
      console.error('[approvals.linkDeal]', e)
      ElMessage.error('操作失败，请稍后重试')
    }
  }

  // ---------- 发起价格审批 ----------
  const createDlg = ref(false)
  const applySaving = ref(false)
  const applyForm = reactive({
    customerName: '',
    cardName: '',
    standardPrice: 3200,
    requestPrice: 3000,
    reason: ''
  })

  function openCreate() {
    Object.assign(applyForm, {
      customerName: '',
      cardName: '',
      standardPrice: 3200,
      requestPrice: 3000,
      reason: ''
    })
    createDlg.value = true
  }

  async function doCreate() {
    if (!applyForm.customerName.trim()) return ElMessage.warning('请填写客户姓名')
    if (!applyForm.cardName.trim()) return ElMessage.warning('请填写卡项名称')
    if (applyForm.requestPrice >= applyForm.standardPrice)
      return ElMessage.warning('申请价应低于标准价')
    applySaving.value = true
    try {
      await createApproval({ ...applyForm })
      ElMessage.success('审批单已提交，进入店长初审')
      createDlg.value = false
      await refreshData()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      applySaving.value = false
    }
  }

  function handleSearch() {
    replaceSearchParams({ ...searchForm.value })
    getData()
  }

  function handleReset() {
    searchForm.value = { status: '' }
    resetSearchParams()
    getData()
  }

  // ---------- 移动端卡片 ----------
  //
  // 表格 6 列（含右固定操作列），手机上固定列就占满可见宽度，窄屏一律换成卡片。
  // 审批动作与桌面端操作列同一套规则：能审的直接平铺成按钮（最多 2 个），不能审的把
  // 桌面端那行灰色提示（等待超管终审 / 等待关联成交）搬到卡片上，避免卡片上一个动作都没有。

  /** 折扣：与表格「标准价 → 申请价」列同口径，标准价未设置时不显示 */
  function discountText(row: YimaiApproval): string {
    return row.standardPrice > 0
      ? `${((row.requestPrice / row.standardPrice) * 10).toFixed(1)}折`
      : ''
  }

  /** 顶部标签：审批状态优先（决定该怎么处理），其次折扣 */
  function cardTags(row: YimaiApproval): MobileCardTag[] {
    const tags: MobileCardTag[] = [
      { text: row.status, type: STATUS_TAG[row.status], effect: 'dark' }
    ]
    const discount = discountText(row)
    if (discount) tags.push({ text: discount, effect: 'plain' })
    return tags
  }

  /** 关键指标：申请价是审批的核心判断依据，标准价放旁边作对比 */
  function cardMetrics(row: YimaiApproval): MobileCardMetric[] {
    return [
      { label: '标准价', value: fmtPrice(row.standardPrice) },
      { label: '申请价', value: fmtPrice(row.requestPrice), danger: true }
    ]
  }

  /** 补充说明：申请原因（让价背景是审批判断的关键依据） */
  function cardNote(row: YimaiApproval): { text: string; label: string } {
    return { label: '申请原因', text: row.reason || '—' }
  }

  /** 无审批动作时的说明，等价于桌面端操作列里的灰色提示文案 */
  function cardFlowHint(row: YimaiApproval): string {
    if (row.status === '待老板终审' && !isSuper.value) return '等待超管终审'
    if (row.status === '已通过' && !isSuper.value) return '等待关联成交'
    return ''
  }

  /**
   * 卡片操作：与桌面端操作列同规则
   *
   * 「初审通过 + 驳回」正好两个，直接可见；超管的「终审通过 + 驳回」同理，
   * 其余状态没有动作就返回空数组，卡片下方也不会出现空按钮区。
   */
  function cardActions(row: YimaiApproval): MobileCardAction[] {
    if (row.status === '待店长初审') {
      return [
        { text: '初审通过', type: 'primary', onClick: () => act(row, true) },
        { text: '驳回', type: 'danger', onClick: () => act(row, false) }
      ]
    }
    if (row.status === '待老板终审' && isSuper.value) {
      return [
        { text: '终审通过', type: 'primary', onClick: () => act(row, true, true) },
        { text: '驳回', type: 'danger', onClick: () => act(row, false) }
      ]
    }
    if (row.status === '已通过' && isSuper.value) {
      return [{ text: '关联成交', type: 'primary', onClick: () => linkDeal(row) }]
    }
    return []
  }

  /** 预计算一次，避免模板里对每行重复调用多个函数 */
  const cardRows = computed(() =>
    data.value.map((row) => ({
      id: row.id,
      title: row.customerName,
      subtitle: `${row.cardName} · 申请老师 ${row.applicant}`,
      tags: cardTags(row),
      metrics: cardMetrics(row),
      note: cardNote(row),
      flowHint: cardFlowHint(row),
      applyTime: row.applyTime,
      actions: cardActions(row)
    }))
  )
</script>

<style scoped lang="scss">
  // 移动端卡片里的补充信息（流转提示 / 提交时间）
  .approval-card {
    &__extra {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px dashed var(--art-card-border);
    }

    &__hint {
      font-size: 13px;
      line-height: 1.6;
      color: var(--art-gray-600);
    }

    &__meta {
      margin-top: 6px;
      font-size: 12px;
      color: var(--art-gray-500);
    }
  }
</style>
