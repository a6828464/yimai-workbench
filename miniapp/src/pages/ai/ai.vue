<script setup lang="ts">
/**
 * AI 助手 hub（tab 页）
 *
 * 结构（方案 §5.5）：
 *  1. 两张主卡：营销工具（ALL）/ 谈单工具（MGMT）—— 入口由 visibleFeatures(roles,'ai')
 *     生成，页面里不写 `roles.includes(...)`，权限收口只留一份（utils/permission.ts）。
 *  2. 最近生成 3 条：读本地 `yimai-ai-recent`（marketing 页生成成功后写入）。
 *     走本地而不是 GET /marketing/history 的原因：hub 是 tab 首屏，必须秒开；
 *     服务端历史还要等 token + 网络往返，弱网下会先空一屏再跳出内容。
 *  3. 底部说明：AI 内容需人工审核后发布（品牌合规口径，不做成可关闭的浮层）。
 */
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { requireLogin } from '@/utils/guard'
import { goFeature } from '@/utils/nav'
import { visibleFeatures } from '@/utils/permission'
import {
  clearRecentGenerations,
  listRecentGenerations,
  type RecentGeneration
} from '@/api/marketing'

const user = useUserStore()
const recent = ref<RecentGeneration[]>([])
const refreshing = ref(false)

/** AI 区的两张主卡（顺序 = 注册表顺序：营销工具在前） */
const cards = computed(() => visibleFeatures(user.roles, 'ai'))

/** 卡片副文案：说清「点进去能得到什么」，不是重复 desc */
const CARD_HINTS: Record<string, string> = {
  marketing: '朋友圈 3 条 / 小红书笔记，选中即复制',
  sales: '客户分享页 + 谈单话术 + 异议速查'
}

const venueText = computed(() => user.venue || '一麦瑜伽')

function loadRecent() {
  recent.value = listRecentGenerations(3)
}

onShow(() => {
  if (!requireLogin()) return
  loadRecent()
  // 角色可能在电脑端被改过：静默刷新一次，避免无权入口残留 / 有权入口缺失。
  // 不 await —— 卡片先按本地缓存渲染，回来再对齐。
  if (!refreshing.value) {
    refreshing.value = true
    void user.refreshMe().finally(() => {
      refreshing.value = false
    })
  }
})

function open(key: string) {
  // goFeature 内含权限判定 + 无权 toast，不在页面里重复判断
  goFeature(key)
}

function openRecent() {
  goFeature('marketing')
}

function onClear() {
  uni.showModal({
    title: '清空最近生成',
    content: '只清本机记录，服务端历史与收藏不受影响。',
    confirmText: '清空',
    success: (res) => {
      if (!res.confirm) return
      clearRecentGenerations()
      loadRecent()
      uni.showToast({ title: '已清空', icon: 'none' })
    }
  })
}

/** 摘要：优先标题，没有标题就取正文首行 */
function summaryOf(item: RecentGeneration): string {
  const text = (item.title || item.preview || '').replace(/\s+/g, ' ').trim()
  if (!text) return '（无内容）'
  return text.length > 40 ? `${text.slice(0, 40)}…` : text
}
</script>

<template>
  <view class="ym-page ym-page--tab ai">
    <ym-hero
      eyebrow="一麦瑜伽 · AI"
      title="AI 助手"
      :subtitle="`${user.roleText} · ${venueText}`"
    />

    <view class="ai__cards">
      <view
        v-for="f in cards"
        :key="f.key"
        class="ai__card ym-tap"
        :class="`is-${f.tone || 'brand'}`"
        @click="open(f.key)"
      >
        <view class="ai__card-icon">
          <wd-icon :name="f.icon" size="22px" color="#ffffff" />
        </view>
        <view class="ai__card-body">
          <text class="ai__card-title">{{ f.title }}</text>
          <text class="ai__card-desc">{{ CARD_HINTS[f.key] || f.desc }}</text>
        </view>
        <wd-icon name="chevron-right" size="16px" color="#AEB6B2" />
      </view>

      <ym-empty
        v-if="!cards.length"
        compact
        icon="lock-on"
        text="当前账号没有 AI 工具权限"
        desc="如需使用，请联系店长开通"
      />
    </view>

    <ym-section
      title="最近生成"
      desc="只保留本机最近 3 条"
      :action-text="recent.length ? '清空' : ''"
      @action="onClear"
    >
      <view v-if="recent.length" class="ai__recent">
        <view
          v-for="(item, i) in recent"
          :key="item.id"
          class="ai__recent-row ym-tap"
          :class="{ 'is-divider': i < recent.length - 1 }"
          @click="openRecent"
        >
          <view class="ai__recent-main">
            <view class="ai__recent-head">
              <ym-badge
                :text="item.platform || '文案'"
                :tone="item.platform === '小红书' ? 'gold' : 'brand'"
              />
              <text class="ai__recent-time">{{ item.createdAt }}</text>
            </view>
            <text class="ai__recent-text ym-ellipsis">{{ summaryOf(item) }}</text>
          </view>
          <wd-icon name="chevron-right" size="14px" color="#C0C8C3" />
        </view>
      </view>
      <ym-empty
        v-else
        compact
        text="还没有生成记录"
        desc="进入营销工具生成第一条文案"
        action-text="去生成"
        @action="openRecent"
      />
    </ym-section>

    <view class="ai__note">
      <wd-icon name="info-circle" size="14px" color="#8A948F" />
      <text class="ai__note-text">AI 生成内容需人工审核后发布</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.ai {
  &__cards {
    padding: 4rpx 0 8rpx;
  }

  &__card {
    display: flex;
    align-items: center;
    gap: 24rpx;
    min-height: 132rpx;
    padding: 28rpx;
    margin: 20rpx 24rpx;
    background: $white;
    border-radius: $r-lg;
    box-shadow: $shadow-card;

    &.is-gold .ai__card-icon {
      background: linear-gradient(140deg, $gold-500 0%, $gold-600 100%);
    }
  }

  &__card-icon {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 84rpx;
    height: 84rpx;
    background: linear-gradient(140deg, $brand-400 0%, $brand-700 100%);
    border-radius: 24rpx;
  }

  &__card-body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 6rpx;
    min-width: 0;
  }

  &__card-title {
    font-size: 32rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__card-desc {
    font-size: 24rpx;
    color: $ink-400;
  }

  &__recent-row {
    display: flex;
    align-items: center;
    gap: 16rpx;
    min-height: 96rpx;
    padding: 18rpx 0;

    &.is-divider {
      border-bottom: 2rpx solid $hairline;
    }
  }

  &__recent-main {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 8rpx;
    min-width: 0;
  }

  &__recent-head {
    display: flex;
    align-items: center;
    gap: 12rpx;
  }

  &__recent-time {
    font-size: 22rpx;
    color: $ink-300;
  }

  &__recent-text {
    font-size: 26rpx;
    color: $ink-700;
  }

  &__note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8rpx;
    padding: 24rpx 40rpx 8rpx;
  }

  &__note-text {
    font-size: 22rpx;
    color: $ink-400;
    text-align: center;
  }
}
</style>
