<script setup lang="ts">
/**
 * 客户经营 hub（tab 页，自定义头）
 *
 * 职责只有一个：把「客户全生命周期」的入口按角色摆出来，并把两个跨页角标提前算好。
 *  - 入口不写死：一律 visibleFeatures(roles, 'crm' | 'biz')，新增页面只在 permission.ts 注册；
 *  - 跳转不判断 tab：一律 goFeature(key) → utils/nav 的 goPath 收口 switchTab / navigateTo；
 *  - 角标只有两个来源：/today/todo 的未完成数、/post-class-reviews/pending-count。
 *
 * 角标口径说明（做过兼容，不写死 .length）：
 *  /today/todo 历史上出现过三种形态——TodoGroup[]、Record<组名, TodoItem[]>，
 *  以及当前后端的 Record + `bookings:{items,trialCount}` + `counts` 汇总。三者都要能数。
 *  只数「今日待办页真正渲染的 7 组」，`tasks` 归任务中心，不重复计数。
 */
import { computed, ref } from 'vue'
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { requireLogin } from '@/utils/guard'
import { goFeature } from '@/utils/nav'
import { visibleFeatures, type FeatureDef } from '@/utils/permission'
import { fetchTodayTodo } from '@/api/today'
import { fetchReviewPendingCount } from '@/api/review'

const user = useUserStore()

const todoPending = ref<number | null>(null)
const reviewPending = ref<number | null>(null)
const loading = ref(false)
const updatedAt = ref('')

/** 客户经营主入口（注册表顺序 = 产品定义的优先级） */
const crmEntries = computed<FeatureDef[]>(() => visibleFeatures(user.roles, 'crm'))
/** 业务执行分组 */
const bizEntries = computed<FeatureDef[]>(() => visibleFeatures(user.roles, 'biz'))

/** 当前可见范围：角色 + 门店 + 入口数（让人一眼知道「为什么我看不到某些入口」） */
const scopeText = computed(() => {
  const role = user.roleText || '未分配角色'
  const venue = user.venue || (user.isSuper ? '双店' : '全部门店')
  const count = crmEntries.value.length + bizEntries.value.length
  return `${role} · ${venue} · 可见 ${count} 项`
})

const heroBadge = computed(() => {
  const n = todoPending.value
  return n !== null && n > 0 ? (n > 99 ? '99+' : String(n)) : ''
})

/** 顶部小字用门店名（超管没有单一门店时回品牌名） */
const heroEyebrow = computed(() => user.venue || '一麦瑜伽')

const hasAnyEntry = computed(() => crmEntries.value.length + bizEntries.value.length > 0)

/* ---------------- /today/todo 未完成数（三种形态兼容） ---------------- */

const TODO_GROUP_KEYS = [
  'bookings',
  'renewals',
  'churnRisks',
  'birthdays',
  'trials',
  'reviews',
  'newLeads'
] as const

interface TodoGroupLike {
  type?: unknown
  items?: unknown
}

function isDone(item: unknown): boolean {
  return !!item && typeof item === 'object' && (item as { done?: unknown }).done === true
}

function countPending(items: unknown): number {
  if (!Array.isArray(items)) return 0
  return items.filter((item) => !isDone(item)).length
}

/** 取一组的条目：既支持数组，也支持 { items: [...] }（bookings 是后一种） */
function sectionItems(section: unknown): unknown[] | null {
  if (Array.isArray(section)) return section
  const items = (section as { items?: unknown } | null | undefined)?.items
  return Array.isArray(items) ? items : null
}

function countTodoPending(raw: unknown): number | null {
  if (!raw) return null

  // 形态 A：TodoGroup[]
  if (Array.isArray(raw)) {
    let sum = 0
    let matched = false
    raw.forEach((group) => {
      if (!group || typeof group !== 'object') return
      const type = String((group as TodoGroupLike).type ?? '')
      if (type && !(TODO_GROUP_KEYS as readonly string[]).includes(type)) return
      const items = sectionItems((group as TodoGroupLike).items) ?? sectionItems(group)
      if (items) {
        sum += countPending(items)
        matched = true
      }
    })
    return matched ? sum : countPending(raw)
  }

  // 形态 B：Record<组名, 条目[] | { items: [] }>
  if (typeof raw === 'object') {
    const record = raw as Record<string, unknown>
    let sum = 0
    let matched = false
    TODO_GROUP_KEYS.forEach((key) => {
      const items = sectionItems(record[key])
      if (items) {
        sum += countPending(items)
        matched = true
      }
    })
    if (matched) return sum

    // 形态 C：只有 counts 汇总（此时无法扣掉「已处理」，退化为原始计数）
    const counts = record.counts
    if (counts && typeof counts === 'object') {
      return TODO_GROUP_KEYS.reduce((total, key) => {
        const value = (counts as Record<string, unknown>)[key]
        return total + (typeof value === 'number' ? value : 0)
      }, 0)
    }
  }

  return null
}

/** /post-class-reviews/pending-count 可能回数字或 {count} */
function normalizeCount(raw: unknown): number | null {
  if (typeof raw === 'number') return raw
  const value = (raw as { count?: unknown } | null | undefined)?.count
  return typeof value === 'number' ? value : null
}

function stamp(): string {
  const now = new Date()
  return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`
}

async function loadCounts(): Promise<void> {
  if (loading.value) return
  loading.value = true
  try {
    const [todo, review] = await Promise.all([
      fetchTodayTodo().catch(() => null),
      fetchReviewPendingCount().catch(() => null)
    ])
    todoPending.value = countTodoPending(todo)
    reviewPending.value = normalizeCount(review)
    updatedAt.value = stamp()
  } finally {
    loading.value = false
  }
}

/* ---------------- 交互 ---------------- */

function openFeature(key: string): void {
  goFeature(key)
}

/** 入口角标文案（0 与 null 都不显示，超过 99 收成 99+） */
function badgeText(key: string): string {
  const map: Record<string, number | null> = {
    todo: todoPending.value,
    reviews: reviewPending.value
  }
  const value = map[key]
  if (value === null || value === undefined || value <= 0) return ''
  return value > 99 ? '99+' : String(value)
}

onShow(() => {
  if (requireLogin()) loadCounts()
})

onPullDownRefresh(async () => {
  await loadCounts()
  uni.stopPullDownRefresh()
})
</script>

<template>
  <view class="ym-page ym-page--tab crm">
    <ym-hero :eyebrow="heroEyebrow" title="客户经营" :subtitle="scopeText" compact>
      <template #badge>
        <view v-if="heroBadge" class="crm__hero-badge ym-tap" @click="openFeature('todo')">
          <text class="crm__hero-badge-num ym-num">{{ heroBadge }}</text>
          <text class="crm__hero-badge-label">今日待办</text>
        </view>
      </template>
    </ym-hero>

    <!-- 主入口：第一条（今日待办）通栏，其余两列，按角色自动收敛 -->
    <view v-if="crmEntries.length" class="crm__entries">
      <view
        v-for="(entry, index) in crmEntries"
        :key="entry.key"
        class="crm-entry ym-tap"
        :class="[`is-${entry.tone || 'plain'}`, { 'is-wide': index === 0 }]"
        @click="openFeature(entry.key)"
      >
        <view class="crm-entry__icon">
          <wd-icon :name="entry.icon" size="19px" />
        </view>
        <view class="crm-entry__body">
          <view class="crm-entry__title-row">
            <text class="crm-entry__title">{{ entry.title }}</text>
            <text v-if="badgeText(entry.key)" class="crm-entry__badge ym-num">
              {{ badgeText(entry.key) }}
            </text>
          </view>
          <text class="crm-entry__desc">{{ entry.desc }}</text>
        </view>
        <wd-icon name="chevron-right" size="14px" custom-class="crm-entry__arrow" />
      </view>
    </view>

    <ym-empty
      v-if="!hasAnyEntry"
      text="当前账号暂无可用的客户经营入口"
      desc="入口按角色下发；如需开通请找管理员调整角色"
      icon="lock-on"
    />

    <!-- 业务执行：按权限过滤后的动作入口 -->
    <ym-section
      v-if="bizEntries.length"
      title="业务执行"
      desc="按角色显示可用动作"
      class="crm__biz"
    >
      <ym-cell-row
        v-for="(entry, index) in bizEntries"
        :key="entry.key"
        :label="entry.title"
        :hint="entry.desc"
        arrow
        :divider="index < bizEntries.length - 1"
        @click="openFeature(entry.key)"
      >
        <template #left>
          <view class="crm__biz-icon">
            <wd-icon :name="entry.icon" size="16px" />
          </view>
        </template>
        <template #right>
          <text v-if="badgeText(entry.key)" class="crm__biz-badge ym-num">
            {{ badgeText(entry.key) }}
          </text>
        </template>
      </ym-cell-row>
    </ym-section>

    <view class="crm__foot">
      <text class="ym-muted">
        {{ updatedAt ? `角标更新于 ${updatedAt} · ` : '' }}入口可见性按角色裁剪，客户数据范围仍由后端按角色隔离
      </text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.crm {
  &__hero-badge {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2rpx;
    padding: 10rpx 20rpx;
    background: rgba(255, 255, 255, 0.14);
    border: 2rpx solid rgba(255, 255, 255, 0.18);
    border-radius: 20rpx;
  }

  &__hero-badge-num {
    font-size: 36rpx;
    font-weight: 600;
    line-height: 1.1;
    color: #fff;
  }

  &__hero-badge-label {
    font-size: 20rpx;
    letter-spacing: 1rpx;
    color: rgba(255, 255, 255, 0.72);
  }

  &__entries {
    display: flex;
    flex-wrap: wrap;
    gap: 20rpx;
    padding: 24rpx;
  }

  &__biz {
    margin-top: 0;
  }

  &__biz-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 60rpx;
    height: 60rpx;
    color: $brand-600;
    background: $brand-50;
    border-radius: 16rpx;
  }

  &__biz-badge {
    min-width: 40rpx;
    padding: 2rpx 10rpx;
    font-size: 20rpx;
    color: $danger-600;
    text-align: center;
    background: $danger-100;
    border-radius: 999rpx;
  }

  &__foot {
    padding: 8rpx 30rpx 32rpx;
    line-height: 1.6;
  }
}

.crm-entry {
  display: flex;
  align-items: center;
  gap: 20rpx;
  width: calc(50% - 10rpx);
  min-height: 148rpx;
  padding: 26rpx 22rpx;
  background: $white;
  border-radius: $r-lg;
  box-shadow: $shadow-card;

  &.is-wide {
    width: 100%;
  }

  &.is-brand {
    background: linear-gradient(150deg, $brand-50, $white 72%);
  }

  &.is-gold {
    background: linear-gradient(150deg, $gold-100, $white 72%);
  }

  &__icon {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 76rpx;
    height: 76rpx;
    color: $brand-600;
    background: $brand-100;
    border-radius: 24rpx;
  }

  &.is-gold &__icon {
    color: $gold-600;
    background: $gold-100;
  }

  &__body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 6rpx;
    min-width: 0;
  }

  &__title-row {
    display: flex;
    align-items: center;
    gap: 10rpx;
  }

  &__title {
    font-size: 30rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__badge {
    min-width: 36rpx;
    padding: 2rpx 10rpx;
    font-size: 20rpx;
    color: #fff;
    text-align: center;
    background: $danger-500;
    border-radius: 999rpx;
  }

  &__desc {
    overflow: hidden;
    font-size: 22rpx;
    line-height: 1.4;
    color: $ink-400;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__arrow {
    flex-shrink: 0;
    color: $ink-200;
  }
}
</style>
