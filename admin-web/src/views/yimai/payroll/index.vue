<!--
  薪酬计算（仅超管）

  用户原话：「经营看板里面给我加一个薪酬计算的栏目，它能够获取系统每位老师的课时数，
  还要给我一个老师课时费的设置选项，包括老师的身份标签……这个仅限超管可见；
  这个页面设计出来的话，就直接把手机端的也适配一下，卡片展示」

  ## 权限

  三层，缺一不可：
  1. 路由 `meta.roles = SUPER`（`router/modules/yimai.ts` 的数据中心组）—— 非超管看不到菜单；
  2. 本页 `v-if="isSuper"` —— 直接敲 URL 时给出明确说明而不是空白页；
  3. 后端 11 个端点各自 `requireSuper` —— **这才是硬闸门**，前端隐藏菜单不算权限控制。
     所以 403 要能被识别并显示成「仅超管可用」，而不是笼统的「加载失败」。

  ## 口径来源

  所有枚举 / 档位 / 三态语义一律来自后端 `GET /payroll/roles`，本页与其子组件**不写死
  任何一份** —— 写两份就会出现第二套口径（规格 §7.4 明令）。
-->
<template>
  <div class="payroll-page list-page list-page--fill">
    <!-- 非超管（直接敲 URL 进来）：说清楚原因，不留白屏 -->
    <ElCard v-if="!isSuper" shadow="never">
      <ElAlert
        type="error"
        show-icon
        :closable="false"
        title="仅超管可访问"
        description="薪酬计算包含全员底薪、课时费与工资明细，仅限超级管理员查看。如需权限请联系超管。"
      />
    </ElCard>

    <template v-else>
      <ElCard shadow="never" class="mb-3">
        <div class="filter-bar">
          <ElDatePicker
            v-model="month"
            type="month"
            value-format="YYYY-MM"
            placeholder="选择月份"
            class="f-lg"
            :clearable="false"
          />
          <ElSelect v-model="venue" placeholder="全部门店（两店合并）" clearable class="f-lg">
            <ElOption v-for="v in venues" :key="v" :label="v" :value="v" />
          </ElSelect>
          <ElButton @click="reloadAll">刷新</ElButton>
          <div class="filter-bar__spacer" />
          <span class="text-xs text-gray-400">
            默认当月；门店留空 = 两店合并（社保仍按各自门店回退，不跨店）
          </span>
        </div>

        <!-- 双源核验摘要：常驻筛选栏下方，切到别的 tab 也看得到「两源差了几节」。
             数据由「老师课时数」面板上抛（同一份响应，不重复请求）。 -->
        <div v-if="hoursSource" class="source-bar">
          <ElTag size="small" effect="plain" :type="hoursSourceTagType">
            {{ hoursSourceTagText }}
          </ElTag>
          <span class="text-xs text-gray-500">
            预约记录 {{ hoursSource.bookingSessions }} 课次 · 课时记录
            {{ hoursSource.available ? hoursSource.courseRecordSessions + ' 节' : '不可用' }}
          </span>
        </div>

        <!-- 权限/加载错误：403 单独说清，不混成「加载失败」 -->
        <ElAlert
          v-if="errorMsg"
          :type="errorIsForbidden ? 'warning' : 'error'"
          show-icon
          :closable="false"
          :title="errorMsg"
        />
        <ElAlert
          v-if="successMsg"
          type="success"
          show-icon
          :closable="true"
          class="mt-2"
          :title="successMsg"
          @close="successMsg = ''"
        />
      </ElCard>

      <ElCard shadow="never">
        <ElTabs v-model="activeTab" class="payroll-tabs">
          <ElTabPane name="hours" label="老师课时数" />
          <ElTabPane name="profiles" label="课时与业绩" />
          <ElTabPane name="teachers" label="人员档案" />
          <ElTabPane name="performance" label="业绩表导入" />
          <ElTabPane name="inputs" label="考勤 / 社保 / 个税" />
          <ElTabPane name="calc" label="薪酬计算" />
        </ElTabs>

        <!-- keep-alive 关掉了（路由 keepAlive:false），这里用 v-if 保证切回 tab 时重新拉数据，
             避免看到别的月份/门店的旧数据 -->
        <PayrollHoursPanel
          v-if="activeTab === 'hours'"
          :month="month"
          :venue="venue"
          @error="onError"
          @source="onHoursSource"
        />
        <PayrollProfilesPanel
          v-else-if="activeTab === 'profiles'"
          :venue="venue"
          :catalog="catalog"
          @error="onError"
          @success="onSuccess"
        />
        <!-- 人员档案（原「门店老师一览」，v3.3.6 改名并聚焦基本信息）：
             各门店老师的基本信息 —— 身份信息 / 银行卡信息 / 归属门店 / 课时费。
             课时节数不再在此展示（与「老师课时数」「课时与业绩」重复）。 -->
        <PayrollTeachersPanel
          v-else-if="activeTab === 'teachers'"
          :month="month"
          :venue="venue"
          :catalog="catalog"
          @error="onError"
          @go-profiles="activeTab = 'profiles'"
        />
        <PayrollPerformancePanel
          v-else-if="activeTab === 'performance'"
          :month="month"
          :venues="venues"
          :initial-venue="venue"
          @error="onError"
          @success="onSuccess"
        />
        <PayrollInputsPanel
          v-else-if="activeTab === 'inputs'"
          :month="month"
          :venue="venue"
          :social-modes="catalog?.socialSecurityModes ?? []"
          @error="onError"
          @success="onSuccess"
        />
        <PayrollCalculatePanel
          v-else-if="activeTab === 'calc'"
          :month="month"
          :venue="venue"
          @error="onError"
        />
      </ElCard>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue'
  import { fetchPayrollRoles, payrollErrorMessage, type PayrollRolesCatalog } from '@/api/payroll'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import PayrollHoursPanel from './modules/hours-panel.vue'
  import PayrollProfilesPanel from './modules/profiles-panel.vue'
  import PayrollTeachersPanel from './modules/teachers-panel.vue'
  import PayrollPerformancePanel from './modules/performance-panel.vue'
  import PayrollInputsPanel from './modules/inputs-panel.vue'
  import PayrollCalculatePanel from './modules/calc-panel.vue'
  import { currentMonth } from './modules/shared'

  defineOptions({ name: 'YimaiPayroll' })

  const userStore = useUserStore()
  const { isHandheld } = useDevice()

  /** 超管判定与路由 `meta.roles` 同源（都看 R_SUPER），不另立一套角色规则 */
  const isSuper = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_SUPER'))

  const month = ref(currentMonth())
  const venue = ref<string | null>(null)
  const activeTab = ref('hours')
  const catalog = ref<PayrollRolesCatalog | null>(null)
  const errorMsg = ref('')
  const successMsg = ref('')
  const errorIsForbidden = ref(false)

  /** 双源核验摘要（由「老师课时数」面板上抛，页面级常驻展示差额） */
  const hoursSource = ref<{
    bookingSessions: number
    courseRecordSessions: number
    diff: number
    available: boolean
    label: string
  } | null>(null)

  function onHoursSource(s: typeof hoursSource.value) {
    hoursSource.value = s
  }

  const hoursSourceTagType = computed<'success' | 'warning' | 'danger'>(() => {
    const s = hoursSource.value
    if (!s) return 'success'
    if (!s.available) return 'danger'
    return s.diff === 0 ? 'success' : 'warning'
  })

  const hoursSourceTagText = computed(() => {
    const s = hoursSource.value
    if (!s) return ''
    if (!s.available) return '课时记录源不可用（差额无法计算）'
    if (s.diff === 0) return '课时两源一致'
    return `课时两源差 ${s.diff > 0 ? '+' : ''}${s.diff} 节`
  })

  const venues = computed(() => catalog.value?.venues ?? [])

  function onError(msg: string) {
    errorMsg.value = msg
    // 403 是后端硬闸门；单独提示，避免被当成「页面坏了」
    errorIsForbidden.value = /无权限|仅超管/.test(msg)
    successMsg.value = ''
  }

  function onSuccess(msg: string) {
    successMsg.value = msg
    errorMsg.value = ''
  }

  async function loadCatalog() {
    try {
      catalog.value = await fetchPayrollRoles()
      errorMsg.value = ''
    } catch (e) {
      errorMsg.value = payrollErrorMessage(e, '薪酬规则加载失败')
      errorIsForbidden.value = /无权限|仅超管/.test(errorMsg.value)
    }
  }

  /** 月份 / 门店变化时，子组件靠 props watch 自行重拉；这里只负责清掉上一轮提示 */
  function reloadAll() {
    errorMsg.value = ''
    successMsg.value = ''
    // 月份/门店变了，上一轮的双源摘要已失效，先清掉避免显示旧差额
    hoursSource.value = null
    void loadCatalog()
  }

  onMounted(loadCatalog)
</script>

<style scoped lang="scss">
  .source-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    padding: 6px 10px;
    background: var(--art-gray-100);
    border-radius: 4px;
  }

  .payroll-tabs {
    // Tabs 头部在手机上要能横向滚动，否则 6 个标签会把卡片撑出视口
    :deep(.el-tabs__nav-wrap) {
      overflow-x: auto;
    }

    :deep(.el-tabs__nav-scroll) {
      overflow-x: auto;
    }

    :deep(.el-tabs__nav) {
      flex-wrap: nowrap;
    }
  }

  @media (max-width: 768px) {
    .payroll-tabs {
      // 手机端标签本身也要够高才点得中（44px 由全局兜底保证高度，这里只管不换行）
      :deep(.el-tabs__item) {
        padding: 0 12px;
        white-space: nowrap;
      }
    }
  }
</style>
