<!--
  门店老师一览（`GET /payroll/profiles` + `GET /payroll/hours` 前端合并）

  用户原话：「重新建立一列：按两家门店区分，明确哪家店有哪些老师，填入老师们的
  身份属性以及上了哪些课。用户后续自己改。」

  ## 数据合并口径

  - 档案侧 `fetchPayrollProfiles()`：venue / role / 各课型单价（一人一行）。
  - 课时侧 `fetchPayrollHours(month)`：当月各课型节数（private60/private45/small/group/
    enterprise）。hours 行的 `name` 是**档案姓名**（后端已走姓名解析，含别名归并），
    `profileId` 与档案 id 对齐 —— 所以合并键用 profileId，名字只做展示。
  - **月份无课时的老师也要显示（节数 0）**：以档案侧为主表做左连接，不能反过来
    （否则「本月没上课的人」会从名单里消失，用户点名要看「哪家店有哪些老师」）。
  - **未建档老师**（hours 行 profileId=null）：单独一节显示，不混进门店分组 ——
    他们没有 venue/role/单价，硬塞进门店列会造出假归属。

  ## 分组

  门店只有绿地店 / 东部店（PayrollRoles::VENUES，经 catalog.venues 下发）。
  按档案 venue 分组展示；顶层筛选栏选了单店时只显示该店。

  ⚠️ 枚举一律来自 `catalog.roles`（props 传入），本文件不写死任何一份。
-->
<template>
  <div class="teachers">
    <!-- 按门店分组：每店一节，门店标题行带小计 -->
    <ElCard v-for="group in venueGroups" :key="group.venue" shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">
            {{ group.venue }}
            <span class="text-xs text-gray-400">（{{ group.rows.length }} 人）</span>
          </span>
          <span class="text-xs text-gray-400">
            本月合计 {{ group.totalHours }} 节 · 私教 {{ group.privateHours }} 节
          </span>
        </div>
      </template>

      <!-- 桌面端：表格 -->
      <ElTable v-if="!isHandheld" v-loading="loading" :data="group.rows" border stripe size="small">
        <ElTableColumn prop="name" label="姓名" width="100" fixed="left">
          <template #default="{ row }">
            {{ row.name }}
            <ElTag v-if="row.pendingReview" size="small" type="danger" effect="plain" class="ml-1">
              待完善
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="身份标签" width="130">
          <template #default="{ row }">
            <ElTag v-if="row.roleLabel" size="small" effect="plain">{{ row.roleLabel }}</ElTag>
            <ElTag v-else size="small" type="danger" effect="plain">未设置</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="私教60" width="80" align="right">
          <template #default="{ row }">{{ row.hours.private60 }}</template>
        </ElTableColumn>
        <ElTableColumn label="私教45" width="80" align="right">
          <template #default="{ row }">{{ row.hours.private45 }}</template>
        </ElTableColumn>
        <ElTableColumn label="小班" width="70" align="right">
          <template #default="{ row }">{{ row.hours.small }}</template>
        </ElTableColumn>
        <ElTableColumn label="团课" width="70" align="right">
          <template #default="{ row }">{{ row.hours.group }}</template>
        </ElTableColumn>
        <ElTableColumn label="企业课" width="80" align="right">
          <template #default="{ row }">{{ row.hours.enterprise }}</template>
        </ElTableColumn>
        <ElTableColumn label="合计节数" width="90" align="right">
          <template #default="{ row }">
            <b>{{ row.totalHours }}</b>
          </template>
        </ElTableColumn>
        <ElTableColumn label="私教60/45 单价" min-width="150">
          <template #default="{ row }">
            {{ money(row.feePrivate60) }} / {{ money(row.feePrivate45Effective) }}
            <span v-if="row.feePrivate45Derived" class="text-warning fee-derived">折算</span>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表（门店在分组标题，卡片 subtitle 放身份标签） -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="row in group.rows"
          :key="row.profileId"
          :title="row.name"
          :subtitle="row.roleLabel || '身份标签未设置'"
          :tags="cardTags(row)"
          :metrics="cardMetrics(row)"
          :note="cardNote(row)"
        />
        <div v-if="!loading && !group.rows.length" class="m-card-list__empty"
          >该门店暂无老师档案</div
        >
      </div>
    </ElCard>

    <!-- 未建档老师：有课时记录但没对上档案（profileId=null），单独一节 -->
    <ElCard v-if="unmatched.length" shadow="never" class="mb-3 unmatched-card">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">
            未建档老师
            <span class="text-xs text-gray-400">（{{ unmatched.length }} 人）</span>
          </span>
          <ElTag size="small" type="warning" effect="plain">有课时，无薪酬档案</ElTag>
        </div>
      </template>
      <div class="text-xs text-gray-500 mb-2">
        这些老师本月有签到课次，但没匹配到薪酬档案（身份属性与单价未知，课时费无法计算）。
        请到「课时费与身份标签」里补建档或补别名。
      </div>

      <ElTable v-if="!isHandheld" :data="unmatched" border stripe size="small">
        <ElTableColumn prop="name" label="姓名" width="120" />
        <ElTableColumn label="出现过的名字" min-width="180">
          <template #default="{ row }">{{ row.sourceNames?.join('、') || row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="私教60" width="80" align="right">
          <template #default="{ row }">{{ row.hours.private60 }}</template>
        </ElTableColumn>
        <ElTableColumn label="私教45" width="80" align="right">
          <template #default="{ row }">{{ row.hours.private45 }}</template>
        </ElTableColumn>
        <ElTableColumn label="小班" width="70" align="right">
          <template #default="{ row }">{{ row.hours.small }}</template>
        </ElTableColumn>
        <ElTableColumn label="团课" width="70" align="right">
          <template #default="{ row }">{{ row.hours.group }}</template>
        </ElTableColumn>
        <ElTableColumn label="企业课" width="80" align="right">
          <template #default="{ row }">{{ row.hours.enterprise }}</template>
        </ElTableColumn>
        <ElTableColumn label="合计节数" width="90" align="right">
          <template #default="{ row }"
            ><b>{{ row.totalHours }}</b></template
          >
        </ElTableColumn>
      </ElTable>

      <div v-if="isHandheld" class="m-card-list">
        <MobileCard
          v-for="row in unmatched"
          :key="row.name"
          :title="row.name"
          :subtitle="row.sourceNames?.join('、') || row.name"
          :tags="[{ text: '未建档', type: 'warning', effect: 'dark' }]"
          :metrics="cardMetrics(row)"
        />
      </div>
    </ElCard>

    <!-- 空态：档案一个都没有（比如门店筛选下无档案） -->
    <ElCard v-if="!venueGroups.length && !unmatched.length" shadow="never">
      <ElEmpty description="没有符合条件的老师档案" :image-size="80" />
    </ElCard>

    <!-- 口径说明（底部小字） -->
    <div class="text-xs text-gray-400 foot-note">
      课时为所选月份（{{ month }}）的签到课次；身份属性来自人员主档导入； 私教 45
      单价显示生效值（档案未单独设置时按 60×{{ fee45Factor }} 折算）。
    </div>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import {
    fetchPayrollHours,
    fetchPayrollProfiles,
    payrollErrorMessage,
    type PayrollHoursRow,
    type PayrollProfileRow,
    type PayrollRolesCatalog
  } from '@/api/payroll'
  import { useDevice } from '@/hooks/core/useDevice'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'
  import { money } from './shared'

  defineOptions({ name: 'PayrollTeachers' })

  const props = defineProps<{
    /** 月份（YYYY-MM），来自顶层筛选栏 */
    month: string
    /** 门店筛选（null = 两店合并全部显示） */
    venue: string | null
    /** 规则目录（roles 用于身份标签显示、venues 用于分组顺序），与 profiles 页同源 */
    catalog: PayrollRolesCatalog | null
  }>()

  const emit = defineEmits<{ error: [msg: string] }>()

  const { isHandheld } = useDevice()

  const loading = ref(false)
  const profiles = ref<PayrollProfileRow[]>([])
  const hoursRows = ref<PayrollHoursRow[]>([])

  /** 枚举与系数一律来自后端 catalog；未加载时退回空（宁可空，也不写死一份） */
  const roles = computed(() => props.catalog?.roles ?? [])
  const fee45Factor = computed(() => props.catalog?.fee45FallbackFactor ?? 0.75)

  /** 档案 + 当月课时按 profileId 合并后的行 */
  interface TeacherRow {
    profileId: number
    name: string
    venue: string
    role: string
    roleLabel: string
    pendingReview: boolean
    feePrivate60: number
    feePrivate45Effective: number
    feePrivate45Derived: boolean
    hours: {
      private60: number
      private45: number
      small: number
      group: number
      enterprise: number
    }
    totalHours: number
  }

  /** 未建档老师（hours 行 profileId=null）单独一节 */
  interface UnmatchedRow extends TeacherRow {
    sourceNames: string[]
  }

  /** 门店分组视图 */
  const venueGroups = computed<
    { venue: string; rows: TeacherRow[]; totalHours: number; privateHours: number }[]
  >(() => {
    const venues = props.venue ? [props.venue] : (props.catalog?.venues ?? [])
    return venues.map((venue) => {
      const rows = teacherRows.value.filter((r) => r.venue === venue)
      const totalHours = rows.reduce((s, r) => s + r.totalHours, 0)
      const privateHours = rows.reduce((s, r) => s + r.hours.private60 + r.hours.private45, 0)
      return { venue, rows, totalHours, privateHours }
    })
  })

  /** 已建档老师的合并行（档案为主表左连接当月课时 —— 无课时也显示，节数 0） */
  const teacherRows = computed<TeacherRow[]>(() => {
    const hoursByProfile = new Map<number, PayrollHoursRow>()
    for (const h of hoursRows.value) {
      if (h.profileId !== null) hoursByProfile.set(h.profileId, h)
    }
    return profiles.value.map((p) => {
      const h = hoursByProfile.get(p.id)
      return {
        profileId: p.id,
        name: p.name,
        venue: p.venue,
        role: p.role,
        roleLabel: roleLabelOf(p.role),
        pendingReview: p.pendingReview,
        feePrivate60: p.feePrivate60,
        feePrivate45Effective: p.feePrivate45Effective,
        feePrivate45Derived: p.feePrivate45Derived,
        hours: {
          private60: h?.private60 ?? 0,
          private45: h?.private45 ?? 0,
          small: h?.small ?? 0,
          group: h?.group ?? 0,
          enterprise: h?.enterprise ?? 0
        },
        totalHours: h?.totalHours ?? 0
      }
    })
  })

  /** 未建档老师（profileId=null 的 hours 行）：不混进门店分组，单独一节 */
  const unmatched = computed<UnmatchedRow[]>(() =>
    hoursRows.value
      .filter((h) => h.profileId === null)
      .map((h) => ({
        profileId: -1,
        name: h.name,
        venue: '',
        role: '',
        roleLabel: '',
        pendingReview: false,
        feePrivate60: 0,
        feePrivate45Effective: 0,
        feePrivate45Derived: false,
        hours: {
          private60: h.private60,
          private45: h.private45,
          small: h.small,
          group: h.group,
          enterprise: h.enterprise
        },
        totalHours: h.totalHours,
        sourceNames: h.sourceNames ?? [h.name]
      }))
  )

  function roleLabelOf(value: string): string {
    return roles.value.find((r) => r.value === value)?.label ?? value
  }

  async function load() {
    loading.value = true
    try {
      // 两个接口并行：档案侧不受月份影响，课时侧按月份取
      const [profileRes, hoursRes] = await Promise.all([
        fetchPayrollProfiles(),
        fetchPayrollHours(props.month)
      ])
      profiles.value = profileRes.rows ?? []
      hoursRows.value = hoursRes.rows ?? []
    } catch (e) {
      profiles.value = []
      hoursRows.value = []
      emit('error', payrollErrorMessage(e, '门店老师一览加载失败'))
    } finally {
      loading.value = false
    }
  }

  watch(() => [props.month, props.venue], load, { immediate: true })

  // ---------- 手机端卡片 ----------

  function cardTags(row: TeacherRow): MobileCardTag[] {
    const tags: MobileCardTag[] = []
    if (row.pendingReview) tags.push({ text: '待完善', type: 'danger', effect: 'dark' })
    return tags
  }

  function cardMetrics(row: TeacherRow): MobileCardMetric[] {
    return [
      { label: '合计', value: row.totalHours, unit: '节' },
      { label: '私教 60/45', value: `${row.hours.private60} / ${row.hours.private45}` },
      { label: '小班/团课', value: `${row.hours.small} / ${row.hours.group}` }
    ]
  }

  function cardNote(row: TeacherRow): string {
    const parts: string[] = []
    parts.push(
      `私教单价 ${money(row.feePrivate60)} / ${money(row.feePrivate45Effective)}${row.feePrivate45Derived ? '（45 折算）' : ''}`
    )
    if (row.hours.enterprise > 0) parts.push(`企业课 ${row.hours.enterprise} 节`)
    return parts.join('；')
  }

  defineExpose({ reload: load })
</script>

<style scoped lang="scss">
  .unmatched-card {
    border-left: 3px solid var(--el-color-warning);
  }

  .fee-derived {
    margin-left: 2px;
    padding: 0 3px;
    font-size: 10px;
    background: var(--el-color-warning-light-9);
    border-radius: 2px;
  }

  .foot-note {
    padding: 4px 8px;
  }
</style>
