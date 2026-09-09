<template>
  <div class="sync-page art-full-height !h-auto">
    <!-- 连接状态 -->
    <ElCard shadow="never" class="mb-4">
      <div class="flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-2">
          <span
            class="inline-block w-2.5 h-2.5 rounded-full"
            :class="connected ? 'bg-green-500' : 'bg-gray-300'"
          />
          <span class="font-500">KeepYoga / 随心瑜云 · 只读接入</span>
        </div>
        <ElTag size="small" effect="plain">品牌 108193 · 一麦瑜伽</ElTag>
        <ElTag v-if="sessionAt" size="small" type="success">会话 {{ sessionAt }}</ElTag>
        <div class="flex-1" />
        <ElButton size="small" @click="openKyConfig">登录账号设置</ElButton>
        <ElButton type="primary" :loading="connecting" @click="connect">{{
          connected ? '刷新会话' : '建立连接'
        }}</ElButton>
      </div>
      <div class="mt-2 text-xs text-gray-400 leading-5">
        凭据保存在服务器端 · 只读查询，不写回任何核心业务数据 ·
        全量导入由服务器直连随心瑜完成，不经过浏览器
      </div>

      <!-- 随心瑜登录账号设置（仅超管可见） -->
      <ElDialog v-model="kyCfgOpen" title="随心瑜登录账号设置" width="460px" append-to-body>
        <ElForm label-width="96px">
          <ElFormItem label="登录手机号">
            <ElInput v-model="kyForm.phone" placeholder="随心瑜后台登录手机号" />
          </ElFormItem>
          <ElFormItem label="登录密码">
            <ElInput
              v-model="kyForm.password"
              type="password"
              show-password
              placeholder="留空则保持不变"
            />
          </ElFormItem>
          <ElAlert type="info" :closable="false" class="mb-2">
            <template #title>
              保存后会立即使用该账号重新连接，用于计数/同步/全量导入。两个门店共用同一个随心瑜管理账号。
            </template>
          </ElAlert>
        </ElForm>
        <template #footer>
          <ElButton @click="kyCfgOpen = false">取消</ElButton>
          <ElButton type="primary" :loading="kySaving" @click="saveKyConfig"
            >保存并切换账号</ElButton
          >
        </template>
      </ElDialog>

      <div class="mt-3 flex flex-wrap items-center gap-2">
        <ElButton
          type="warning"
          plain
          :loading="importing"
          :disabled="!connected"
          @click="importAll"
        >
          同步会员、卡项和预约
        </ElButton>
        <span class="text-xs text-gray-400"
          >会员/卡项全量同步，预约首次近两年、后续增量回溯 3 天；每次保存带日期的私有 CSV 快照</span
        >
      </div>
      <ElAlert
        v-if="importProgress"
        :title="importProgress"
        type="info"
        show-icon
        :closable="false"
        class="mt-3"
      />
      <ElAlert
        v-if="importResult"
        :title="importResult"
        :type="importResult.includes('部分失败') ? 'warning' : 'success'"
        show-icon
        :closable="false"
        class="mt-3"
      />
    </ElCard>

    <!-- 双店实时计数 -->
    <ElRow :gutter="16" class="mb-4">
      <ElCol v-for="(row, store) in counts" :key="store" :xs="24" :md="12" class="mb-1">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">{{ store }} 实时数据</span>
              <ElTag v-if="countsFetchedAt" size="small" effect="plain">{{
                countsFetchedAt
              }}</ElTag>
            </div>
          </template>
          <div v-loading="countLoading" class="grid grid-cols-4 gap-2 text-center py-1">
            <div
              ><div class="text-xl font-600">{{ row.members }}</div
              ><div class="text-xs text-gray-400 mt-1">会员</div></div
            >
            <div
              ><div class="text-xl font-600">{{ row.visitors }}</div
              ><div class="text-xs text-gray-400 mt-1">访客</div></div
            >
            <div
              ><div class="text-xl font-600">{{ row.mcards }}</div
              ><div class="text-xs text-gray-400 mt-1">会员卡</div></div
            >
            <div
              ><div class="text-xl font-600">{{ row.contracts }}</div
              ><div class="text-xs text-gray-400 mt-1">合同</div></div
            >
          </div>
          <div
            v-if="countErrors[store]"
            class="mt-2 rounded-md bg-red-50 dark:bg-red-500/10 px-3 py-2 text-xs text-red-500 leading-5 break-all"
          >
            {{ countErrors[store] }}
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
            <div
              v-for="(v, store) in today"
              :key="store"
              class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 text-center"
            >
              <div class="text-xs text-gray-400 mb-1">{{ store }}</div>
              <div class="text-2xl font-600"
                >{{ v.total }} <span class="text-sm font-400 text-gray-400">条预约</span></div
              >
              <div class="mt-1 text-xs"
                ><ElTag size="small" type="warning" effect="plain"
                  >新客体验 {{ v.trialHits }}</ElTag
                ></div
              >
              <div v-if="todayErrors[store]" class="mt-2 text-xs text-red-500 break-all">
                {{ todayErrors[store] }}
              </div>
            </div>
          </div>
          <div class="mt-3 flex items-center justify-between">
            <span class="text-xs text-gray-400">快照将同步至各角色工作台的「今日预约」指标</span>
            <ElButton
              size="small"
              type="primary"
              plain
              :loading="todayLoading"
              @click="loadToday(true)"
              >更新快照</ElButton
            >
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
            <ElInput
              v-model="sampleCond"
              placeholder="姓名关键词，留空取最新"
              clearable
              class="!w-56"
              @keyup.enter="searchMembers"
            />
            <ElButton :loading="memberLoading" @click="searchMembers">检索</ElButton>
            <span class="text-xs text-gray-400">内部系统全量显示手机号</span>
          </div>
          <ElTable :data="members" size="small" max-height="240" v-loading="memberLoading" border>
            <ElTableColumn prop="name" label="姓名" width="110" />
            <ElTableColumn prop="phone" label="手机号" width="120" />
            <ElTableColumn prop="source" label="来源" min-width="110" show-overflow-tooltip />
            <ElTableColumn prop="consultant" label="会籍顾问" width="90" />
            <ElTableColumn prop="createdAt" label="录入日期" width="105" />
            <ElTableColumn label="操作" width="120" fixed="right">
              <template #default="{ row }">
                <ElButton link type="primary" size="small" @click="importLead(row)"
                  >导入客资</ElButton
                >
              </template>
            </ElTableColumn>
          </ElTable>
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 历史同步批次 -->
    <ElCard shadow="never">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">历史同步批次</span>
          <span class="text-xs text-gray-400">会员、卡项和出勤多表同步记录</span>
        </div>
      </template>
      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left>
          <span class="text-xs text-gray-400">失败批次请重新执行对应门店同步</span>
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

    <ElDialog v-model="artifactDialog.visible" title="历史导入表格" width="820px">
      <ElTable v-loading="artifactDialog.loading" :data="artifactDialog.rows" border stripe>
        <ElTableColumn prop="displayName" label="表格名称" min-width="280" show-overflow-tooltip />
        <ElTableColumn label="范围" width="150">
          <template #default="{ row }">{{
            row.isFull ? '全量' : `${row.dateFrom || '-'} ~ ${row.dateTo || '-'}`
          }}</template>
        </ElTableColumn>
        <ElTableColumn prop="rowCount" label="行数" width="90" />
        <ElTableColumn label="大小" width="100"
          ><template #default="{ row }">{{ formatSize(row.size) }}</template></ElTableColumn
        >
        <ElTableColumn label="操作" width="90"
          ><template #default="{ row }"
            ><ElButton link type="primary" @click="downloadArtifact(row)">下载</ElButton></template
          ></ElTableColumn
        >
      </ElTable>
      <ElEmpty
        v-if="!artifactDialog.loading && !artifactDialog.rows.length"
        description="该批次暂无可下载表格"
      />
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import { querySyncJobs } from '@/api/yimai'
  import type { KyImportAck, KyImportResult, SyncArtifactItem, YimaiSyncJob } from '@/api/yimai'
  import { fetchKyCounts, fetchKyToday, fetchKyMembers, kySession, KY_STORES } from '@/api/keepyoga'
  import type { KyCounts, KyMemberRow } from '@/api/keepyoga'
  import { useYimaiStore } from '@/store/modules/yimai'
  import { addLead, getSyncArtifacts, getSyncJob, importKyMembersToPool } from '@/api/yimai'
  import { apiDownload, apiGet, apiPut, USE_BACKEND } from '@/api/backend'
  import { toLocalDateString } from '@/utils'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiSync' })

  const yimaiStore = useYimaiStore()

  // ---------- 随心瑜账号设置（仅超管） ----------
  const kyCfgOpen = ref(false)
  const kySaving = ref(false)
  const kyForm = reactive({ phone: '', password: '' })

  async function openKyConfig() {
    if (!USE_BACKEND) return
    try {
      const d = await apiGet<{ phone: string }>('/ky/config')
      kyForm.phone = d?.phone ?? ''
      kyForm.password = ''
      kyCfgOpen.value = true
    } catch (e) {
      ElMessage.error(`读取账号设置失败：${String(e).slice(0, 80)}`)
    }
  }

  async function saveKyConfig() {
    if (!kyForm.phone) {
      ElMessage.warning('请填写登录手机号')
      return
    }
    kySaving.value = true
    try {
      await apiPut('/ky/config', { phone: kyForm.phone, password: kyForm.password })
      ElMessage.success('账号已保存，正在用新账号重新连接...')
      kyCfgOpen.value = false
      connected.value = false
      await connect()
    } catch (e) {
      ElMessage.error(`保存失败：${String(e).slice(0, 100)}`)
    } finally {
      kySaving.value = false
    }
  }

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
      const msg = String((e as { message?: string }).message ?? e)
      // 未配置凭据时给出明确指引，而非笼统的"连接失败"
      if (msg.includes('KY_PHONE') || msg.includes('配置')) {
        ElMessage.warning('尚未配置随心瑜登录账号，请点击「登录账号设置」填写手机号与密码')
      } else {
        ElMessage.error(`连接失败：${msg.slice(0, 100)}`)
      }
    } finally {
      connecting.value = false
    }
  }

  // ---------- 全量导入（受理 → 轮询任务状态 → 汇总） ----------
  const importing = ref(false)
  const importResult = ref('')
  const importProgress = ref('')
  let pollTimer: ReturnType<typeof setInterval> | null = null

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  onBeforeUnmount(stopPolling)

  /** 等待后台任务收尾：4 秒轮询，130 分钟兜底（超过按失败处理，与后端僵尸回收一致） */
  function waitForJob(jobId: number, store: string): Promise<YimaiSyncJob> {
    return new Promise((resolve, reject) => {
      let elapsed = 0
      stopPolling()
      pollTimer = setInterval(async () => {
        elapsed += 4
        if (elapsed > 130 * 60) {
          stopPolling()
          reject(new Error(`${store} 同步超过 130 分钟未收尾，请稍后在历史批次中确认状态`))
          return
        }
        try {
          const job = await getSyncJob(jobId)
          if (job.status !== '进行中') {
            stopPolling()
            resolve(job)
          }
        } catch (e) {
          // 单次轮询失败不中断（网络抖动），连续失败由超时兜底
          console.warn('[sync] poll failed', e)
        }
      }, 4000)
    })
  }

  async function importAll() {
    importing.value = true
    importResult.value = ''
    importProgress.value = ''
    const details: string[] = []
    const failedStores: string[] = []
    try {
      for (const store of Object.keys(KY_STORES) as ('绿地店' | '东部店')[]) {
        try {
          importProgress.value = `${store} 同步已受理，服务器后台执行中…`
          const ack = await importKyMembersToPool(store)
          if (ack.background) {
            // 生产：立即拿到受理回执，轮询直到任务收尾
            const job = await waitForJob(ack.jobId, store)
            if (job.status === '失败') {
              failedStores.push(store)
              ElMessage.error(
                `${store} 同步失败：${(job.errorMessage || '详见错误详情').slice(0, 80)}`
              )
            } else {
              details.push(job.detail || `${store} 同步完成`)
            }
          } else {
            // 本地/测试：同步执行返回全量结果（兼容旧契约）
            const r = ack as KyImportAck & KyImportResult
            details.push(
              `${store}：会员 ${r.total}，卡项 ${r.cards}，预约 ${r.bookings}，有效签到 ${r.signedBookings}；新增 ${r.created}，更新 ${r.updated}，未变化 ${r.unchanged}，跳过 ${r.skipped}`
            )
            if (r.skipped > 0) failedStores.push(store)
          }
        } catch (e) {
          failedStores.push(store)
          ElMessage.error(`${store} 导入失败：${String(e).slice(0, 80)}`)
        }
      }
      const status = failedStores.length ? `部分失败（${failedStores.join('、')}）` : '成功'
      importResult.value = `多表同步${status}：${details.join(' ；')}`
      importProgress.value = ''
      await refreshData()
      if (failedStores.length) ElMessage.warning(importResult.value.slice(0, 120))
      else ElMessage.success('两店多表同步完成')
    } finally {
      stopPolling()
      importing.value = false
      importProgress.value = ''
    }
  }

  // ---------- 计数 ----------
  const countLoading = ref(false)
  const counts = ref<Record<string, KyCounts>>({
    绿地店: { members: '-', visitors: '-', mcards: '-', contracts: '-' },
    东部店: { members: '-', visitors: '-', mcards: '-', contracts: '-' }
  })
  const countsFetchedAt = ref('')
  const countErrors = ref<Record<string, string>>({})

  async function loadCounts() {
    countLoading.value = true
    const failures: string[] = []
    const errors: Record<string, string> = {}
    try {
      for (const store of Object.keys(KY_STORES)) {
        try {
          counts.value[store] = await fetchKyCounts(store)
          if (Object.values(counts.value[store]).every((value) => value === '-')) {
            failures.push(store)
            errors[store] = '上游接口未返回计数'
          }
        } catch (e) {
          const msg = String((e as { message?: string }).message ?? e).slice(0, 120)
          failures.push(store)
          errors[store] = msg
        }
      }
      countsFetchedAt.value = new Date().toLocaleTimeString('zh-CN', { hour12: false })
      countErrors.value = errors
      if (failures.length) {
        ElMessage.warning(`实时数据获取失败：${failures.join('；')}（详见卡片下方错误提示）`)
      }
    } finally {
      countLoading.value = false
    }
  }

  // ---------- 今日 ----------
  const todayLoading = ref(false)
  const today = ref<
    Record<
      string,
      { total: number; trialHits: number; kinds: { 私教: number; 小班: number; 团课: number } }
    >
  >({
    绿地店: { total: 0, trialHits: 0, kinds: { 私教: 0, 小班: 0, 团课: 0 } },
    东部店: { total: 0, trialHits: 0, kinds: { 私教: 0, 小班: 0, 团课: 0 } }
  })
  const todayErrors = ref<Record<string, string>>({})

  async function loadToday(withSnapshot: boolean) {
    todayLoading.value = true
    const nextErrors: Record<string, string> = {}
    try {
      for (const store of Object.keys(KY_STORES)) {
        try {
          today.value[store] = await fetchKyToday(store)
        } catch (error) {
          nextErrors[store] = String((error as { message?: string }).message ?? error).slice(0, 120)
        }
      }
      todayErrors.value = nextErrors
      if (withSnapshot) {
        if (Object.keys(nextErrors).length) {
          ElMessage.error(`预约获取失败，已保留原快照：${Object.keys(nextErrors).join('、')}`)
          return
        }
        const snap = {
          fetchedAt: new Date().toLocaleString('zh-CN', { hour12: false }),
          fetchedBy: yimaiStore.currentActor().operatorName,
          counts: JSON.parse(JSON.stringify(counts.value)),
          todayBookings: {
            绿地店: today.value['绿地店'].total,
            东部店: today.value['东部店'].total
          },
          trialBookings: {
            绿地店: today.value['绿地店'].trialHits,
            东部店: today.value['东部店'].trialHits
          },
          todayKinds: {
            绿地店: today.value['绿地店'].kinds ?? { 私教: 0, 小班: 0, 团课: 0 },
            东部店: today.value['东部店'].kinds ?? { 私教: 0, 小班: 0, 团课: 0 }
          }
        }
        if (USE_BACKEND) {
          // 后端模式：快照落库，工作台/经营看板读取同一份
          await apiPut('/today/snapshot', {
            todayBookings: snap.todayBookings,
            trialBookings: snap.trialBookings,
            todayKinds: snap.todayKinds
          })
        } else {
          yimaiStore.saveSnapshot(snap)
        }
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
      leadDate: toLocalDateString(new Date()),
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
  const { columns, data, pagination, refreshData, handleSizeChange, handleCurrentChange } =
    useTable({
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
                h('p', { class: 'font-500' }, row.displayName || row.batchNo),
                h('p', { class: 'text-xs text-gray-400' }, row.batchNo),
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
            prop: 'detail',
            label: '同步明细',
            minWidth: 320,
            showOverflowTooltip: true,
            formatter: (row: YimaiSyncJob) =>
              row.detail
                ? h('span', { class: 'text-xs text-gray-500' }, row.detail)
                : h('span', { class: 'text-xs text-gray-300' }, '—')
          },
          {
            prop: 'totalCount',
            label: '总数 / 成功 / 失败',
            width: 150,
            sortable: true,
            formatter: (row: YimaiSyncJob) =>
              h('div', [
                h('span', {}, `${row.totalCount} / ${row.successCount} / `),
                row.failCount > 0
                  ? h('span', { class: 'font-500 text-red-500' }, String(row.failCount))
                  : h('span', {}, '0')
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
              h('div', { class: 'flex gap-1' }, [
                row.artifactsCount
                  ? h(ArtButtonTable, {
                      type: 'view',
                      title: `表格(${row.artifactsCount})`,
                      onClick: () => showArtifacts(row)
                    })
                  : null,
                row.failCount > 0 || row.errorMessage
                  ? h(ArtButtonTable, {
                      type: 'view',
                      title: '错误',
                      onClick: () => showErrors(row)
                    })
                  : null
              ])
          }
        ]
      }
    })

  function showErrors(row: YimaiSyncJob) {
    ElMessage.info(row.errorMessage || `批次 ${row.batchNo} 包含 ${row.failCount} 条未处理记录`)
  }

  const artifactDialog = reactive({
    visible: false,
    loading: false,
    rows: [] as SyncArtifactItem[]
  })

  async function showArtifacts(row: YimaiSyncJob) {
    artifactDialog.visible = true
    artifactDialog.loading = true
    try {
      artifactDialog.rows = await getSyncArtifacts(row.id)
    } catch {
      artifactDialog.rows = []
      ElMessage.error('历史表格读取失败')
    } finally {
      artifactDialog.loading = false
    }
  }

  async function downloadArtifact(row: SyncArtifactItem) {
    try {
      await apiDownload(`/sync-artifacts/${row.id}/download`, row.displayName)
    } catch {
      ElMessage.error('表格下载失败')
    }
  }

  function formatSize(size: number): string {
    return size < 1024
      ? `${size} B`
      : size < 1048576
        ? `${(size / 1024).toFixed(1)} KB`
        : `${(size / 1048576).toFixed(1)} MB`
  }

  onMounted(() => {
    connect()
  })
</script>
