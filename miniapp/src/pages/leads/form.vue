<script setup lang="ts">
/**
 * 留资 新增 / 编辑（移动端）
 *
 * 新增：POST /leads（name / venue / source 必填；status 缺省后端补「新留资」）
 * 编辑：PATCH /leads/{id} —— **只提交本次改动的字段**
 *
 * 为什么编辑只提交改动：
 * ① 后端 update 对 source/orderPlatform 的语义是「改动才校验」——原样带回的
 *    历史值放行，改动必须落在枚举内。少提交就不存在把历史值改坏的机会。
 * ② web 端编辑弹窗提交整个表单，是它自己的历史包袱；小程序没有必须照抄的理由。
 *
 * 编辑态数据来源：后端没有 GET /leads/{id}，列表页把整行放进本地草稿
 * （stageLeadDraft / takeLeadDraft，键 yimai-lead-draft），用完即删。
 */
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { useUserStore } from '@/store/user'
import {
  FALLBACK_LEAD_ENUMS,
  LEAD_GRADES,
  LEAD_STATUSES,
  checkLead,
  createLead,
  fetchLeads,
  takeLeadDraft,
  updateLead,
  type LeadPayload
} from '@/api/leads'
import type { MiniappLead } from '@/types'

const user = useUserStore()
const VENUES = ['绿地店', '东部店']
const GRADE_LABELS = ['未分级', 'A', 'B', 'C']

const mode = ref<'create' | 'edit'>('create')
const id = ref(0)
const origin = ref<MiniappLead | null>(null)
const saving = ref(false)
const dupTip = ref('')

const sources = ref<string[]>([...FALLBACK_LEAD_ENUMS.sources])
const platforms = ref<string[]>([...FALLBACK_LEAD_ENUMS.orderPlatforms])

const form = ref({
  name: '',
  phone: '',
  wechat: '',
  venue: (user.venue as string) || '绿地店',
  source: '',
  orderPlatform: '',
  demand: '',
  serviceTeacher: '',
  status: '新留资',
  grade: '',
  dealCard: '',
  dealAmount: '',
  remark: ''
})

const venueIndex = computed(() => Math.max(0, VENUES.indexOf(form.value.venue)))
const sourceIndex = computed(() => Math.max(0, sources.value.indexOf(form.value.source)))
const platformIndex = computed(() => Math.max(0, platforms.value.indexOf(form.value.orderPlatform)))
const statusIndex = computed(() => Math.max(0, LEAD_STATUSES.indexOf(form.value.status as never)))
const gradeIndex = computed(() => Math.max(0, LEAD_GRADES.indexOf(form.value.grade as never)))

/** 首屏把渠道清单补全（列表接口随响应下发 enums，是唯一权威来源） */
async function loadEnums() {
  try {
    const res = await fetchLeads({ current: 1, size: 1 })
    if (res.enums?.sources?.length) sources.value = res.enums.sources
    if (res.enums?.orderPlatforms?.length) platforms.value = res.enums.orderPlatforms
  } catch {
    /* 兜底清单已在，加载失败不阻塞录入 */
  }
}

function hydrate(lead: MiniappLead) {
  form.value = {
    name: lead.name ?? '',
    phone: lead.phone ?? '',
    wechat: lead.wechat ?? '',
    venue: lead.venue || '绿地店',
    source: lead.source || '',
    orderPlatform: lead.orderPlatform || '',
    demand: lead.demand || '',
    serviceTeacher: lead.serviceTeacher || '',
    status: lead.status || '新留资',
    grade: lead.grade || '',
    dealCard: lead.dealCard || '',
    dealAmount: lead.dealAmount ? String(lead.dealAmount) : '',
    remark: (lead.remark as string) || ''
  }
}

function onPickVenue(e: { detail: { value: number | string } }) {
  form.value.venue = VENUES[Number(e.detail.value)] ?? form.value.venue
}

function onPickSource(e: { detail: { value: number | string } }) {
  form.value.source = sources.value[Number(e.detail.value)] ?? ''
}

function onPickPlatform(e: { detail: { value: number | string } }) {
  form.value.orderPlatform = platforms.value[Number(e.detail.value)] ?? ''
}

function onPickStatus(e: { detail: { value: number | string } }) {
  form.value.status = LEAD_STATUSES[Number(e.detail.value)] ?? '新留资'
}

function onPickGrade(e: { detail: { value: number | string } }) {
  form.value.grade = LEAD_GRADES[Number(e.detail.value)] ?? ''
}

/** 手机号查重：命中会员或已有留资时给出**事实**，不阻断录入 */
async function checkDup() {
  const phone = form.value.phone.trim()
  dupTip.value = ''
  if (phone.length < 4) return
  try {
    const res = await checkLead(phone)
    if (!res?.exists) return
    const lines = (res.matches ?? []).slice(0, 3).map((m) => `${m.kind}：${m.name}（${m.detail}）`)
    dupTip.value = lines.join('；')
  } catch {
    /* 查重失败不阻塞 */
  }
}

function validate(): string {
  if (!form.value.name.trim()) return '请填写客户姓名'
  if (!form.value.venue) return '请选择门店'
  if (!form.value.source) return '请选择来源渠道'
  return ''
}

function buildCreatePayload(): LeadPayload {
  const f = form.value
  return {
    name: f.name.trim(),
    venue: f.venue,
    source: f.source,
    phone: f.phone.trim() || undefined,
    wechat: f.wechat.trim() || undefined,
    demand: f.demand.trim() || undefined,
    orderPlatform: f.orderPlatform || undefined,
    serviceTeacher: f.serviceTeacher.trim() || undefined,
    status: f.status,
    grade: f.grade || undefined,
    dealCard: f.dealCard.trim() || undefined,
    dealAmount: f.dealAmount === '' ? null : Number(f.dealAmount),
    remark: f.remark.trim() || undefined
  }
}

/** 只把真正变化的字段放进 PATCH */
function buildPatch(): Partial<LeadPayload> {
  const f = form.value
  const o = origin.value
  if (!o) return buildCreatePayload()
  const patch: Partial<LeadPayload> = {}
  const cmp = (a: unknown, b: unknown) => (a ?? '') !== (b ?? '')
  if (cmp(f.name.trim(), o.name)) patch.name = f.name.trim()
  if (cmp(f.phone.trim(), o.phone)) patch.phone = f.phone.trim()
  if (cmp(f.wechat.trim(), o.wechat)) patch.wechat = f.wechat.trim()
  if (cmp(f.venue, o.venue)) patch.venue = f.venue
  if (cmp(f.source, o.source)) patch.source = f.source
  if (cmp(f.orderPlatform, o.orderPlatform)) patch.orderPlatform = f.orderPlatform
  if (cmp(f.demand.trim(), o.demand)) patch.demand = f.demand.trim()
  if (cmp(f.serviceTeacher.trim(), o.serviceTeacher)) patch.serviceTeacher = f.serviceTeacher.trim()
  if (cmp(f.status, o.status)) patch.status = f.status
  if (cmp(f.grade, o.grade)) patch.grade = f.grade
  if (cmp(f.dealCard.trim(), o.dealCard)) patch.dealCard = f.dealCard.trim()
  const oAmount = o.dealAmount === null || o.dealAmount === undefined ? '' : String(o.dealAmount)
  if (cmp(f.dealAmount, oAmount)) patch.dealAmount = f.dealAmount === '' ? null : Number(f.dealAmount)
  if (cmp(f.remark.trim(), o.remark)) patch.remark = f.remark.trim()
  return patch
}

async function submit() {
  if (saving.value) return
  const err = validate()
  if (err) {
    uni.showToast({ title: err, icon: 'none' })
    return
  }
  saving.value = true
  try {
    if (mode.value === 'edit' && id.value) {
      const patch = buildPatch()
      if (!Object.keys(patch).length) {
        uni.showToast({ title: '没有改动', icon: 'none' })
        saving.value = false
        return
      }
      const res = await updateLead(id.value, patch)
      if (res?.warnings?.length) {
        uni.showModal({
          title: '已保存（含提示）',
          content: res.warnings.map((w) => w.message).join('\n'),
          showCancel: false
        })
      } else {
        uni.showToast({ title: '已保存', icon: 'success' })
      }
    } else {
      const res = await createLead(buildCreatePayload())
      if (res?.warnings?.length) {
        uni.showModal({
          title: '已录入（含提示）',
          content: res.warnings.map((w) => w.message).join('\n'),
          showCancel: false,
          success: () => uni.navigateBack()
        })
        return
      }
      uni.showToast({ title: '已录入', icon: 'success' })
    }
    setTimeout(() => uni.navigateBack(), 500)
  } catch {
    /* http 层已 toast（422 的枚举错误文案会带允许值） */
  } finally {
    saving.value = false
  }
}

onLoad((options) => {
  if (!requireLogin()) return
  const opt = (options ?? {}) as Record<string, string>
  if (opt.mode === 'edit' && opt.id) {
    mode.value = 'edit'
    id.value = Number(opt.id)
    const draft = takeLeadDraft()
    if (draft) {
      origin.value = draft
      hydrate(draft)
    }
  }
  loadEnums()
})
</script>

<template>
  <view class="ym-page lead-form">
    <view class="ym-card">
      <text class="ym-title">{{ mode === 'edit' ? '编辑留资' : '新增留资' }}</text>

      <view class="field">
        <text class="field__label">姓名 <text class="field__req">*</text></text>
        <input v-model="form.name" class="field__input" placeholder="客户姓名" />
      </view>

      <view class="field">
        <text class="field__label">手机号</text>
        <input
          v-model="form.phone"
          class="field__input"
          type="number"
          maxlength="11"
          placeholder="11 位完整手机号"
          @blur="checkDup"
        />
        <text v-if="dupTip" class="field__warn">已有记录：{{ dupTip }}</text>
      </view>

      <view class="field">
        <text class="field__label">微信号</text>
        <input v-model="form.wechat" class="field__input" placeholder="选填" />
      </view>

      <view class="field field--tap">
        <text class="field__label">门店 <text class="field__req">*</text></text>
        <picker :value="venueIndex" :range="VENUES" @change="onPickVenue">
          <text :class="form.venue ? 'field__value' : 'ym-muted'">{{ form.venue || '请选择' }}</text>
        </picker>
      </view>

      <view class="field field--tap">
        <text class="field__label">来源渠道 <text class="field__req">*</text></text>
        <picker :value="sourceIndex" :range="sources" @change="onPickSource">
          <text :class="form.source ? 'field__value' : 'ym-muted'">{{ form.source || '请选择' }}</text>
        </picker>
      </view>

      <view class="field field--tap">
        <text class="field__label">下单平台</text>
        <picker :value="platformIndex" :range="platforms" @change="onPickPlatform">
          <text :class="form.orderPlatform ? 'field__value' : 'ym-muted'">{{ form.orderPlatform || '选填' }}</text>
        </picker>
      </view>

      <view class="field">
        <text class="field__label">需求 / 痛点</text>
        <input v-model="form.demand" class="field__input" placeholder="体态调整 / 产后修复 / 体式提升" />
      </view>

      <view class="field">
        <text class="field__label">上课老师 / 会籍顾问</text>
        <input v-model="form.serviceTeacher" class="field__input" placeholder="选填" />
      </view>

      <view class="field field--tap">
        <text class="field__label">状态</text>
        <picker :value="statusIndex" :range="LEAD_STATUSES" @change="onPickStatus">
          <text class="field__value">{{ form.status }}</text>
        </picker>
      </view>

      <view class="field field--tap">
        <text class="field__label">等级</text>
        <picker :value="gradeIndex" :range="GRADE_LABELS" @change="onPickGrade">
          <text class="field__value">{{ GRADE_LABELS[gradeIndex] }}</text>
        </picker>
      </view>

      <view class="field">
        <text class="field__label">成交卡项</text>
        <input v-model="form.dealCard" class="field__input" placeholder="成交时填写" />
      </view>

      <view class="field">
        <text class="field__label">成交金额</text>
        <input v-model="form.dealAmount" class="field__input" type="digit" placeholder="未成交可留空" />
      </view>

      <view class="field">
        <text class="field__label">备注</text>
        <textarea v-model="form.remark" class="field__textarea" placeholder="沟通记录、客户关注点等" />
      </view>
    </view>

    <view class="lead-form__actions">
      <button class="ym-btn" :disabled="saving" @click="submit">
        {{ saving ? '提交中…' : mode === 'edit' ? '保存改动' : '录入留资' }}
      </button>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.lead-form {
  padding-bottom: 60rpx;

  &__actions {
    padding: 0 24rpx;
  }
}

.field {
  display: flex;
  align-items: center;
  min-height: 96rpx;
  border-top: 2rpx solid #f0f1f3;
  font-size: 28rpx;

  &__label {
    width: 260rpx;
    color: #4e5969;
  }

  &__req {
    color: #c0392b;
  }

  &__input {
    flex: 1;
    font-size: 28rpx;
    text-align: right;
  }

  &__value {
    color: #1f2329;
    font-size: 28rpx;
  }

  &__textarea {
    flex: 1;
    height: 160rpx;
    font-size: 28rpx;
    padding-top: 16rpx;
    text-align: left;
  }

  &__warn {
    position: absolute;
    right: 0;
    transform: translateY(150rpx);
    color: #c26a1b;
    font-size: 22rpx;
  }
}
</style>
