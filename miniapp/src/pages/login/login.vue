<script setup lang="ts">
import { onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { computed, ref } from 'vue'
import { useUserStore } from '@/store/user'
import { fetchMiniappConfig } from '@/api/miniapp'
import { getJSON, setJSON } from '@/utils/storage'

const user = useUserStore()
const userName = ref('')
const password = ref('')
const loading = ref(false)
const errorMsg = ref('')

/* --------------------------------------------------------------------------
 * 主题：editorial（默认）/ oriental —— 口径与首页、到店指引同一条
 * （`/miniapp/config` 的 theme，后端只认这两个值，缺失/非法一律 editorial）。
 *
 * 三点说明：
 *  1. 走 api/miniapp.ts 的 fetchMiniappConfig（免登录 + 401 不跳登录）：
 *     登录页本身就是未登录状态，用 http.ts 的请求会在 401 时把「登录过期」流程跑起来；
 *  2. 先读缓存渲染（login 通常由首页跳进来，首页已经拿过并缓存了主题）→ 不会闪；
 *  3. 请求失败就沿用缓存/默认值：登录页不能因为主题配置拉不到而白屏或卡住。
 * ------------------------------------------------------------------------ */
const THEME_CACHE_KEY = 'yimai-miniapp-theme' // 与 home.vue 共用同一个 key

function readCachedTheme(): string {
  const v = getJSON<string>(THEME_CACHE_KEY)
  return typeof v === 'string' ? v : ''
}

const themeRaw = ref(readCachedTheme())

/** 主题归一：只有 oriental 走东方主题，其余（缺失 / 未知 / 老值 classic）一律 editorial */
const theme = computed<'editorial' | 'oriental'>(() =>
  themeRaw.value === 'oriental' ? 'oriental' : 'editorial'
)
const isOriental = computed(() => theme.value === 'oriental')

async function loadTheme(): Promise<void> {
  try {
    const cfg = await fetchMiniappConfig()
    // 空串也要写：后台把主题改回 editorial 时缓存必须能回落
    themeRaw.value = typeof cfg.theme === 'string' ? cfg.theme : ''
    setJSON(THEME_CACHE_KEY, themeRaw.value)
  } catch {
    /* 主题拿不到就沿用缓存/默认 editorial —— 登录功能本身不依赖它 */
  }
}

// 不 await：主题是锦上添花，登录表单要立刻可输入
void loadTheme()

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

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="login" :class="isOriental ? 'login--oriental' : 'login--editorial'">
    <view class="login__brand">
      <image class="login__logo" src="/static/logo.png" mode="aspectFit" />
      <!-- 香槟金宽字距小标签：东方主题的身份标识（editorial 不渲染） -->
      <text v-if="isOriental" class="login__tag">S T A F F  O N L Y</text>
      <text class="login__name">一麦工作台</text>
      <text class="login__slogan">授课服务完整衔接，为会员提供更有温度的服务</text>
    </view>

    <!-- 两套主题都去掉了白卡：表单直接落在底色上（无边框无阴影） -->
    <view class="login__form">
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
      <button
        class="login__submit"
        :class="{ 'is-disabled': loading }"
        :disabled="loading"
        @click="doLogin"
      >
        {{ loading ? '登录中…' : '登 录' }}
      </button>
      <!-- 微信登录保持纯文字链接形态：不抢主按钮的注意力 -->
      <button
        class="login__wx"
        :class="{ 'is-disabled': loading || wxBusy }"
        :disabled="loading || wxBusy"
        @click="doWxLogin"
      >
        {{ wxBusy ? '正在唤起微信…' : WX_LOGIN_ENABLED ? '微信一键登录' : '微信登录（需先绑定）' }}
      </button>
    </view>

    <text class="login__tip">仅限一麦员工使用</text>
  </view>
</template>

<style lang="scss" scoped>
/* ==========================================================================
 * 登录页 · 两套主题
 *   editorial（默认）：暖白纸感、无卡片、下划线输入框、整宽深墨绿实心按钮；
 *   oriental        ：米灰底、衬线标题 + 香槟金 STAFF ONLY、发丝下划线、
 *                      1.5rpx 墨绿描边直角透明按钮。
 * 两套都不再用白卡（.ym-card）：登录是「一页纸」的动作，卡片会让它像后台表单。
 * ========================================================================== */
.login {
  min-height: 100vh;
  /* 顶部留白加大：品牌区原来 120rpx 起步太靠上，表单整体下移一档（馆主 2026-11-07） */
  padding: 200rpx 44rpx 0;
  box-sizing: border-box;

  &--editorial {
    background: #fcfbf8;
  }

  &--oriental {
    background: #f2f4f2;
  }

  &__brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 72rpx;
  }

  &__logo {
    width: 132rpx;
    height: 132rpx;
    margin-bottom: 24rpx;
  }

  &__form {
    display: flex;
    flex-direction: column;
  }

  &__ph {
    color: #b0b5bd;
  }

  &__error {
    color: #c0392b;
    font-size: 24rpx;
    margin-bottom: 20rpx;
  }

  &__tip {
    display: block;
    text-align: center;
    margin-top: 60rpx;
    font-size: 24rpx;
  }

  &__tip--weak {
    font-size: 22rpx;
    margin-top: 12rpx;
  }
}

/* ---------------- editorial（默认） ---------------- */
.login--editorial {
  .login__name {
    font-size: 44rpx;
    font-weight: 300;
    letter-spacing: 0.12em;
    color: #17241d;
  }

  .login__slogan {
    margin-top: 14rpx;
    font-size: 25rpx;
    letter-spacing: 0.08em;
    color: #8a8f99;
  }

  /* 下划线式输入框：无圆角、无填充、0.5px（=1rpx）发丝线 */
  .login__input {
    height: 96rpx;
    padding: 0;
    margin-bottom: 8rpx;
    font-size: 30rpx;
    color: #17241d;
    background: transparent;
    border-radius: 0;
    border-bottom: 1rpx solid rgba(23, 36, 29, 0.18);
    box-sizing: border-box;
  }

  /* 整宽 46px（92rpx）实心墨绿 + 4px（8rpx）圆角 + 字距 .18em */
  .login__submit {
    margin-top: 48rpx;
    width: 100%;
    height: 92rpx;
    line-height: 92rpx;
    font-size: 28rpx;
    letter-spacing: 0.18em;
    padding: 0;
    color: #fcfbf8;
    background: #17241d;
    border: 0;
    border-radius: 8rpx;

    &::after {
      border: 0;
    }
  }

  /* 微信一键登录：纯文字链接（无边框、无底色） */
  .login__wx {
    margin-top: 28rpx;
    width: 100%;
    height: 72rpx;
    line-height: 72rpx;
    font-size: 25rpx;
    letter-spacing: 0.04em;
    padding: 0;
    color: #7b8781;
    background: transparent;
    border: 0;

    &::after {
      border: 0;
    }
  }

  .login__tip {
    color: #9aa39e;
  }

}

/* ---------------- oriental（静奢东方） ---------------- */
.login--oriental {
  .login__tag {
    margin-bottom: 16rpx;
    font-size: 18rpx;
    letter-spacing: 0.5em;
    /* 字距会在行尾多出一格，左移半格让视觉居中 */
    margin-left: 0.5em;
    color: #c19a4b;
  }

  .login__name {
    font-family: 'Songti SC', 'STSong', 'SimSun', serif;
    font-weight: 300;
    font-size: 46rpx;
    letter-spacing: 0.14em;
    color: #22332b;
  }

  .login__slogan {
    margin-top: 14rpx;
    font-size: 23rpx;
    letter-spacing: 0.16em;
    color: #7c8a82;
  }

  /* 1px（2rpx）发丝线：比 editorial 的 1rpx 略重，配衬线标题不显虚 */
  .login__input {
    height: 96rpx;
    padding: 0;
    margin-bottom: 8rpx;
    font-size: 30rpx;
    color: #22332b;
    background: transparent;
    border-radius: 0;
    border-bottom: 2rpx solid rgba(28, 96, 71, 0.35);
    box-sizing: border-box;
  }

  .login__ph {
    color: #a6b1aa;
  }

  /* 1.5rpx 墨绿描边 + 直角 + 透明底 */
  .login__submit {
    margin-top: 48rpx;
    width: 100%;
    height: 92rpx;
    line-height: 92rpx;
    font-size: 28rpx;
    letter-spacing: 0.2em;
    padding: 0;
    color: #1c6047;
    background: transparent;
    border: 1.5rpx solid #1c6047;
    border-radius: 0;

    &::after {
      border: 0;
    }
  }

  .login__wx {
    margin-top: 28rpx;
    width: 100%;
    height: 72rpx;
    line-height: 72rpx;
    font-size: 24rpx;
    letter-spacing: 0.08em;
    padding: 0;
    color: #7c8a82;
    background: transparent;
    border: 0;

    &::after {
      border: 0;
    }
  }

  .login__tip {
    color: #8c9a92;
  }

}

/* 禁用态：两套主题共用（透明度足够说明不可点，不改颜色语义） */
.login__submit.is-disabled,
.login__wx.is-disabled {
  opacity: 0.45;
}
</style>
