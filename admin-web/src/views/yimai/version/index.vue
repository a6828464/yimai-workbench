<template>
  <div class="mx-auto pt-5 mb-5 space-y-5">
    <!-- 当前版本 -->
    <div class="art-card-sm rounded-lg p-6 max-md:p-4">
      <div class="flex-cb gap-3 flex-wrap mb-4">
        <h3 class="text-lg font-medium text-g-900 flex-c gap-2">
          <i class="ri-install-line text-theme text-xl" />
           版本与发布状态
        </h3>
        <ElButton type="primary" plain size="small" :loading="checking" @click="checkUpdate">
          <i class="ri-refresh-line mr-1" />
          检查更新
        </ElButton>
      </div>

      <ElSkeleton v-if="loading && !version" :rows="3" animated />
      <template v-else-if="version">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
          <div class="flex-c gap-2">
            <span class="text-g-500 w-20 shrink-0">当前分支</span>
            <ElTag size="small" effect="plain">{{ version.local.branch || '-' }}</ElTag>
          </div>
          <div class="flex-c gap-2">
            <span class="text-g-500 w-20 shrink-0">当前提交</span>
            <span class="font-mono">{{ short(version.local.commit) }}</span>
          </div>
          <div class="flex-c gap-2">
            <span class="text-g-500 w-20 shrink-0">提交时间</span>
            <span>{{ version.local.date || '-' }}</span>
          </div>
          <div class="flex-c gap-2">
            <span class="text-g-500 w-20 shrink-0">远端状态</span>
            <ElTag v-if="remoteError" type="danger" size="small">远端不可达</ElTag>
            <ElTag v-else-if="upToDate" type="success" size="small">已是最新</ElTag>
            <ElTag v-else type="warning" size="small">有可用更新</ElTag>
          </div>
        </div>

        <div class="mt-3 p-3 bg-g-300/50 rounded text-sm text-g-700">
          {{ version.local.message }}
        </div>

         <!-- 有更新时仅允许服务器预置的受控脚本执行更新 -->
        <ElAlert
          v-if="!upToDate && !remoteError"
          type="warning"
          :closable="false"
          class="mt-4"
        >
           <template #title>远端存在新提交 {{ short(version.remote.commit) }}</template>
           <template #default>
             <div class="flex-cb gap-3 flex-wrap">
               <span>可由服务器上的受控更新脚本完成更新，更新期间服务可能短暂重启。</span>
               <ElButton type="warning" size="small" :loading="updating" @click="applyUpdate">立即更新</ElButton>
             </div>
           </template>
        </ElAlert>

        <ElAlert v-if="remoteError" type="error" :closable="false" class="mt-4">
          <template #title>无法连接远端仓库：{{ remoteError }}</template>
        </ElAlert>

        <ElAlert v-if="updateError" type="error" :closable="false" class="mt-4">
          <template #title>{{ updateError }}</template>
          <template #default v-if="updateOutput.length">
            <div class="mt-2 max-h-60 overflow-auto rounded bg-black/70 p-3 font-mono text-xs text-green-300 leading-6">
              <div v-for="(line, i) in updateOutput" :key="i">{{ line }}</div>
            </div>
          </template>
        </ElAlert>

      </template>
    </div>

    <!-- 更新日志 -->
    <div class="art-card-sm rounded-lg p-6 max-md:p-4">
      <h3 class="text-lg font-medium text-g-900 flex-c gap-2 mb-4">
        <i class="ri-history-line text-theme text-xl" />
        更新日志
      </h3>
      <ElSkeleton v-if="loading" :rows="6" animated />
      <div v-else-if="changelogHtml" class="changelog-body" v-html="changelogHtml" />
      <ElAlert v-else-if="changelogError" type="warning" :closable="false" :title="changelogError" />
      <ElEmpty v-else description="暂无 CHANGELOG.md" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { apiGet, apiPost, USE_BACKEND } from '@/api/backend'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiVersion' })

  interface VersionInfo {
    local: { branch: string; commit: string; message: string; date: string }
    remote: { commit: string; error: string }
    upToDate: boolean
  }
  const loading = ref(true)
  const checking = ref(false)
  const updating = ref(false)
  const version = ref<VersionInfo | null>(null)
  const changelogHtml = ref('')
  const changelogError = ref('')
  const updateError = ref('')
  const updateOutput = ref<string[]>([])

  const remoteError = computed(() => version.value?.remote.error || '')
  const upToDate = computed(() => version.value?.upToDate ?? true)

  function short(sha?: string): string {
    return sha ? sha.slice(0, 7) : '-'
  }

  async function loadVersion() {
    version.value = await apiGet<VersionInfo>('/system/version')
  }

  async function loadChangelog() {
    try {
      const d = await apiGet<{ content: string }>('/system/changelog')
      changelogHtml.value = renderMarkdown(d.content || '')
    } catch {
      changelogHtml.value = ''
      changelogError.value = '更新日志暂时无法读取，请稍后重试'
    }
  }

  async function checkUpdate() {
    checking.value = true
    try {
      await loadVersion()
      if (!remoteError.value) {
        ElMessage.success(upToDate.value ? '已是最新版本' : '发现新版本')
      }
    } catch (e) {
      ElMessage.error(`检查失败：${String(e).slice(0, 80)}`)
    } finally {
      checking.value = false
    }
  }

  async function applyUpdate() {
    updating.value = true
    updateError.value = ''
    updateOutput.value = []
    try {
      const r = await apiPost<{ updated: boolean; message?: string; output?: string[] }>('/system/update', {})
      if (r.output?.length) updateOutput.value = r.output
      ElMessage.success(r.message || (r.updated ? '更新已执行，请稍候刷新页面' : '当前已是最新版本'))
      if (r.updated) setTimeout(() => window.location.reload(), 2500)
    } catch (e) {
      const anyE = e as { response?: { data?: { message?: string; output?: string[] } }; message?: string }
      updateError.value = anyE.response?.data?.message || anyE.message || '更新失败'
      if (anyE.response?.data?.output?.length) updateOutput.value = anyE.response.data.output
      ElMessage.error(`更新失败：${updateError.value}`)
    } finally {
      updating.value = false
    }
  }

  /** 极简 Markdown 渲染：仅覆盖 CHANGELOG.md 用到的语法（#/## 标题、列表、段落） */
  function renderMarkdown(md: string): string {
    const esc = (s: string) =>
      s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const inline = (s: string) =>
      esc(s)
        .replace(/`([^`]+)`/g, '<code class="px-1 rounded bg-g-300/60 text-xs">$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')

    const out: string[] = []
    let inList = false
    const closeList = () => {
      if (inList) {
        out.push('</ul>')
        inList = false
      }
    }

    for (const raw of md.split('\n')) {
      const line = raw.trimEnd()
      if (line.startsWith('## ')) {
        closeList()
        out.push(
          `<div class="mt-5 mb-2 pb-2 border-b border-solid border-g-200"><span class="inline-block px-2.5 py-1 bg-theme/10 text-theme text-sm font-medium rounded-full">${inline(line.slice(3))}</span></div>`
        )
      } else if (line.startsWith('# ')) {
        closeList()
      } else if (line.startsWith('- ')) {
        if (!inList) {
          out.push('<ul class="space-y-1.5 mb-3">')
          inList = true
        }
        out.push(`<li class="flex-c gap-2 text-sm text-g-600"><span class="mt-0.5 shrink-0">•</span><span>${inline(line.slice(2))}</span></li>`)
      } else if (line.trim() === '') {
        closeList()
      } else {
        closeList()
        out.push(`<p class="text-sm text-g-600 my-1">${inline(line)}</p>`)
      }
    }
    closeList()

    return out.join('')
  }

  onMounted(async () => {
    if (!USE_BACKEND) {
      loading.value = false
      return
    }
    const results = await Promise.allSettled([loadVersion(), loadChangelog()])
    if (results.some((result) => result.status === 'rejected')) ElMessage.warning('部分版本信息暂时不可用')
    loading.value = false
  })
</script>

<style scoped>
  .changelog-body :deep(code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }
</style>
