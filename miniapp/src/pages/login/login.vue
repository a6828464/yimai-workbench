<script setup lang="ts">
import { ref } from 'vue'
import { useUserStore } from '@/store/user'

const user = useUserStore()
const userName = ref('')
const password = ref('')
const loading = ref(false)
const errorMsg = ref('')

async function doLogin() {
  if (!userName.value.trim() || !password.value) {
    errorMsg.value = '请输入账号和密码'
    return
  }
  loading.value = true
  errorMsg.value = ''
  try {
    await user.login(userName.value.trim(), password.value)
    uni.switchTab({ url: '/pages/workbench/workbench' })
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '登录失败，请重试'
  } finally {
    loading.value = false
  }
}

/**
 * 微信授权登录开关（P2 合规硬门，运营规范 2.12.6：存在账号体系时必须提供微信授权登录）。
 *
 * 打开的前置条件（缺一条就还是显示账号密码单入口）：
 *  1. 后端 `/auth/wx-login` 已上线（2026-11-04 已实现，WeChatSession + 20 条契约测试）；
 *  2. 服务器环境已配 `WECHAT_MINI_APPID` / `WECHAT_MINI_SECRET`；
 *  3. 当前微信已在「人员管理」里绑定到员工账号 —— 未绑定时接口返回 403 并给出引导文案，
 *     不做自动建号（见 AuthController::wxLogin 的口径注释）。
 *
 * 用构建期变量而不是写死 true：developer tool 里没有真实 appid/secret 时，
 * 让人能一键退回单入口，不必改代码。
 */
const WX_LOGIN_ENABLED = (import.meta.env.VITE_WX_LOGIN_ENABLED ?? 'false') === 'true'

/**
 * 微信按钮**常显**（不再由开关决定是否出现），开关只影响文案。
 *
 * 理由：开关关着时按钮直接消失，员工在登录页看不到任何「微信」字样，
 * 就以为这套系统不支持微信登录，回头还是每次敲密码。常显之后：
 *  - 服务端没配 secret → 点下去得到「微信登录未开通，请使用账号密码登录」（后端归一化文案）；
 *  - 微信未绑定 → 403 文案已经明确告诉去哪绑；
 *  - 正常 → 直接进工作台。
 * 三种情况都是**可执行的信息**，比「按钮不存在」好。
 */
const wxBusy = ref(false)
async function doWxLogin() {
  loading.value = true
  wxBusy.value = true
  errorMsg.value = ''
  try {
    await user.loginWithWechat()
    uni.switchTab({ url: '/pages/workbench/workbench' })
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : '微信登录暂不可用，请用账号登录'
  } finally {
    loading.value = false
    wxBusy.value = false
  }
}
</script>

<template>
  <view class="login">
    <view class="login__brand">
      <image class="login__logo" src="/static/logo.png" mode="aspectFit" />
      <text class="login__name">一麦工作台</text>
      <text class="login__slogan">员工的移动工作台</text>
    </view>

    <view class="ym-card login__form">
      <input
        v-model="userName"
        class="login__input"
        placeholder="账号 / 姓名"
        placeholder-class="login__ph"
        :disabled="loading"
      />
      <input
        v-model="password"
        class="login__input"
        type="password"
        placeholder="密码"
        placeholder-class="login__ph"
        :disabled="loading"
        @confirm="doLogin"
      />
      <view v-if="errorMsg" class="login__error">{{ errorMsg }}</view>
      <button class="ym-btn login__submit" :class="{ 'ym-btn--disabled': loading }" :disabled="loading" @click="doLogin">
        {{ loading ? '登录中…' : '登 录' }}
      </button>
      <button
        class="ym-btn ym-btn--plain login__wx"
        :class="{ 'ym-btn--disabled': loading || wxBusy }"
        :disabled="loading || wxBusy"
        @click="doWxLogin"
      >
        {{ wxBusy ? '正在唤起微信…' : WX_LOGIN_ENABLED ? '微信一键登录' : '微信登录（需先绑定）' }}
      </button>
    </view>

    <text class="login__tip">
      仅限一麦员工使用，账号由管理员在后台开通
    </text>
    <text class="login__tip login__tip--weak">
      首次请用账号密码登录，再到「我的 → 微信绑定」绑定一次；绑定后以后点上面按钮即可
    </text>
  </view>
</template>

<style lang="scss" scoped>
.login {
  min-height: 100vh;
  padding: 120rpx 32rpx 0;
  box-sizing: border-box;
  background: linear-gradient(180deg, #eef4f1 0%, #f6f7f9 40%);

  &__brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 60rpx;
  }

  &__logo {
    width: 140rpx;
    height: 140rpx;
    margin-bottom: 24rpx;
  }

  &__name {
    font-size: 44rpx;
    font-weight: 600;
    color: #1f2329;
  }

  &__slogan {
    margin-top: 12rpx;
    font-size: 26rpx;
    color: #8a8f99;
  }

  &__input {
    height: 96rpx;
    background: #f6f7f9;
    border-radius: 16rpx;
    padding: 0 28rpx;
    font-size: 30rpx;
    margin-bottom: 24rpx;
    box-sizing: border-box;
  }

  &__ph {
    color: #b0b5bd;
  }

  &__error {
    color: #c0392b;
    font-size: 24rpx;
    margin-bottom: 20rpx;
  }

  &__submit {
    margin-top: 12rpx;
  }

  &__wx {
    margin-top: 24rpx;
  }

  &__tip--weak {
    color: #a8adb5;
    font-size: 22rpx;
    margin-top: 12rpx;
    display: block;
  }

  &__tip {
    display: block;
    text-align: center;
    margin-top: 60rpx;
    color: #b0b5bd;
    font-size: 24rpx;
  }
}
</style>
