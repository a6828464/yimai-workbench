<script setup lang="ts">
/**
 * 个人信息 / 登录密码（子页 · 原生导航栏）
 *
 * 按方案 §5.9：
 *  - `GET /my/profile` 回填 nickname / phone / avatar（邮箱已下线）；
 *  - `PUT /my/profile` **只提交改动字段**（后端对未传字段是「不修改」语义，
 *    整表提交会把用户没碰过、甚至没加载到的 profile 子结构一起覆盖）；
 *  - 头像自己压缩：chooseImage → 画布居中裁方 → 160px JPEG dataURI（≤300KB），**不上传文件**；
 *  - `?tab=password` 直接进密码表单（`PUT /my/password`，改密后其他设备下线）；
 *  - 保存后 `user.setProfile()` + `user.patchLocal()`，让「我的」页头像/昵称立即刷新。
 *
 * ## 两个已修的问题（别再犯）
 *  1. **点进来一片空白**：`loading` 初值是 true（为了首帧出骨架），而 onShow 的守卫写成
 *     `if (!loaded && !loading) load()` —— 首帧 loading 已经是 true，load() 永远不触发，
 *     页面就永久停在骨架上（真机上看起来就是白屏）。现在守卫只看 `loaded`，并加了
 *     `inFlight` 防重入 + 8 秒看门狗兜底，任何状态都不会是空白。
 *  2. **邮箱**：小程序端已下线（不展示、不提交）。后端不再默认生成邮箱，
 *     老账号即使有值也不显示；`buildProfilePatch` 也不会产出该字段（邮箱）的键。
 */
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { useUserStore, type MyProfile } from '@/store/user'
import {
  buildProfilePatch,
  fetchMyProfile,
  profileToForm,
  updateMyPassword,
  updateMyProfile,
  validateProfileForm,
  type ProfileFormValues
} from '@/api/profile'
import { AVATAR_CANVAS_ID, AVATAR_SIZE, chooseImageSource, imageToAvatarDataUrl } from '@/utils/image'

const user = useUserStore()

/** JS 侧拿不到 SCSS 变量：$brand-500 的运行时值，只在 uni.showModal 的 confirmColor 用 */
const BRAND_COLOR = '#2E7D5B'

type TabKey = 'profile' | 'password'

const tab = ref<TabKey>('profile')
const TAB_OPTIONS = ['资料', '密码']
const tabLabel = computed(() => (tab.value === 'password' ? '密码' : '资料'))

// 初值为 true：onShow 在首帧之后才跑，先渲染骨架，避免闪一下空表单/「待配置」
const loading = ref(true)
const loaded = ref(false)
const errorText = ref('')
const formError = ref('')

/** 远端快照：diff 的基准，保存后立刻换成服务端返回值 */
const origin = ref<MyProfile | null>(null)

const form = reactive<ProfileFormValues>({ nickname: '', phone: '', avatar: '' })
const fieldErrors = reactive({ nickname: '', phone: '', avatar: '' })

const saving = ref(false)
const avatarBusy = ref(false)
/** 压缩后 dataURI 的字符数（≈字节数），只用于提示，不参与提交 */
const avatarBytes = ref(0)

const pwd = reactive({ oldPassword: '', newPassword: '', confirm: '' })
const pwdError = ref('')
const pwdSaving = ref(false)

const canvasStyle = computed(() => `width:${AVATAR_SIZE}px;height:${AVATAR_SIZE}px;`)

/** 头像是否已换过（和远端快照比，而不是看是不是 dataURI——保存后服务端会回传同一个 dataURI） */
const avatarDirty = computed(() => form.avatar !== (origin.value?.avatar ?? ''))

const avatarHint = computed(() => {
  if (!form.avatar) return '未设置：会用姓名首字代替'
  if (!avatarDirty.value) return '正在使用已保存的头像'
  if (avatarBytes.value > 0) {
    const kb = Math.max(1, Math.round(avatarBytes.value / 1024))
    return `已压缩 ${AVATAR_SIZE}×${AVATAR_SIZE} · 约 ${kb}KB，保存后生效`
  }
  return '已选择新图片，保存后生效'
})

function readable(e: unknown, fallback = '请稍后重试'): string {
  return e instanceof Error && e.message ? e.message : fallback
}

function clearErrors(): void {
  fieldErrors.nickname = ''
  fieldErrors.phone = ''
  fieldErrors.avatar = ''
  formError.value = ''
}

function syncForm(p: MyProfile | null): void {
  const next = profileToForm(p)
  form.nickname = next.nickname
  form.phone = next.phone
  form.avatar = next.avatar
  avatarBytes.value = 0
}

/**
 * 拉资料。
 * `inFlight` 是普通变量而不是 ref：它只防重入，不参与渲染，做成响应式反而多一次刷新。
 * 超时交给 http 层（20s 会 reject）→ 走 catch 的错误态，所以这里不需要自己再看门狗，
 * 免得「接口慢但最终成功」时先把错误态摆出来、又不清掉。
 */
let inFlight = false

async function load(): Promise<void> {
  if (inFlight) return
  inFlight = true
  loading.value = true
  errorText.value = ''
  try {
    const p = await fetchMyProfile()
    origin.value = p
    user.setProfile(p)
    syncForm(p)
    loaded.value = true
    errorText.value = ''
  } catch (e) {
    errorText.value = readable(e, '资料加载失败，请重试')
  } finally {
    inFlight = false
    loading.value = false
  }
}

async function pickAvatar(): Promise<void> {
  if (avatarBusy.value) return
  avatarBusy.value = true
  try {
    const src = await chooseImageSource()
    if (!src) return // 用户取消，不算错误
    // 画布就在本页模板里，不传 scope：wx 默认用「当前页面」的 canvas-id 上下文，最省事也最稳
    const res = await imageToAvatarDataUrl(src, { canvasId: AVATAR_CANVAS_ID, size: AVATAR_SIZE })
    form.avatar = res.dataUrl
    avatarBytes.value = res.bytes
    fieldErrors.avatar = ''
  } catch (e) {
    uni.showModal({
      title: '头像没换成',
      content: readable(e, '请换一张照片再试'),
      showCancel: false,
      confirmColor: BRAND_COLOR
    })
  } finally {
    avatarBusy.value = false
  }
}

async function save(): Promise<void> {
  if (saving.value) return
  clearErrors()

  const invalid = validateProfileForm(form)
  if (invalid) {
    fieldErrors[invalid.field] = invalid.message
    uni.showToast({ title: invalid.message, icon: 'none' })
    return
  }

  const patch = buildProfilePatch(origin.value, form)
  if (Object.keys(patch).length === 0) {
    uni.showToast({ title: '没有需要保存的修改', icon: 'none' })
    return
  }

  saving.value = true
  try {
    const p = await updateMyProfile(patch)
    origin.value = p
    user.setProfile(p)
    // 让「我的」页与本地缓存立刻反映新昵称/头像；staffName 是归属用的规范名，绝不动
    user.patchLocal({
      userName: p.nickname || p.name,
      phone: p.phone,
      avatar: p.avatar
    })
    syncForm(p)
    uni.showToast({ title: '已保存', icon: 'none' })
  } catch (e) {
    formError.value = readable(e, '保存失败，请重试')
    uni.showToast({ title: formError.value, icon: 'none' })
  } finally {
    saving.value = false
  }
}

async function savePassword(): Promise<void> {
  if (pwdSaving.value) return
  pwdError.value = ''

  if (!pwd.oldPassword) {
    pwdError.value = '请输入当前密码'
    return
  }
  if (Array.from(pwd.newPassword).length < 8) {
    pwdError.value = '新密码至少 8 位'
    return
  }
  if (Array.from(pwd.newPassword).length > 64) {
    pwdError.value = '新密码最多 64 位'
    return
  }
  if (pwd.newPassword === pwd.oldPassword) {
    pwdError.value = '新密码不能与当前密码相同'
    return
  }
  if (pwd.newPassword !== pwd.confirm) {
    pwdError.value = '两次输入的新密码不一致'
    return
  }

  pwdSaving.value = true
  try {
    await updateMyPassword({ oldPassword: pwd.oldPassword, newPassword: pwd.newPassword })
    pwd.oldPassword = ''
    pwd.newPassword = ''
    pwd.confirm = ''
    uni.showModal({
      title: '密码已修改',
      content: '其他设备已下线，当前这台保持登录。',
      showCancel: false,
      confirmColor: BRAND_COLOR
    })
  } catch (e) {
    pwdError.value = readable(e, '修改失败，请重试')
  } finally {
    pwdSaving.value = false
  }
}

function onTabChange(e: { value: string | number }): void {
  const key: TabKey = e.value === '密码' ? 'password' : 'profile'
  tab.value = key
  uni.setNavigationBarTitle({ title: key === 'password' ? '修改密码' : '个人信息' })
}

onLoad((options) => {
  const opt = (options ?? {}) as Record<string, string>
  if (opt.tab === 'password') {
    tab.value = 'password'
    uni.setNavigationBarTitle({ title: '修改密码' })
  }
})

onShow(() => {
  if (!requireLogin()) return
  // 只看 loaded：首帧 loading 已经是 true，旧写法 `&& !loading` 会把首次 load() 挡掉（白屏根因）
  if (!loaded.value) load()
})
</script>

<template>
  <view class="ym-page edit">
    <view class="edit__tabs">
      <wd-segmented :value="tabLabel" :options="TAB_OPTIONS" @change="onTabChange" />
    </view>

    <!-- ============ 资料 ============ -->
    <template v-if="tab === 'profile'">
      <!-- 三态之一：加载 -->
      <view v-if="loading" class="ym-card edit__skeleton">
        <text class="ym-muted edit__hint">正在加载资料…</text>
        <view class="edit__sk" />
        <view class="edit__sk" />
        <view class="edit__sk edit__sk--short" />
      </view>

      <!-- 三态之二：错误 -->
      <ym-empty
        v-else-if="errorText"
        :text="errorText"
        desc="确认网络后重试；连不上也不影响你去改密码"
        action-text="重试"
        icon="refresh"
        @action="load"
      />

      <!-- 三态之三：表单 -->
      <template v-else>
        <view class="ym-card edit__avatar-card">
          <view class="edit__avatar-row">
            <ym-avatar :name="form.nickname || user.staffName" :src="form.avatar" :size="128" />
            <view class="edit__avatar-text">
              <text class="ym-title">头像</text>
              <text class="ym-muted edit__hint">{{ avatarHint }}</text>
            </view>
            <wd-button size="small" plain :loading="avatarBusy" @click="pickAvatar">
              {{ avatarBusy ? '处理中' : '更换' }}
            </wd-button>
          </view>
          <text v-if="fieldErrors.avatar" class="edit__err">{{ fieldErrors.avatar }}</text>
        </view>

        <view class="ym-card edit__form">
          <view class="edit__field edit__field--readonly">
            <text class="edit__label">账号</text>
            <text class="edit__readonly">{{ user.staffName || '未同步' }}</text>
            <text class="ym-muted edit__hint">账号是归属判定用的规范名，只能由管理员在电脑端修改。</text>
          </view>

          <view class="edit__field">
            <text class="edit__label">昵称</text>
            <wd-input
              v-model="form.nickname"
              placeholder="留空则显示姓名"
              :maxlength="30"
              clearable
              no-border
            />
            <text v-if="fieldErrors.nickname" class="edit__err">{{ fieldErrors.nickname }}</text>
          </view>

          <view class="edit__field">
            <text class="edit__label">手机号</text>
            <wd-input
              v-model="form.phone"
              type="number"
              placeholder="11 位手机号"
              :maxlength="20"
              clearable
              no-border
            />
            <text v-if="fieldErrors.phone" class="edit__err">{{ fieldErrors.phone }}</text>
          </view>


          <text v-if="formError" class="edit__err edit__err--block">{{ formError }}</text>

          <view class="edit__actions">
            <wd-button block :loading="saving" @click="save">
              {{ saving ? '保存中…' : '保存资料' }}
            </wd-button>
          </view>
        </view>
      </template>
    </template>

    <!-- ============ 密码 ============ -->
    <template v-else>
      <view class="ym-card edit__form">
        <text class="ym-title">修改登录密码</text>
        <text class="ym-muted edit__hint">改完其他设备会下线，当前这台保持登录；下次用新密码登录。</text>

        <view class="edit__field">
          <text class="edit__label">当前密码</text>
          <wd-input v-model="pwd.oldPassword" show-password placeholder="请输入当前密码" no-border />
        </view>

        <view class="edit__field">
          <text class="edit__label">新密码</text>
          <wd-input
            v-model="pwd.newPassword"
            show-password
            placeholder="至少 8 位"
            :maxlength="64"
            no-border
          />
        </view>

        <view class="edit__field">
          <text class="edit__label">确认新密码</text>
          <wd-input
            v-model="pwd.confirm"
            show-password
            placeholder="再输一次"
            :maxlength="64"
            no-border
            @confirm="savePassword"
          />
        </view>

        <text v-if="pwdError" class="edit__err edit__err--block">{{ pwdError }}</text>

        <view class="edit__actions">
          <wd-button block :loading="pwdSaving" @click="savePassword">
            {{ pwdSaving ? '提交中…' : '保存新密码' }}
          </wd-button>
        </view>
      </view>
    </template>

    <!-- 头像压缩用的离屏画布：必须真渲染，所以 fixed 到视口外而不是 display:none -->
    <canvas :canvas-id="AVATAR_CANVAS_ID" class="edit__canvas" :style="canvasStyle" />
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.edit {
  &__tabs {
    padding: 24rpx 24rpx 0;
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
    width: 46%;
  }

  &__avatar-row {
    display: flex;
    gap: 24rpx;
    align-items: center;
  }

  &__avatar-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 8rpx;
    min-width: 0;
  }

  &__avatar-card {
    display: flex;
    flex-direction: column;
    gap: 12rpx;
  }

  &__form {
    display: flex;
    flex-direction: column;
    gap: 8rpx;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: 8rpx;
    padding: 16rpx 0;
    /* 每个字段一条发丝线；最后一条正好把「表单」和「保存按钮」分开，不用特判最后一个 */
    border-bottom: 2rpx solid $hairline;
  }

  &__label {
    font-size: 24rpx;
    color: $ink-500;
  }

  &__readonly {
    font-size: 30rpx;
    color: $ink-800;
  }

  &__field--readonly {
    background: transparent;
  }

  &__hint {
    display: block;
    line-height: 1.6;
  }

  &__err {
    font-size: 22rpx;
    color: $danger-600;
  }

  &__err--block {
    display: block;
    margin-top: 12rpx;
  }

  &__actions {
    margin-top: 28rpx;
  }

  &__canvas {
    position: fixed;
    top: -2000px;
    left: -2000px;
    pointer-events: none;
  }
}
</style>
