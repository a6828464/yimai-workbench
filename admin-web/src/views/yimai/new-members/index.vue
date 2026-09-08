<template>
  <div class="p-4">
    <ElAlert
      title="新客培养：统计入会近 N 天的会员，跟踪其私教 / 小班 / 团课上课养成进度，帮助新会员建立练习习惯、了解身体需求，保障体验"
      type="info"
      show-icon
      :closable="false"
      class="mb-3"
    />
    <ElAlert
      v-if="error"
      :title="error"
      type="error"
      show-icon
      :closable="false"
      class="mb-3"
    />
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

    <!-- 概览 -->
    <ElRow :gutter="12" class="mb-3">
      <ElCol :xs="12" :sm="8" :md="4">
        <ElCard shadow="never"><Stat label="新入会会员" :value="summary.total" /></ElCard>
      </ElCol>
      <ElCol :xs="12" :sm="8" :md="4">
        <ElCard shadow="never"><Stat label="待激活(0上课)" :value="summary.idle" warn /></ElCard>
      </ElCol>
      <ElCol :xs="12" :sm="8" :md="4">
        <ElCard shadow="never"><Stat label="待养成" :value="summary.cultivating" /></ElCard>
      </ElCol>
      <ElCol :xs="12" :sm="8" :md="4">
        <ElCard shadow="never"><Stat label="已养成" :value="summary.cultured" /></ElCard>
      </ElCol>
      <ElCol :xs="12" :sm="8" :md="4">
        <ElCard shadow="never"><Stat label="有私教课" :value="summary.private" /></ElCard>
      </ElCol>
      <ElCol :xs="12" :sm="8" :md="4">
        <ElCard shadow="never"><Stat label="有小班/团课" :value="summary.small + summary.group" /></ElCard>
      </ElCol>
    </ElRow>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="pagedList" border stripe>
        <ElTableColumn prop="name" label="会员" min-width="110" fixed="left">
          <template #default="{ row }">
            <div class="font-500">{{ row.name }}</div>
            <div class="text-xs text-gray-400">{{ row.phoneTail }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn v-if="canPickVenue" prop="venue" label="门店" width="90">
          <template #default="{ row }"><ElTag size="small" effect="plain">{{ row.venue }}</ElTag></template>
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
            <ElTag :type="HEALTH_META[(row as NewMemberCultivation).health].type" size="small">{{ HEALTH_META[(row as NewMemberCultivation).health].text }}</ElTag>
          </template>
        </ElTableColumn>
      </ElTable>
      <div class="mt-4 flex justify-end">
        <ElPagination
          v-model:current-page="page.current"
          :page-size="page.size"
          :total="records.length"
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

  defineOptions({ name: 'YimaiNewMembers' })

  const HEALTH_META: Record<NewMemberCultivation['health'], { text: string; type: 'danger' | 'warning' | 'success' }> = {
    idle: { text: '待激活', type: 'danger' },
    cultivating: { text: '待养成', type: 'warning' },
    cultured: { text: '已养成', type: 'success' }
  }

  // 单个课型养成进度行：已签到/目标 + 预约/爽约 计数
  const KindRow = defineComponent({
    props: { label: { type: String, required: true }, cat: { type: Object as () => CultivationCategory, required: true } },
    setup(props) {
      const pct = computed(() =>
        props.cat.target > 0 ? Math.min(100, Math.round((props.cat.signed / props.cat.target) * 100)) : 0
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
          props.cat.booked > 0 ? h('span', { class: 'text-blue-500' }, `约${props.cat.booked}`) : null,
          props.cat.noShow > 0 ? h('span', { class: 'text-red-400' }, `爽${props.cat.noShow}`) : null
        ])
    }
  })

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isTeacher = computed(() => roles.value.includes('R_TEACHER'))
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  const canPickVenue = computed(() => !isTeacher.value && !isManager.value)

  const Stat = defineComponent({
    props: { label: { type: String, required: true }, value: { type: Number, required: true }, warn: { type: Boolean } },
    setup(props) {
      return () =>
        h('div', {}, [
          h('div', { class: 'text-sm text-gray-500' }, props.label),
          h('div', { class: ['mt-1 text-2xl font-600', props.warn && props.value > 0 ? 'text-danger' : 'text-g-900'] }, String(props.value))
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

  const summary = computed(() => lastSummary.value)
  const lastSummary = ref({ total: 0, private: 0, small: 0, group: 0, idle: 0, cultivating: 0, cultured: 0 })

  const pagedList = computed(() =>
    records.value.slice((page.value.current - 1) * page.value.size, page.value.current * page.value.size)
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
      lastSummary.value = res.summary
      syncTime.value = res.syncTime
      error.value = ''
    } catch (e) {
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
    reload()
  }

  onMounted(reload)
</script>
