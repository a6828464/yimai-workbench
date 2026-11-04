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
 *
 * 视觉层（2026-11 重构）：分组 ym-section + wd-input / wd-textarea / wd-picker，
 * 底部固定提交条；字段白名单、diff 提交、查重软提示全部原样保留。
 */
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
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
import { fetchStaffOptions, matchStaff, type StaffOption } from '@/api/staff'
import { ROLE_SHORT, type RoleCode } from '@/utils/permission'
import type { MiniappLead } from '@/types'

const user = useUserStore()
const VENUES = ['绿地店', '东部店']
const GRADE_LABELS = ['未分级', 'A', 'B', 'C']
/** wd-picker 的 columns 需要可变数组 */
const STATUS_OPTIONS: string[] = [...LEAD_STATUSES]

const mode = ref<'create' | 'edit'>('create')
const id = ref(0)
const origin = ref<MiniappLead | null>(null)
const saving = ref(false)
const dupTip = ref('')
const inited = ref(false)
/** onLoad 的原始参数（等权限通过后再消费） */
const opts = ref<Record<string, string> | null>(null)

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

const gradeIndex = computed(() => Math.max(0, LEAD_GRADES.indexOf(form.value.grade as never)))
/** 等级在 UI 上是「未分级 / A / B / C」，提交值仍是 '' / A / B / C */
const gradeLabel = computed(() => GRADE_LABELS[gradeIndex.value])

/* ---------- 老师/顾问：选人（/staff/options）+ 手输兜底 ---------- */
const staffSheet = ref(false)
const staffList = ref<StaffOption[]>([])
const staffLoading = ref(false)
/** 人员清单请求是否成功返回过（成功=可以判定「这个名字不在账号里」） */
const staffFetched = ref(false)
/** 清单里是否真的有人（决定面板渲染列表还是空提示） */
const staffLoaded = computed(() => staffList.value.length > 0)
const staffKeyword = ref('')
const manualTeacher = ref('')

const staffFiltered = computed(() => matchStaff(staffList.value, staffKeyword.value))

/**
 * 当前值是不是「不在账号清单里」的历史写法。
 * 只有清单加载成功后才敢判断 —— 接口失败时一律当真（不能把历史名字说成不存在）。
 */
const teacherHistorical = computed(
  () =>
    staffFetched.value &&
    !!form.value.serviceTeacher &&
    !staffList.value.some((s) => s.name === form.value.serviceTeacher)
)

function staffMeta(s: StaffOption): string {
  const roles = s.roles
    .map((r) => ROLE_SHORT[r as RoleCode] ?? r)
    .filter(Boolean)
    .join('/')
  return [roles, s.venue || ''].filter(Boolean).join(' · ')
}

/** 打开面板：首次打开才拉清单（一次会话内不重复打接口） */
function openStaffPicker(): void {
  staffSheet.value = true
  staffKeyword.value = ''
  manualTeacher.value = ''
  void loadStaff()
}

function closeStaffPicker(): void {
  staffSheet.value = false
}

async function loadStaff(): Promise<void> {
  if (staffFetched.value || staffLoading.value) return
  staffLoading.value = true
  try {
    staffList.value = await fetchStaffOptions()
    staffFetched.value = true
  } catch {
    /* 静默：接口不可用就只剩手输，不阻塞录入 */
  } finally {
    staffLoading.value = false
  }
}

/** 选中账号 → 写入**规范名**（后端靠它解析归属 user_id） */
function pickStaff(s: StaffOption): void {
  form.value.serviceTeacher = s.name
  staffSheet.value = false
}

/** 手输兜底：历史写法 / 临时名字 / 接口挂了都能录 */
function applyManual(): void {
  const v = manualTeacher.value.trim()
  if (!v) {
    uni.showToast({ title: '请先输入名字', icon: 'none' })
    return
  }
  form.value.serviceTeacher = v
  staffSheet.value = false
}

function clearTeacher(): void {
  form.value.serviceTeacher = ''
  manualTeacher.value = ''
  staffSheet.value = false
}

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

type PickerPayload = { value: string | number | string[] | number[] }

function onPickVenue(e: PickerPayload) {
  form.value.venue = String(e.value)
}

function onPickSource(e: PickerPayload) {
  form.value.source = String(e.value)
}

function onPickPlatform(e: PickerPayload) {
  form.value.orderPlatform = String(e.value)
}

function onPickStatus(e: PickerPayload) {
  form.value.status = String(e.value)
}

function onPickGrade(e: PickerPayload) {
  const v = String(e.value)
  form.value.grade = v === '未分级' ? '' : v
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
  opts.value = (options ?? {}) as Record<string, string>
})

/**
 * 编辑态回填：只在权限通过后执行一次。
 * 本地草稿 takeLeadDraft() 是「读后即删」的，不能在校验之前消费掉。
 */
function applyDraft() {
  const opt = opts.value
  if (!opt || opt.mode !== 'edit' || !opt.id) return
  mode.value = 'edit'
  id.value = Number(opt.id)
  const draft = takeLeadDraft()
  if (draft) {
    origin.value = draft
    hydrate(draft)
  }
}

// 权限：先登录，再校验「留资管理」功能权限；首个 show 才回填草稿 + 补渠道清单
onShow(() => {
  if (!requireLogin()) return
  if (!requireFeature('leads')) return
  if (!inited.value) {
    inited.value = true
    applyDraft()
    loadEnums()
    // 顺手预取人员清单：点开选人面板时通常已经加载好（失败也不影响录入）
    void loadStaff()
  }
})
</script>

<template>
  <view class="ym-page lead-form">
    <view class="fhead">
      <text class="fhead__title">{{ mode === 'edit' ? '编辑留资' : '新增留资' }}</text>
      <text class="fhead__desc">
        {{ mode === 'edit' ? '只提交你改动过的字段，未动的保持原值' : '带 * 为必填，手机号可查重（只提示不拦截）' }}
      </text>
    </view>

    <ym-section title="客户信息">
      <wd-input
        v-model="form.name"
        label="姓名"
        required
        placeholder="客户姓名"
        label-width="200rpx"
      />
      <wd-input
        v-model="form.phone"
        label="手机号"
        type="number"
        :maxlength="11"
        placeholder="11 位完整手机号"
        label-width="200rpx"
        @blur="checkDup"
      />
      <view v-if="dupTip" class="dup">
        <text class="dup__title">已有记录</text>
        <text class="dup__text">{{ dupTip }}</text>
      </view>
      <wd-input
        v-model="form.wechat"
        label="微信号"
        placeholder="选填"
        label-width="200rpx"
      />
    </ym-section>

    <ym-section title="归属与渠道" desc="渠道清单以后端下发为准">
      <wd-picker
        label="门店"
        required
        :columns="VENUES"
        :model-value="form.venue"
        placeholder="请选择"
        label-width="200rpx"
        @confirm="onPickVenue"
      />
      <wd-picker
        label="来源渠道"
        required
        :columns="sources"
        :model-value="form.source"
        placeholder="请选择"
        label-width="200rpx"
        @confirm="onPickSource"
      />
      <wd-picker
        label="下单平台"
        :columns="platforms"
        :model-value="form.orderPlatform"
        placeholder="选填"
        label-width="200rpx"
        @confirm="onPickPlatform"
      />
      <!-- 老师/顾问：从账号里选（写规范名），历史写法可手输 -->
      <view class="pick-row ym-tap" @click="openStaffPicker">
        <text class="pick-row__label">老师/顾问</text>
        <view class="pick-row__value">
          <text class="pick-row__text" :class="{ 'pick-row__text--ph': !form.serviceTeacher }">
            {{ form.serviceTeacher || '选填 · 点这里选人' }}
          </text>
          <text v-if="teacherHistorical" class="pick-row__flag">手输值</text>
          <wd-icon name="arrow-right" size="14px" color="#8A948F" />
        </view>
      </view>
    </ym-section>

    <ym-section title="需求与跟进">
      <wd-input
        v-model="form.demand"
        label="需求/痛点"
        placeholder="体态调整 / 产后修复 / 体式提升"
        label-width="200rpx"
      />
      <wd-picker
        label="状态"
        :columns="STATUS_OPTIONS"
        :model-value="form.status"
        placeholder="请选择"
        label-width="200rpx"
        @confirm="onPickStatus"
      />
      <wd-picker
        label="等级"
        :columns="GRADE_LABELS"
        :model-value="gradeLabel"
        label-width="200rpx"
        @confirm="onPickGrade"
      />
    </ym-section>

    <ym-section title="成交与备注">
      <wd-input
        v-model="form.dealCard"
        label="成交卡项"
        placeholder="成交时填写"
        label-width="200rpx"
      />
      <wd-input
        v-model="form.dealAmount"
        label="成交金额"
        type="digit"
        placeholder="未成交可留空"
        label-width="200rpx"
      />
      <wd-textarea
        v-model="form.remark"
        label="备注"
        placeholder="沟通记录、客户关注点等"
        :maxlength="500"
      />
    </ym-section>

    <!-- 选人面板：可搜索 + 手输兜底 + 清除 -->
    <view v-if="staffSheet" class="picker" @click="closeStaffPicker">
      <view class="picker__panel" @click.stop>
        <view class="picker__head">
          <text class="picker__title">老师/顾问</text>
          <view class="picker__close ym-tap" @click="closeStaffPicker">
            <wd-icon name="close" size="16px" color="#8A948F" />
          </view>
        </view>
        <text class="picker__hint">
          选中后写入账号规范名，本人才能在「我的客资」里看到这条记录。
        </text>

        <view class="picker__search">
          <wd-icon name="search" size="14px" color="#8A948F" />
          <input
            v-model="staffKeyword"
            class="picker__input"
            placeholder="搜名字 / 花名"
            confirm-type="search"
          />
        </view>

        <scroll-view v-if="staffLoaded" scroll-y class="picker__list">
          <view
            v-for="s in staffFiltered"
            :key="s.id || s.name"
            class="sitem ym-tap"
            @click="pickStaff(s)"
          >
            <view class="sitem__main">
              <view class="sitem__row">
                <text class="sitem__name">{{ s.name }}</text>
                <text v-if="s.self" class="sitem__tag">我</text>
              </view>
              <text class="sitem__meta ym-ellipsis">{{ staffMeta(s) }}</text>
            </view>
            <wd-icon
              v-if="form.serviceTeacher === s.name"
              name="check"
              size="16px"
              color="#2E7D5B"
            />
          </view>
          <view v-if="!staffFiltered.length" class="picker__empty">
            没有匹配的账号，可直接在下面手输
          </view>
        </scroll-view>
        <view v-else class="picker__state">
          {{
            staffLoading
              ? '正在读取人员清单…'
              : staffFetched
                ? '还没有可选的账号，可直接在下面手输'
                : '人员清单读取失败，可在下面手输'
          }}
        </view>

        <view class="picker__manual">
          <input
            v-model="manualTeacher"
            class="picker__input picker__input--manual"
            placeholder="手输名字（历史写法 / 临时名字）"
            confirm-type="done"
            @confirm="applyManual"
          />
          <view class="picker__manual-btn ym-tap" @click="applyManual">
            <text class="picker__manual-text">用手输</text>
          </view>
        </view>

        <view v-if="form.serviceTeacher" class="picker__clear ym-tap" @click="clearTeacher">
          <text class="picker__clear-text">清除当前选择</text>
        </view>
      </view>
    </view>

    <view class="actions">
      <wd-button type="primary" size="large" block :loading="saving" @click="submit">
        {{ saving ? '提交中…' : mode === 'edit' ? '保存改动' : '录入留资' }}
      </wd-button>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.lead-form {
  padding-bottom: calc(200rpx + env(safe-area-inset-bottom));

  --wot-button-large-height: 96rpx;

  /* 表单行：横向内边距交给 ym-section，纵向撑到 96rpx 触摸高度 */
  --wot-input-padding: 0;
  --wot-textarea-padding: 0;
  --wot-input-fs: 28rpx;
  --wot-textarea-fs: 28rpx;
  --wot-input-cell-padding: 12px;
  --wot-textarea-cell-padding: 12px;
  --wot-cell-title-fs: 28rpx;
}

/* ---------- 页头 ---------- */
.fhead {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
  padding: 28rpx 30rpx 6rpx;
}

.fhead__title {
  font-size: 40rpx;
  font-weight: 600;
  color: $ink-800;
}

.fhead__desc {
  display: block;
  font-size: 23rpx;
  line-height: 1.5;
  color: $ink-400;
}

/* ---------- 查重软提示 ---------- */
.dup {
  padding: 16rpx 20rpx;
  margin: 6rpx 0 18rpx;
  background: $warn-100;
  border-radius: $r-sm;
}

.dup__title {
  display: block;
  margin-bottom: 6rpx;
  font-size: 20rpx;
  color: $warn-600;
}

.dup__text {
  font-size: 24rpx;
  line-height: 1.5;
  color: $ink-700;
}

/* ---------- 底部提交条 ---------- */
.actions {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 8;
  padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));
  background: $white;
  border-top: 2rpx solid $hairline;
  box-shadow: 0 -8rpx 24rpx rgba(16, 24, 21, 0.04);
}

/* ---------- 老师/顾问：选人行 ---------- */
.pick-row {
  display: flex;
  gap: 16rpx;
  align-items: center;
  justify-content: space-between;
  min-height: 96rpx;
  padding: 12px 0;
  border-bottom: 2rpx solid $hairline;
}

.pick-row__label {
  flex-shrink: 0;
  width: 200rpx;
  font-size: 28rpx;
  color: $ink-700;
}

.pick-row__value {
  display: flex;
  flex: 1;
  gap: 10rpx;
  align-items: center;
  justify-content: flex-end;
  min-width: 0;
}

.pick-row__text {
  flex-shrink: 1;
  min-width: 0;
  overflow: hidden;
  font-size: 28rpx;
  color: $ink-800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pick-row__text--ph {
  color: $ink-300;
}

.pick-row__flag {
  flex-shrink: 0;
  padding: 2rpx 10rpx;
  font-size: 20rpx;
  color: $warn-600;
  background: $warn-100;
  border-radius: 999rpx;
}

/* ---------- 选人面板 ---------- */
.picker {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-end;
  background: rgba(16, 24, 21, 0.5);
}

.picker__panel {
  width: 100%;
  padding: 30rpx 28rpx calc(24rpx + env(safe-area-inset-bottom));
  background: $white;
  border-radius: $r-xl $r-xl 0 0;
  box-shadow: $shadow-float;
}

.picker__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.picker__title {
  font-size: 33rpx;
  font-weight: 600;
  color: $ink-800;
}

.picker__close {
  padding: 8rpx;
}

.picker__hint {
  display: block;
  margin-top: 8rpx;
  font-size: 21rpx;
  line-height: 1.5;
  color: $ink-400;
}

.picker__search {
  display: flex;
  gap: 12rpx;
  align-items: center;
  height: 84rpx;
  padding: 0 22rpx;
  margin-top: 18rpx;
  background: $ink-50;
  border-radius: 999rpx;
}

.picker__input {
  flex: 1;
  min-width: 0;
  font-size: 27rpx;
  color: $ink-800;
}

.picker__list {
  height: 44vh;
  margin-top: 14rpx;
}

.sitem {
  display: flex;
  gap: 16rpx;
  align-items: center;
  justify-content: space-between;
  min-height: 104rpx;
  padding: 16rpx 6rpx;
  border-bottom: 2rpx solid $hairline;
}

.sitem__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6rpx;
  min-width: 0;
}

.sitem__row {
  display: flex;
  gap: 10rpx;
  align-items: center;
  min-width: 0;
}

.sitem__name {
  font-size: 29rpx;
  font-weight: 500;
  color: $ink-800;
}

.sitem__tag {
  padding: 0 10rpx;
  font-size: 19rpx;
  line-height: 1.7;
  color: $brand-700;
  background: $brand-100;
  border-radius: 999rpx;
}

.sitem__meta {
  font-size: 21rpx;
  color: $ink-400;
}

.picker__empty,
.picker__state {
  display: block;
  padding: 36rpx 6rpx;
  font-size: 23rpx;
  color: $ink-400;
  text-align: center;
}

.picker__manual {
  display: flex;
  gap: 14rpx;
  align-items: center;
  padding-top: 18rpx;
  margin-top: 8rpx;
  border-top: 2rpx solid $hairline;
}

.picker__input--manual {
  height: 84rpx;
  padding: 0 22rpx;
  background: $ink-50;
  border-radius: 999rpx;
}

.picker__manual-btn {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  height: 84rpx;
  padding: 0 30rpx;
  color: $white;
  background: linear-gradient(135deg, $brand-500, $brand-700);
  border-radius: 999rpx;
}

.picker__manual-text {
  font-size: 26rpx;
  font-weight: 500;
}

.picker__clear {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 76rpx;
  margin-top: 12rpx;
}

.picker__clear-text {
  font-size: 24rpx;
  color: $danger-600;
}
</style>
