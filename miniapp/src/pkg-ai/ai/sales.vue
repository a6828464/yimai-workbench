<script setup lang="ts">
/**
 * 谈单工具（MGMT：SUPER / MANAGER）
 *
 * 方案 §5.7，三块：
 *  1. 客户分享页状态：GET /shares/current?type=sales
 *     - enabled 且码可信 → 访问量 + 复制客户链接 + 停用
 *     - needsRepublish → 「请在电脑端营销工具重新开启分享」（码不可信时链接实际打不开，
 *       这里绝不能显示成「分享中」）
 *  2. 谈单话术：POST /ai/chat featureType='chat'，固定输出「开场 / 价值 / 收尾」三段
 *  3. 常见异议速查：本地静态 6 条，可折叠（不联网、不走 AI，话术题库属于培训资产）
 *
 * 权限：requireFeature('sales') —— 与后端 ShareController::assertCanManageSales 同口径
 * （R_SUPER / R_MANAGER），非 MGMT 角色在页面层就被挡掉。
 */

import { computed, ref } from 'vue'
import { onShow, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { copyText, requireFeature } from '@/utils/nav'
import {
  aiChat,
  aiReadableError,
  isAiNotConfigured,
  parseScriptSections,
  type ChatMessage,
  type ScriptSection
} from '@/api/marketing'
import {
  EMPTY_SHARE,
  buildShareLink,
  disableShare,
  fetchCurrentShare,
  type ShareStatus
} from '@/api/shares'

/* ==========================================================================
 * 分享页状态
 * ========================================================================== */

const share = ref<ShareStatus>({ ...EMPTY_SHARE })
const shareLoading = ref(false)
/** 首屏是否已经拿到过一次结果：没拿到之前不能先渲染「未开启」，否则会闪一句错的状态 */
const shareLoaded = ref(false)
const shareError = ref('')
const disabling = ref(false)

const link = computed(() => buildShareLink(share.value.code ?? ''))
const shareUsable = computed(
  () => share.value.enabled && !!share.value.code && !share.value.needsRepublish
)

async function loadShare() {
  if (shareLoading.value) return
  shareLoading.value = true
  shareError.value = ''
  try {
    share.value = await fetchCurrentShare('sales')
  } catch (e) {
    shareError.value = e instanceof Error ? e.message : '分享状态读取失败'
  } finally {
    shareLoading.value = false
    shareLoaded.value = true
  }
}

function copyLink() {
  if (!link.value) {
    uni.showToast({ title: '当前没有可分享的链接', icon: 'none' })
    return
  }
  copyText(link.value, '客户链接已复制')
}

function copyCode() {
  copyText(share.value.code ?? '', '分享码已复制')
}

function onDisable() {
  uni.showModal({
    title: '停用客户分享页',
    content: '停用后客户打开链接会看不到内容，已发出的链接不会自动恢复。',
    confirmText: '停用',
    confirmColor: '#B23C33',
    success: (res) => {
      if (!res.confirm) return
      void doDisable()
    }
  })
}

async function doDisable() {
  if (disabling.value) return
  disabling.value = true
  try {
    await disableShare('sales')
    uni.showToast({ title: '已停用', icon: 'none' })
    await loadShare()
  } catch (e) {
    uni.showModal({
      title: '停用失败',
      content: e instanceof Error ? e.message : '请稍后重试',
      showCancel: false
    })
  } finally {
    disabling.value = false
  }
}

/* ==========================================================================
 * 谈单话术
 * ========================================================================== */

const SCENES = ['首次咨询', '价格异议', '邀约到店', '续费挽回', '沉睡唤醒']
const CUSTOMER_TYPES = ['新客', '会员', 'VIP']

const scene = ref(SCENES[0])
const customerType = ref(CUSTOMER_TYPES[0])
const remark = ref('')
const scriptLoading = ref(false)
const scriptError = ref('')
/** 未配置模型单独记一个布尔：错误文案是可读后的中文，再拿文案去正则匹配会漏判 */
const scriptNotConfigured = ref(false)
const scriptRaw = ref('')
const scriptSections = ref<ScriptSection[]>([])

function buildScriptPrompt(): ChatMessage[] {
  const system = [
    '你是一麦瑜伽·宁波的资深店长，带过宁波两家门店的销售团队，现在教新同事怎么在微信上谈单。',
    '输出的硬性要求：',
    '1. 只输出三段，段名前单独一行写「开场」「价值」「收尾」（不要加粗、不要编号、不要其它小标题）。',
    '2. 每段 2-4 句，全文 200-300 字，像微信里真人说话，允许短句和口语。',
    '3. 开场：先接住客户的原话或处境，不急着介绍产品。',
    '4. 价值：给 2-3 个具体证据（课程安排、师资、场地、同类会员的变化过程），不空喊「专业」「高端」。',
    '5. 收尾：只给一个低压力、可立刻执行的下一步（约一节体验课 / 发课表 / 发一个相似案例），并以一个封闭问题结束。',
    '6. 不承诺疗效（治愈 / 根治 / 包治 / 暴瘦），不写绝对化用语（最 / 第一 / 唯一），不编造价格、优惠和具体人名。',
    '7. 不要 emoji 堆砌：全文最多 1 个，可以一个都不放。'
  ].join('\n')

  const user = [
    `【场景】${scene.value}`,
    `【客户类型】${customerType.value}`,
    `【客户情况 / 我的补充】${remark.value.trim() || '未提供 —— 用这个场景最常见的情况来写，不要编造具体人名与数字'}`,
    '请直接给三段话术，不要解释思路。'
  ].join('\n')

  return [
    { role: 'system', content: system },
    { role: 'user', content: user }
  ]
}

async function generateScript() {
  if (scriptLoading.value) return
  scriptLoading.value = true
  scriptError.value = ''
  scriptNotConfigured.value = false
  try {
    const content = await aiChat({
      messages: buildScriptPrompt(),
      temperature: 0.8,
      maxTokens: 1200,
      featureType: 'chat'
    })
    scriptRaw.value = content
    scriptSections.value = parseScriptSections(content)
  } catch (e) {
    scriptNotConfigured.value = isAiNotConfigured(e)
    scriptError.value = aiReadableError(e)
  } finally {
    scriptLoading.value = false
  }
}

function copyScript() {
  const text = scriptSections.value.length
    ? scriptSections.value.map((s) => `【${s.label}】\n${s.content}`).join('\n\n')
    : scriptRaw.value
  copyText(text, '话术已复制')
}

/** 未配置 AI 时的补充说明（这一页只用到 chat 特征） */
const scriptHint = computed(() =>
  scriptNotConfigured.value ? '需要管理员在电脑端「设置 → 模型配置」开启后才能生成' : ''
)

/* ==========================================================================
 * 异议速查（本地静态，可折叠）
 * ========================================================================== */

interface Objection {
  q: string
  a: string
  tip: string
}

const OBJECTIONS: Objection[] = [
  {
    q: '「太贵了 / 预算不够」',
    a: '先认同再算账：把年卡摊到每天多少钱，和同城同类场馆的单节私教价、团课价各报一个数；不降价，给的是方案 —— 次数少的卡、分两次付款、先带朋友来体验一节。',
    tip: '降价是最贵的成交方式：价格一让，后面续费、转介绍都按新价谈。'
  },
  {
    q: '「我怕自己坚持不下来」',
    a: '别谈自律，谈安排：先按每周 2 次、一节 50 分钟起步，我把你固定进教练的课表，前 4 次我每次课后问你一句感受。先体验 3 次再决定要不要买卡。',
    tip: '客户怕的不是花钱，是花了钱又回到原样；把「坚持」拆成可执行的排课。'
  },
  {
    q: '「我真的没时间」',
    a: '给具体时段而不是「随时可以」：早课 7:00、午间 12:15、晚课 19:30，东部店和绿地店各报一遍；从公司或家过来步行几分钟、一节课多长，说清楚。',
    tip: '「没时间」多半是「不知道要花多少时间」，先消除时间的不确定性。'
  },
  {
    q: '「练瑜伽会不会受伤 / 我腰颈不好」',
    a: '先评估再上课：约一节私教评估，看你的活动度和代偿模式，再决定从私教还是小班开始；老师说清自己的资质与擅长方向。不承诺任何治疗效果，不舒服的动作可以随时停。',
    tip: '有伤病史的客户只做评估与转介，不当场卖卡、不承诺疗效。'
  },
  {
    q: '「我自己在家跟视频练不就行了」',
    a: '差在纠正和节奏：视频不会告诉你哪里在代偿。器械普拉提有阻力和固定轨迹，老师会用手法提示位置；小班 4 人，一次课能被纠正十几次，这是自己练拿不到的。',
    tip: '别否定客户的努力，只讲「自己练拿不到什么」。'
  },
  {
    q: '「我再考虑一下 / 回去问问家人」',
    a: '不逼单，给一个具体动作：今天先把这周课表发你，你挑一个能来的时段，我先帮你留位；体验课不占用卡项。48 小时内我再问一次，不合适就当没发生。',
    tip: '「再考虑」= 现在信息不够或有顾虑没说出来，先问一句「主要是哪方面还在犹豫」。'
  }
]

const openObjection = ref(-1)

function toggleObjection(i: number) {
  openObjection.value = openObjection.value === i ? -1 : i
}

/* ==========================================================================
 * 生命周期
 * ========================================================================== */

onShow(() => {
  if (!requireFeature('sales')) return
  void loadShare()
})

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="ym-page sl">
    <!-- 1. 客户分享页状态 -->
    <ym-section
      title="客户分享页"
      desc="门店/产品/教练/案例的一页式展示"
      :action-text="shareLoading ? '' : '刷新'"
      @action="loadShare"
    >
      <view v-if="!shareLoaded" class="sl__sk">
        <view class="sl__sk-bar" />
        <view class="sl__sk-bar is-short" />
      </view>

      <ym-empty
        v-else-if="shareError"
        compact
        text="分享状态读取失败"
        :desc="shareError"
        action-text="重试"
        @action="loadShare"
      />

      <template v-else-if="shareUsable">
        <view class="sl__views">
          <text class="sl__views-num ym-num">{{ share.views }}</text>
          <text class="sl__views-label">累计访问</text>
        </view>
        <view v-if="share.viewingOther" class="sl__owner">
          <ym-badge text="他人分享" tone="info" />
          <text class="sl__owner-name">{{ share.ownerName || '该账号' }}</text>
        </view>
        <view class="sl__link">
          <text class="sl__link-text ym-ellipsis">{{ link }}</text>
        </view>
        <view class="sl__link-actions">
          <wd-button type="primary" size="small" @click="copyLink">复制客户链接</wd-button>
          <wd-button size="small" plain @click="copyCode">复制分享码</wd-button>
        </view>
        <view class="sl__share-foot">
          <text class="sl__hint">链接可直接发微信；客户打开不需要登录</text>
          <text class="sl__danger ym-tap" @click="onDisable">
            {{ disabling ? '停用中…' : '停用分享' }}
          </text>
        </view>
      </template>

      <template v-else>
        <view class="sl__off">
          <view class="sl__off-head">
            <wd-icon name="warning" size="16px" color="#B5761F" />
            <text class="sl__off-title">
              {{ share.needsRepublish ? '分享码需要重新生成' : '分享页当前未开启' }}
            </text>
          </view>
          <text class="sl__off-text">
            {{
              share.needsRepublish
                ? '原来发出的链接已打不开。请在电脑端「营销工具 → 谈单工具」重新开启分享，会换发一个新码。'
                : '请在电脑端「营销工具 → 谈单工具」开启分享，开启后这里会显示链接和访问量。'
            }}
          </text>
          <text v-if="share.viewingOther" class="sl__off-text">
            当前查看的是 {{ share.ownerName || '他人' }} 的分享记录。
          </text>
        </view>
      </template>
    </ym-section>

    <!-- 2. 谈单话术 -->
    <ym-section title="谈单话术" desc="按场景生成「开场 / 价值 / 收尾」三段">
      <text class="sl__label">场景</text>
      <view class="sl__chips">
        <view
          v-for="s in SCENES"
          :key="s"
          class="sl__chip ym-tap"
          :class="{ 'is-active': scene === s }"
          @click="scene = s"
        >
          {{ s }}
        </view>
      </view>

      <text class="sl__label">客户类型</text>
      <view class="sl__chips">
        <view
          v-for="t in CUSTOMER_TYPES"
          :key="t"
          class="sl__chip ym-tap"
          :class="{ 'is-active': customerType === t }"
          @click="customerType = t"
        >
          {{ t }}
        </view>
      </view>

      <wd-textarea
        v-model="remark"
        :maxlength="200"
        show-word-limit
        label="客户情况"
        placeholder="可选，例如：32 岁久坐，肩颈紧，问过一次价格就没回（别写真实姓名）"
      />

      <view class="sl__gen">
        <wd-button type="primary" block :loading="scriptLoading" @click="generateScript">
          {{ scriptSections.length || scriptRaw ? '重新生成话术' : '生成话术' }}
        </wd-button>
      </view>

      <view v-if="scriptError" class="sl__error">
        <view class="sl__error-head">
          <wd-icon name="warning" size="16px" color="#B5761F" />
          <text class="sl__error-title">这次没生成出来</text>
        </view>
        <text class="sl__error-text">{{ scriptError }}</text>
        <text v-if="scriptHint" class="sl__error-text">{{ scriptHint }}</text>
        <view class="sl__error-btn">
          <wd-button size="small" plain @click="generateScript">重试</wd-button>
        </view>
      </view>

      <view v-if="scriptLoading && !scriptRaw" class="sl__sk">
        <view class="sl__sk-bar" />
        <view class="sl__sk-bar is-mid" />
        <view class="sl__sk-bar is-short" />
      </view>

      <view v-if="scriptSections.length" class="sl__script">
        <view v-for="s in scriptSections" :key="s.label" class="sl__script-block">
          <view class="sl__script-head">
            <ym-badge :text="s.label" tone="brand" />
            <text class="sl__mini ym-tap" @click="copyText(s.content, '已复制')">复制</text>
          </view>
          <text class="sl__script-text">{{ s.content }}</text>
        </view>
        <wd-button size="small" plain block @click="copyScript">复制三段话术</wd-button>
      </view>

      <view v-else-if="scriptRaw && !scriptLoading" class="sl__script">
        <text class="sl__script-text">{{ scriptRaw }}</text>
        <wd-button size="small" plain block @click="copyScript">复制话术</wd-button>
      </view>
    </ym-section>

    <!-- 3. 异议速查 -->
    <ym-section title="常见异议速查" desc="6 条本地话术，不联网">
      <view v-for="(o, i) in OBJECTIONS" :key="o.q" class="sl__obj" :class="{ 'is-line': i < OBJECTIONS.length - 1 }">
        <view class="sl__obj-head ym-tap" @click="toggleObjection(i)">
          <text class="sl__obj-q">{{ o.q }}</text>
          <wd-icon
            :name="openObjection === i ? 'chevron-up' : 'chevron-down'"
            size="14px"
            color="#8A948F"
          />
        </view>
        <view v-if="openObjection === i" class="sl__obj-body">
          <text class="sl__obj-a">{{ o.a }}</text>
          <text class="sl__obj-tip">教练提示：{{ o.tip }}</text>
        </view>
      </view>
    </ym-section>

    <view class="sl__foot">
      <wd-icon name="info-circle" size="14px" color="#8A948F" />
      <text class="sl__foot-text">话术与分享页内容需人工核对后使用</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.sl {
  padding-top: 20rpx;

  &__views {
    display: flex;
    align-items: baseline;
    gap: 12rpx;
    padding: 4rpx 0 16rpx;
  }

  &__views-num {
    font-size: 64rpx;
    font-weight: 700;
    line-height: 1;
    color: $brand-600;
  }

  &__views-label {
    font-size: 24rpx;
    color: $ink-400;
  }

  &__owner {
    display: flex;
    align-items: center;
    gap: 10rpx;
    margin-bottom: 12rpx;
  }

  &__owner-name {
    font-size: 24rpx;
    color: $ink-600;
  }

  &__link {
    padding: 18rpx 20rpx;
    background: $ink-50;
    border-radius: $r-md;
  }

  &__link-text {
    font-size: 24rpx;
    color: $ink-600;
  }

  &__link-actions {
    display: flex;
    gap: 16rpx;
    margin-top: 18rpx;
  }

  &__share-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 18rpx;
  }

  &__hint {
    font-size: 22rpx;
    color: $ink-400;
  }

  &__danger {
    display: flex;
    align-items: center;
    min-height: 88rpx;
    padding: 0 28rpx;
    font-size: 24rpx;
    color: $danger-600;
    background: $danger-100;
    border-radius: 999rpx;
  }

  &__off {
    padding: 8rpx 0;
  }

  &__off-head {
    display: flex;
    align-items: center;
    gap: 8rpx;
  }

  &__off-title {
    font-size: 28rpx;
    font-weight: 600;
    color: $warn-600;
  }

  &__off-text {
    display: block;
    margin-top: 10rpx;
    font-size: 24rpx;
    line-height: 1.6;
    color: $ink-600;
  }

  &__label {
    display: block;
    margin: 12rpx 0 12rpx;
    font-size: 24rpx;
    color: $ink-500;
  }

  &__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 14rpx;
    margin-bottom: 8rpx;
  }

  &__chip {
    /* 方案 §4.4：触控目标 ≥ 88rpx 高 */
    min-height: 88rpx;
    padding: 0 28rpx;
    font-size: 26rpx;
    line-height: 88rpx;
    color: $ink-600;
    background: $ink-50;
    border: 2rpx solid transparent;
    border-radius: 999rpx;

    &.is-active {
      color: $brand-600;
      background: $brand-100;
      border-color: $brand-200;
    }
  }

  &__gen {
    margin-top: 20rpx;
  }

  &__error {
    padding: 22rpx;
    margin-top: 20rpx;
    background: $warn-100;
    border-radius: $r-md;
  }

  &__error-head {
    display: flex;
    align-items: center;
    gap: 8rpx;
  }

  &__error-title {
    font-size: 28rpx;
    font-weight: 600;
    color: $warn-600;
  }

  &__error-text {
    display: block;
    margin-top: 8rpx;
    font-size: 24rpx;
    line-height: 1.6;
    color: $ink-600;
  }

  &__error-btn {
    display: flex;
    justify-content: flex-end;
    margin-top: 12rpx;
  }

  &__script {
    margin-top: 20rpx;
  }

  &__script-block {
    padding: 20rpx;
    margin-bottom: 16rpx;
    background: $brand-50;
    border-radius: $r-md;
  }

  &__script-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  &__mini {
    display: flex;
    align-items: center;
    min-height: 88rpx;
    padding: 0 20rpx;
    font-size: 24rpx;
    color: $brand-600;
  }

  &__script-text {
    display: block;
    margin-top: 12rpx;
    font-size: 28rpx;
    line-height: 1.75;
    color: $ink-800;
    white-space: pre-wrap;
    word-break: break-word;
  }

  &__obj {
    padding: 20rpx 0;

    &.is-line {
      border-bottom: 2rpx solid $hairline;
    }
  }

  &__obj-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16rpx;
    /* 方案 §4.4：整行可点，触控目标 ≥ 88rpx 高 */
    min-height: 88rpx;
  }

  &__obj-q {
    flex: 1;
    font-size: 28rpx;
    color: $ink-800;
  }

  &__obj-body {
    padding-top: 12rpx;
  }

  &__obj-a {
    display: block;
    font-size: 26rpx;
    line-height: 1.75;
    color: $ink-600;
  }

  &__obj-tip {
    display: block;
    margin-top: 10rpx;
    font-size: 22rpx;
    color: $gold-600;
  }

  &__sk {
    padding: 10rpx 0;
  }

  &__sk-bar {
    height: 22rpx;
    margin-bottom: 14rpx;
    background: $ink-50;
    border-radius: 999rpx;

    &.is-mid {
      width: 82%;
    }

    &.is-short {
      width: 46%;
      margin-bottom: 0;
    }
  }

  &__foot {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8rpx;
    padding: 24rpx 40rpx 8rpx;
  }

  &__foot-text {
    font-size: 22rpx;
    color: $ink-400;
  }
}
</style>
