<template>
  <div class="p-4">
    <ElAlert
      title="新客培养：统计入会近 N 天的会员，跟踪其私教 / 小班 / 团课上课养成进度，帮助新会员建立练习习惯、了解身体需求，保障体验"
      type="info"
      show-icon
      :closable="false"
      class="mb-3"
    />
    <ElAlert v-if="error" :title="error" type="error" show-icon :closable="false" class="mb-3" />
    <div v-else-if="syncTime" class="mb-3 text-xs text-gray-400">
      数据来自 KeepYoga 同步（非实时）· 数据截至 {{ syncTime || '尚未同步' }}
      <span v-if="!syncTime" class="text-warning">（该店尚未同步，暂无新客数据）</span>
    </div>

    <!-- 筛选条 -->
    <ElCard shadow="never" class="mb-3">
      <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm">入会日期</span>
        <ElDatePicker
          v-model="dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          range-separator="至"
          start-placeholder="开始"
          end-placeholder="结束"
          :clearable="false"
        />
        <ElButton link @click="setQuickRange(30)">近30天</ElButton>
        <ElButton link @click="setQuickRange(90)">近90天</ElButton>
        <ElButton link @click="setQuickRange(180)">近半年</ElButton>
        <ElSelect v-if="canPickVenue" v-model="venue" clearable placeholder="全部门店" class="w-28">
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElInput v-model="name" placeholder="会员姓名" clearable class="w-40" />
        <ElButton type="primary" :loading="loading" @click="reload">查询</ElButton>
        <ElButton @click="resetFilters">重置</ElButton>
      </div>
      <div class="mt-3">
        <ElRadioGroup v-model="cardType">
          <ElRadioButton label="" value="">全部课型</ElRadioButton>
          <ElRadioButton label="private" value="private">私教</ElRadioButton>
          <ElRadioButton label="small" value="small">小班</ElRadioButton>
          <ElRadioButton label="group" value="group">团课</ElRadioButton>
        </ElRadioGroup>
      </div>
    </ElCard>

    <!-- 概览：点卡片即筛选下方列表，再点一次取消 -->
    <ElRow :gutter="12" class="mb-3">
      <ElCol v-for="s in STATS" :key="s.key" :xs="12" :sm="8" :md="4">
        <ElCard
          shadow="never"
          class="stat-card"
          :class="{ 'stat-card--active': statFilter === s.key }"
          @click="toggleStat(s.key)"
        >
          <Stat
            :label="s.label"
            :value="s.value(statCounts)"
            :warn="s.warn"
            :active="statFilter === s.key"
          />
        </ElCard>
      </ElCol>
    </ElRow>
    <div v-if="statFilter" class="mb-3 flex items-center gap-2 text-xs text-gray-400">
      <span>已筛选：{{ activeStatLabel }}（{{ filteredRecords.length }} 人）</span>
      <ElButton link type="primary" size="small" @click="statFilter = ''">清除筛选</ElButton>
    </div>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-if="!isHandheld" v-loading="loading" :data="pagedList" border stripe>
        <ElTableColumn prop="name" label="会员" min-width="110" fixed="left">
          <template #default="{ row }">
            <div class="font-500">{{ row.name }}</div>
            <div class="text-xs tabular-nums text-gray-400">
              {{ row.phone || (row.phoneTail ? '尾号' + row.phoneTail : '—') }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn v-if="canPickVenue" prop="venue" label="门店" width="90">
          <template #default="{ row }"
            ><ElTag size="small" effect="plain">{{ row.venue }}</ElTag></template
          >
        </ElTableColumn>
        <ElTableColumn label="入会" width="120">
          <template #default="{ row }">
            <div>{{ row.enrolledAt }}</div>
            <div class="text-xs text-gray-400">{{ row.enrolledDays }} 天前</div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="负责/顾问" width="110">
          <template #default="{ row }">{{ row.consultant }}</template>
        </ElTableColumn>
        <ElTableColumn label="上课养成（已签到 / 目标）" min-width="240">
          <template #default="{ row }">
            <div class="space-y-1">
              <KindRow label="私教" :cat="row.categories.private" />
              <KindRow label="小班" :cat="row.categories.small" />
              <KindRow label="团课" :cat="row.categories.group" />
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="预约主题" min-width="180">
          <template #default="{ row }">
            <span v-if="!row.themes.length" class="text-xs text-gray-400">—</span>
            <ElTag v-for="t in row.themes" :key="t" size="small" class="mr-1 mb-1">{{ t }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="健康度" width="100">
          <template #default="{ row }">
            <ElTag :type="HEALTH_META[(row as NewMemberCultivation).health].type" size="small">{{
              HEALTH_META[(row as NewMemberCultivation).health].text
            }}</ElTag>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表 -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="item in cardRows"
          :key="item.id"
          :title="item.title"
          :subtitle="item.subtitle"
          :tags="item.tags"
          :metrics="item.metrics"
        >
          <div class="nm-card__body">
            <div class="text-xs text-gray-400">入会 {{ item.enrolledAt }}</div>
            <div class="space-y-1 mt-2">
              <KindRow label="私教" :cat="item.row.categories.private" />
              <KindRow label="小班" :cat="item.row.categories.small" />
              <KindRow label="团课" :cat="item.row.categories.group" />
            </div>
            <div v-if="item.themes.length" class="nm-card__themes">
              <ElTag v-for="t in item.themes" :key="t" size="small" class="mr-1 mb-1">{{
                t
              }}</ElTag>
            </div>
          </div>
        </MobileCard>
        <div v-if="!loading && !cardRows.length" class="m-card-list__empty">暂无数据</div>
      </div>
      <div class="mt-4 flex justify-end">
        <ElPagination
          v-model:current-page="page.current"
          :page-size="page.size"
          :total="filteredRecords.length"
          layout="total, prev, pager, next"
        />
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { h, defineComponent, computed, ref, onMounted } from 'vue'
  import { ElProgress, ElTag } from 'element-plus'
  import { queryNewMemberCultivation } from '@/api/yimai'
  import type { NewMemberCultivation, CultivationCategory } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { toLocalDateString } from '@/utils'
  import { useDevice } from '@/hooks/core/useDevice'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'

  defineOptions({ name: 'YimaiNewMembers' })

  // 手持设备上用卡片列表代替宽表格（「上课养成」列宽 240px，手机上必被压扁）
  const { isHandheld } = useDevice()

  const HEALTH_META: Record<
    NewMemberCultivation['health'],
    { text: string; type: 'danger' | 'warning' | 'success' }
  > = {
    idle: { text: '待激活', type: 'danger' },
    cultivating: { text: '待养成', type: 'warning' },
    cultured: { text: '已养成', type: 'success' }
  }

  // 单个课型养成进度行：已签到/目标 + 预约/爽约 计数
  const KindRow = defineComponent({
    props: {
      label: { type: String, required: true },
      cat: { type: Object as () => CultivationCategory, required: true }
    },
    setup(props) {
      const pct = computed(() =>
        props.cat.target > 0
          ? Math.min(100, Math.round((props.cat.signed / props.cat.target) * 100))
          : 0
      )
      return () =>
        h('div', { class: 'flex items-center gap-2 text-xs' }, [
          h('span', { class: 'w-8 shrink-0 text-gray-500' }, props.label),
          h(ElProgress, {
            percentage: pct.value,
            strokeWidth: 8,
            style: 'width: 110px',
            status: pct.value >= 100 ? 'success' : undefined
          }),
          h('span', { class: 'shrink-0 font-500' }, `${props.cat.signed}/${props.cat.target}`),
          props.cat.booked > 0
            ? h('span', { class: 'text-blue-500' }, `约${props.cat.booked}`)
            : null,
          props.cat.noShow > 0
            ? h('span', { class: 'text-red-400' }, `爽${props.cat.noShow}`)
            : null
        ])
    }
  })

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isTeacher = computed(
    () => roles.value.includes('R_SERVICE') || roles.value.includes('R_TEACHER')
  )
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  const canPickVenue = computed(() => !isTeacher.value && !isManager.value)

  // ---------- 移动端卡片 ----------
  //
  // 桌面的「上课养成」列宽 240px，在手机上会被压扁到不可读。
  // 卡片里改用默认插槽放同一个 KindRow 进度条，信息量不减、可解释性也保留。
  const cardRows = computed(() =>
    pagedList.value.map((row) => {
      const tags: MobileCardTag[] = []
      if (canPickVenue.value) tags.push({ text: row.venue, effect: 'plain' })
      tags.push({
        text: HEALTH_META[row.health].text,
        type: HEALTH_META[row.health].type
      })
      if (row.consultant) tags.push({ text: row.consultant, effect: 'plain' })

      const metrics: MobileCardMetric[] = [
        {
          label: '入会',
          value: row.enrolledDays ?? '—',
          unit: row.enrolledDays == null ? '' : '天'
        },
        { label: '已签到', value: row.totalSigned, unit: '节' }
      ]

      return {
        id: row.id,
        row,
        title: row.name,
        subtitle: row.phone || (row.phoneTail ? `尾号${row.phoneTail}` : '—'),
        enrolledAt: row.enrolledAt,
        themes: row.themes,
        tags,
        metrics
      }
    })
  )

  const Stat = defineComponent({
    props: {
      label: { type: String, required: true },
      value: { type: Number, required: true },
      warn: { type: Boolean },
      active: { type: Boolean }
    },
    setup(props) {
      return () =>
        h('div', {}, [
          h(
            'div',
            { class: ['text-sm', props.active ? 'text-primary' : 'text-gray-500'] },
            props.label
          ),
          h(
            'div',
            {
              class: [
                'mt-1 text-2xl font-600',
                props.warn && props.value > 0 ? 'text-danger' : 'text-g-900'
              ]
            },
            String(props.value)
          )
        ])
    }
  })

  function defaultRange(): [string, string] {
    const end = new Date()
    const start = new Date()
    start.setDate(start.getDate() - 89)
    return [toLocalDateString(start), toLocalDateString(end)]
  }

  const loading = ref(false)
  const error = ref('')
  const syncTime = ref('')
  const dateRange = ref<[string, string]>(defaultRange())
  const venue = ref('')
  const name = ref('')
  const cardType = ref('')
  const records = ref<NewMemberCultivation[]>([])
  const page = ref({ current: 1, size: 20 })
  /** 概览卡片筛选：空=不筛选；与上方筛选条叠加生效 */
  const statFilter = ref<StatKey>('')

  type StatKey = '' | 'total' | 'idle' | 'cultivating' | 'cultured' | 'private' | 'smallGroup'

  /**
   * 概览卡片按「人」计数，且一律从当前列表数据算出。
   *
   * 卡片现在可点击筛选，所以「卡片上的数字 = 点下去看到的行数」必须成立；后端 summary 在
   * 勾了课型筛选时统计的是未筛选的全量，两者会对不上。另外后端 summary 的 small+group 是
   * 两个「人数」相加，同时上过小班和团课的人会被算两次，这里按并集去重。
   */
  const statCounts = computed(() => {
    const rows = records.value
    return {
      total: rows.length,
      idle: rows.filter((r) => r.health === 'idle').length,
      cultivating: rows.filter((r) => r.health === 'cultivating').length,
      cultured: rows.filter((r) => r.health === 'cultured').length,
      private: rows.filter((r) => r.categories.private.signed > 0).length,
      smallGroup: rows.filter((r) => r.categories.small.signed + r.categories.group.signed > 0)
        .length
    }
  })

  const STATS: {
    key: Exclude<StatKey, ''>
    label: string
    warn?: boolean
    value: (c: typeof statCounts.value) => number
  }[] = [
    { key: 'total', label: '新入会会员', value: (c) => c.total },
    { key: 'idle', label: '待激活(0上课)', warn: true, value: (c) => c.idle },
    { key: 'cultivating', label: '待养成', value: (c) => c.cultivating },
    { key: 'cultured', label: '已养成', value: (c) => c.cultured },
    { key: 'private', label: '有私教课', value: (c) => c.private },
    { key: 'smallGroup', label: '有小班/团课', value: (c) => c.smallGroup }
  ]

  const activeStatLabel = computed(() => STATS.find((s) => s.key === statFilter.value)?.label ?? '')

  function matchStat(row: NewMemberCultivation, key: StatKey): boolean {
    switch (key) {
      case 'idle':
        return row.health === 'idle'
      case 'cultivating':
        return row.health === 'cultivating'
      case 'cultured':
        return row.health === 'cultured'
      case 'private':
        return row.categories.private.signed > 0
      case 'smallGroup':
        return row.categories.small.signed + row.categories.group.signed > 0
      default:
        return true
    }
  }

  /** 概览卡片筛选后的记录；分页基于它，页数不会超出 */
  const filteredRecords = computed(() =>
    statFilter.value === ''
      ? records.value
      : records.value.filter((r) => matchStat(r, statFilter.value))
  )

  function toggleStat(key: Exclude<StatKey, ''>) {
    statFilter.value = statFilter.value === key ? '' : key
    page.value.current = 1
  }

  const pagedList = computed(() =>
    filteredRecords.value.slice(
      (page.value.current - 1) * page.value.size,
      page.value.current * page.value.size
    )
  )

  function setQuickRange(days: number) {
    const end = new Date()
    const start = new Date()
    start.setDate(start.getDate() - (days - 1))
    dateRange.value = [toLocalDateString(start), toLocalDateString(end)]
    reload()
  }

  async function reload() {
    loading.value = true
    page.value.current = 1
    try {
      const res = await queryNewMemberCultivation({
        start: dateRange.value[0],
        end: dateRange.value[1],
        venue: venue.value || undefined,
        name: name.value || undefined,
        cardType: cardType.value || undefined
      })
      records.value = res.records
      syncTime.value = res.syncTime
      error.value = ''
    } catch {
      error.value = '新客培养数据加载失败，请稍后重试'
    } finally {
      loading.value = false
    }
  }

  function resetFilters() {
    dateRange.value = defaultRange()
    venue.value = ''
    name.value = ''
    cardType.value = ''
    statFilter.value = ''
    reload()
  }

  onMounted(reload)
</script>

<style scoped lang="scss">
  // 概览卡片可点击筛选，选中态给出明确反馈（否则用户不知道点了有没有生效）
  .stat-card {
    cursor: pointer;
    transition:
      border-color 0.2s,
      box-shadow 0.2s;

    &:hover {
      border-color: var(--el-color-primary);
    }

    &--active {
      border-color: var(--el-color-primary);
      box-shadow: 0 0 0 1px var(--el-color-primary) inset;
    }
  }

  // 卡片里的养成进度区，与「预约主题」用一条虚线分隔
  .nm-card {
    &__body {
      padding-top: 10px;
      margin-top: 10px;
      border-top: 1px dashed var(--art-card-border);
    }

    &__themes {
      padding-top: 8px;
      margin-top: 8px;
      border-top: 1px dashed var(--art-card-border);
    }
  }
</style>
