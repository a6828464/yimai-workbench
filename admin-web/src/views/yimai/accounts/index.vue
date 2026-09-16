<template>
  <div class="p-4">
    <ElCard shadow="never">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">账号与角色</span>
          <div class="flex-c gap-2">
            <ElButton size="small" @click="openMapping">归属映射</ElButton>
            <ElButton type="primary" size="small" @click="openCreate">开通新账号</ElButton>
          </div>
        </div>
      </template>

      <ElTable :data="accounts" border stripe v-loading="loading">
        <ElTableColumn prop="userName" label="姓名" width="120" />
        <ElTableColumn prop="key" label="登录名" width="130">
          <template #default="{ row }">
            <span>{{ row.key }}</span>
            <ElTag v-if="row.self" size="small" effect="plain" class="ml-1">本人</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="角色" width="190">
          <template #default="{ row }">
            <ElTag
              v-for="r in row.roles"
              :key="r"
              size="small"
              :type="roleType(r)"
              effect="dark"
              class="mr-1"
            >
              {{ ROLE_OPTIONS[r] ?? r }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="门店范围" min-width="160">
          <template #default="{ row }">
            <ElTag v-for="v in row.venues" :key="v" size="small" effect="plain" class="mr-1">{{
              v
            }}</ElTag>
            <span v-if="!row.venues?.length" class="text-xs text-gray-400">双店</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="90">
          <template #default="{ row }">
            <ElTag size="small" :type="row.status === '启用' ? 'success' : 'info'">{{
              row.status
            }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="email" label="邮箱" min-width="180" />
        <ElTableColumn label="操作" width="300" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" :disabled="row.self" @click="openEdit(row)"
              >编辑</ElButton
            >
            <ElButton
              v-if="row.status === '启用'"
              link
              type="danger"
              size="small"
              :disabled="row.self"
              @click="doDisable(row)"
              >停用</ElButton
            >
            <ElButton v-else link type="success" size="small" @click="doEnable(row)">启用</ElButton>
            <ElButton
              link
              type="warning"
              size="small"
              :disabled="row.self"
              @click="doResetPassword(row)"
              >重置密码</ElButton
            >
            <ElButton link type="danger" size="small" :disabled="row.self" @click="doDelete(row)"
              >删除</ElButton
            >
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <!-- 新增账号 -->
    <ElDialog v-model="createDlg" title="开通新账号" width="460px" destroy-on-close>
      <ElForm label-width="92px">
        <ElFormItem label="姓名">
          <ElInput v-model="form.name" placeholder="真实姓名，用于展示与会籍归属" maxlength="20" />
        </ElFormItem>
        <ElFormItem label="登录名">
          <ElInput v-model="form.userName" placeholder="字母数字，将用于登录" />
        </ElFormItem>
        <ElFormItem label="角色">
          <ElCheckboxGroup v-model="form.roles">
            <ElCheckbox v-for="(label, code) in ROLE_OPTIONS" :key="code" :value="code">
              {{ label }}
            </ElCheckbox>
          </ElCheckboxGroup>
          <div class="text-xs text-gray-400">
            可多选：权限叠加，可见范围取并集（例如同时是服务老师 + 授课老师时，
            名下会籍会员与私教课学员都能看到；叠加店长则放大到全店）
          </div>
        </ElFormItem>
        <ElFormItem label="门店范围">
          <ElCheckboxGroup v-model="form.venues">
            <ElCheckbox value="绿地店">绿地店</ElCheckbox>
            <ElCheckbox value="东部店">东部店</ElCheckbox>
          </ElCheckboxGroup>
          <span v-if="!needsVenue(form.roles)" class="text-xs text-gray-400">
            仅超管/新媒体时默认为双店；勾选店长或老师侧角色后锁定单一门店
          </span>
        </ElFormItem>
        <ElFormItem label="初始化密码">
          <ElInput
            v-model="form.password"
            type="password"
            show-password
            placeholder="至少8位，首次登录后建议修改"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="createDlg = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="doCreate">开通</ElButton>
      </template>
    </ElDialog>

    <!-- 重置密码 -->
    <ElDialog
      v-model="pwdDlg.visible"
      :title="`重置密码 · ${pwdDlg.row?.userName ?? ''}`"
      width="400px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="新密码">
          <ElInput v-model="pwdForm.password" type="password" show-password placeholder="至少8位" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="pwdDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="doReset">确认重置</ElButton>
      </template>
    </ElDialog>

    <!--
      归属映射

      业务表的归属列（service_teacher / owner / consultant / teacher_name / created_by）
      存的是姓名字符串，不是外键。所以「谁能看到这条数据」取决于姓名能不能对上账号：
      账号改过名、随心瑜登记的是另一个姓名、历史数据写过昵称 —— 都会让数据"悬空"。
      这里把对不上的姓名映射到账号，映射完归属立刻恢复。
    -->
    <ElDialog v-model="mapDlg.visible" title="人员归属映射" width="620px" destroy-on-close>
      <ElAlert v-if="mapDlg.unmapped.length" type="warning" :closable="false" class="mb-3">
        有 {{ mapDlg.unmapped.length }} 个姓名出现在业务数据里、但对不上任何账号。
        这些数据的归属是悬空的（本人看不到），把它们映射到账号即可恢复。
      </ElAlert>
      <ElAlert v-else type="success" :closable="false" class="mb-3">
        当前所有归属姓名都能对上账号。
      </ElAlert>

      <ElTable v-if="mapDlg.unmapped.length" :data="mapDlg.unmapped" border size="small">
        <ElTableColumn prop="name" label="未映射的姓名" width="150" />
        <ElTableColumn label="出现在" min-width="200">
          <template #default="{ row }">
            <ElTag v-for="(c, k) in row.counts" :key="k" size="small" class="mr-1 mb-1">
              {{ k }} × {{ c }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="映射到账号" width="176">
          <template #default="{ row }">
            <ElSelect
              v-model="mapDlg.picks[row.name]"
              placeholder="选择账号"
              size="small"
              class="!w-full"
            >
              <ElOption
                v-for="a in mapDlg.accounts"
                :key="a.key"
                :label="`${a.name}（${a.key}）`"
                :value="a.key"
              />
            </ElSelect>
          </template>
        </ElTableColumn>
      </ElTable>

      <div v-if="mapDlg.staleIds.length" class="mt-4">
        <div class="mb-1 text-sm font-500">待清理：归属 id 指向已不存在的账号</div>
        <div class="text-xs text-gray-400 mb-2">
          账号删掉重建后会留下这些行。本人仍能通过姓名看到数据，但建议清掉 id
          以免将来被两人同时认领。
        </div>
        <ElTag
          v-for="(s, i) in mapDlg.staleIds"
          :key="`${s.table}-${s.column}-${i}`"
          size="small"
          type="warning"
          class="mr-1 mb-1"
        >
          {{ s.table }}.{{ s.column }} → 账号 {{ s.user_id }}（{{ s.rows }} 行）
        </ElTag>
      </div>

      <div class="mt-4">
        <div class="mb-2 text-sm font-500">各账号的别名</div>
        <div class="text-xs text-gray-400 mb-2">
          一个名字只能属于一个账号；填写后点保存生效。规范姓名不用填，系统始终认它。
        </div>
        <div v-for="a in mapDlg.accounts" :key="a.key" class="mb-2 flex items-center gap-2">
          <span class="w-36 shrink-0 text-sm">{{ a.name }}（{{ a.key }}）</span>
          <ElSelect
            v-model="mapDlg.aliases[a.key]"
            multiple
            filterable
            allow-create
            default-first-option
            size="small"
            class="!w-full"
            placeholder="该账号在业务数据里出现过的其它姓名"
          />
        </div>
      </div>

      <template #footer>
        <ElButton @click="mapDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="mapDlg.saving" @click="saveMapping">保存</ElButton>
      </template>
    </ElDialog>

    <!-- 编辑账号（角色 / 门店范围） -->
    <ElDialog
      v-model="editDlg.visible"
      :title="`编辑账号 · ${editDlg.row?.userName ?? ''}`"
      width="460px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="角色">
          <ElCheckboxGroup v-model="editForm.roles">
            <ElCheckbox v-for="(label, code) in ROLE_OPTIONS" :key="code" :value="code">
              {{ label }}
            </ElCheckbox>
          </ElCheckboxGroup>
          <div class="text-xs text-gray-400">
            调整角色会立即失效该账号的登录状态，需要重新登录后生效
          </div>
        </ElFormItem>
        <ElFormItem label="门店范围">
          <ElCheckboxGroup v-model="editForm.venues">
            <ElCheckbox value="绿地店">绿地店</ElCheckbox>
            <ElCheckbox value="东部店">东部店</ElCheckbox>
          </ElCheckboxGroup>
          <span v-if="!needsVenue(editForm.roles)" class="text-xs text-gray-400">
            仅超管/新媒体时默认为双店；勾选店长或老师侧角色后锁定单一门店
          </span>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="editDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="doEdit">保存</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import {
    createAccount,
    getStaffMapping,
    listAccounts,
    saveStaffAliases,
    updateAccount
  } from '@/api/auth'
  import type { AccountRow } from '@/api/auth'
  import { ElMessage, ElMessageBox, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiAccounts' })

  const ROLE_OPTIONS: Record<string, string> = {
    R_MANAGER: '店长',
    R_SERVICE: '服务老师',
    R_TEACHER: '授课老师',
    R_MEDIA: '新媒体',
    R_SUPER: '超管'
  }

  const loading = ref(false)
  const saving = ref(false)
  const accounts = ref<AccountRow[]>([])

  // ---------- 归属映射 ----------
  const mapDlg = reactive({
    visible: false,
    saving: false,
    accounts: [] as { key: string; name: string; aliases: string[] }[],
    unmapped: [] as { name: string; counts: Record<string, number> }[],
    staleIds: [] as { table: string; column: string; user_id: number; rows: number }[],
    aliases: {} as Record<string, string[]>,
    // 「未映射的姓名 → 要映射到哪个账号」，保存时并入该账号的别名
    picks: {} as Record<string, string>
  })

  function openMapping() {
    mapDlg.visible = true
    void loadMapping()
  }

  async function loadMapping() {
    try {
      const d = await getStaffMapping()
      mapDlg.accounts = d.accounts
      mapDlg.unmapped = d.unmapped
      mapDlg.staleIds = d.staleIds
      mapDlg.aliases = Object.fromEntries(d.accounts.map((a) => [a.key, [...a.aliases]]))
      mapDlg.picks = {}
    } catch (e) {
      ElMessage.error(e instanceof Error ? e.message : '归属映射加载失败')
    }
  }

  async function saveMapping() {
    mapDlg.saving = true
    try {
      // 先把「未映射 → 账号」的选择并进该账号的别名，再整体保存
      for (const u of mapDlg.unmapped) {
        const key = mapDlg.picks[u.name]
        if (!key) continue
        const list = mapDlg.aliases[key] ?? []
        if (!list.includes(u.name)) mapDlg.aliases[key] = [...list, u.name]
      }
      for (const a of mapDlg.accounts) {
        await saveStaffAliases(a.key, mapDlg.aliases[a.key] ?? [])
      }
      ElMessage.success('已保存，归属立即生效')
      await loadMapping()
      await load()
    } catch (e) {
      ElMessage.error(e instanceof Error ? e.message : '保存失败')
    } finally {
      mapDlg.saving = false
    }
  }

  /** 需要锁定单一门店的角色（与后端 VENUE_BOUND 一致） */
  const VENUE_BOUND = ['R_MANAGER', 'R_SERVICE', 'R_TEACHER']
  function needsVenue(roles: string[]): boolean {
    return roles.some((r) => VENUE_BOUND.includes(r))
  }

  const createDlg = ref(false)
  const form = reactive({
    name: '',
    userName: '',
    roles: ['R_TEACHER'] as string[],
    venues: ['绿地店'] as string[],
    password: ''
  })

  const pwdDlg = reactive<{ visible: boolean; row: AccountRow | null }>({
    visible: false,
    row: null
  })
  const pwdForm = reactive({ password: '' })

  const editDlg = reactive<{ visible: boolean; row: AccountRow | null }>({
    visible: false,
    row: null
  })
  const editForm = reactive({ roles: ['R_TEACHER'] as string[], venues: ['绿地店'] as string[] })

  function roleType(code: string): 'warning' | 'success' | 'primary' | 'info' | 'danger' {
    if (code === 'R_SUPER') return 'warning'
    if (code === 'R_MANAGER') return 'primary'
    if (code === 'R_SERVICE') return 'success'
    if (code === 'R_TEACHER') return 'danger'
    return 'info'
  }

  async function load() {
    loading.value = true
    try {
      accounts.value = await listAccounts()
    } finally {
      loading.value = false
    }
  }

  function openCreate() {
    Object.assign(form, {
      name: '',
      userName: '',
      roles: ['R_TEACHER'],
      venues: ['绿地店'],
      password: ''
    })
    createDlg.value = true
  }

  async function doCreate() {
    if (!form.userName.trim()) return ElMessage.warning('请填写登录名')
    if (!/^[A-Za-z0-9]+$/.test(form.userName)) return ElMessage.warning('登录名只能包含字母和数字')
    if (form.password.length < 8) return ElMessage.warning('密码至少8位')
    if (!form.roles.length) return ElMessage.warning('请至少选择一个角色')
    const venues = needsVenue(form.roles) ? [...form.venues] : ['绿地店', '东部店']
    if (!venues.length) return ElMessage.warning('请至少选择一个门店')
    saving.value = true
    try {
      await createAccount({
        userName: form.userName.trim(),
        name: form.name.trim() || form.userName.trim(),
        roles: [...form.roles],
        venues,
        password: form.password
      })
      ElMessage.success('账号已开通')
      createDlg.value = false
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      saving.value = false
    }
  }

  function openEdit(row: AccountRow) {
    editDlg.row = row
    editForm.roles = [...(row.roles?.length ? row.roles : [row.roleCode])]
    editForm.venues = [...(row.venues?.length ? row.venues : ['绿地店', '东部店'])]
    editDlg.visible = true
  }

  async function doEdit() {
    if (!editDlg.row) return
    if (!editForm.roles.length) return ElMessage.warning('请至少选择一个角色')
    const venues = needsVenue(editForm.roles) ? [...editForm.venues] : ['绿地店', '东部店']
    if (!venues.length) return ElMessage.warning('请至少选择一个门店')
    saving.value = true
    try {
      await updateAccount(editDlg.row.key, 'update', { roles: [...editForm.roles], venues })
      ElMessage.success('账号信息已更新')
      editDlg.visible = false
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      saving.value = false
    }
  }

  async function doDisable(row: AccountRow) {
    await ElMessageBox.confirm(
      `确定停用「${row.userName}」？停用后该账号无法再登录。`,
      '停用账号',
      { type: 'warning' }
    )
    try {
      await updateAccount(row.key, 'disable')
      ElMessage.success('已停用')
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    }
  }

  async function doEnable(row: AccountRow) {
    try {
      await updateAccount(row.key, 'enable')
      ElMessage.success('已启用')
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    }
  }

  async function doDelete(row: AccountRow) {
    await ElMessageBox.confirm(
      `确定删除账号「${row.userName}」（登录名 ${row.key}）？删除后无法恢复。`,
      '删除账号',
      { type: 'error' }
    )
    try {
      await updateAccount(row.key, 'delete')
      ElMessage.success('已删除')
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    }
  }

  function doResetPassword(row: AccountRow) {
    pwdDlg.row = row
    pwdForm.password = ''
    pwdDlg.visible = true
  }

  async function doReset() {
    if (!pwdDlg.row) return
    if (pwdForm.password.length < 8) return ElMessage.warning('密码至少8位')
    saving.value = true
    try {
      await updateAccount(pwdDlg.row.key, 'resetPassword', { password: pwdForm.password })
      ElMessage.success('密码已重置')
      pwdDlg.visible = false
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      saving.value = false
    }
  }

  onMounted(load)
</script>
