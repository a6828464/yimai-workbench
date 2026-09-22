<template>
  <div class="list-page list-page--fill">
    <ElCard>
      <div class="filter-bar">
        <ElSelect
          v-model="searchForm.status"
          placeholder="任务状态"
          clearable
          class="f-lg"
          @change="handleSearch"
        >
          <ElOption v-for="s in STATUSES" :key="s" :label="s" :value="s" />
        </ElSelect>
        <ElSelect
          v-if="!isManager"
          v-model="searchForm.venue"
          placeholder="门店"
          clearable
          class="f-md"
          @change="handleSearch"
        >
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElButton @click="handleReset">重置</ElButton>
      </div>

      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <ElButton type="primary" v-ripple @click="openCreate">新建任务</ElButton>
        </template>
      </ArtTableHeader>

      <!-- 宽表格带左固定列 + 右固定操作列，手机上固定列会吃掉整个可见宽度，窄屏整体换卡片列表 -->
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
        />
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

    <!-- 新建任务 -->
    <ElDialog v-model="createDlg" title="新建任务" width="520px" destroy-on-close>
      <ElForm label-width="92px">
        <ElFormItem label="任务名称" required>
          <ElInput
            v-model="taskForm.title"
            placeholder="如：新客首次响应 / 预约确认"
            maxlength="50"
          />
        </ElFormItem>
        <ElFormItem label="客户姓名" required>
          <ElInput v-model="taskForm.customerName" placeholder="会员/留资姓名" maxlength="20" />
        </ElFormItem>
        <ElFormItem label="门店" required>
          <ElRadioGroup v-model="taskForm.venue">
            <ElRadio value="绿地店">绿地店</ElRadio>
            <ElRadio value="东部店">东部店</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem label="负责人">
          <ElInput
            v-model="taskForm.owner"
            placeholder="留空则进入待接收，由店长认领"
            maxlength="20"
          />
        </ElFormItem>
        <ElFormItem label="优先级">
          <ElSelect v-model="taskForm.priority" class="f-sm">
            <ElOption label="高" value="高" />
            <ElOption label="中" value="中" />
            <ElOption label="低" value="低" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="截止时间">
          <ElInput v-model="taskForm.deadline" placeholder="如：2026-09-01 18:00" maxlength="24" />
        </ElFormItem>
        <ElFormItem label="验收标准">
          <ElInput
            v-model="taskForm.standard"
            type="textarea"
            :rows="2"
            maxlength="200"
            placeholder="定义完成口径，作为验收依据"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="createDlg = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="doCreate">创建任务</ElButton>
      </template>
    </ElDialog>

    <!-- 任务详情 / 流转 -->
    <ElDialog
      v-model="detailDlg.visible"
      :title="`任务详情 · ${detailDlg.row?.title ?? ''}`"
      width="520px"
      destroy-on-close
    >
      <template v-if="detailDlg.row">
        <ElDescriptions :column="2" border size="small" class="mb-3">
          <ElDescriptionsItem label="客户"
            >{{ detailDlg.row.customerName }} · {{ detailDlg.row.venue }}</ElDescriptionsItem
          >
          <ElDescriptionsItem label="状态">
            <ElTag size="small" :type="STATUS_TAG[detailDlg.row.status]">{{
              detailDlg.row.status
            }}</ElTag>
          </ElDescriptionsItem>
          <ElDescriptionsItem label="负责人">{{ detailDlg.row.owner }}</ElDescriptionsItem>
          <ElDescriptionsItem label="优先级">{{ detailDlg.row.priority }}</ElDescriptionsItem>
          <ElDescriptionsItem label="截止时间">{{
            detailDlg.row.deadline || '—'
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="验收标准">{{
            detailDlg.row.standard || '—'
          }}</ElDescriptionsItem>
        </ElDescriptions>
        <ElAlert type="info" :closable="false" :title="flowHint" class="mb-3" />
        <div class="flex flex-wrap gap-2">
          <ElButton v-if="canStart" type="primary" size="small" @click="startTask"
            >认领并开始执行</ElButton
          >
          <ElButton
            type="primary"
            plain
            size="small"
            :disabled="!canAccept"
            @click="transit('待验收')"
            >提报完成（待验收）</ElButton
          >
          <ElButton
            v-if="isManager || isSuper"
            type="success"
            size="small"
            :disabled="!canVerify"
            @click="transit('已完成')"
            >验收通过</ElButton
          >
          <ElButton
            v-if="isManager || isSuper"
            type="danger"
            size="small"
            plain
            :disabled="!canVerify"
            @click="transit('已退回')"
            >验收退回</ElButton
          >
          <ElButton
            v-if="(isManager || isSuper) && detailDlg.row?.status === '待接收'"
            type="warning"
            size="small"
            plain
            @click="assignOwner"
            >分配负责人</ElButton
          >
        </div>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import { queryTasks, createTask, updateTask } from '@/api/yimai'
  import type { YimaiTask } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'
  import { ElMessage, ElMessageBox, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiTasks' })

  // 手持设备上用卡片列表代替宽表格（见下方 cardTags / cardMetrics / cardNote）
  const { isHandheld } = useDevice()

  const STATUSES = ['待接收', '进行中', '待验收', '已完成', '已退回', '已逾期'] as const

  const STATUS_TAG: Record<string, 'danger' | 'warning' | 'info' | 'success' | 'primary'> = {
    待接收: 'info',
    进行中: 'primary',
    待验收: 'warning',
    已完成: 'success',
    已退回: 'danger',
    已逾期: 'danger'
  }

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  const isSuper = computed(() => roles.value.includes('R_SUPER'))

  const searchForm = ref({
    status: '',
    venue: ''
  })

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
      apiFn: queryTasks,
      apiParams: { current: 1, size: 20 },
      columnsFactory: () => [
        {
          prop: 'title',
          label: '任务 / 客户',
          minWidth: 160,
          formatter: (row: YimaiTask) =>
            h('div', [
              h('p', { class: 'font-500' }, row.title),
              h('p', { class: 'text-xs text-gray-400' }, `${row.customerName} · ${row.venue}`)
            ])
        },
        {
          prop: 'owner',
          label: '负责人',
          width: 90,
          formatter: (row: YimaiTask) =>
            row.owner === '未分配'
              ? h('span', { class: 'font-500 text-red-500' }, '未分配')
              : h('span', {}, row.owner)
        },
        {
          prop: 'priority',
          label: '优先级',
          width: 80,
          formatter: (row: YimaiTask) =>
            h(
              ElTag,
              {
                size: 'small',
                type: row.priority === '高' ? 'danger' : row.priority === '中' ? 'warning' : 'info'
              },
              () => row.priority
            )
        },
        {
          prop: 'standard',
          label: '验收标准',
          minWidth: 220
        },
        {
          prop: 'deadline',
          label: '截止时间',
          width: 140,
          sortable: true
        },
        {
          prop: 'status',
          label: '状态',
          width: 90,
          formatter: (row: YimaiTask) =>
            h(ElTag, { size: 'small', type: STATUS_TAG[row.status] }, () => row.status)
        },
        {
          prop: 'operation',
          label: '操作',
          width: 90,
          fixed: 'right',
          formatter: (row: YimaiTask) =>
            h(ArtButtonTable, { type: 'more', onClick: () => openDetail(row) })
        }
      ]
    }
  })

  function handleSearch() {
    replaceSearchParams({ ...searchForm.value })
    getData()
  }

  function handleReset() {
    searchForm.value = { status: '', venue: '' }
    resetSearchParams()
    getData()
  }

  // ---------- 新建任务 ----------
  const createDlg = ref(false)
  const saving = ref(false)
  const taskForm = reactive({
    title: '',
    customerName: '',
    venue: '绿地店' as '绿地店' | '东部店',
    owner: '',
    priority: '中',
    deadline: '',
    standard: ''
  })

  function openCreate() {
    Object.assign(taskForm, {
      title: '',
      customerName: '',
      venue: '绿地店',
      owner: '',
      priority: '中',
      deadline: '',
      standard: ''
    })
    createDlg.value = true
  }

  async function doCreate() {
    if (!taskForm.title.trim()) return ElMessage.warning('请填写任务名称')
    if (!taskForm.customerName.trim()) return ElMessage.warning('请填写客户姓名')
    saving.value = true
    try {
      await createTask({
        title: taskForm.title,
        customerName: taskForm.customerName,
        venue: taskForm.venue,
        owner: taskForm.owner,
        priority: taskForm.priority as '高' | '中' | '低',
        deadline: taskForm.deadline,
        standard: taskForm.standard
      })
      ElMessage.success('任务已创建')
      createDlg.value = false
      await getData()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      saving.value = false
    }
  }

  // ---------- 任务详情 / 流转 ----------
  const detailDlg = reactive<{ visible: boolean; row: YimaiTask | null }>({
    visible: false,
    row: null
  })

  function openDetail(row: YimaiTask) {
    detailDlg.row = row
    detailDlg.visible = true
  }

  const canAccept = computed(() => {
    const r = detailDlg.row
    if (!r || r.status !== '进行中') return false
    return isManager.value || isSuper.value || r.owner === userStore.getUserInfo.staffName
  })
  const canStart = computed(() => {
    const r = detailDlg.row
    if (!r || r.status !== '待接收') return false
    const me = userStore.getUserInfo.staffName
    return r.owner === me || r.owner === '未分配' || isManager.value || isSuper.value
  })
  const canVerify = computed(() => {
    const r = detailDlg.row
    return !!r && r.status === '待验收' && (isManager.value || isSuper.value)
  })
  const flowHint = computed(() => {
    const r = detailDlg.row
    if (!r) return ''
    if (r.status === '待接收')
      return '任务待认领：负责人可「认领并开始执行」，店长可「分配负责人」。'
    if (r.status === '进行中') return '执行中：完成后由负责人「提报完成」，进入店长验收。'
    if (r.status === '待验收')
      return `待验收：由店长验收。通过后任务闭环，退回则说明未达标。负责人：${r.owner}`
    if (r.status === '已完成') return '任务已闭环，谢谢！'
    if (r.status === '已退回') return '已退回：请按验收标准补充后再提报。'
    return '任务已逾期，请尽快处理。'
  })

  async function transit(status: string) {
    if (!detailDlg.row) return
    const label = status === '待验收' ? '提报完成' : status === '已完成' ? '验收通过' : '验收退回'
    try {
      await updateTask(detailDlg.row.id, { status: status as YimaiTask['status'] }, label)
      ElMessage.success(`${label}成功`)
      detailDlg.visible = false
      await getData()
    } catch (e) {
      console.error('[tasks.transit]', e)
      ElMessage.error('操作失败，请稍后重试')
    }
  }

  async function startTask() {
    const r = detailDlg.row
    if (!r) return
    const me = userStore.getUserInfo.staffName
    try {
      await updateTask(
        r.id,
        { status: '进行中', owner: r.owner === '未分配' ? me : r.owner },
        '认领并开始'
      )
      ElMessage.success('已开始执行')
      detailDlg.visible = false
      await getData()
    } catch (e) {
      console.error('[tasks.startTask]', e)
      ElMessage.error('操作失败，请稍后重试')
    }
  }

  async function assignOwner() {
    const r = detailDlg.row
    if (!r) return
    const { value } = await ElMessageBox.prompt('请输入负责人姓名', '分配负责人', {
      inputValue: r.owner === '未分配' ? '' : r.owner,
      inputPlaceholder: '负责人姓名'
    }).catch(() => ({ value: null }))
    if (!value) return
    try {
      await updateTask(r.id, { owner: value }, '分配负责人')
      ElMessage.success(`已分配给 ${value}`)
      detailDlg.visible = false
      await getData()
    } catch (e) {
      console.error('[tasks.assignOwner]', e)
      ElMessage.error('分配失败，请稍后重试')
    }
  }

  // ---------- 移动端卡片 ----------
  //
  // 表格 7 列（含左右固定列），手机上固定列就占满可见宽度，窄屏一律换成卡片。
  // 卡片保留「这个任务归谁、急不急、卡在哪一步」三类信息，流转按钮仍走任务详情弹窗
  // （弹窗已由全局 ≤640 规则限宽，不用改）。

  /** 顶部标签：状态 + 优先级 + 负责人（未分配标红，店长需要立刻认领） */
  function cardTags(row: YimaiTask): MobileCardTag[] {
    const priorityType =
      row.priority === '高' ? 'danger' : row.priority === '中' ? 'warning' : 'info'
    return [
      { text: row.status, type: STATUS_TAG[row.status], effect: 'dark' },
      { text: `优先级 ${row.priority}`, type: priorityType, effect: 'plain' },
      row.owner === '未分配'
        ? { text: '未分配', type: 'danger', effect: 'plain' }
        : { text: row.owner, effect: 'plain' }
    ]
  }

  /** 关键指标：截止时间——任务卡片上最先要看的就是还剩多少时间 */
  function cardMetrics(row: YimaiTask): MobileCardMetric[] {
    return [{ label: '截止时间', value: row.deadline || '—' }]
  }

  /** 补充说明：验收标准（完成口径），任务能否被验收全靠它 */
  function cardNote(row: YimaiTask): { text: string; label: string } {
    return { label: '验收标准', text: row.standard || '—' }
  }

  /** 卡片操作：认领/提报/验收等流转都在详情弹窗里，与桌面端操作列一致 */
  function cardActions(row: YimaiTask): MobileCardAction[] {
    return [{ text: '任务详情', type: 'primary', onClick: () => openDetail(row) }]
  }

  /** 预计算一次，避免模板里对每行重复调用四个函数 */
  const cardRows = computed(() =>
    data.value.map((row) => ({
      id: row.id,
      title: row.title,
      subtitle: `${row.customerName} · ${row.venue}`,
      tags: cardTags(row),
      metrics: cardMetrics(row),
      note: cardNote(row),
      actions: cardActions(row)
    }))
  )
</script>
