<script setup lang="ts">
/**
 * 模板通知（微信订阅消息 · 子页 · 原生导航栏）
 *
 * 契约见方案 §6.3（后端由 A 任务实现）：
 *  - GET  /wx/subscriptions → {configured, bound, templates:[{key,title,desc,enabled,templateId,accepted,remain,lastAcceptedAt,fields}], updatedAt}
 *  - POST /wx/subscriptions {accepts:[key], denies:[key]}
 *  - POST /wx/subscriptions/test {key} → {sent, message}
 *
 * 授权流程（小程序强约束：必须在用户点击的同步栈里调用）：
 *   点「开启提醒」→ uni.requestSubscribeMessage({tmplIds}) → 逐模板 accept/reject →
 *   把同意的 key 与拒绝的 key 一起 POST 回后端（同意一次 remain+1，发一条 remain-1）。
 *
 * 三个容易踩的点：
 *  1. `configured=false` 是「管理员还没配模板 id」，不是错误 —— 整页说明，不弹错、不重试循环；
 *  2. 未绑定微信时订阅消息无处可发 —— 先引导去「我的」绑定（tab 页不能带 query，用 storage 一次性标记握手）；
 *  3. 一次最多请求 3 个模板（老版本 iOS/Android 仅支持 1 个），这里按 3 个分片顺序请求再合并结果。
 */
import { computed, ref } from 'vue'
import { onShow, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { requireLogin } from '@/utils/guard'
import {
  WX_BIND_INTENT_KEY,
  countEnabledTemplates,
  fetchWxSubscriptions,
  reportWxSubscriptions,
  templateStatus,
  testWxSubscription,
  type WxSubscriptionsResult,
  type WxTemplateItem
} from '@/api/notify'

/** JS / 组件 props 侧拿不到 SCSS 变量，这里收口设计令牌的运行时值 */
const BRAND_COLOR = '#2E7D5B' // = $brand-500
const GOLD_COLOR = '#C19A4B' // = $gold-500

/** 单次订阅请求的模板上限（微信老版本只支持 1 条，3 条是兼容与效率的折中） */
const SUBSCRIBE_BATCH = 3

const data = ref<WxSubscriptionsResult | null>(null)
// 初值为 true：onShow 在首帧之后才跑，先渲染骨架，避免闪一下空表单/「待配置」
const loading = ref(true)
const loaded = ref(false)
const errorText = ref('')
const subscribing = ref(false)
const testingKey = ref('')

// 用真值判断而不是 === true：后端若把 boolean 序列化成 1/0，页面也不该误判成「未配置」
const configured = computed(() => !!data.value?.configured)
const bound = computed(() => !!data.value?.bound)
const templates = computed<WxTemplateItem[]>(() => data.value?.templates ?? [])

/** 能发起订阅的模板：启用 + 有模板 id */
const subscribableTemplates = computed(() => templates.value.filter((t) => t.enabled && !!t.templateId))
/**
 * 员工端只展示「现在真能开」的模板。
 *
 * 后端目录里保留 memberAssigned / todoReminder 两个占位键（本期没有独立模板 / 没有触发点），
 * 它们在角色权限里是 enabled=false。若照单全渲染，员工会看到两行「暂未开通」，
 * 既无法操作又要来问店长。目录信息仍通过接口下发，管理侧需要时可查，员工侧不展示。
 */
const visibleTemplates = computed(() => subscribableTemplates.value)
const enabledCount = computed(() => countEnabledTemplates(data.value))
const canSubscribe = computed(() => bound.value && subscribableTemplates.value.length > 0)

const summaryLine = computed(() => {
  if (!data.value) return ''
  const parts = [`已开启 ${enabledCount.value} 项`]
  const at = formatTime(data.value.updatedAt)
  if (at) parts.push(`更新 ${at}`)
  return parts.join(' · ')
})

const ctaHint = computed(() => {
  if (!subscribableTemplates.value.length) return '没有可开启的模板，等管理员配置后再来'
  return '点开后微信会弹授权框；同意几项就能收到几条，发完后要再来点一次'
})

function readable(e: unknown, fallback = '请稍后重试'): string {
  return e instanceof Error && e.message ? e.message : fallback
}

function formatTime(v?: string | null): string {
  if (!v) return ''
  return String(v).replace('T', ' ').slice(0, 16)
}

async function load(): Promise<void> {
  loading.value = true
  errorText.value = ''
  try {
    data.value = await fetchWxSubscriptions()
    loaded.value = true
  } catch (e) {
    errorText.value = readable(e, '提醒配置加载失败，请重试')
  } finally {
    loading.value = false
  }
}

/** 单项的辅助说明（按钮为什么不能点，直接写在按钮旁边） */
function itemHint(t: WxTemplateItem): string {
  if (!t.templateId) return '管理员还没填模板 id'
  if (!t.enabled) return '该提醒已停用'
  if (!bound.value) return '绑定微信后才能发'
  if (t.remain > 0) return `还剩 ${t.remain} 次发送机会`
  if (t.accepted) return '授权次数已用完，再点一次「开启提醒」'
  return '先点上方「开启提醒」授权'
}

function canTest(t: WxTemplateItem): boolean {
  if (testingKey.value) return false
  return bound.value && t.enabled && !!t.templateId && t.accepted && t.remain > 0
}

/** 把 uni.requestSubscribeMessage 包成 Promise，并把 [tmplId]: 'accept'|'reject'|... 摊平 */
function requestSubscribeMessage(tmplIds: string[]): Promise<Record<string, string>> {
  return new Promise((resolve, reject) => {
    uni.requestSubscribeMessage({
      tmplIds,
      success: (res) => {
        const bag = res as unknown as Record<string, unknown>
        const out: Record<string, string> = {}
        for (const id of tmplIds) {
          const v = bag[id]
          out[id] = typeof v === 'string' ? v : 'unknown'
        }
        resolve(out)
      },
      fail: (err) => {
        const msg = String((err as { errMsg?: string } | undefined)?.errMsg ?? '')
        reject(
          new Error(
            /cancel/i.test(msg)
              ? '你取消了这次授权，需要时再点一次「开启提醒」'
              : '订阅授权没有完成，请重试'
          )
        )
      }
    })
  })
}

async function enableReminders(): Promise<void> {
  if (subscribing.value) return
  if (!bound.value) {
    guideBind()
    return
  }
  const targets = subscribableTemplates.value
  if (!targets.length) {
    uni.showToast({ title: '管理员还没配置模板', icon: 'none' })
    return
  }

  subscribing.value = true
  const accepts: string[] = []
  const denies: string[] = []
  try {
    for (let i = 0; i < targets.length; i += SUBSCRIBE_BATCH) {
      const chunk = targets.slice(i, i + SUBSCRIBE_BATCH)
      try {
        const result = await requestSubscribeMessage(chunk.map((t) => t.templateId))
        for (const t of chunk) {
          // accept = 用户点了「允许」；reject / ban / filter 都按「未允许」上报，后端据此记账
          if (result[t.templateId] === 'accept') accepts.push(t.key)
          else denies.push(t.key)
        }
      } catch (e) {
        // 第一片就失败（用户取消 / 微信拒绝）→ 直接提示，不必回写；
        // 后续分片失败是微信「必须用户点击」的老限制，已拿到的结果照样上报，剩余按未允许处理
        if (i === 0) throw e
        for (const t of chunk) {
          if (!accepts.includes(t.key) && !denies.includes(t.key)) denies.push(t.key)
        }
      }
    }

    const res = await reportWxSubscriptions({ accepts, denies })
    if (data.value) {
      data.value = { ...data.value, templates: res.templates ?? data.value.templates }
    }
    // 后端会静默忽略未登记的键，所以用回执里的 accepted 计数才是真记账数
    const recorded = res.accepted?.length ?? accepts.length
    uni.showToast({
      title: recorded ? `已开启 ${recorded} 项提醒` : '这次没有开启提醒',
      icon: 'none'
    })
  } catch (e) {
    uni.showModal({
      title: '开启提醒未完成',
      content: readable(e),
      showCancel: false,
      confirmColor: BRAND_COLOR
    })
  } finally {
    subscribing.value = false
  }
}

async function testSend(t: WxTemplateItem): Promise<void> {
  if (!canTest(t)) return
  testingKey.value = t.key
  try {
    const res = await testWxSubscription(t.key)
    if (res.sent) {
      uni.showModal({
        title: '已发送',
        content: res.message || '一条测试提醒已发出，请留意微信「服务通知」',
        showCancel: false,
        confirmColor: BRAND_COLOR
      })
    } else {
      uni.showModal({
        title: '没有发出去',
        content: res.message || '后端跳过了这次发送，通常是模板未配置或剩余次数为 0',
        showCancel: false,
        confirmColor: BRAND_COLOR
      })
    }
    // 真发出去会扣一次 remain，重新拉一遍才是权威值
    if (res.sent) await load()
  } catch (e) {
    uni.showModal({
      title: '测试发送失败',
      content: readable(e),
      showCancel: false,
      confirmColor: BRAND_COLOR
    })
  } finally {
    testingKey.value = ''
  }
}

/**
 * 引导绑定微信。
 * 「我的」是 tab 页，switchTab 会丢 query —— 所以用一次性 storage 标记，
 * 让 profile.vue 的 onShow 读到后自动展开微信绑定面板。
 */
function guideBind(): void {
  uni.showModal({
    title: '先绑定微信',
    content: '提醒只能发到你自己的微信上。去「我的 → 微信绑定」绑一次，再回来开启提醒。',
    confirmText: '去绑定',
    confirmColor: BRAND_COLOR,
    success: (r) => {
      if (!r.confirm) return
      try {
        uni.setStorageSync(WX_BIND_INTENT_KEY, 1)
      } catch {
        /* 存储不可用时不阻断跳转，用户仍可手动点「微信绑定」 */
      }
      uni.switchTab({ url: '/pages/profile/profile' })
    }
  })
}

onShow(() => {
  if (!requireLogin()) return
  load()
})

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="ym-page notify">
    <view class="ym-card notify__intro">
      <view class="notify__intro-head">
        <wd-icon name="notification" size="18px" :color="BRAND_COLOR" />
        <text class="ym-title">微信订阅提醒</text>
      </view>
      <text class="ym-muted notify__hint">
        新客资分配到您名下、客户归属发生变更时，通过微信「服务通知」提醒你，不用一直盯小程序。
      </text>
      <text class="ym-muted notify__hint">
        订阅消息是一次性的：同意一次只能收一条，发完后要再点一次「开启提醒」。
      </text>
    </view>

    <!-- 三态之一：加载 -->
    <view v-if="loading" class="ym-card notify__skeleton">
      <view class="notify__sk" />
      <view class="notify__sk" />
      <view class="notify__sk notify__sk--short" />
    </view>

    <!-- 三态之二：错误 -->
    <ym-empty
      v-else-if="errorText"
      :text="errorText"
      desc="检查网络后重试"
      action-text="重试"
      icon="refresh"
      @action="load"
    />

    <!-- 未配置：整页说明（不是错误，不弹任何 toast） -->
    <ym-section v-else-if="!configured" title="待管理员配置模板" desc="现在不需要你做任何操作">
      <view class="notify__pending">
        <wd-icon name="tips" size="20px" :color="GOLD_COLOR" />
        <text class="notify__pending-text">
          后端还没配置微信订阅消息的模板 id（WECHAT_TPL_LEAD_ASSIGNED /
          WECHAT_TPL_MEMBER_ASSIGNED / WECHAT_TPL_TODO）。
        </text>
        <text class="notify__pending-text">
          管理员在服务器 .env 与 config/services.php 补齐后，重新进入本页就会出现可开启的提醒。
        </text>
      </view>
    </ym-section>

    <template v-else>
      <!-- 未绑定微信：先引导绑定 -->
      <view v-if="!bound" class="ym-card notify__bind">
        <view class="notify__row-between">
          <text class="ym-title">先绑定微信</text>
          <ym-badge text="未绑定" tone="warn" />
        </view>
        <text class="ym-muted notify__hint">
          订阅消息只能发到你自己的微信上。绑定后回到本页点「开启提醒」即可。
        </text>
        <view class="notify__actions">
          <wd-button block @click="guideBind">去「我的」绑定微信</wd-button>
        </view>
      </view>

      <!-- 已绑定：开启入口 -->
      <view v-else class="ym-card notify__cta">
        <view class="notify__row-between">
          <text class="ym-title">开启提醒</text>
          <ym-badge :text="`已开启 ${enabledCount} 项`" :tone="enabledCount > 0 ? 'brand' : 'muted'" />
        </view>
        <text class="ym-muted notify__hint">{{ ctaHint }}</text>
        <view class="notify__actions">
          <wd-button
            block
            :loading="subscribing"
            :disabled="!canSubscribe"
            @click="enableReminders"
          >
            {{ subscribing ? '正在唤起微信…' : '开启提醒' }}
          </wd-button>
        </view>
      </view>

      <!-- 逐项列表 -->
      <ym-section title="提醒项目" :desc="summaryLine" action-text="刷新" @action="load">
        <template v-if="visibleTemplates.length">
          <view v-for="t in visibleTemplates" :key="t.key" class="notify__item">
            <view class="notify__item-head">
              <text class="notify__item-title">{{ t.title }}</text>
              <ym-badge :text="templateStatus(t).text" :tone="templateStatus(t).tone" />
            </view>
            <text class="ym-muted notify__item-desc">{{ t.desc }}</text>
            <view class="notify__item-foot">
              <text class="ym-muted notify__item-hint">{{ itemHint(t) }}</text>
              <wd-button
                size="small"
                plain
                :disabled="!canTest(t)"
                :loading="testingKey === t.key"
                @click="testSend(t)"
              >
                发一条测试
              </wd-button>
            </view>
          </view>
        </template>
        <ym-empty
          v-else
          compact
          text="还没有可用的提醒模板"
          desc="管理员配置后会出现在这里"
          icon="notification"
        />
      </ym-section>

      <view class="ym-card notify__tips">
        <text class="ym-muted">
          收不到提醒时按顺序排查：① 本页是否显示「已授权」；② 微信里是否关闭了本小程序的服务通知；
          ③ 后端模板 id 与公众号后台是否一致。这三处都正常还收不到，把本页截图发给管理员。
        </text>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.notify {
  &__intro {
    display: flex;
    flex-direction: column;
    gap: 10rpx;
  }

  &__intro-head {
    display: flex;
    gap: 12rpx;
    align-items: center;
  }

  &__row-between {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16rpx;
  }

  &__hint {
    display: block;
    line-height: 1.65;
  }

  &__skeleton {
    display: flex;
    flex-direction: column;
    gap: 22rpx;
  }

  &__sk {
    height: 36rpx;
    background: $ink-100;
    border-radius: 8rpx;
  }

  &__sk--short {
    width: 42%;
  }

  &__pending {
    display: flex;
    flex-direction: column;
    gap: 14rpx;
    padding: 22rpx;
    background: $gold-100;
    border-radius: $r-md;
  }

  &__pending-text {
    font-size: 24rpx;
    line-height: 1.7;
    color: $ink-600;
  }

  &__bind,
  &__cta {
    display: flex;
    flex-direction: column;
    gap: 14rpx;
  }

  &__actions {
    margin-top: 10rpx;
  }

  &__item {
    display: flex;
    flex-direction: column;
    gap: 10rpx;
    padding: 22rpx 0;
    border-bottom: 2rpx solid $hairline;

    &:last-child {
      border-bottom: none;
    }
  }

  &__item-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16rpx;
  }

  &__item-title {
    font-size: 30rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__item-desc {
    display: block;
    line-height: 1.6;
  }

  &__item-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16rpx;
    margin-top: 6rpx;
  }

  &__item-hint {
    flex: 1;
    min-width: 0;
  }

  &__tips {
    line-height: 1.7;
  }
}
</style>
