<template>
  <div class="p-4">
    <ElCard shadow="never">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">账号与角色</span>
          <span class="text-xs text-gray-400">阶段1接入后端后支持新增/停用/重置密码，全部操作写入审计日志</span>
        </div>
      </template>

      <ElTable :data="accounts" border stripe v-loading="loading">
        <ElTableColumn prop="userName" label="姓名" width="120" />
        <ElTableColumn prop="key" label="登录名" width="120" />
        <ElTableColumn label="角色" width="100">
          <template #default="{ row }">
            <ElTag size="small" :type="roleType(row.roleCode)" effect="dark">{{ row.roleLabel }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="门店范围" min-width="160">
          <template #default="{ row }">
            <ElTag v-for="v in row.venues" :key="v" size="small" effect="plain" class="mr-1">{{ v }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="90">
          <template #default>
            <ElTag size="small" type="success">启用</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="email" label="邮箱" min-width="180" />
        <ElTableColumn label="操作" width="150" fixed="right">
          <template #default>
            <ElButton link type="primary" size="small" disabled>编辑</ElButton>
            <ElButton link type="danger" size="small" disabled>停用</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { listAccounts } from '@/api/auth'
  import type { AccountRow } from '@/api/auth'
  import { ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiAccounts' })

  const loading = ref(false)
  const accounts = ref<AccountRow[]>([])

  function roleType(code: string): 'warning' | 'success' | 'primary' | 'info' {
    if (code === 'R_SUPER') return 'warning'
    if (code === 'R_MANAGER') return 'primary'
    if (code === 'R_TEACHER') return 'success'
    return 'info'
  }

  onMounted(async () => {
    loading.value = true
    try {
      accounts.value = await listAccounts()
    } finally {
      loading.value = false
    }
  })
</script>
