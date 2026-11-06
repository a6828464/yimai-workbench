<script setup lang="ts">
/**
 * 人设设置（子页 · 原生导航栏）
 *
 * 这一页解决的是「AI 写的文案不像我说的」：营销工具的提示词里有两行是
 * `【我的身份】` 与 `【目标人群】`，之前身份被写死成「会籍顾问」，
 * 老师 / 前台 / 主理人生出来的东西自然串味。这里把这两项做成账号级设置。
 *
 * 数据来源只有一个接口（不新增）：`GET /my/profile` → `profile.persona` / `profile.xhs`。
 *  - `persona.role`      身份角色（会籍顾问 / 全职老师 / 门店前台 / 管理层 / 主理人）
 *  - `persona.audiences` 目标人群（多选）
 *  - `profile.xhs`       小红书资料，**存在才显示**（管理员在电脑端建过才有），
 *                        其中 role / audiences 本页不编辑，保存时原样带回，避免被 array_merge 抹掉。
 *
 * 保存只发本页字段：`PUT /my/profile {profile:{persona:{role,audiences}, xhs:{...}}}`。
 * 后端是整键替换（array_merge），所以「只提交本页字段」必须自己保证不漏字段，
 * 这部分收在 `api/profile.ts#buildPersonaPatch` 里。
 */
import { computed, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { useUserStore, type MyProfile } from '@/store/user'
import {
  PERSONA_AUDIENCES,
  PERSONA_ROLE_OPTIONS,
  XHS_CONVERSIONS,
  XHS_IP_TYPES,
  XHS_STYLES,
  buildPersonaPatch,
  fetchMyProfile,
  personaFromProfile,
  updateMyProfile,
  type PersonaSettings,
  type PersonaXhsSettings
} from '@/api/profile'

const user = useUserStore()

const loading = ref(true)
const loaded = ref(false)
const errorText = ref('')
const saving = ref(false)
const formError = ref('')

/** 远端快照：保存后换成服务端返回值 */
const origin = ref<MyProfile | null>(null)

const form = reactive({
  role: PERSONA_ROLE_OPTIONS[0],
  audiences: [] as string[]
})

/** 小红书资料（仅 hasXhs 为真时显示） */
const hasXhs = ref(false)
const xhs = reactive<PersonaXhsSettings>({
  ipType: '',
  accountName: '',
  style: '',
  conversion: '',
  localFocus: false
})

let inFlight = false

/** 已经存在但不在预设清单里的人设/风格，也要能被看到和取消（否则用户改不掉旧值） */
const audienceOptions = computed(() => {
  const extra = form.audiences.filter((a) => !PERSONA_AUDIENCES.includes(a))
  return [...PERSONA_AUDIENCES, ...extra]
})
const styleOptions = computed(() => {
  const extra = xhs.style && !XHS_STYLES.includes(xhs.style) ? [xhs.style] : []
  return [...XHS_STYLES, ...extra]
})
const conversionOptions = computed(() => {
  const extra = xhs.conversion && !XHS_CONVERSIONS.includes(xhs.conversion) ? [xhs.conversion] : []
  return [...XHS_CONVERSIONS, ...extra]
})

function readable(e: unknown, fallback = '请稍后重试'): string {
  return e instanceof Error && e.message ? e.message : fallback
}

function hydrate(p: MyProfile | null): void {
  const settings = personaFromProfile(p)
  form.role = settings.role || PERSONA_ROLE_OPTIONS[0]
  form.audiences = [...settings.audiences]
  hasXhs.value = !!settings.xhs
  if (settings.xhs) {
    xhs.ipType = settings.xhs.ipType
    xhs.accountName = settings.xhs.accountName
    xhs.style = settings.xhs.style
    xhs.conversion = settings.xhs.conversion
    xhs.localFocus = settings.xhs.localFocus
  }
}

async function load(): Promise<void> {
  if (inFlight) return
  inFlight = true
  loading.value = true
  errorText.value = ''
  try {
    const p = await fetchMyProfile()
    origin.value = p
    user.setProfile(p)
    hydrate(p)
    loaded.value = true
    errorText.value = ''
  } catch (e) {
    errorText.value = readable(e, '人设资料加载失败，请重试')
  } finally {
    inFlight = false
    loading.value = false
  }
}

function toggleAudience(v: string): void {
  const i = form.audiences.indexOf(v)
  if (i >= 0) form.audiences.splice(i, 1)
  else form.audiences.push(v)
}

function pickIpType(v: string): void {
  xhs.ipType = xhs.ipType === v ? '' : v
}

function pickStyle(v: string): void {
  xhs.style = xhs.style === v ? '' : v
}

function pickConversion(v: string): void {
  xhs.conversion = xhs.conversion === v ? '' : v
}

async function save(): Promise<void> {
  if (saving.value) return
  formError.value = ''

  const role = form.role.trim()
  if (!role) {
    formError.value = '请选择身份角色'
    uni.showToast({ title: formError.value, icon: 'none' })
    return
  }

  const settings: PersonaSettings = {
    role,
    audiences: [...form.audiences],
    xhs: hasXhs.value ? { ...xhs } : null
  }

  saving.value = true
  try {
    const p = await updateMyProfile(buildPersonaPatch(settings, origin.value))
    origin.value = p
    user.setProfile(p)
    hydrate(p)
    uni.showToast({ title: '人设已保存', icon: 'none' })
  } catch (e) {
    // 失败原因 http 层已经统一 toast 过：这里只写内联错误（模板 persona__err），
    // 不再二次弹窗，否则同一句错误会连弹两次（F23）
    formError.value = readable(e, '保存失败，请重试')
  } finally {
    saving.value = false
  }
}

onShow(() => {
  if (!requireLogin()) return
  // 只认 loaded：首帧 loading 已是 true，用 `&& !loading` 会把首次加载挡掉（个人信息页白屏就栽在这）
  if (!loaded.value) load()
})
</script>

<template>
  <view class="ym-page persona">
    <view class="ym-card persona__intro">
      <text class="ym-title">人设</text>
      <text class="ym-muted persona__hint">
        这里填的「身份」与「目标人群」会直接写进营销工具的提示词：身份决定口吻，
        人群决定内容写给谁看。填得越具体，生成的文案越像你自己。
      </text>
    </view>

    <!-- 三态之一：加载（给一句说明，绝不留白屏） -->
    <view v-if="loading" class="ym-card persona__skeleton">
      <text class="ym-muted persona__hint">正在加载人设…</text>
      <view class="persona__sk" />
      <view class="persona__sk" />
      <view class="persona__sk persona__sk--short" />
    </view>

    <!-- 三态之二：失败 -->
    <ym-empty
      v-else-if="errorText"
      :text="errorText"
      desc="检查网络后重试；人设只影响 AI 文案，不影响登录与客户数据"
      action-text="重试"
      icon="refresh"
      @action="load"
    />

    <!-- 三态之三：表单 -->
    <template v-else>
      <view class="ym-card persona__card">
        <text class="persona__section">身份与人群</text>

        <wd-picker
          v-model="form.role"
          label="身份角色"
          :columns="PERSONA_ROLE_OPTIONS"
          custom-class="persona__picker"
        />

        <view class="persona__field">
          <text class="persona__label">目标人群（可多选）</text>
          <view class="persona__chips">
            <view
              v-for="a in audienceOptions"
              :key="a"
              class="persona__chip ym-tap"
              :class="{ 'is-on': form.audiences.includes(a) }"
              @click="toggleAudience(a)"
            >
              {{ a }}
            </view>
          </view>
          <text class="ym-muted persona__hint">
            选你平时真正常接的人群；没选就不会出现在提示词里（不会让 AI 自己猜）。
          </text>
        </view>
      </view>

      <!-- 小红书资料：存在才显示 -->
      <view v-if="hasXhs" class="ym-card persona__card">
        <text class="persona__section">小红书</text>
        <text class="ym-muted persona__hint">
          这些字段来自电脑端已建好的小红书资料，只影响小红书文案的写法。
        </text>

        <view class="persona__field">
          <text class="persona__label">IP 类型</text>
          <view class="persona__chips">
            <view
              v-for="t in XHS_IP_TYPES"
              :key="t"
              class="persona__chip ym-tap"
              :class="{ 'is-on': xhs.ipType === t }"
              @click="pickIpType(t)"
            >
              {{ t }}
            </view>
          </view>
        </view>

        <view class="persona__field">
          <text class="persona__label">账号名</text>
          <wd-input
            v-model="xhs.accountName"
            placeholder="例如：一麦瑜伽·小鹿"
            :maxlength="60"
            clearable
            no-border
          />
        </view>

        <view class="persona__field">
          <text class="persona__label">表达风格</text>
          <view class="persona__chips">
            <view
              v-for="s in styleOptions"
              :key="s"
              class="persona__chip ym-tap"
              :class="{ 'is-on': xhs.style === s }"
              @click="pickStyle(s)"
            >
              {{ s }}
            </view>
          </view>
        </view>

        <view class="persona__field">
          <text class="persona__label">转化目标</text>
          <view class="persona__chips">
            <view
              v-for="c in conversionOptions"
              :key="c"
              class="persona__chip ym-tap"
              :class="{ 'is-on': xhs.conversion === c }"
              @click="pickConversion(c)"
            >
              {{ c }}
            </view>
          </view>
        </view>

        <view class="persona__switch">
          <view class="persona__switch-text">
            <text class="persona__label">同城流量优先</text>
            <text class="ym-muted persona__hint">打开后小红书文案会带更多宁波本地场景与关键词。</text>
          </view>
          <wd-switch v-model="xhs.localFocus" />
        </view>
      </view>

      <view v-else class="ym-card persona__notice">
        <text class="ym-muted persona__hint">
          还没有小红书资料：管理员在电脑端为你建好账号资料后，这里会自动出现「小红书」设置区块。
          当前只保存身份与人群也完全可用。
        </text>
      </view>

      <text v-if="formError" class="persona__err">{{ formError }}</text>

      <view class="persona__actions">
        <wd-button block :loading="saving" @click="save">
          {{ saving ? '保存中…' : '保存人设' }}
        </wd-button>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.persona {
  &__intro {
    display: flex;
    flex-direction: column;
    gap: 10rpx;
  }

  &__card {
    display: flex;
    flex-direction: column;
    gap: 6rpx;
  }

  &__section {
    font-size: 26rpx;
    font-weight: 600;
    letter-spacing: 1rpx;
    color: $ink-600;
  }

  &__hint {
    display: block;
    line-height: 1.65;
  }

  &__skeleton {
    display: flex;
    flex-direction: column;
    gap: 20rpx;
  }

  &__sk {
    height: 36rpx;
    background: $ink-100;
    border-radius: 8rpx;
  }

  &__sk--short {
    width: 46%;
  }

  &__picker {
    margin: 4rpx 0;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: 12rpx;
    padding: 20rpx 0;
    border-top: 2rpx solid $hairline;
  }

  &__label {
    font-size: 26rpx;
    color: $ink-700;
  }

  &__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 16rpx;
  }

  &__chip {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 72rpx;
    padding: 0 26rpx;
    font-size: 26rpx;
    color: $ink-600;
    background: $ink-50;
    border: 2rpx solid transparent;
    border-radius: 999rpx;

    &.is-on {
      color: $brand-700;
      background: $brand-50;
      border-color: $brand-200;
      font-weight: 600;
    }
  }

  &__switch {
    display: flex;
    gap: 20rpx;
    align-items: center;
    justify-content: space-between;
    padding: 20rpx 0;
    border-top: 2rpx solid $hairline;
  }

  &__switch-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 6rpx;
    min-width: 0;
  }

  &__notice {
    line-height: 1.7;
  }

  &__err {
    display: block;
    padding: 0 28rpx;
    margin-top: 16rpx;
    font-size: 22rpx;
    color: $danger-600;
  }

  &__actions {
    padding: 12rpx 24rpx 0;
  }
}
</style>
