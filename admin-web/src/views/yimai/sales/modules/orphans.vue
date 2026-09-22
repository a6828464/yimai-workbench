<template>
  <div>
    <ElAlert type="info" show-icon :closable="false" class="mb-4">
      <template #title>这里列出「归属对不上账号」的历史分享</template>
      <template #default>
        <div class="text-sm leading-6">
          分享的归属写入只认账号 id（同名不会互相接管，这是必要的安全约束）。代价是姓名对不上账号、
          或归属 id 指向已删除账号的历史记录会变成<strong>无人在线可管</strong>的记录 ——
          若它仍是启用状态，链接照样能被客户打开，而原主人既看不到也停不掉它。
          <br />
          处置方式：<strong>重新归属</strong>给正确账号（此后本人可自行管理），或
          直接<strong>停用</strong>该链接（无法确定归属时的稳妥做法，停用后公开链接立即失效）。
        </div>
      </template>
    </ElAlert>

    <div class="mb-3 flex flex-wrap items-center gap-3">
      <ElButton type="primary" plain :loading="loading" @click="load">
        <i class="ri-refresh-line mr-1" />刷新清单
      </ElButton>
      <ElTag v-if="!loading && !error" size="small" :type="records.length ? 'warning' : 'success'">
        {{ records.length ? `${records.length} 条待处置` : '无待处置记录' }}
      </ElTag>
      <span v-if="!ownershipColumnsReady && !loading" class="text-xs text-gray-400">
        注意：归属 id 列尚未就绪（迁移未完成），重新归属可能不可用
      </span>
    </div>

    <ElAlert v-if="error" type="error" show-icon :closable="false" class="mb-3" :title="error" />

    <ElTable
      v-if="!isHandheld"
      ref="tableRef"
      v-loading="loading"
      :data="records"
      size="small"
      border
      :max-height="tableMaxHeight"
    >
      <ElTableColumn prop="token" label="分享码" min-width="180">
        <template #default="{ row }">
          <span class="font-mono text-xs break-all">{{ row.token }}</span>
          <ElTag size="small" effect="plain" class="ml-2">{{ row.type }}</ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn prop="created_by" label="归属姓名" min-width="120">
        <template #default="{ row }">
          <span>{{ row.created_by || '（空）' }}</span>
          <span v-if="row.created_by_user_id" class="ml-1 text-xs text-gray-400">
            #{{ row.created_by_user_id }}
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn prop="reason" label="原因" min-width="150">
        <template #default="{ row }">
          <ElTag size="small" :type="reasonTagType(row.reason)">{{ row.reason }}</ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn label="处置" width="230" fixed="right">
        <template #default="{ row }">
          <ElButton size="small" type="primary" plain @click="openReassign(row)">重新归属</ElButton>
          <ElButton size="small" type="danger" plain :loading="busyId === row.id" @click="disable(row)">
            停用
          </ElButton>
        </template>
      </ElTableColumn>
      <template #empty>
        <div class="list-empty">暂无归属异常的分享记录</div>
      </template>
    </ElTable>

    <!--
      手持设备：卡片列表。
      表格最小宽 680px（分享码 180 + 归属姓名 120 + 原因 150 + 处置 230），
      390px 视口里只剩横向滚动，处置按钮还挂在 fixed=right 列上跟着横滑，很难点中。
    -->
    <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
      <MobileCard
        v-for="item in cardRows"
        :key="item.id"
        :title="item.title"
        :subtitle="item.subtitle"
        :tags="item.tags"
        :metrics="item.metrics"
        :actions="item.actions"
      />
      <div v-if="!loading && !cardRows.length" class="m-card-list__empty">
        暂无归属异常的分享记录
      </div>
    </div>

    <ElDialog v-model="reassignDlg.visible" title="重新归属分享" width="440px">
      <div class="text-sm leading-6">
        <p class="mb-2">
          把分享码
          <span class="font-mono text-xs">{{ reassignDlg.row?.token }}</span>
          的归属改到指定账号名下，之后该账号在谈单工具里就能自行管理它。
        </p>
        <ElSelect
          v-model="reassignDlg.userId"
          filterable
          placeholder="选择要归属的账号"
          class="w-full"
        >
          <ElOption v-for="a in candidates" :key="a.id" :label="a.name" :value="a.id" />
        </ElSelect>
        <p v-if="!candidates.length" class="mt-2 text-xs text-gray-400">
          没有可选的账号（或账号列表读取失败）
        </p>
      </div>
      <template #footer>
        <ElButton @click="reassignDlg.visible = false">取消</ElButton>
        <ElButton
          type="primary"
          :disabled="!reassignDlg.userId"
          :loading="reassignDlg.saving"
          @click="confirmReassign"
        >
          确认归属
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import MobileCard from '@/components/business/mobile-card/index.vue'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'
  import { listOrphanShares, repairOrphanShare, type OrphanShareRow, type OrphanReason } from '@/api/yimai'
  import { ElMessage, ElMessageBox, ElTag } from 'element-plus'

  defineOptions({ name: 'SalesOrphans' })

  // 宽表格在手机上换成卡片列表
  const { isHandheld } = useDevice()

  // 表格高度自适应（本页在谈单工具页签内，根容器是谈单工具页）
  const { tableMaxHeight, tableRef } = useTableHeight()

  const records = ref<OrphanShareRow[]>([])
  const ownershipColumnsReady = ref(true)
  const loading = ref(false)
  const error = ref('')
  /** 正在处置的行 id（用于单行按钮 loading，避免整表转圈） */
  const busyId = ref<number | null>(null)

  /**
   * 可归属的账号由后端随清单一并给出（权威 {id, name}）。
   *
   * 不复用 /accounts：它的 key 是 **username** 字符串、不暴露数字 id，
   * 而归属写的是 users.id —— 从 username 里抠数字会拼出错误的 id。
   */
  const candidates = ref<{ id: number; name: string }[]>([])
  const reassignDlg = reactive({
    visible: false,
    row: null as OrphanShareRow | null,
    userId: undefined as number | undefined,
    saving: false
  })

  /**
   * 手持设备卡片数据。
   *
   * 卡片上放的是「谁、什么原因、要做什么」——表格里的分享码在 390px 屏上
   * 折成两行也没什么用，压成一行小字放 subtitle 即可。
   */
  const cardRows = computed(() =>
    records.value.map((row) => {
      const tags: MobileCardTag[] = [
        { text: row.reason, type: reasonTagType(row.reason), effect: 'dark' },
        { text: row.type, effect: 'plain' }
      ]
      const metrics: MobileCardMetric[] = [
        { label: '归属账号', value: row.created_by_user_id ? `#${row.created_by_user_id}` : '无' }
      ]
      const actions: MobileCardAction[] = [
        { text: '重新归属', type: 'primary', onClick: () => openReassign(row) },
        { text: '停用', type: 'danger', onClick: () => disable(row) }
      ]
      return {
        id: row.id,
        title: row.created_by || '（归属姓名为空）',
        subtitle: row.token,
        tags,
        metrics,
        actions
      }
    })
  )

  /** 三档原因用不同颜色：悬挂 id 最需要人工判断，其次查无此人，再次仅缺 id */
  function reasonTagType(reason: OrphanReason): 'danger' | 'warning' | 'info' {
    if (reason === '归属 user_id 悬挂') return 'danger'
    if (reason === '姓名查无此人') return 'warning'
    return 'info'
  }

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const d = await listOrphanShares()
      records.value = d.records ?? []
      ownershipColumnsReady.value = d.ownershipColumnsReady !== false
      candidates.value = d.candidates ?? []
    } catch (e) {
      const status = (e as { response?: { status?: number } })?.response?.status
      error.value =
        status === 403
          ? '仅超管可查看归属异常清单'
          : `清单读取失败：${String(e).slice(0, 80)}`
    } finally {
      loading.value = false
    }
  }

  async function openReassign(row: OrphanShareRow) {
    reassignDlg.row = row
    reassignDlg.userId = undefined
    reassignDlg.visible = true
  }

  async function confirmReassign() {
    if (!reassignDlg.row || !reassignDlg.userId) return
    reassignDlg.saving = true
    try {
      await repairOrphanShare(reassignDlg.row.id, 'reassign', reassignDlg.userId)
      ElMessage.success('已重新归属，该账号现在可以自行管理这条分享')
      reassignDlg.visible = false
      await load()
    } catch (e) {
      ElMessage.error(`重新归属失败：${String(e).slice(0, 80)}`)
    } finally {
      reassignDlg.saving = false
    }
  }

  async function disable(row: OrphanShareRow) {
    try {
      await ElMessageBox.confirm(
        `停用后该链接立即失效（客户打开会提示已失效）。分享码：${row.token}`,
        '确认停用这条分享？',
        { type: 'warning', confirmButtonText: '停用', cancelButtonText: '取消' }
      )
    } catch {
      return // 用户取消
    }

    busyId.value = row.id
    try {
      await repairOrphanShare(row.id, 'disable')
      ElMessage.success('已停用，该链接不再对外可访问')
      await load()
    } catch (e) {
      ElMessage.error(`停用失败：${String(e).slice(0, 80)}`)
    } finally {
      busyId.value = null
    }
  }

  onMounted(load)
</script>
