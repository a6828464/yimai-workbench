<template>
  <div class="leads-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElInput v-model="filters.name" placeholder="客户姓名" clearable class="!w-40" @change="load" />
        <ElSelect v-if="showVenueFilter" v-model="filters.venue" placeholder="门店" clearable class="!w-32" @change="load">
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElSelect v-model="filters.status" placeholder="状态" clearable class="!w-32" @change="load">
          <ElOption v-for="s in STATUS_LIST" :key="s" :label="s" :value="s" />
        </ElSelect>
        <ElButton @click="load">查询</ElButton>
        <ElButton @click="resetFilters">重置</ElButton>
        <div class="flex-1" />
        <ElButton type="primary" v-ripple @click="openCreate">新增留资</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left>
          <span class="text-sm text-gray-400">
            {{ scopeHint }} · 三次跟进时限自动计算（7/15/30天），超期红色提醒 · 来源口径：大众点评A/美团B/抖音C/视频号D/自然到店E
          </span>
        </template>
      </ArtTableHeader>

      <ElTable v-loading="loading" :data="pagedList" border stripe>
        <ElTableColumn prop="leadDate" label="留资日期" width="100" sortable />
        <ElTableColumn label="姓名 / 电话" min-width="130">
          <template #default="{ row }">
            <div class="font-500">{{ row.name }}</div>
            <div class="text-xs text-gray-400">{{ row.phone || (row.phoneTail ? '尾号' + row.phoneTail : '—') }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="demand" label="需求/痛点" min-width="110" show-overflow-tooltip />
        <ElTableColumn prop="source" label="来源" width="120" />
        <ElTableColumn prop="venue" label="门店" width="85">
          <template #default="{ row }">
            <ElTag size="small" effect="plain">{{ row.venue }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="会籍顾问" width="95">
          <template #default="{ row }">
            <span v-if="row.serviceTeacher">{{ row.serviceTeacher }}</span>
            <ElTag v-else size="small" type="danger">待分配</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="上课老师" width="95">
          <template #default="{ row }">
            <span v-if="row.trialTeacher">{{ row.trialTeacher }}</span>
            <span v-else class="text-gray-300">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="核销金额" width="90" align="right">
          <template #default="{ row }">
            <span v-if="row.redeemAmount !== null && row.redeemAmount !== undefined" class="font-500 text-orange-600">¥{{ row.redeemAmount }}</span>
            <span v-else class="text-gray-300">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="成交金额" width="100" align="right">
          <template #default="{ row }">
            <span v-if="row.dealAmount !== null && row.dealAmount !== undefined" class="font-600 text-green-700">¥{{ row.dealAmount.toLocaleString() }}</span>
            <span v-else class="text-gray-300">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="status" label="状态" width="90">
          <template #default="{ row }">
            <ElTag size="small" :type="statusType(row.status)">{{ row.status }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="跟进时限" width="170">
          <template #default="{ row }">
            <div class="followup-cell">
              <span :class="deadlineClass(row, 7)">首跟{{ plusDays(row.leadDate, 7) }}</span>
              <span :class="deadlineClass(row, 15)">二跟{{ plusDays(row.leadDate, 15) }}</span>
              <span :class="deadlineClass(row, 30)">三跟{{ plusDays(row.leadDate, 30) }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="createdBy" label="录入人" width="80" />
        <ElTableColumn label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" :disabled="!canManageLead(row)" @click="openEdit(row)">
              编辑
            </ElButton>
            <ElButton link type="info" size="small" @click="openHistory(row)">变更记录</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="mt-4 flex justify-end">
        <ElPagination
          v-model:current-page="page.current"
          :page-size="page.size"
          :total="filteredList.length"
          layout="total, prev, pager, next"
        />
      </div>
    </ElCard>

    <!-- 新增/编辑弹窗 -->
    <ElDialog v-model="dialog.visible" :title="dialog.isCreate ? '新增留资' : `编辑留资 #${dialog.form.id}`" width="620px" destroy-on-close>
      <ElForm :model="dialog.form" label-width="92px">
        <ElRow :gutter="12">
          <ElCol :span="12">
            <ElFormItem label="留资日期" required>
              <ElDatePicker v-model="dialog.form.leadDate" type="date" value-format="YYYY-MM-DD" class="!w-full" />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="姓名" required>
              <ElInput v-model="dialog.form.name" placeholder="客户姓名" />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="电话尾号">
              <ElInput v-model="dialog.form.phone" maxlength="11" placeholder="11位完整手机号" />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="来源" required>
              <ElSelect v-model="dialog.form.source" placeholder="选择来源渠道" class="!w-full">
                <ElOption v-for="s in SOURCE_OPTIONS" :key="s" :label="s" :value="s" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="目标门店" required>
              <ElSelect v-model="dialog.form.venue" :disabled="dialogLockedVenue" class="!w-full">
                <ElOption label="绿地店" value="绿地店" />
                <ElOption label="东部店" value="东部店" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
        </ElRow>
        <ElFormItem label="需求/痛点">
          <ElInput v-model="dialog.form.demand" placeholder="体态调整/产后修复/体式提升" />
        </ElFormItem>
        <ElRow :gutter="12">
          <ElCol :span="8">
            <ElFormItem label="状态">
              <ElSelect v-model="dialog.form.status" class="!w-full">
                <ElOption v-for="s in STATUS_LIST" :key="s" :label="s" :value="s" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol :span="8">
            <ElFormItem label="客户分级">
              <ElSelect v-model="dialog.form.grade" clearable class="!w-full" placeholder="A/B/C">
                <ElOption label="A（高价值）" value="A" />
                <ElOption label="B（次卡低频）" value="B" />
                <ElOption label="C（日常活跃）" value="C" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol v-if="canAssign" :span="8">
            <ElFormItem label="会籍顾问">
              <ElInput v-model="dialog.form.serviceTeacher" placeholder="店长/老板分配" />
            </ElFormItem>
          </ElCol>
        </ElRow>
        <ElRow :gutter="12">
          <ElCol :span="12">
            <ElFormItem label="体验课时间">
              <ElDatePicker v-model="dialog.form.trialTime" type="datetime" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" class="!w-full" />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="体验课主题">
              <ElInput v-model="dialog.form.trialTopic" placeholder="如：内观流/核心床小班" />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="上课老师"><ElInput v-model="dialog.form.trialTeacher" placeholder="体验课/上课老师" /></ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="平台券码">
              <ElInput v-model="dialog.form.voucherCode" placeholder="团购券码" />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="核销金额"><ElInputNumber v-model="dialog.form.redeemAmount" :min="0" controls-position="right" class="!w-full" placeholder="团单核销" /></ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="成交卡项"><ElInput v-model="dialog.form.dealCard" placeholder="成交时填写" /></ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="成交金额"><ElInputNumber v-model="dialog.form.dealAmount" :min="0" controls-position="right" class="!w-full" placeholder="成交时填写" /></ElFormItem>
          </ElCol>
        </ElRow>
        <ElFormItem label="备注">
          <ElInput v-model="dialog.form.remark" type="textarea" :rows="2" placeholder="沟通记录、客户关注点等" />
        </ElFormItem>
        <ElAlert v-if="!dialog.isCreate" title="每次保存都会记录变更日志，对方角色在「变更记录」中可见" type="info" :closable="false" />
      </ElForm>
      <template #footer>
        <ElButton @click="dialog.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="dialog.saving" @click="save">保存</ElButton>
      </template>
    </ElDialog>

    <!-- 变更记录抽屉 -->
    <ElDrawer v-model="history.visible" title="变更记录（双方可见）" size="420px">
      <div v-if="history.list.length">
        <ElTimeline>
          <ElTimelineItem v-for="h in history.list" :key="h.id" :timestamp="`${h.time} · ${h.operatorName}（${h.operatorRole}）`" placement="top">
            <div class="text-sm font-500 mb-1">{{ h.action }}</div>
            <div class="text-xs text-gray-500 leading-5">{{ h.detail }}</div>
          </ElTimelineItem>
        </ElTimeline>
      </div>
      <ElEmpty v-else description="暂无变更记录" />
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { addLead, canAssignTeacher, canManageLead, getLeadHistory, queryLeads, updateLead } from '@/api/yimai'
  import type { YimaiLead } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiLeads' })

  const STATUS_LIST = ['新留资', '已联系', '已约体验', '已体验', '已成交', '已流失'] as const

  const SOURCE_OPTIONS = ['大众点评', '美团', '抖音', '抖音直播', '抖音私信', '视频号', '小红书', '电话咨询', '转介绍', '会员转介绍', '自然到店', '潜客激活']

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  const isTeacher = computed(() => roles.value.includes('R_TEACHER'))
  const showVenueFilter = computed(() => !isManager.value)
  const scopeHint = computed(() => {
    if (isManager.value) return `数据范围：本店（${userStore.getUserInfo.venue}）`
    if (isTeacher.value) return `我的客资 + 本店待分配池（${userStore.getUserInfo.venue ?? '未选门店'}）`
    return '数据范围：双店'
  })
  const canAssign = computed(() => !roles.value.includes('R_MEDIA') && !isTeacher.value)

  const loading = ref(false)
  const filters = ref({ name: '', venue: '', status: '' })
  const page = ref({ current: 1, size: 20 })
  const list = ref<YimaiLead[]>([])

  const filteredList = computed(() => list.value)
  const pagedList = computed(() =>
    filteredList.value.slice((page.value.current - 1) * page.value.size, page.value.current * page.value.size)
  )

  function emptyForm() {
    return {
      id: 0,
      leadDate: new Date().toISOString().slice(0, 10),
      name: '',
      phone: '',
      phoneTail: '',
      demand: '',
      source: '',
      venue: isManager.value ? String(userStore.getUserInfo.venue ?? '绿地店') as YimaiLead['venue'] : ('绿地店' as YimaiLead['venue']),
      serviceTeacher: '',
      status: '新留资' as YimaiLead['status'],
      grade: '' as YimaiLead['grade'],
      trialTime: '',
      trialTopic: '',
      trialTeacher: '',
      dealCard: '',
      dealAmount: null as number | null,
      redeemAmount: null as number | null,
      voucherCode: '',
      remark: ''
    }
  }

  const dialog = reactive({
    visible: false,
    isCreate: true,
    saving: false,
    form: emptyForm()
  })

  const dialogLockedVenue = computed(() => isManager.value)

  const history = reactive({ visible: false, list: [] as Awaited<ReturnType<typeof getLeadHistory>> })

  async function load() {
    loading.value = true
    try {
      page.value.current = 1
      list.value = (await queryLeads({ ...filters.value, current: 1, size: 5000 })).records
    } finally {
      loading.value = false
    }
  }

  function resetFilters() {
    filters.value = { name: '', venue: '', status: '' }
    load()
  }

  function openCreate() {
    dialog.isCreate = true
    dialog.form = emptyForm()
    dialog.visible = true
  }

  function openEdit(row: YimaiLead) {
    dialog.isCreate = false
    dialog.form = { ...emptyForm(), ...row, status: row.status } as unknown as ReturnType<typeof emptyForm>
    dialog.visible = true
  }

  async function save() {
    if (!dialog.form.name || !dialog.form.source) {
      ElMessage.warning('请至少填写姓名和来源')
      return
    }
    dialog.saving = true
    try {
      if (dialog.isCreate) {
        await addLead(dialog.form)
        ElMessage.success('留资已提交，对应门店店长端立即可见')
      } else {
        await updateLead(dialog.form.id, dialog.form)
        ElMessage.success('已保存，变更已同步并写入留痕日志')
      }
      dialog.visible = false
      await load()
    } finally {
      dialog.saving = false
    }
  }

  async function openHistory(row: YimaiLead) {
    history.list = await getLeadHistory(row.id)
    history.visible = true
  }

  function plusDays(date: string, days: number): string {
    if (!date) return '—'
    const d = new Date(date)
    d.setDate(d.getDate() + days)
    return `${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  }

  function deadlineClass(row: YimaiLead, days: number): string {
    if (!row.leadDate) return ''
    if (['已成交', '已流失'].includes(row.status)) return 'done'
    const deadline = new Date(row.leadDate)
    deadline.setDate(deadline.getDate() + days)
    return deadline.getTime() < Date.now() ? 'overdue' : ''
  }

  function statusType(status: string): 'danger' | 'warning' | 'info' | 'success' | 'primary' {
    const map: Record<string, 'danger' | 'warning' | 'info' | 'success' | 'primary'> = {
      新留资: 'danger',
      已联系: 'warning',
      已约体验: 'primary',
      已体验: 'primary',
      已成交: 'success',
      已流失: 'info'
    }
    return map[status] ?? 'info'
  }

  onMounted(load)
</script>

<style scoped lang="scss">
  .followup-cell {
    display: flex;
    flex-direction: column;
    font-size: 11px;
    line-height: 16px;
    color: var(--el-text-color-secondary);

    .overdue {
      font-weight: 500;
      color: var(--el-color-danger);
    }

    .done {
      text-decoration: line-through;
      opacity: 0.5;
    }
  }
</style>
