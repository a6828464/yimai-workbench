<template>
  <div class="list-page">
    <ElCard shadow="never" class="mb-4">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2">
            <span class="font-500">体验课课后分析</span>
            <ElTag size="small" effect="plain">当场填完 ≤ 5 分钟</ElTag>
            <ElTag v-if="summary.redFlag" size="small" type="danger" effect="plain">
              红线 {{ summary.redFlag }} 条
            </ElTag>
          </div>
          <div class="flex items-center gap-2">
            <ElButton size="small" :loading="loading" @click="reload">刷新</ElButton>
            <ElButton v-if="canWrite" type="primary" size="small" @click="openWizard(null)">
              手工新建
            </ElButton>
          </div>
        </div>
      </template>

      <ElAlert type="info" :closable="false" class="mb-3">
        先勾观察项 → 系统按「类型 × 本次观察」生成三阶段方向 → 你改一两处 → 当场讲给学员，
        让他扫码带走。讲观察不讲诊断，讲方向不承诺疗效，老师不报价。
      </ElAlert>

      <ElTabs v-model="tab">
        <!-- 待填写：来自真实排课事实 -->
        <ElTabPane name="pending">
          <template #label>
            <span>待填写</span>
            <ElBadge
              v-if="candidates.length"
              :value="candidates.length"
              type="danger"
              class="ml-1"
            />
          </template>

          <div class="mb-2 flex flex-wrap items-center gap-2">
            <ElRadioGroup v-model="sourceTab" size="small">
              <ElRadioButton value="class">
                上过课<span class="ml-1 text-xs">({{ candidates.length }})</span>
              </ElRadioButton>
              <ElRadioButton value="lead">
                我的留资<span class="ml-1 text-xs">({{ myLeads.length }})</span>
              </ElRadioButton>
              <ElRadioButton value="member">
                我的会员<span class="ml-1 text-xs">({{ myMembers.length }})</span>
              </ElRadioButton>
            </ElRadioGroup>
            <template v-if="sourceTab === 'class'">
              <span class="text-xs text-gray-500">范围</span>
              <ElRadioGroup v-model="days" size="small" @change="loadCandidates">
                <ElRadioButton :value="3">近 3 天</ElRadioButton>
                <ElRadioButton :value="7">近 7 天</ElRadioButton>
                <ElRadioButton :value="30">近 30 天</ElRadioButton>
              </ElRadioGroup>
              <span class="text-xs text-gray-500">来源</span>
              <ElRadioGroup v-model="personFilter" size="small">
                <ElRadioButton value="all">全部</ElRadioButton>
                <ElRadioButton value="member">
                  老会员<span class="ml-1 text-xs">({{ memberCount }})</span>
                </ElRadioButton>
                <ElRadioButton value="lead">
                  新建客资<span class="ml-1 text-xs">({{ leadCount }})</span>
                </ElRadioButton>
              </ElRadioGroup>
            </template>
            <span class="text-xs text-gray-400">{{ sourceHint }}</span>
          </div>

          <!-- ① 上过课：待填写课后分析（手持设备换成下方卡片列表） -->
          <ElTable
            v-if="!isHandheld && sourceTab === 'class'"
            ref="candidateTableRef"
            :data="filteredCandidates"
            v-loading="loading"
            size="default"
            :max-height="candidateTableMaxHeight"
          >
            <ElTableColumn prop="date" label="日期" width="105" />
            <ElTableColumn prop="time" label="时间" width="70" />
            <ElTableColumn prop="studentName" label="学员" width="110" />
            <ElTableColumn prop="courseName" label="课程" min-width="140" show-overflow-tooltip />
            <ElTableColumn label="课型" width="130">
              <template #default="{ row }">
                <ElTag v-if="row.isTrial" size="small" type="warning" effect="dark">体验课</ElTag>
                <ElTag
                  v-else
                  size="small"
                  :type="row.kind === '私教' ? 'danger' : 'info'"
                  effect="plain"
                >
                  {{ row.kind }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn prop="teacherName" label="老师" width="90" />
            <ElTableColumn label="来源" min-width="150">
              <template #default="{ row }">
                <ElTag
                  v-if="row.personType === 'member'"
                  size="small"
                  type="success"
                  effect="plain"
                >
                  老会员
                </ElTag>
                <ElTag
                  v-else-if="row.personType === 'lead'"
                  size="small"
                  type="warning"
                  effect="plain"
                >
                  新建客资
                </ElTag>
                <ElTag v-else size="small" type="info" effect="plain">未建档</ElTag>
                <span v-if="row.personType === 'member'" class="ml-1 text-xs text-gray-500">
                  {{ row.memberCard || '—' }}
                  <span v-if="row.memberRemain !== null && row.memberRemain !== undefined">
                    · 剩 {{ row.memberRemain }} 节
                  </span>
                </span>
                <span v-else-if="row.leadStatus" class="ml-1 text-xs text-gray-500">
                  {{ row.leadStatus }}
                </span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="操作" width="110" fixed="right">
              <template #default="{ row }">
                <ElButton v-if="canWrite" link type="primary" size="small" @click="openWizard(row)">
                  填写分析
                </ElButton>
                <span v-else class="text-xs text-gray-400">待老师填写</span>
              </template>
            </ElTableColumn>
          </ElTable>

          <!-- ② 留资管理里分配给他的 -->
          <ElTable
            v-else-if="!isHandheld && sourceTab === 'lead'"
            ref="leadTableRef"
            :data="myLeads"
            v-loading="loading"
            size="default"
            :max-height="leadTableMaxHeight"
          >
            <ElTableColumn prop="studentName" label="客户" width="110" />
            <ElTableColumn label="手机号" width="120">
              <template #default="{ row }">
                <span class="tabular-nums">{{ row.phone || '—' }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn prop="venue" label="门店" width="90" />
            <ElTableColumn prop="leadSource" label="来源" width="110" />
            <ElTableColumn prop="demand" label="需求" min-width="130" show-overflow-tooltip />
            <ElTableColumn prop="leadDate" label="留资日期" width="110" />
            <ElTableColumn label="状态" width="100">
              <template #default="{ row }">
                <ElTag
                  size="small"
                  :type="
                    row.leadStatus === '已成交'
                      ? 'success'
                      : row.leadStatus === '新留资'
                        ? 'danger'
                        : 'primary'
                  "
                >
                  {{ row.leadStatus }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn label="操作" width="120" fixed="right">
              <template #default="{ row }">
                <ElButton v-if="canWrite" link type="primary" size="small" @click="openWizard(row)">
                  填写分析
                </ElButton>
                <span v-else class="text-xs text-gray-400">—</span>
              </template>
            </ElTableColumn>
          </ElTable>

          <!-- ③ 约课系统里会籍顾问归属他的会员 -->
          <ElTable
            v-else-if="!isHandheld"
            ref="memberTableRef"
            :data="myMembers"
            v-loading="loading"
            size="default"
            :max-height="memberTableMaxHeight"
          >
            <ElTableColumn prop="studentName" label="会员" width="120" />
            <ElTableColumn label="手机号" width="120">
              <template #default="{ row }">
                <span class="tabular-nums">{{ row.phone || '—' }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn prop="venue" label="门店" width="90" />
            <ElTableColumn prop="mainCard" label="主卡" min-width="140" show-overflow-tooltip />
            <ElTableColumn prop="remainTimes" label="剩余节数" width="100" />
            <ElTableColumn label="课后分析" width="110">
              <template #default="{ row }">
                <ElTag v-if="row.hasReview" size="small" type="success" effect="plain"
                  >已填写</ElTag
                >
                <span v-else class="text-xs text-gray-400">未填写</span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="操作" width="120" fixed="right">
              <template #default="{ row }">
                <ElButton v-if="canWrite" link type="primary" size="small" @click="openWizard(row)">
                  填写分析
                </ElButton>
                <span v-else class="text-xs text-gray-400">—</span>
              </template>
            </ElTableColumn>
          </ElTable>

          <!-- 手持设备：三类来源共用一张卡片列表（905px 宽的表格在手机上只剩 278px 可见，
               横滑也只能看到左右固定列），空态文案沿用 sourceEmptyText -->
          <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
            <MobileCard
              v-for="item in pendingCardRows"
              :key="item.id"
              :title="item.title"
              :subtitle="item.subtitle"
              :tags="item.tags"
              :metrics="item.metrics"
              :note="item.note?.text"
              :note-label="item.note?.label"
              :note-danger="item.note?.danger"
              :actions="item.actions"
            >
              <div v-if="item.hint" class="pc-card__hint">{{ item.hint }}</div>
            </MobileCard>
            <div v-if="!loading && currentSourceEmpty" class="m-card-list__empty">
              {{ sourceEmptyText }}
            </div>
          </div>

          <!-- 卡片列表自带空态，ElEmpty 只在桌面端出现，避免两处重复提示 -->
          <ElEmpty
            v-if="!isHandheld && !loading && currentSourceEmpty"
            :description="sourceEmptyText"
            :image-size="70"
          />
        </ElTabPane>

        <!-- 已填写记录 -->
        <ElTabPane name="done">
          <template #label>
            <span>已填写（{{ summary.total }}）</span>
          </template>

          <div class="mb-2 flex flex-wrap items-center gap-2">
            <ElSelect
              v-model="filter.studentType"
              clearable
              placeholder="学员类型"
              size="small"
              class="!w-40"
              @change="loadReviews"
            >
              <ElOption
                v-for="t in catalog?.types ?? []"
                :key="t.key"
                :label="t.label"
                :value="t.key"
              />
            </ElSelect>
            <ElSelect
              v-model="filter.status"
              clearable
              placeholder="状态"
              size="small"
              class="!w-32"
              @change="loadReviews"
            >
              <ElOption label="草稿" value="草稿" />
              <ElOption label="已确认" value="已确认" />
            </ElSelect>
            <ElCheckbox v-model="filter.onlyRedFlag" label="只看红线" @change="loadReviews" />
          </div>

          <ElTable
            v-if="!isHandheld"
            ref="reviewTableRef"
            :data="reviews"
            v-loading="loading"
            size="default"
            :max-height="reviewTableMaxHeight"
          >
            <ElTableColumn prop="classAt" label="上课时间" width="150" />
            <ElTableColumn prop="studentName" label="学员" width="100" />
            <ElTableColumn prop="studentType" label="类型" width="90" />
            <ElTableColumn prop="teacherName" label="老师" width="90" />
            <ElTableColumn label="状态" width="150">
              <template #default="{ row }">
                <ElTag v-if="row.redFlag" size="small" type="danger" effect="dark"
                  >红线 · 不建议排课</ElTag
                >
                <ElTag v-else size="small" :type="row.status === '已确认' ? 'success' : 'info'">
                  {{ row.status }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn label="成交归因" min-width="150">
              <template #default="{ row }">
                <span v-if="row.leadStatus === '已成交'" class="text-green-600 text-[13px]">
                  已成交 ¥{{ row.dealAmount }}
                </span>
                <span v-else-if="row.leadStatus" class="text-xs text-gray-500">{{
                  row.leadStatus
                }}</span>
                <span v-else class="text-xs text-gray-400">—</span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="对客页" width="90">
              <template #default="{ row }">
                <span v-if="row.share?.enabled" class="text-xs text-gray-500">
                  {{ row.share.views ?? 0 }} 次
                </span>
                <span v-else class="text-xs text-gray-300">未开启</span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="操作" width="260" fixed="right">
              <template #default="{ row }">
                <ElButton link type="primary" size="small" @click="viewDetail(row)">查看</ElButton>
                <ElButton
                  v-if="!row.redFlag && row.share?.enabled"
                  link
                  type="success"
                  size="small"
                  @click="openSharePage(row)"
                  >对客页</ElButton
                >
                <ElButton
                  v-if="canWrite && !row.redFlag && row.status === '已确认'"
                  link
                  type="warning"
                  size="small"
                  @click="toTrainingPlan(row)"
                  >转计划</ElButton
                >
                <ElButton link type="danger" size="small" @click="removeReview(row)">删除</ElButton>
              </template>
            </ElTableColumn>
          </ElTable>

          <!-- 手持设备：卡片列表。主操作「查看」排在最前，保证它落在直接可见的两个按钮里 -->
          <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
            <MobileCard
              v-for="item in reviewCardRows"
              :key="item.id"
              :title="item.title"
              :subtitle="item.subtitle"
              :tags="item.tags"
              :metrics="item.metrics"
              :note="item.note?.text"
              :note-label="item.note?.label"
              :note-danger="item.note?.danger"
              :actions="item.actions"
            />
            <div v-if="!loading && !reviewCardRows.length" class="m-card-list__empty">暂无数据</div>
          </div>

          <!-- 卡片列表自带空态，ElEmpty 只在桌面端出现，避免两处重复提示 -->
          <ElEmpty
            v-if="!isHandheld && !loading && !reviews.length"
            description="还没有课后分析记录"
            :image-size="70"
          />
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <!-- 向导 -->
    <PostClassReviewWizard v-model="wizardVisible" :candidate="activeCandidate" @saved="reload" />

    <!-- 详情抽屉 -->
    <ElDrawer v-model="detailVisible" title="课后分析详情" size="620px">
      <template v-if="detail">
        <ElDescriptions :column="2" border size="small" class="mb-4">
          <ElDescriptionsItem label="学员">{{ detail.studentName }}</ElDescriptionsItem>
          <ElDescriptionsItem label="类型">{{ detail.studentType || '—' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="授课老师">{{ detail.teacherName || '—' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="上课时间">{{ detail.classAt || '—' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="场景">{{ sceneLabel(detail.scene) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="状态">
            <ElTag size="small" :type="detail.redFlag ? 'danger' : 'success'">
              {{ detail.redFlag ? '红线' : detail.status }}
            </ElTag>
          </ElDescriptionsItem>
        </ElDescriptions>

        <ElAlert v-if="detail.redFlag" type="error" :closable="false" class="mb-3" show-icon>
          命中红线，本次不产出对客训练方案，仅为内部沟通卡。
        </ElAlert>

        <template v-else-if="detail.objective">
          <h4 class="sec-title">我在你身上观察到的</h4>
          <ul class="detail-list">
            <li v-for="(o, i) in detail.objective.observed" :key="i">{{ o }}</li>
          </ul>

          <h4 class="sec-title">训练方向</h4>
          <div v-for="(p, i) in detail.objective.plan" :key="i" class="phase-box">
            <div class="flex items-baseline justify-between">
              <span class="font-500 text-[13px]">{{ p.name }}</span>
              <span class="text-xs text-gray-400">{{ p.duration }}</span>
            </div>
            <p class="text-[13px] leading-6 mt-1">{{ p.goal }}</p>
            <div v-if="p.focus?.length" class="mt-1 flex flex-wrap gap-1.5">
              <ElTag
                v-for="(f, fi) in p.focus"
                :key="fi"
                size="small"
                type="warning"
                effect="plain"
              >
                {{ f }}
              </ElTag>
            </div>
          </div>

          <h4 class="sec-title">建议频率</h4>
          <p class="text-[13px]">{{ detail.objective.frequency }}</p>

          <h4 class="sec-title">回家作业</h4>
          <ul class="detail-list">
            <li v-for="(h, i) in detail.objective.homework" :key="i">{{ h }}</li>
          </ul>
        </template>

        <template v-if="detail.script">
          <h4 class="sec-title">提词卡</h4>
          <div class="prompt-line">① {{ detail.script.whatPracticed }}</div>
          <div class="prompt-line highlight">② {{ detail.script.progress }}</div>
          <div class="prompt-line">③ {{ detail.script.nextClass }}</div>
          <div class="prompt-line muted">✓ {{ detail.script.reminder }}</div>
        </template>

        <template v-if="detail.plan?.basis?.length">
          <h4 class="sec-title">内部分析依据</h4>
          <ElTable :data="detail.plan.basis" size="small">
            <ElTableColumn prop="label" label="观察项" width="150" />
            <ElTableColumn prop="level" label="程度" width="60" />
            <ElTableColumn
              prop="direction"
              label="对应训练方向"
              min-width="220"
              show-overflow-tooltip
            />
          </ElTable>
        </template>

        <template v-if="detail.plan?.cautions?.length">
          <h4 class="sec-title">注意事项 / 表达边界</h4>
          <ul class="detail-list">
            <li v-for="(c, i) in detail.plan.cautions" :key="i">{{ c }}</li>
          </ul>
        </template>
      </template>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import PostClassReviewWizard from './modules/review-wizard.vue'
  import {
    deletePostClassReview,
    getPostClassCatalog,
    reviewToTrainingPlan,
    queryPostClassCandidates,
    queryPostClassReviews
  } from '@/api/yimai'
  import type { PostClassCatalog, PostClassCandidate, PostClassReviewRow } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'

  defineOptions({ name: 'YimaiPostClass' })

  // 手持设备上用卡片列表代替宽表格，明细见下方 pendingCardRows / reviewCardRows
  const { isHandheld } = useDevice()

  // 表格高度自适应：本页 4 张表（待填写 3 个来源 + 已分析），各持一个 ref。
  // 共用同一个 ref 会让后渲染的表覆盖先渲染的测量结果。
  const { tableMaxHeight: candidateTableMaxHeight, tableRef: candidateTableRef } = useTableHeight()
  const { tableMaxHeight: leadTableMaxHeight, tableRef: leadTableRef } = useTableHeight()
  const { tableMaxHeight: memberTableMaxHeight, tableRef: memberTableRef } = useTableHeight()
  const { tableMaxHeight: reviewTableMaxHeight, tableRef: reviewTableRef } = useTableHeight()

  // 服务老师（会籍顾问）对本页只读：看自己名下会员的课后分析与顾问衔接，填写由授课老师完成
  const userStore = useUserStore()
  const router = useRouter()
  const canWrite = computed(() => {
    const roles = userStore.getUserInfo.roles ?? []
    return roles.some((r: string) => ['R_SUPER', 'R_MANAGER', 'R_TEACHER'].includes(r))
  })

  const tab = ref('pending')
  const loading = ref(false)
  const days = ref(7)
  /** 三类人群：上过课 / 分配给他的留资 / 会籍归属他的会员 */
  const sourceTab = ref<'class' | 'lead' | 'member'>('class')
  const candidates = ref<PostClassCandidate[]>([])
  const myLeads = ref<PostClassCandidate[]>([])
  const myMembers = ref<PostClassCandidate[]>([])
  const sourceHint = computed(() => {
    if (sourceTab.value === 'class') return '含体验课与私教课；已填写的会自动移出该列表'
    if (sourceTab.value === 'lead') return '留资管理里会籍顾问或体验课老师是本人的客资'
    return '约课系统里会籍顾问归属于本人的会员'
  })
  /** 来源筛选：老会员来自会员系统，新建客资来自留资管理，两条渠道分开看 */
  const personFilter = ref<'all' | 'member' | 'lead'>('all')
  const memberCount = computed(
    () => candidates.value.filter((x) => x.personType === 'member').length
  )
  const leadCount = computed(() => candidates.value.filter((x) => x.personType === 'lead').length)
  const filteredCandidates = computed(() =>
    personFilter.value === 'all'
      ? candidates.value
      : candidates.value.filter((x) => x.personType === personFilter.value)
  )

  const currentSourceEmpty = computed(() => {
    if (sourceTab.value === 'class') return filteredCandidates.value.length === 0
    if (sourceTab.value === 'lead') return myLeads.value.length === 0
    return myMembers.value.length === 0
  })
  const sourceEmptyText = computed(() => {
    if (sourceTab.value === 'class') return '范围内没有待填写的课'
    if (sourceTab.value === 'lead') return '暂无分配给你的留资'
    return '暂无名下会员'
  })
  const reviews = ref<PostClassReviewRow[]>([])
  const summary = reactive({ total: 0, confirmed: 0, redFlag: 0 })
  const filter = reactive({ studentType: '', status: '', onlyRedFlag: false })
  const catalog = ref<PostClassCatalog | null>(null)

  const wizardVisible = ref(false)
  const activeCandidate = ref<PostClassCandidate | null>(null)

  const detailVisible = ref(false)
  const detail = ref<Record<string, any> | null>(null)

  function sceneLabel(scene: string): string {
    return catalog.value?.scenes?.[scene] ?? scene
  }

  async function loadCatalog() {
    if (catalog.value) return
    try {
      catalog.value = await getPostClassCatalog()
    } catch {
      /* 目录缺失不阻塞列表渲染 */
    }
  }

  async function loadCandidates() {
    loading.value = true
    try {
      const r = await queryPostClassCandidates(days.value)
      candidates.value = r.records.filter((x) => !x.hasReview)
      myLeads.value = r.leads ?? []
      myMembers.value = r.members ?? []
    } catch (e) {
      ElMessage.error(String((e as { message?: string })?.message ?? e).slice(0, 120))
    } finally {
      loading.value = false
    }
  }

  async function loadReviews() {
    loading.value = true
    try {
      const r = await queryPostClassReviews({
        current: 1,
        size: 50,
        studentType: filter.studentType || undefined,
        status: filter.status || undefined,
        redFlag: filter.onlyRedFlag ? '1' : undefined
      })
      reviews.value = r.records
      Object.assign(summary, r.summary)
    } catch (e) {
      ElMessage.error(String((e as { message?: string })?.message ?? e).slice(0, 120))
    } finally {
      loading.value = false
    }
  }

  async function reload() {
    await Promise.all([loadCandidates(), loadReviews()])
  }

  function openWizard(c: PostClassCandidate | null) {
    // 三类来源统一走同一个向导：带课次的会预填上课时间与课型，
    // 只有留资/会员的则预填身份信息，上课时间由老师当场补
    activeCandidate.value = c
    wizardVisible.value = true
  }

  async function viewDetail(row: PostClassReviewRow) {
    try {
      const full = await import('@/api/yimai')
      detail.value = await full.getPostClassReview(row.id)
      detailVisible.value = true
    } catch (e) {
      ElMessage.error(String((e as { message?: string })?.message ?? e).slice(0, 120))
    }
  }

  function openSharePage(row: PostClassReviewRow) {
    const code = row.share?.code
    if (!code) return ElMessage.warning('该记录还没有分享码')
    window.open(
      `${window.location.origin}${window.location.pathname}#/s/post-class/${code}`,
      '_blank'
    )
  }

  /** 把课后分析（含体测解读）流转成训练计划草稿，老师到训练计划页继续排课次 */
  async function toTrainingPlan(row: PostClassReviewRow) {
    try {
      await ElMessageBox.confirm(
        `把「${row.studentName}」的课后分析生成一份训练计划草稿？\n会员情况、三阶段方向会直接带过去，你到训练计划里继续排课次。`,
        '生成训练计划',
        { type: 'info', confirmButtonText: '生成' }
      )
    } catch {
      return
    }
    try {
      const { planId } = await reviewToTrainingPlan(row.id)
      ElMessage.success('已生成训练计划草稿，正在跳转…')
      router.push({ path: '/yimai/training', query: { planId: String(planId) } })
    } catch (e) {
      ElMessage.error(String((e as { message?: string })?.message ?? e).slice(0, 140))
    }
  }

  async function removeReview(row: PostClassReviewRow) {
    try {
      await ElMessageBox.confirm(
        `确定删除「${row.studentName}」的课后分析？删除后无法恢复。`,
        '删除确认',
        { type: 'warning' }
      )
    } catch {
      return
    }
    try {
      await deletePostClassReview(row.id)
      ElMessage.success('已删除')
      await reload()
    } catch (e) {
      ElMessage.error(String((e as { message?: string })?.message ?? e).slice(0, 120))
    }
  }

  // ---------- 手持设备卡片 ----------
  //
  // 卡片不是「把表格横过来」：每个列表只留「谁、什么时候、该点哪个按钮」，
  // 权限判断、状态色、操作函数全部沿用表格里的口径，卡片只是另一种呈现。
  // 判定阈值见 src/config/breakpoints.ts。

  /** 四个列表（三类待填写 + 已填写）共用同一套卡片字段 */
  interface PostClassCardRow {
    id: string
    title: string
    subtitle: string
    tags: MobileCardTag[]
    metrics: MobileCardMetric[]
    note: { text: string; label: string; danger: boolean } | null
    /** 只读角色（服务老师）看不到操作按钮时的一句话说明，等价于表格里的「待老师填写」 */
    hint: string
    actions: MobileCardAction[]
  }

  /** 候选记录没有统一主键（三类来源各有各的业务 id），按可用字段拼一个稳定的列表 key */
  function candidateKey(row: PostClassCandidate, index: number): string {
    if (row.bookingId) return `booking-${row.bookingId}`
    if (row.leadId) return `lead-${row.leadId}`
    if (row.customerId) return `customer-${row.customerId}`
    return `row-${index}`
  }

  const writeAction = (row: PostClassCandidate): MobileCardAction => ({
    text: '填写分析',
    type: 'primary',
    show: canWrite.value,
    onClick: () => openWizard(row)
  })

  const writeHint = computed(() => (canWrite.value ? '' : '待老师填写'))

  /** 待填写 · 上过课：老师按「哪天上了什么课」找人，所以时间与课程放副标题 */
  function candidateCard(row: PostClassCandidate, index: number): PostClassCardRow {
    const tags: MobileCardTag[] = []
    if (row.isTrial) {
      tags.push({ text: '体验课', type: 'warning', effect: 'dark' })
    } else if (row.kind) {
      tags.push({ text: row.kind, type: row.kind === '私教' ? 'danger' : 'info', effect: 'plain' })
    }
    if (row.personType === 'member') {
      tags.push({ text: '老会员', type: 'success', effect: 'plain' })
    } else if (row.personType === 'lead') {
      tags.push({ text: '新建客资', type: 'warning', effect: 'plain' })
    } else {
      tags.push({ text: '未建档', type: 'info', effect: 'plain' })
    }
    if (row.teacherName) tags.push({ text: row.teacherName, effect: 'plain' })

    const metrics: MobileCardMetric[] = []
    if (row.memberRemain !== null && row.memberRemain !== undefined) {
      metrics.push({ label: '剩余课时', value: row.memberRemain, unit: '节' })
    }

    // 表格「来源」标签后跟的那句灰色小字：老会员给主卡名，客资给留资状态
    let note: PostClassCardRow['note'] = null
    if (row.memberCard) note = { label: '会员卡', text: row.memberCard, danger: false }
    else if (row.leadStatus) note = { label: '留资状态', text: row.leadStatus, danger: false }

    return {
      id: candidateKey(row, index),
      title: row.studentName,
      subtitle: [[row.date, row.time].filter(Boolean).join(' '), row.courseName || '—']
        .filter(Boolean)
        .join(' · '),
      tags,
      metrics,
      note,
      hint: writeHint.value,
      actions: [writeAction(row)]
    }
  }

  /** 待填写 · 我的留资：还没有课次，重点是身份、状态与需求 */
  function leadCandidateCard(row: PostClassCandidate, index: number): PostClassCardRow {
    const tags: MobileCardTag[] = [
      {
        text: row.leadStatus ?? '',
        type:
          row.leadStatus === '已成交'
            ? 'success'
            : row.leadStatus === '新留资'
              ? 'danger'
              : 'primary',
        effect: 'dark'
      }
    ]
    if (row.leadSource) tags.push({ text: row.leadSource, effect: 'plain' })
    if (row.venue) tags.push({ text: row.venue, effect: 'plain' })

    return {
      id: candidateKey(row, index),
      title: row.studentName,
      subtitle: `手机号 ${row.phone || '—'}${row.leadDate ? ` · 留资 ${row.leadDate}` : ''}`,
      tags,
      metrics: [],
      note: row.demand ? { label: '需求', text: row.demand, danger: false } : null,
      hint: writeHint.value,
      actions: [writeAction(row)]
    }
  }

  /** 待填写 · 我的会员：主卡与剩余节数是老师当场要看的两个信息 */
  function memberCandidateCard(row: PostClassCandidate, index: number): PostClassCardRow {
    const tags: MobileCardTag[] = []
    if (row.venue) tags.push({ text: row.venue, effect: 'plain' })
    tags.push(
      row.hasReview
        ? { text: '已填写', type: 'success', effect: 'plain' }
        : { text: '未填写', type: 'info', effect: 'plain' }
    )

    const remain = row.remainTimes ?? null

    return {
      id: candidateKey(row, index),
      title: row.studentName,
      subtitle: `手机号 ${row.phone || '—'}`,
      tags,
      metrics: [{ label: '剩余节数', value: remain ?? '—', unit: remain === null ? '' : '节' }],
      note: row.mainCard ? { label: '主卡', text: row.mainCard, danger: false } : null,
      hint: writeHint.value,
      actions: [writeAction(row)]
    }
  }

  /** 已填写：复盘时最关心「有没有红线、成交归因、对客页有没有人看」 */
  function reviewCard(row: PostClassReviewRow): PostClassCardRow {
    const tags: MobileCardTag[] = [
      row.redFlag
        ? { text: '红线 · 不建议排课', type: 'danger', effect: 'dark' }
        : { text: row.status, type: row.status === '已确认' ? 'success' : 'info', effect: 'plain' }
    ]
    if (row.studentType) tags.push({ text: row.studentType, effect: 'plain' })
    if (row.teacherName) tags.push({ text: row.teacherName, effect: 'plain' })

    const shareEnabled = Boolean(row.share?.enabled)

    return {
      id: `review-${row.id}`,
      title: row.studentName,
      subtitle: row.classAt || '—',
      tags,
      metrics: [
        {
          label: '成交归因',
          value: row.leadStatus === '已成交' ? `¥${row.dealAmount}` : row.leadStatus || '—'
        },
        {
          label: '对客页',
          value: shareEnabled ? (row.share?.views ?? 0) : '未开启',
          unit: shareEnabled ? '次' : ''
        }
      ],
      note: null,
      hint: '',
      // 「查看」是唯一的通用操作，排最前才不会被收进「更多」
      actions: [
        { text: '查看', type: 'primary', onClick: () => viewDetail(row) },
        {
          text: '对客页',
          type: 'success',
          show: !row.redFlag && shareEnabled,
          onClick: () => openSharePage(row)
        },
        {
          text: '转计划',
          type: 'warning',
          show: canWrite.value && !row.redFlag && row.status === '已确认',
          onClick: () => toTrainingPlan(row)
        },
        { text: '删除', type: 'danger', onClick: () => removeReview(row) }
      ]
    }
  }

  /** 预计算一次，避免模板里对每行重复调用多个函数 */
  const pendingCardRows = computed<PostClassCardRow[]>(() => {
    if (sourceTab.value === 'class') return filteredCandidates.value.map(candidateCard)
    if (sourceTab.value === 'lead') return myLeads.value.map(leadCandidateCard)
    return myMembers.value.map(memberCandidateCard)
  })

  const reviewCardRows = computed<PostClassCardRow[]>(() => reviews.value.map(reviewCard))

  watch(tab, (v) => {
    if (v === 'pending') loadCandidates()
    else loadReviews()
  })

  onMounted(async () => {
    if (!canWrite.value) tab.value = 'done'
    await loadCatalog()
    await reload()
  })
</script>

<style scoped lang="scss">
  .sec-title {
    margin: 16px 0 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .detail-list {
    margin: 0;
    padding-left: 18px;
    font-size: 13px;
    line-height: 2;
    color: var(--el-text-color-regular);
  }

  .phase-box {
    padding: 8px 10px;
    margin-bottom: 8px;
    border-radius: 8px;
    background: var(--el-fill-color-light);
  }

  .prompt-line {
    font-size: 14px;
    line-height: 1.9;

    &.highlight {
      font-weight: 600;
      color: var(--el-color-primary);
    }

    &.muted {
      color: var(--el-text-color-secondary);
    }
  }

  // 移动端卡片里「只读角色没有操作按钮」的说明行
  .pc-card__hint {
    margin-top: 10px;
    font-size: 12px;
    color: var(--art-gray-500);
  }
</style>
