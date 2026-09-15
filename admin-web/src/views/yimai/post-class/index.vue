<template>
  <div class="p-4">
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
            <span class="text-xs text-gray-500">范围</span>
            <ElRadioGroup v-model="days" size="small" @change="loadCandidates">
              <ElRadioButton :value="3">近 3 天</ElRadioButton>
              <ElRadioButton :value="7">近 7 天</ElRadioButton>
              <ElRadioButton :value="30">近 30 天</ElRadioButton>
            </ElRadioGroup>
            <span class="text-xs text-gray-400"> 含体验课与私教课；已填写的会自动移出该列表 </span>
          </div>

          <ElTable :data="candidates" v-loading="loading" size="default">
            <ElTableColumn prop="date" label="日期" width="105" />
            <ElTableColumn prop="time" label="时间" width="70" />
            <ElTableColumn prop="studentName" label="学员" width="110" />
            <ElTableColumn prop="courseName" label="课程" min-width="140" show-overflow-tooltip />
            <ElTableColumn label="课型" width="150">
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
            <ElTableColumn label="客资状态" width="100">
              <template #default="{ row }">
                <ElTag v-if="row.leadStatus" size="small" effect="plain">{{
                  row.leadStatus
                }}</ElTag>
                <span v-else class="text-xs text-gray-400">—</span>
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
          <ElEmpty
            v-if="!loading && !candidates.length"
            description="范围内没有待填写的课"
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

          <ElTable :data="reviews" v-loading="loading" size="default">
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
            <ElTableColumn label="操作" width="200" fixed="right">
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
                <ElButton link type="danger" size="small" @click="removeReview(row)">删除</ElButton>
              </template>
            </ElTableColumn>
          </ElTable>
          <ElEmpty
            v-if="!loading && !reviews.length"
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
    queryPostClassCandidates,
    queryPostClassReviews
  } from '@/api/yimai'
  import type { PostClassCatalog, PostClassCandidate, PostClassReviewRow } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'

  defineOptions({ name: 'YimaiPostClass' })

  // 服务老师（会籍顾问）对本页只读：看自己名下会员的课后分析与顾问衔接，填写由授课老师完成
  const userStore = useUserStore()
  const canWrite = computed(() => {
    const roles = userStore.getUserInfo.roles ?? []
    return roles.some((r: string) => ['R_SUPER', 'R_MANAGER', 'R_TEACHER'].includes(r))
  })

  const tab = ref('pending')
  const loading = ref(false)
  const days = ref(7)
  const candidates = ref<PostClassCandidate[]>([])
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
    window.open(`${location.origin}/s/post-class/${code}`, '_blank')
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
</style>
