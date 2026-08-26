<template>
  <div class="sync-page art-full-height !h-auto">
    <!-- 连接状态 -->
    <ElCard shadow="never" class="mb-4">
      <div class="flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-2">
          <span class="inline-block w-2.5 h-2.5 rounded-full" :class="connected ? 'bg-green-500' : 'bg-gray-300'" />
          <span class="font-500">KeepYoga / 随心瑜云 · 只读接入</span>
        </div>
        <ElTag size="small" effect="plain">品牌 108193 · 一麦瑜伽</ElTag>
        <ElTag v-if="sessionAt" size="small" type="success">会话 {{ sessionAt }}</ElTag>
        <div class="flex-1" />
        <ElButton type="primary" :loading="connecting" @click="connect">{{ connected ? '刷新会话' : '建立连接' }}</ElButton>
      </div>
      <div class="mt-2 text-xs text-gray-400 leading-5">
        凭据仅保存在开发服务器侧（.env.local），前端只持有临时token · 只读查询，不写回任何核心业务数据 · 阶段1由 Laravel Integration 模块接管并增加批次校验
      </div>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <ElButton
          type="warning"
          plain
          :loading="importing"
          :disabled="!connected"
          @click="importAll"
        >
          全量导入会员到客户池
        </ElButton>
        <span class="text-xs text-gray-400">拉取双店会员基础表，按外部ID去重合并（已有本地分层/负责人不被覆盖）</span>
      </div>
      <ElAlert v-if="importResult" :title="importResult" type="success" show-icon :closable="false" class="mt-3" />
    </ElCard>

    <!-- 双店实时计数 -->
    <ElRow :gutter="16" class="mb-4">
      <ElCol v-for="(row, store) in counts" :key="store" :xs="24" :md="12" class="mb-1">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">{{ store }} 实时数据</span>
              <ElTag v-if="countsFetchedAt" size="small" effect="plain">{{ countsFetchedAt }}</ElTag>
            </div>
          </template>
          <div v-loading="countLoading" class="grid grid-cols-4 gap-2 text-center py-1">
            <div><div class="text-xl font-600">{{ row.members }}</div><div class="text-xs text-gray-400 mt-1">会员</div></div>
            <div><div class="text-xl font-600">{{ row.visitors }}</div><div class="text-xs text-gray-400 mt-1">访客</div></div>
            <div><div class="text-xl font-600">{{ row.mcards }}</div><div class="text-xs text-gray-400 mt-1">会员卡</div></div>
            <div><div class="text-xl font-600">{{ row.contracts }}</div><div class="text-xs text-gray-400 mt-1">合同</div></div>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="mb-4">
      <!-- 今日预约 -->
      <ElCol :xs="24" :md="10" class="mb-4">
        <ElCard shadow="never" class="h-full">
          <template #header><span class="font-500">今日预约快照</span></template>
          <div v-loading="todayLoading" class="grid grid-cols-2 gap-3 py-1">
            <div v-for="(v, store) in today" :key="store" class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 text-center">
              <div class="text-xs text-gray-400 mb-1">{{ store }}</div>
              <div class="text-2xl font-600">{{ v.total }} <span class="text-sm font-400 text-gray-400">条预约</span></div>
              <div class="mt-1 text-xs"><ElTag size="small" type="warning" effect="plain">新客体验 {{ v.trialHits }}</ElTag></div>
            </div>
          </div>
          <div class="mt-3 flex items-center justify-between">
            <span class="text-xs text-gray-400">快照将同步至各角色工作台的「今日预约」指标</span>
            <ElButton size="small" type="primary" plain :loading="todayLoading" @click="loadToday(true)">更新快照</ElButton>
          </div>
        </ElCard>
      </ElCol>

      <!-- 样本导入 -->
      <ElCol :xs="24" :md="14" class="mb-4">
        <ElCard shadow="never" class="h-full">
          <template #header><span class="font-500">会员样本检索 → 导入为本地客资</span></template>
          <div class="flex flex-wrap items-center gap-3 mb-3">
            <ElSelect v-model="sampleStore" class="!w-32">
              <ElOption label="绿地店" value="绿地店" />
              <ElOption label="东部店" value="东部店" />
            </ElSelect>
            <ElInput v-model="sampleCond" placeholder="姓名关键词，留空取最新" clearable class="!w-56" @keyup.enter="searchMembers" />
            <ElButton :loading="memberLoading" @click="searchMembers">检索</ElButton>
            <span class="text-xs text-gray-400">内部系统全量显示手机号</span>
          </div>
          <ElTable :data="members" size="small" max-height="240" v-loading="memberLoading" border>
            <ElTableColumn prop="name" label="姓名" width="110" />
            <ElTableColumn prop="phone" label="手机号" width="120" />
            <ElTableColumn prop="source" label="来源" min-width="110" show-overflow-tooltip />
            <ElTableColumn prop="consultant" label="顾问" width="90" />
            <ElTableColumn prop="createdAt" label="录入日期" width="105" />
            <ElTableColumn label="操作" width="120" fixed="right">
              <template #default="{ row }">
                <ElButton link type="primary" size="small" @click="importLead(row)">导入客资</ElButton>
              </template>
            </ElTableColumn>
          </ElTable>
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 历史批次（示例结构） -->
    <ElCard shadow="never">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">历史同步批次</span>
          <span class="text-xs text-gray-400">示例批次结构；真实批次由后端同步任务生成</span>
        </div>
      </template>
      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left>
          <ElButton plain disabled>重试失败批次</ElButton>
        </template>
      </ArtTableHeader>

      <ArtTable
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import { querySyncJobs } from '@/api/yimai'
  import type { YimaiSyncJob } from '@/api/yimai'
  import { fetchKyCounts, fetchKyToday, fetchKyMembers, kySession, KY_STORES } from '@/api/keepyoga'
  import type { KyCounts, KyMemberRow } from '@/api/keepyoga'
  import { useYimaiStore } from '@/store/modules/yimai'
  import { addLead, importKyMembersToPool } from '@/api/yimai'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiSync' })

  const yimaiStore = useYimaiStore()

  // ---------- 连接 ----------
  const connecting = ref(false)
  const connected = ref(false)
  const sessionAt = ref('')

  async function connect() {
    connecting.value = true
    try {
      await kySession(true)
      connected.value = true
      sessionAt.value = new Date().toLocaleTimeString('zh-CN', { hour12: false })
      ElMessage.success('KeepYoga 会话已建立')
      await Promise.all([loadCounts(), loadToday(false)])
    } catch (e) {
      connected.value = false
      ElMessage.error(`连接失败：${String(e).slice(0, 100)}`)
    } finally {
      connecting.value = false
    }
  }

  // ---------- 全量导入 ----------
  const importing = ref(false)
  const importResult = ref('')

  async function importAll() {
    importing.value = true
    importResult.value = ''
    let created = 0
    let updated = 0
    let total = 0
    try {
      for (const store of Object.keys(KY_STORES) as ('绿地店' | '东部店')[]) {
        try {
          const r = await importKyMembersToPool(store)
          created += r.created
          updated += r.updated
          total += r.total
        } catch (e) {
          ElMessage.error(`${store} 导入失败：${String(e).slice(0, 80)}`)
        }
      }
      importResult.value = `导入完成：共读取 ${total} 位会员，新增客资 ${created} 条，更新档案 ${updated} 条（已按外部ID去重）`
      ElMessage.success(importResult.value)
    } finally {
      importing.value = false
    }
  }

  // ---------- 计数 ----------
  const countLoading = ref(false)
  const counts = ref<Record<string, KyCounts>>({
    绿地店: { members: '-', visitors: '-', mcards: '-', contracts: '-' },
    东部店: { members: '-', visitors: '-', mcards: '-', contracts: '-' }
  })
  const countsFetchedAt = ref('')

  async function loadCounts() {
    countLoading.value = true
    try {
      for (const store of Object.keys(KY_STORES)) {
        try {
          counts.value[store] = await fetchKyCounts(store)
        } catch {
          /* keep dash */
        }
      }
      countsFetchedAt.value = new Date().toLocaleTimeString('zh-CN', { hour12: false })
    } finally {
      countLoading.value = false
    }
  }

  // ---------- 今日 ----------
  const todayLoading = ref(false)
  const today = ref<Record<string, { total: number; trialHits: number }>>({
    绿地店: { total: 0, trialHits: 0 },
    东部店: { total: 0, trialHits: 0 }
  })

  async function loadToday(withSnapshot: boolean) {
    todayLoading.value = true
    try {
      for (const store of Object.keys(KY_STORES)) {
        try {
          today.value[store] = await fetchKyToday(store)
        } catch {
          /* ignore */
        }
      }
      if (withSnapshot) {
        yimaiStore.saveSnapshot({
          fetchedAt: new Date().toLocaleString('zh-CN', { hour12: false }),
          fetchedBy: yimaiStore.currentActor().operatorName,
          counts: JSON.parse(JSON.stringify(counts.value)),
          todayBookings: { 绿地店: today.value['绿地店'].total, 东部店: today.value['东部店'].total },
          trialBookings: { 绿地店: today.value['绿地店'].trialHits, 东部店: today.value['东部店'].trialHits }
        })
        ElMessage.success('快照已更新，工作台「今日预约」已联动')
      }
    } finally {
      todayLoading.value = false
    }
  }

  // ---------- 样本导入 ----------
  const sampleStore = ref<'绿地店' | '东部店'>('绿地店')
  const sampleCond = ref('')
  const memberLoading = ref(false)
  const members = ref<KyMemberRow[]>([])

  async function searchMembers() {
    memberLoading.value = true
    try {
      members.value = await fetchKyMembers(sampleStore.value, sampleCond.value.trim(), 20)
      if (!members.value.length) ElMessage.info('未检索到会员')
    } catch (e) {
      ElMessage.error(`检索失败：${String(e).slice(0, 90)}`)
    } finally {
      memberLoading.value = false
    }
  }

  async function importLead(row: KyMemberRow) {
    await addLead({
      leadDate: new Date().toISOString().slice(0, 10),
      name: row.name || `KeepYoga#${row.memberId}`,
      phone: row.phone,
      phoneTail: row.phone.slice(-4),
      demand: 'KeepYoga存量会员待回访',
      source: '潜客激活',
      venue: sampleStore.value as '绿地店' | '东部店',
      serviceTeacher: '',
      grade: '' as const,
      trialTime: '',
      trialTopic: '',
      trialTeacher: '',
      dealCard: '',
      dealAmount: null,
      redeemAmount: null,
      voucherCode: '',
      remark: `来自KeepYoga会员库：来源[${row.source || '-'}] 录入[${row.createdAt}]`
    })
    ElMessage.success(`已导入「${row.name}」到 ${sampleStore.value} 客资池`)
  }

  // ---------- 历史批次表 ----------
  const STATUS_TAG: Record<string, 'danger' | 'warning' | 'info' | 'success' | 'primary'> = {
    成功: 'success',
    部分失败: 'warning',
    进行中: 'primary',
    失败: 'danger'
  }

  const loading = ref(false)
  const {
    columns,
    data,
    pagination,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: querySyncJobs,
      apiParams: { current: 1, size: 20 },
      columnsFactory: () => [
        {
          prop: 'batchNo',
          label: '批次号 / 类型',
          minWidth: 170,
          formatter: (row: YimaiSyncJob) =>
            h('div', [
              h('p', { class: 'font-500' }, row.batchNo),
              h('p', { class: 'text-xs text-gray-400' }, row.dataType)
            ])
        },
        {
          prop: 'venue',
          label: '门店',
          width: 90,
          formatter: (row: YimaiSyncJob) =>
            h(ElTag, { size: 'small', effect: 'plain' }, () => row.venue)
        },
        { prop: 'dateRange', label: '数据范围', minWidth: 180 },
        {
          prop: 'totalCount',
          label: '总数 / 成功 / 失败',
          width: 150,
          sortable: true,
          formatter: (row: YimaiSyncJob) =>
            h('div', [
              h('span', {}, `${row.totalCount} / ${row.successCount} / `),
              row.failCount > 0 ? h('span', { class: 'font-500 text-red-500' }, String(row.failCount)) : h('span', {}, '0')
            ])
        },
        {
          prop: 'status',
          label: '状态',
          width: 100,
          formatter: (row: YimaiSyncJob) =>
            h(ElTag, { size: 'small', type: STATUS_TAG[row.status] }, () => row.status)
        },
        { prop: 'finishedAt', label: '完成时间', width: 150, sortable: true },
        {
          prop: 'operation',
          label: '操作',
          width: 130,
          fixed: 'right',
          formatter: (row: YimaiSyncJob) =>
            row.failCount > 0
              ? h(ArtButtonTable, { type: 'view', title: '查看错误明细', onClick: () => showErrors(row) })
              : h('span', { class: 'text-xs text-gray-400' }, '—')
        }
      ]
    }
  })

  function showErrors(row: YimaiSyncJob) {
    ElMessage.info(`批次 ${row.batchNo} 错误明细在阶段1交付（external_id 定位）`)
  }

  onMounted(() => {
    connect()
  })
</script>
