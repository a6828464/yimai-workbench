<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { requireLogin } from '@/utils/guard'
import { fetchWxIdentity, wxSelfBind } from '@/api/auth'

const user = useUserStore()

/**
 * 微信绑定态。
 *
 * 为什么放「我的」页：openid 不能靠员工自己报（他平时看不到），也不能自动猜
 * （微信不给手机号）。这里把「有没有绑 + 自己的 openid」摆在他面前，
 * 一条路是本人直接绑（wxSelfBind，最省事），另一条是把 openid 发给管理员代绑。
 */
const wx = ref<{ openid: string; bound: boolean; boundAt?: string | null } | null>(null)
const wxBusy = ref(false)

async function loadWx() {
  try {
    wx.value = await fetchWxIdentity()
  } catch {
    wx.value = null
  }
}

async function bindWx() {
  if (wxBusy.value) return
  wxBusy.value = true
  try {
    await wxSelfBind()
    uni.showToast({ title: '绑定成功，下次可微信一键登录', icon: 'none' })
    await Promise.all([loadWx(), user.refreshMe()])
  } catch (e) {
    uni.showModal({
      title: '绑定未完成',
      content: e instanceof Error ? e.message : '请稍后重试',
      showCancel: false
    })
  } finally {
    wxBusy.value = false
  }
}

function copyOpenid() {
  if (!wx.value?.openid) return
  uni.setClipboardData({
    data: wx.value.openid,
    success: () => uni.showToast({ title: '已复制，可发给管理员', icon: 'none' })
  })
}

function bindTime(v?: string | null): string {
  return v ? String(v).replace('T', ' ').slice(0, 16) : ''
}

onShow(() => {
  if (requireLogin()) loadWx()
})

function confirmLogout() {
  uni.showModal({
    title: '退出登录',
    content: '确定退出当前账号吗？',
    confirmColor: '#2E7D5B',
    success: (r) => {
      if (r.confirm) user.logout()
    }
  })
}
</script>

<template>
  <view class="ym-page">
    <view class="ym-card profile__head">
      <view class="profile__avatar">{{ (user.info?.userName || '一')[0] }}</view>
      <view>
        <text class="ym-title">{{ user.info?.userName }}</text>
        <text class="ym-muted profile__sub">{{ user.info?.venue || '—' }} · {{ (user.info?.roles || []).join(' / ') }}</text>
      </view>
    </view>

    <view class="ym-card">
      <view class="ym-row--between profile__row">
        <text>账号邮箱</text>
        <text class="ym-muted">{{ user.info?.email || '—' }}</text>
      </view>
      <view class="ym-row--between profile__row">
        <text>归属门店</text>
        <text class="ym-muted">{{ (user.info?.venues || []).join('、') || '—' }}</text>
      </view>
      <view class="ym-row--between profile__row">
        <text>账号状态</text>
        <text class="ym-muted">{{ user.info?.status || '启用' }}</text>
      </view>
      <view class="ym-row--between profile__row">
        <text>微信绑定</text>
        <text :class="wx?.bound ? 'profile__bound' : 'ym-muted profile__unbound'">
          {{ wx?.bound ? `已绑定 ${bindTime(wx?.boundAt)}` : '未绑定' }}
        </text>
      </view>
    </view>

    <view class="ym-card">
      <text class="ym-title">微信一键登录</text>
      <text class="ym-muted profile__hint">
        {{
          wx?.bound
            ? '已绑定：以后在登录页点「微信一键登录」即可，无需再输密码。换了微信号就重新绑定一次。'
            : '绑定后，登录页可以直接微信一键登录，不用每次输账号密码。绑定前请确认这台手机用的是你本人的微信。'
        }}
      </text>

      <view v-if="!wx?.bound" class="profile__openid">
        <text class="ym-muted">你的 openid（可复制发给管理员代绑）</text>
        <view class="ym-row--between profile__openid-row">
          <text class="profile__openid-value">{{ wx?.openid || '加载中…' }}</text>
          <text v-if="wx?.openid" class="profile__copy" @click="copyOpenid">复制</text>
        </view>
      </view>

      <button class="ym-btn profile__bind" :disabled="wxBusy" @click="bindWx">
        {{ wxBusy ? '绑定中…' : wx?.bound ? '重新绑定当前微信' : '用当前微信绑定我的账号' }}
      </button>
    </view>

    <view class="ym-card profile__about">
      <text class="ym-muted">资料与密码修改请使用电脑端工作台</text>
    </view>

    <button class="ym-btn profile__logout" @click="confirmLogout">退出登录</button>
  </view>
</template>

<style lang="scss" scoped>
.profile {
  &__head {
    display: flex;
    align-items: center;
    gap: 24rpx;
  }

  &__avatar {
    width: 110rpx;
    height: 110rpx;
    border-radius: 50%;
    background: #2e7d5b;
    color: #fff;
    font-size: 44rpx;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  &__sub {
    display: block;
    margin-top: 8rpx;
  }

  &__row {
    min-height: 88rpx;
    border-bottom: 2rpx solid #f0f1f3;

    &:last-child {
      border-bottom: none;
    }
  }

  &__bound {
    color: #2e7d5b;
  }

  &__unbound {
    color: #c26a1b;
  }

  &__hint {
    display: block;
    margin-top: 12rpx;
    line-height: 1.6;
  }

  &__openid {
    margin-top: 24rpx;
    background: #f7f8fa;
    border-radius: 14rpx;
    padding: 20rpx;
  }

  &__openid-row {
    margin-top: 10rpx;
  }

  &__openid-value {
    flex: 1;
    font-size: 24rpx;
    word-break: break-all;
    color: #1f2329;
  }

  &__copy {
    color: #2e7d5b;
    font-size: 26rpx;
    padding-left: 20rpx;
  }

  &__bind {
    margin-top: 26rpx;
  }

  &__about {
    text-align: center;
  }

  &__logout {
    margin: 40rpx 24rpx 0;
    background: #fff;
    color: #c0392b;
  }
}
</style>
