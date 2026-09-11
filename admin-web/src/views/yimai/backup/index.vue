<template>
  <div class="p-4">
    <!-- 状态总览 -->
    <ElCard shadow="never" class="mb-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">数据备份状态</span>
          <ElButton size="small" @click="loadAll">刷新</ElButton>
        </div>
      </template>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
          <div class="text-xs text-gray-400">最近备份</div>
          <div class="text-sm font-600 mt-1">{{ status.lastRunAt || '从未备份' }}</div>
          <div
            class="text-xs mt-0.5"
            :class="status.lastResult === '成功' ? 'text-green-600' : 'text-orange-500'"
          >
            {{ status.lastResult || '—' }}
          </div>
        </div>
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
          <div class="text-xs text-gray-400">下次自动备份</div>
          <div class="text-sm font-600 mt-1">{{ status.nextRunAt || '自动备份未启用' }}</div>
        </div>
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
          <div class="text-xs text-gray-400">本地备份</div>
          <div class="text-sm font-600 mt-1"
            >{{ status.localCount }} 份 · {{ humanSize(status.localSize) }}</div
          >
        </div>
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
          <div class="text-xs text-gray-400">远端存储</div>
          <div class="text-sm font-600 mt-1">
            {{ status.remoteConfigured ? 'WebDAV 已配置' : '未配置' }}
          </div>
          <div class="text-xs text-gray-400 mt-0.5">NAS / 坚果云 / Alist</div>
        </div>
      </div>
      <ElAlert
        v-if="status.lastDetail"
        :title="status.lastDetail"
        :type="status.lastResult === '成功' ? 'success' : 'warning'"
        class="mt-3"
        :closable="false"
        show-icon
      />
    </ElCard>

    <!-- 备份配置 -->
    <ElCard shadow="never" class="mb-4">
      <template #header><span class="font-500">备份配置</span></template>
      <ElRow :gutter="12">
        <ElCol :xs="12" :md="5">
          <ElFormItem label="每日自动备份">
            <ElSwitch v-model="form.enabled" />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem label="备份时间">
            <ElTimeSelect
              v-model="form.runAt"
              start="00:00"
              end="23:50"
              step="00:10"
              class="!w-full"
            />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem label="本地保留份数">
            <ElInputNumber
              v-model="form.keepLocal"
              :min="1"
              :max="90"
              :step="1"
              controls-position="right"
              class="!w-full"
            />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="9">
          <ElFormItem label="纳入 .env 凭据">
            <ElSwitch v-model="form.keepEnv" />
            <span class="text-xs text-gray-400 ml-2">换机恢复更省事；备份包请妥善保管</span>
          </ElFormItem>
        </ElCol>
      </ElRow>
      <ElDivider class="!my-2" />
      <ElRow :gutter="12">
        <ElCol :xs="12" :md="4">
          <ElFormItem label="远端存储">
            <ElSelect v-model="form.remote.type">
              <ElOption label="不使用" value="none" />
              <ElOption label="WebDAV（NAS/坚果云/Alist）" value="webdav" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="10">
          <ElFormItem label="WebDAV 地址">
            <ElInput
              v-model="form.remote.url"
              placeholder="如 https://dav.jianguoyun.com/dav/ 或 https://nas.local:5006"
            />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem label="账号">
            <ElInput v-model="form.remote.username" placeholder="WebDAV 账号" />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem label="密码/应用密码">
            <ElInput
              v-model="form.remote.password"
              type="password"
              show-password
              :placeholder="passwordPlaceholder"
              autocomplete="new-password"
            />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="16" :md="9">
          <ElFormItem label="远端目录">
            <ElInput v-model="form.remote.path" placeholder="yimai-backup（不存在会自动创建）" />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="8" :md="4">
          <ElFormItem label=" ">
            <ElButton class="w-full" @click="testConn">测试连接</ElButton>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="5">
          <ElFormItem label=" ">
            <ElButton class="w-full" type="primary" :loading="saving" @click="save"
              >保存配置</ElButton
            >
          </ElFormItem>
        </ElCol>
      </ElRow>
    </ElCard>

    <!-- 手动操作 -->
    <ElCard shadow="never" class="mb-4">
      <template #header><span class="font-500">手动操作</span></template>
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <ElButton type="primary" :loading="busy" :disabled="!connected" @click="runNow(false)">
          立即备份（仅本地）
        </ElButton>
        <ElButton type="primary" plain :loading="busy" :disabled="!connected" @click="runNow(true)">
          立即备份并上传远端
        </ElButton>
        <input ref="fileRef" type="file" accept=".zip" class="hidden" @change="onPickFile" />
        <ElButton :loading="busy" :disabled="!connected" @click="fileRef?.click()"
          >上传备份包恢复</ElButton
        >
        <span class="text-xs text-gray-400">
          备份内容：全部业务数据表 + 同步快照等私有文件{{
            form.keepEnv ? ' + .env 凭据' : ''
          }}；恢复为全量覆盖，恢复前自动做安全快照
        </span>
      </div>
      <ElAlert
        type="warning"
        :closable="false"
        show-icon
        title="恢复会覆盖当前全部数据（全量覆盖策略）；恢复包含账号表，完成后需用备份内当时的账号重新登录。"
      />
    </ElCard>

    <!-- 备份文件列表 -->
    <ElCard shadow="never">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">备份文件</span>
          <ElButton size="small" :loading="remoteLoading" @click="loadRemote"
            >刷新远端列表</ElButton
          >
        </div>
      </template>
      <ElTabs v-model="scope">
        <ElTabPane label="本地备份" name="local">
          <ElTable :data="currentFiles" size="small">
            <ElTableColumn prop="name" label="文件名" min-width="260" show-overflow-tooltip />
            <ElTableColumn label="大小" width="100">
              <template #default="{ row }">{{ humanSize(row.size) }}</template>
            </ElTableColumn>
            <ElTableColumn prop="mtime" label="时间" width="180" />
            <ElTableColumn label="操作" width="260" fixed="right">
              <template #default="{ row }">
                <ElButton link type="primary" size="small" @click="onAction(row, 'download')"
                  >下载</ElButton
                >
                <ElButton link type="primary" size="small" @click="onAction(row, 'verify')"
                  >校验</ElButton
                >
                <ElButton link type="danger" size="small" @click="onAction(row, 'restore')"
                  >恢复</ElButton
                >
                <ElButton link type="danger" size="small" @click="onAction(row, 'delete')"
                  >删除</ElButton
                >
              </template>
            </ElTableColumn>
            <template #empty>
              <ElEmpty description="暂无本地备份，点击上方「立即备份」生成" :image-size="60" />
            </template>
          </ElTable>
        </ElTabPane>
        <ElTabPane :label="`远端备份（${remoteFiles.length}）`" name="remote">
          <ElTable :data="currentFiles" size="small">
            <ElTableColumn prop="name" label="文件名" min-width="260" show-overflow-tooltip />
            <ElTableColumn label="大小" width="100">
              <template #default="{ row }">{{ humanSize(row.size) }}</template>
            </ElTableColumn>
            <ElTableColumn prop="mtime" label="时间" width="180" />
            <ElTableColumn label="操作" width="260" fixed="right">
              <template #default="{ row }">
                <ElButton link type="primary" size="small" @click="onAction(row, 'download')"
                  >下载</ElButton
                >
                <ElButton link type="primary" size="small" @click="onAction(row, 'verify')"
                  >校验</ElButton
                >
                <ElButton link type="danger" size="small" @click="onAction(row, 'restore')"
                  >恢复</ElButton
                >
                <ElButton link type="danger" size="small" @click="onAction(row, 'delete')"
                  >删除</ElButton
                >
              </template>
            </ElTableColumn>
            <template #empty>
              <ElEmpty
                :description="
                  status.remoteConfigured
                    ? '点击右上角「刷新远端列表」查看 NAS/网盘上的备份'
                    : '未配置 WebDAV 远端存储'
                "
                :image-size="60"
              />
            </template>
          </ElTable>
        </ElTabPane>
      </ElTabs>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import {
    deleteBackupFile,
    downloadBackupFile,
    getBackupConfig,
    getSyncJob,
    listBackupFiles,
    restoreBackup,
    restoreBackupUpload,
    runBackup,
    saveBackupConfig,
    testBackupConnection,
    verifyBackup
  } from '@/api/yimai'
  import type { BackupConfig, BackupFileInfo, BackupStatus } from '@/api/yimai'
  import { USE_BACKEND } from '@/api/backend'
  import { ElMessage, ElMessageBox } from 'element-plus'

  defineOptions({ name: 'YimaiBackup' })

  const defaultForm = (): BackupConfig => ({
    enabled: false,
    runAt: '03:30',
    keepLocal: 7,
    keepEnv: true,
    remote: { type: 'none', url: '', username: '', password: '', path: 'yimai-backup' }
  })

  const form = ref<BackupConfig>(defaultForm())
  const status = ref<BackupStatus>({
    lastRunAt: '',
    lastResult: '',
    lastDetail: '',
    nextRunAt: '',
    localCount: 0,
    localSize: 0,
    remoteConfigured: false
  })
  const saving = ref(false)
  const busy = ref(false)
  const scope = ref<'local' | 'remote'>('local')
  const localFiles = ref<BackupFileInfo[]>([])
  const remoteFiles = ref<BackupFileInfo[]>([])
  const remoteLoading = ref(false)
  const fileRef = ref<HTMLInputElement | null>(null)

  const connected = computed(() => USE_BACKEND)
  const currentFiles = computed(() =>
    scope.value === 'local' ? localFiles.value : remoteFiles.value
  )
  const passwordPlaceholder = computed(() =>
    status.value.remoteConfigured ? '留空保持已保存的密码' : 'WebDAV 密码 / 坚果云应用密码'
  )

  function humanSize(bytes: number): string {
    if (!bytes) return '0KB'
    return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)}MB` : `${Math.round(bytes / 1024)}KB`
  }

  async function loadAll(): Promise<void> {
    if (!connected.value) return
    const d = await getBackupConfig()
    form.value = { ...d.config, remote: { ...d.config.remote, password: '' } }
    status.value = d.status
    localFiles.value = (await listBackupFiles('local')).files
  }

  async function loadRemote(): Promise<void> {
    if (!connected.value) return
    remoteLoading.value = true
    try {
      remoteFiles.value = (await listBackupFiles('remote')).files
      scope.value = 'remote'
    } catch (e) {
      ElMessage.error(`远端列表获取失败：${String(e).slice(0, 80)}`)
    } finally {
      remoteLoading.value = false
    }
  }

  async function save(): Promise<void> {
    saving.value = true
    try {
      const d = await saveBackupConfig(form.value)
      form.value = { ...d.config, remote: { ...d.config.remote, password: '' } }
      status.value = d.status
      ElMessage.success('备份配置已保存')
    } catch (e) {
      ElMessage.error(`保存失败：${String(e).slice(0, 80)}`)
    } finally {
      saving.value = false
    }
  }

  async function testConn(): Promise<void> {
    ElMessage.info('正在测试 WebDAV 连接…')
    try {
      const d = await testBackupConnection(form.value.remote)
      ElMessage.success(d.message)
    } catch (e) {
      ElMessage.error(`连接失败：${String(e).slice(0, 100)}`)
    }
  }

  /** 等待后台任务收尾：4 秒轮询，130 分钟兜底（与后端僵尸回收一致） */
  function waitForJob(jobId: number, label: string): Promise<string> {
    return new Promise((resolve, reject) => {
      let elapsed = 0
      const timer = setInterval(async () => {
        elapsed += 4
        if (elapsed > 130 * 60) {
          clearInterval(timer)
          reject(new Error(`${label}超过 130 分钟未收尾，请稍后在历史批次中确认状态`))
          return
        }
        try {
          const job = await getSyncJob(jobId)
          if (job.status !== '进行中') {
            clearInterval(timer)
            if (job.status === '失败') {
              reject(new Error((job.errorMessage || '详见错误详情').slice(0, 120)))
            } else {
              resolve(job.detail || `${label}完成`)
            }
          }
        } catch {
          /* 单次轮询失败不中断，由超时兜底 */
        }
      }, 4000)
    })
  }

  async function runNow(uploadRemote: boolean): Promise<void> {
    busy.value = true
    try {
      const ack = await runBackup(uploadRemote)
      ElMessage.info('备份任务已受理，服务器后台执行中…')
      const detail = await waitForJob(ack.jobId, '备份')
      ElMessage.success(`备份完成：${detail.slice(0, 80)}`)
      await loadAll()
    } catch (e) {
      ElMessage.error(`备份失败：${String(e).slice(0, 100)}`)
    } finally {
      busy.value = false
    }
  }

  async function onPickFile(ev: Event): Promise<void> {
    const input = ev.target as HTMLInputElement
    const file = input.files?.[0]
    input.value = ''
    if (!file) return
    if (!(await confirmRestore(`上传备份包 ${file.name}`))) return
    busy.value = true
    try {
      const ack = await restoreBackupUpload(file)
      ElMessage.info('恢复任务已受理，服务器后台执行中（完成后会自动登出需重新登录）…')
      const detail = await waitForJob(ack.jobId, '恢复')
      ElMessage.success(detail.slice(0, 100))
      await loadAll()
    } catch (e) {
      ElMessage.error(`恢复失败：${String(e).slice(0, 100)}`)
    } finally {
      busy.value = false
    }
  }

  async function confirmRestore(name: string): Promise<boolean> {
    try {
      await ElMessageBox.prompt(
        `将用「${name}」全量覆盖当前数据，覆盖前自动做安全快照。恢复后需用备份内账号重新登录。请输入「恢复」确认执行。`,
        '危险操作确认',
        {
          type: 'warning',
          confirmButtonText: '执行恢复',
          cancelButtonText: '取消',
          inputPlaceholder: '输入：恢复',
          inputValidator: (v: string) => v.trim() === '恢复' || '请输入「恢复」以确认'
        }
      )
      return true
    } catch {
      return false
    }
  }

  async function onAction(row: BackupFileInfo, key: string): Promise<void> {
    const scopeArg = scope.value
    try {
      if (key === 'download') {
        await downloadBackupFile(scopeArg, row.name)
        return
      }
      if (key === 'delete') {
        await ElMessageBox.confirm(`确定删除备份「${row.name}」？删除后不可恢复。`, '删除确认', {
          type: 'warning'
        })
        await deleteBackupFile(scopeArg, row.name)
        ElMessage.success('已删除')
        if (scopeArg === 'local') {
          await loadAll()
        } else {
          await loadRemote()
        }
        return
      }
      if (key === 'verify') {
        busy.value = true
        ElMessage.info('校验任务已受理（远端包需先下载），完成后在结果中提示…')
        const ack = await verifyBackup(scopeArg, row.name)
        const detail = await waitForJob(ack.jobId, '校验')
        ElMessage.success(detail.slice(0, 100))
        return
      }
      if (key === 'restore') {
        if (!(await confirmRestore(row.name))) return
        busy.value = true
        const ack = await restoreBackup(scopeArg, row.name)
        ElMessage.info('恢复任务已受理，服务器后台执行中…')
        const detail = await waitForJob(ack.jobId, '恢复')
        ElMessage.success(detail.slice(0, 100))
        await loadAll()
        return
      }
    } catch (e) {
      ElMessage.error(`操作失败：${String(e).slice(0, 100)}`)
    } finally {
      busy.value = false
    }
  }

  onMounted(loadAll)
</script>
