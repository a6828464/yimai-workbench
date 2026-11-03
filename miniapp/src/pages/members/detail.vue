<script setup lang="ts">
/**
 * 会员详情（移动端）
 *
 * 读：GET /customers/{id} → { customer, logs, leads }（客户 360）
 * 写：PATCH /customers/{id}，只用后端白名单里的字段：
 *     lastTouch（最近沟通）/ birthday（生日）/ inRevive（标记待复活）
 *
 * 为什么只做这三个：
 *  - 现场最常用、且是单值（不需要并排核对表格）；
 *  - 卡项、续费评估这类**计算派生**字段在手机上只读展示，改动一律回桌面，
 *    避免「手机上改一个数、桌面口径对不上」。
 */
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { fetchCustomerDetail, updateCustomer, type CustomerPatch } from '@/api/members'
import type { CustomerDetail } from '@/types'

const id = ref(0)
const detail = ref<CustomerDetail | null>(null)
const loading = ref(false)
const saving = ref(false)

/** 本地编辑态（与服务端值分离，保存成功后才回写） */
const form = ref({ lastTouch: '', birthday: '', inRevive: false })

const c = computed(() => detail.value?.customer ?? null)

function todayStr(): string {
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

function toDateStr(v?: string | null): string {
  if (!v) return ''
  return String(v).slice(0, 10)
}

function syncForm() {
  const row = c.value
  if (!row) return
  form.value = {
    lastTouch: toDateStr(row.lastTouch),
    birthday: toDateStr(row.birthday),
    inRevive: Boolean(row.inRevive)
  }
}

async function load() {
  if (!id.value) return
  loading.value = true
  try {
    detail.value = await fetchCustomerDetail(id.value)
    syncForm()
  } catch {
    /* http 层已 toast */
  } finally {
    loading.value = false
  }
}

function call() {
  const phone = c.value?.phone
  if (!phone) {
    uni.showToast({ title: '无手机号', icon: 'none' })
    return
  }
  uni.makePhoneCall({ phoneNumber: phone, fail: () => {} })
}

function copyPhone() {
  const phone = c.value?.phone
  if (!phone) return
  uni.setClipboardData({ data: phone, success: () => uni.showToast({ title: '已复制', icon: 'none' }) })
}

function onDateChange(e: { detail: { value: string } }, field: 'lastTouch' | 'birthday') {
  form.value[field] = e.detail.value
}

/**
 * uni 的 <switch> 事件：@change 回传 { detail: { value: boolean } }。
 * 这里按通用 Event 接再断言，是因为 vue-tsc 会把同名标签当作 SVG 的 <switch>，
 * 直接写 { detail } 的参数类型会与 SVG 的 (payload: Event) 冲突。
 */
function onToggleRevive(e: Event) {
  const v = (e as unknown as { detail?: { value?: boolean } })?.detail?.value
  form.value.inRevive = Boolean(v)
}

async function save() {
  if (!c.value || saving.value) return
  saving.value = true
  try {
    const patch: CustomerPatch = {
      lastTouch: form.value.lastTouch || null,
      birthday: form.value.birthday || null
    }
    // inRevive 只在发生变化时提交：它触发后端重算 layer，无谓写入会白跑一次重算
    if (form.value.inRevive !== Boolean(c.value.inRevive)) {
      patch.inRevive = form.value.inRevive
    }
    await updateCustomer(c.value.id, patch, '移动端维护')
    uni.showToast({ title: '已保存', icon: 'success' })
    await load()
  } catch {
    /* http 层已 toast */
  } finally {
    saving.value = false
  }
}

function money(v?: number | null): string {
  if (v === null || v === undefined) return '—'
  return `¥${Number(v).toLocaleString('zh-CN')}`
}

function levelText(): string {
  const row = c.value
  if (!row || row.evalScore === null || row.evalScore === undefined) return '待评估'
  const map: Record<string, string> = { high: '高机会', medium: '重点培育', low: '风险修复' }
  return `${row.evalScore} · ${map[row.evalLevel || ''] ?? '—'}`
}

function logTime(v?: string): string {
  if (!v) return ''
  return String(v).replace('T', ' ').slice(0, 16)
}

function toLeadForm(leadId: number) {
  // 关联留资只在详情里展示跳转；编辑回列表页做（列表行已有完整字段）
  uni.navigateTo({ url: `/pages/leads/leads?focus=${leadId}` })
}

onLoad((options) => {
  id.value = Number((options as Record<string, string>)?.id ?? 0)
  // onLoad 里才拿到 id，因此首次加载放在这里（setup 顶层执行时 id 仍是 0）
  if (requireLogin()) load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})
</script>

<template>
  <view class="ym-page member-detail">
    <view v-if="!c && loading" class="ym-empty">加载中…</view>
    <view v-if="!c && !loading" class="ym-empty">未找到该会员</view>

    <template v-if="c">
      <view class="ym-card detail__head">
        <view class="detail__head-row">
          <text class="detail__name">{{ c.name }}</text>
          <text class="ym-tag">{{ c.layer }}</text>
        </view>
        <text class="ym-muted">{{ c.venue }}<text v-if="c.consultant"> · 会籍顾问 {{ c.consultant }}</text></text>
        <view class="detail__phone-row">
          <text class="detail__phone">{{ c.phone || `尾号 ${c.phoneTail}` }}</text>
          <text class="detail__link" @click="copyPhone">复制</text>
          <text class="detail__link" @click="call">拨打</text>
        </view>
        <view class="detail__tags">
          <text v-if="c.source" class="ym-tag">来源 {{ c.source }}</text>
          <text v-if="c.status" class="ym-tag">{{ c.status }}</text>
          <text v-if="c.nextAction" class="ym-tag ym-tag--warn">{{ c.nextAction }}</text>
        </view>
      </view>

      <view class="ym-card">
        <text class="ym-title">卡项与出勤</text>
        <view class="detail__grid">
          <view class="detail__cell">
            <text class="detail__cell-value">{{ c.remainTimes ?? '—' }}</text>
            <text class="detail__cell-label">剩余课时</text>
          </view>
          <view class="detail__cell">
            <text class="detail__cell-value">{{ toDateStr(c.expireDate) || '—' }}</text>
            <text class="detail__cell-label">到期日</text>
          </view>
          <view class="detail__cell">
            <text class="detail__cell-value">{{ c.attendM3 ?? 0 }}</text>
            <text class="detail__cell-label">近30天出勤</text>
          </view>
          <view class="detail__cell">
            <text class="detail__cell-value">{{ money(c.cardPaidAmount) }}</text>
            <text class="detail__cell-label">累计购买</text>
          </view>
        </view>
        <view v-if="c.mainCard" class="detail__line">
          <text class="ym-muted">主卡</text>
          <text>{{ c.mainCard }}</text>
        </view>
        <view class="detail__line">
          <text class="ym-muted">近三档出勤</text>
          <text>{{ c.attendM1 ?? 0 }} / {{ c.attendM2 ?? 0 }} / {{ c.attendM3 ?? 0 }}</text>
        </view>
        <view class="detail__line">
          <text class="ym-muted">最近到店</text>
          <text>{{ toDateStr(c.lastVisit) || '无记录' }}</text>
        </view>
      </view>

      <view class="ym-card">
        <text class="ym-title">续费评估</text>
        <view class="detail__line">
          <text class="ym-muted">30 天评估</text>
          <text>{{ levelText() }}</text>
        </view>
        <view v-if="c.evalAt" class="detail__line">
          <text class="ym-muted">评估时间</text>
          <text>{{ toDateStr(c.evalAt) }}</text>
        </view>
        <view v-if="c.renewalPlan?.time" class="detail__line">
          <text class="ym-muted">续课计划</text>
          <text>{{ c.renewalPlan.intent }} · {{ c.renewalPlan.time }}</text>
        </view>
        <view v-if="c.decline?.reason" class="detail__line">
          <text class="ym-muted">下降原因</text>
          <text>{{ c.decline.reason }}</text>
        </view>
      </view>

      <view class="ym-card">
        <text class="ym-title">移动端可维护</text>
        <text class="ym-muted detail__tip">只改这三项；卡项与评估结果请回电脑端操作</text>

        <view class="detail__line detail__line--tap">
          <text>最近沟通</text>
          <picker mode="date" :value="form.lastTouch" @change="onDateChange($event, 'lastTouch')">
            <text :class="form.lastTouch ? 'detail__value' : 'ym-muted'">{{ form.lastTouch || '未填写' }}</text>
          </picker>
        </view>

        <view class="detail__line detail__line--tap">
          <text>生日</text>
          <picker mode="date" :value="form.birthday || todayStr()" @change="onDateChange($event, 'birthday')">
            <text :class="form.birthday ? 'detail__value' : 'ym-muted'">{{ form.birthday || '未填写' }}</text>
          </picker>
        </view>

        <view class="detail__line detail__line--tap">
          <text>标记待复活</text>
          <switch :checked="form.inRevive" color="#2E7D5B" @change="onToggleRevive" />
        </view>

        <button class="ym-btn detail__save" :disabled="saving" @click="save">
          {{ saving ? '保存中…' : '保存' }}
        </button>
      </view>

      <view v-if="detail?.leads?.length" class="ym-card">
        <text class="ym-title">关联留资（同手机号）</text>
        <view v-for="l in detail.leads" :key="l.id" class="detail__lead" @click="toLeadForm(l.id)">
          <view class="detail__lead-row">
            <text>{{ l.name }}</text>
            <text class="ym-tag">{{ l.status }}</text>
          </view>
          <text class="ym-muted">{{ l.leadDate?.slice(0, 10) }} · {{ l.source }} · {{ l.demand || '未填需求' }}</text>
        </view>
      </view>

      <view v-if="detail?.logs?.length" class="ym-card">
        <text class="ym-title">操作留痕</text>
        <view v-for="log in detail.logs.slice(0, 8)" :key="log.id" class="detail__log">
          <text class="detail__log-main">{{ log.action }} · {{ log.detail }}</text>
          <text class="ym-muted">{{ logTime(log.createdAt) }}<text v-if="log.operator"> · {{ log.operator }}</text></text>
        </view>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.member-detail {
  padding-top: 20rpx;
}

.detail {
  &__head-row {
    display: flex;
    align-items: center;
    gap: 14rpx;
  }

  &__name {
    font-size: 40rpx;
    font-weight: 600;
  }

  &__phone-row {
    display: flex;
    align-items: center;
    margin-top: 18rpx;
    gap: 22rpx;
  }

  &__phone {
    font-size: 30rpx;
  }

  &__link {
    color: #2e7d5b;
    font-size: 26rpx;
  }

  &__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 12rpx;
    margin-top: 18rpx;
  }

  &__grid {
    display: flex;
    flex-wrap: wrap;
    margin-top: 18rpx;
  }

  &__cell {
    width: 50%;
    padding: 14rpx 0;
    display: flex;
    flex-direction: column;
  }

  &__cell-value {
    font-size: 32rpx;
    font-weight: 600;
  }

  &__cell-label {
    margin-top: 6rpx;
    font-size: 22rpx;
    color: #8a8f99;
  }

  &__line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 80rpx;
    border-top: 2rpx solid #f0f1f3;
    font-size: 27rpx;
  }

  &__line--tap {
    border-top: 2rpx solid #f0f1f3;
  }

  &__tip {
    display: block;
    margin-top: 8rpx;
  }

  &__value {
    color: #2e7d5b;
  }

  &__save {
    margin-top: 26rpx;
  }

  &__lead {
    margin-top: 18rpx;
    padding-top: 18rpx;
    border-top: 2rpx solid #f0f1f3;
  }

  &__lead-row {
    display: flex;
    align-items: center;
    gap: 12rpx;
    margin-bottom: 6rpx;
  }

  &__log {
    display: flex;
    flex-direction: column;
    margin-top: 16rpx;
    padding-top: 16rpx;
    border-top: 2rpx solid #f0f1f3;
  }

  &__log-main {
    font-size: 26rpx;
    margin-bottom: 6rpx;
  }
}
</style>
