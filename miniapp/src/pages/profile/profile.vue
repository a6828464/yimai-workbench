<script setup lang="ts">
/**
 * 我的（tab 页 · 自绘 ym-hero）
 *
 * 内容按方案 §5.8：头像 + 昵称 + 角色标签 + 门店；账号卡五行（个人信息 / 登录密码 /
 * 微信绑定 / 模板通知 / 关于）；退出登录二次确认。
 *
 * 两处「沉默」是刻意的：
 *  1. 模板通知摘要（「已开启 n 项」）失败**不打断页面**——它是辅助信息，
 *     为它弹一个错误 toast 会让每次切回「我的」都挨一次提示；失败就留空。
 *  2. 微信身份读取失败同理：只把行文案降级成「未获取」，绑定按钮仍可用
 *     （wxSelfBind 自己重新取 code，不依赖先前读到的 openid）。
 *
 * 和三方状态的关系：本页数据主要来自本地 store（`/me` 缓存），
 * 因此用「骨架卡 / ym-empty + 重试 / 正常」三态；网络辅助信息各自降级。
 */
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { requireLogin } from '@/utils/guard'
import { fetchWxIdentity, wxSelfBind, type WxIdentity } from '@/api/auth'
import { countEnabledTemplates, fetchWxSubscriptions, WX_BIND_INTENT_KEY, type WxSubscriptionsResult } from '@/api/notify'
import { copyText, goPath } from '@/utils/nav'
import { fetchChangelog, type ChangelogEntry } from '@/api/system'

const user = useUserStore()

/**
 * JS 侧拿不到 SCSS 变量（uni.showModal / wd-icon 的 color 都要字面量），
 * 这里把设计令牌的运行时值收两个常量，页面里不再散落十六进制。
 */
const BRAND_COLOR = '#2E7D5B' // = $brand-500
const DANGER_COLOR = '#C0453B' // = $danger-500

const loading = ref(true)
const errorText = ref('')

const wx = ref<WxIdentity | null>(null)
const wxLoading = ref(false)
const wxBusy = ref(false)
const wxPanel = ref(false)

/** 模板通知摘要（失败保持 null = 不显示值） */
const subscription = ref<WxSubscriptionsResult | null>(null)

const displayName = computed(() => user.info?.userName || user.staffName || '一麦伙伴')
const avatarSrc = computed(() => user.info?.avatar || '')
const roleText = computed(() => user.roleText)
const venueText = computed(() => user.venue || (user.venues ?? []).join('、') || '未分配门店')
const staffName = computed(() => user.info?.staffName || '')
// 邮箱已在小程序端下线（见 types/index.ts 的说明），这里只显示账号规范名
const accountLine = computed(() => `账号 ${staffName.value || '未同步'}`)

const wxText = computed(() => {
  // 只有「第一次还没读到」才显示读取中；切 tab 回刷新时不闪文案
  if (wxLoading.value && !wx.value) return '读取中…'
  if (!wx.value) return '未获取'
  if (!wx.value.bound) return '未绑定'
  return wx.value.boundAt ? `已绑定 ${bindTime(wx.value.boundAt)}` : '已绑定'
})

const wxHint = computed(() => {
  if (wx.value?.bound) {
    return '已绑定：登录页点「微信一键登录」即可，不用再输密码；换微信号后重新绑定一次。模板通知也发到这个微信。'
  }
  return '绑定后可用微信一键登录，并能收到「新客资 / 客户分配」提醒。绑定前请确认这台手机用的是你本人的微信。'
})

/** 绑定状态徽标：没读到 openid 时显示「未知」，不要误报成「未绑定」 */
const wxBadgeText = computed(() => (!wx.value ? '未知' : wx.value.bound ? '已绑定' : '未绑定'))
const wxBadgeTone = computed<'brand' | 'warn' | 'muted'>(() =>
  !wx.value ? 'muted' : wx.value.bound ? 'brand' : 'warn'
)

const enabledNotifyCount = computed(() => countEnabledTemplates(subscription.value))

/** 摘要用品牌色高亮：已开启且确实还有剩余次数 */
const notifyHighlight = computed(() => !!subscription.value && enabledNotifyCount.value > 0)

const notifyText = computed(() => {
  const res = subscription.value
  if (!res) return ''
  if (!res.configured) return '待配置'
  const n = enabledNotifyCount.value
  return n > 0 ? `已开启 ${n} 项` : '未开启'
})

const versionText = computed(() => appVersion())

/* ---------- 关于弹窗（版本号 + 最近 3 条更新要点） ---------- */
const aboutOpen = ref(false)
const changelog = ref<ChangelogEntry[]>([])
const changelogLoading = ref(false)
/** 拉成功过就不再重复打（关于是低频弹窗，但接口背后可能要去外网取 release，别每次点都等） */
let changelogLoaded = false

function appVersion(): string {
  try {
    const holder = uni as unknown as {
      getAccountInfoSync?: () => { miniProgram?: { version?: string; envVersion?: string } }
    }
    const mp = holder.getAccountInfoSync?.()?.miniProgram
    if (mp?.version) return `v${mp.version}`
    if (mp?.envVersion === 'develop') return '开发版'
    if (mp?.envVersion === 'trial') return '体验版'
    return '正式版'
  } catch {
    return '—'
  }
}

function bindTime(v?: string | null): string {
  if (!v) return ''
  return String(v).replace('T', ' ').slice(0, 16)
}

function readable(e: unknown, fallback = '请稍后重试'): string {
  return e instanceof Error && e.message ? e.message : fallback
}

function consumeBindIntent(): void {
  try {
    if (uni.getStorageSync(WX_BIND_INTENT_KEY)) {
      uni.removeStorageSync(WX_BIND_INTENT_KEY)
      wxPanel.value = true
    }
  } catch {
    /* 存储不可用时忽略：面板仍可手动点开 */
  }
}

async function loadWx(): Promise<void> {
  wxLoading.value = true
  try {
    wx.value = await fetchWxIdentity()
  } catch {
    // 读不到就保持 null（行文案「未获取」），不做额外提示
  } finally {
    wxLoading.value = false
  }
}

async function loadSubscription(): Promise<void> {
  try {
    subscription.value = await fetchWxSubscriptions()
  } catch {
    // 失败静默：本行只是摘要，不弹错
    subscription.value = null
  }
}

async function reload(): Promise<void> {
  loading.value = true
  await user.refreshMe()
  loading.value = false
  errorText.value = user.info ? '' : '资料加载失败，请检查网络后重试'
  await Promise.all([loadWx(), loadSubscription()])
}

async function bindWx(): Promise<void> {
  if (wxBusy.value) return
  wxBusy.value = true
  try {
    await wxSelfBind()
    uni.showToast({ title: '绑定成功，下次可微信一键登录', icon: 'none' })
    await Promise.all([loadWx(), user.refreshMe(), loadSubscription()])
  } catch (e) {
    uni.showModal({
      title: '绑定未完成',
      content: readable(e),
      showCancel: false,
      confirmColor: BRAND_COLOR
    })
  } finally {
    wxBusy.value = false
  }
}

function copyOpenid(): void {
  const openid = wx.value?.openid ?? ''
  if (!openid) {
    uni.showToast({ title: '还没有取到 openid', icon: 'none' })
    return
  }
  copyText(openid, '已复制，可发给管理员')
}

function toggleWxPanel(): void {
  wxPanel.value = !wxPanel.value
}

function goEdit(tab?: 'password'): void {
  goPath(tab === 'password' ? '/pages/profile/edit?tab=password' : '/pages/profile/edit')
}

function goPersona(): void {
  goPath('/pages/profile/persona')
}

function goNotify(): void {
  goPath('/pages/profile/notify')
}

function showAbout(): void {
  aboutOpen.value = true
  void loadChangelog()
}

function closeAbout(): void {
  aboutOpen.value = false
}

/**
 * 更新要点：GET /system/changelog?limit=3（公开接口，be-content 在加结构化形状）。
 *
 * **失败一律静默**：接口没上线、超时、返回形状不认识 —— 都只是少了这三行字，
 * 弹窗仍然照常显示版本号。为一条辅助信息弹错误提示是本页刻意避免的事
 * （同「模板通知摘要」的处理）。
 */
async function loadChangelog(): Promise<void> {
  if (changelogLoaded || changelogLoading.value) return
  changelogLoading.value = true
  try {
    const entries = await fetchChangelog(3)
    changelog.value = entries
    changelogLoaded = entries.length > 0
  } catch {
    /* 静默降级：只显示版本号 */
  } finally {
    changelogLoading.value = false
  }
}

function confirmLogout(): void {
  uni.showModal({
    title: '退出登录',
    content: '退出后需要重新输入账号密码（或微信一键登录）。确定退出吗？',
    confirmText: '退出',
    confirmColor: DANGER_COLOR,
    success: (r) => {
      if (r.confirm) user.logout()
    }
  })
}

onShow(async () => {
  if (!requireLogin()) return
  consumeBindIntent()
  loading.value = true
  // refreshMe 内部已吞掉异常（401 由 http 层统一跳登录），这里只看结果是否为空
  await user.refreshMe()
  loading.value = false
  errorText.value = user.info ? '' : '资料加载失败，请检查网络后重试'
  await Promise.all([loadWx(), loadSubscription()])
})
</script>

<template>
  <view class="ym-page ym-page--tab">
    <ym-hero eyebrow="一麦工作台 · 我的">
      <template #badge>
        <ym-badge :text="roleText" tone="gold" size="md" />
      </template>
      <view class="me__id">
        <ym-avatar :name="displayName" :src="avatarSrc" :size="120" />
        <view class="me__id-text">
          <text class="me__name ym-ellipsis">{{ displayName }}</text>
          <text class="me__meta ym-ellipsis">{{ venueText }}</text>
          <text class="me__meta me__meta--weak ym-ellipsis">{{ accountLine }}</text>
        </view>
      </view>
    </ym-hero>

    <!-- 三态之一：骨架卡（首屏且本地还没有缓存资料） -->
    <view v-if="loading && !user.info" class="ym-card me__skeleton">
      <view class="me__sk-line" />
      <view class="me__sk-line" />
      <view class="me__sk-line me__sk-line--short" />
    </view>

    <!-- 三态之二：错误态（仍然给一条「退出」出路，避免卡在错误页里出不去） -->
    <view v-else-if="errorText" class="me__state">
      <ym-empty
        :text="errorText"
        desc="检查网络后重试，或退出后重新登录"
        action-text="重试"
        icon="refresh"
        @action="reload"
      />
      <view class="me__logout-wrap">
        <button class="ym-btn me__logout" @click="confirmLogout">退出登录</button>
      </view>
    </view>

    <!-- 三态之三：正常 -->
    <template v-else>
      <ym-section title="账号" desc="资料与安全">
        <ym-cell-row label="个人信息" :value="displayName" arrow divider clickable @click="goEdit()" />
        <ym-cell-row label="人设设置" value="身份 / 人群" arrow divider clickable @click="goPersona" />
        <ym-cell-row label="登录密码" value="修改" arrow divider clickable @click="goEdit('password')" />
        <ym-cell-row label="微信绑定" arrow divider clickable @click="toggleWxPanel">
          <template #right>
            <text class="me__row-value" :class="{ 'me__row-value--warn': wx && !wx.bound }">
              {{ wxText }}
            </text>
          </template>
        </ym-cell-row>
        <ym-cell-row label="模板通知" arrow divider clickable @click="goNotify">
          <template #right>
            <text class="me__row-value" :class="{ 'me__row-value--brand': notifyHighlight }">
              {{ notifyText || '去设置' }}
            </text>
          </template>
        </ym-cell-row>
        <ym-cell-row label="关于" :value="versionText" arrow clickable @click="showAbout" />
      </ym-section>

      <view v-if="wxPanel" class="ym-card me__wx">
        <view class="me__wx-head">
          <text class="ym-title">微信绑定</text>
          <ym-badge :text="wxBadgeText" :tone="wxBadgeTone" />
        </view>
        <text class="ym-muted me__hint">{{ wxHint }}</text>

        <view v-if="!wx?.bound" class="me__openid">
          <text class="ym-muted">你的 openid（可复制发给管理员代绑）</text>
          <view class="ym-row--between me__openid-row">
            <text class="me__openid-value">
              {{ wx?.openid || (wxLoading ? '读取中…' : '暂时取不到，点下方按钮可直接绑') }}
            </text>
            <text v-if="wx?.openid" class="me__copy ym-tap" @click="copyOpenid">复制</text>
          </view>
        </view>

        <view class="me__bind">
          <wd-button block :loading="wxBusy" @click="bindWx">
            {{ wxBusy ? '绑定中…' : wx?.bound ? '重新绑定当前微信' : '用当前微信绑定我的账号' }}
          </wd-button>
        </view>
      </view>

      <view class="ym-card me__tips">
        <text class="ym-muted">
          账号由管理员开通；这里改的是你自己的资料，会同步到电脑端工作台。手机号与邮箱也用于接收提醒。
        </text>
      </view>

      <view class="me__logout-wrap">
        <button class="ym-btn me__logout" @click="confirmLogout">退出登录</button>
      </view>
    </template>

    <!-- 关于：版本号 + 最近 3 条更新要点（失败只留版本号） -->
    <view v-if="aboutOpen" class="about" @click="closeAbout">
      <view class="about__panel" @click.stop>
        <view class="about__head">
          <text class="about__title">关于一麦工作台</text>
          <text class="about__ver">版本 {{ versionText }}</text>
          <text class="about__sub">一麦瑜伽·普拉提 · 员工端 · {{ venueText }}</text>
        </view>

        <view class="about__block">
          <text class="about__label">最近更新</text>
          <view v-if="changelog.length" class="about__list">
            <view v-for="(e, i) in changelog" :key="`${e.version}-${i}`" class="about__item">
              <text class="about__tag">{{ e.version }}</text>
              <text class="about__item-title">{{ e.title }}</text>
            </view>
          </view>
          <text v-else class="about__hint">
            {{ changelogLoading ? '正在读取更新要点…' : '更新要点暂时取不到，不影响使用' }}
          </text>
        </view>

        <text class="about__note">
          资料、密码、模板通知都可在这里自助修改，改动会同步到电脑端工作台。数据备份、薪酬、AI
          模型配置、人员管理、KeepYoga 同步、小程序内收款不在小程序范围内。
        </text>

        <view class="about__close ym-tap" @click="closeAbout">
          <text class="about__close-text">知道了</text>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.me {
  &__id {
    display: flex;
    gap: 24rpx;
    align-items: center;
  }

  &__id-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 8rpx;
    min-width: 0;
  }

  &__name {
    font-size: 42rpx;
    font-weight: 600;
    letter-spacing: 1rpx;
    color: $white;
  }

  &__meta {
    font-size: 24rpx;
    color: rgba($white, 0.72);
  }

  &__meta--weak {
    color: rgba($white, 0.52);
  }

  &__row-value {
    font-size: 26rpx;
    color: $ink-400;
  }

  &__row-value--warn {
    color: $warn-600;
  }

  &__row-value--brand {
    color: $brand-600;
  }

  &__skeleton {
    display: flex;
    flex-direction: column;
    gap: 20rpx;
  }

  &__sk-line {
    height: 32rpx;
    background: $ink-100;
    border-radius: 8rpx;
  }

  &__sk-line--short {
    width: 40%;
  }

  &__wx {
    display: flex;
    flex-direction: column;
    gap: 18rpx;
  }

  &__wx-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  &__hint {
    display: block;
    line-height: 1.65;
  }

  &__openid {
    padding: 20rpx;
    background: $ink-50;
    border-radius: $r-sm;
  }

  &__openid-row {
    margin-top: 10rpx;
  }

  &__openid-value {
    flex: 1;
    min-width: 0;
    font-size: 24rpx;
    color: $ink-700;
    word-break: break-all;
  }

  &__copy {
    flex-shrink: 0;
    padding-left: 20rpx;
    font-size: 26rpx;
    color: $brand-600;
  }

  &__bind {
    margin-top: 6rpx;
  }

  &__tips {
    line-height: 1.6;
  }

  &__logout-wrap {
    padding: 12rpx 24rpx 0;
  }

  &__logout {
    color: $danger-600;
    background: $white;
    border: 2rpx solid $hairline;
  }
}

/* ---------- 关于弹窗 ---------- */
/* 居中弹窗而不是底部抽屉：本页是 tab 页，原生 tabBar 永远盖在页面最上层，
   底部抽屉的按钮会被挡在 tabBar 后面 */
.about {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 48rpx;
  background: rgba(16, 24, 21, 0.5);
}

.about__panel {
  width: 100%;
  padding: 36rpx 32rpx 30rpx;
  background: $white;
  border-radius: $r-xl;
  box-shadow: $shadow-float;
}

.about__head {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
  padding-bottom: 24rpx;
  border-bottom: 2rpx solid $hairline;
}

.about__title {
  font-size: 34rpx;
  font-weight: 600;
  color: $ink-800;
}

.about__ver {
  font-size: 26rpx;
  color: $brand-600;
}

.about__sub {
  font-size: 22rpx;
  color: $ink-400;
}

.about__block {
  padding: 24rpx 0 8rpx;
}

.about__label {
  display: block;
  margin-bottom: 14rpx;
  font-size: 24rpx;
  font-weight: 500;
  color: $ink-600;
}

.about__list {
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}

.about__item {
  display: flex;
  gap: 14rpx;
  align-items: flex-start;
  padding: 18rpx 20rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

.about__tag {
  flex-shrink: 0;
  padding: 2rpx 12rpx;
  font-size: 21rpx;
  line-height: 1.7;
  color: $brand-700;
  background: $brand-100;
  border-radius: 999rpx;
}

.about__item-title {
  flex: 1;
  min-width: 0;
  font-size: 25rpx;
  line-height: 1.5;
  color: $ink-700;
  word-break: break-all;
}

.about__hint {
  font-size: 23rpx;
  color: $ink-400;
}

.about__note {
  display: block;
  padding-top: 8rpx;
  font-size: 21rpx;
  line-height: 1.6;
  color: $ink-400;
}

.about__close {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 92rpx;
  margin-top: 28rpx;
  color: $white;
  background: linear-gradient(135deg, $brand-500, $brand-700);
  border-radius: 999rpx;
}

.about__close-text {
  font-size: 29rpx;
  font-weight: 500;
}
</style>
