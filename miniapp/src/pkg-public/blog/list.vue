<script setup lang="ts">
/**
 * 全部文章列表页（pkg-public/blog/list）—— 到店指引博客栏目「查看全部 ›」的落点。
 *
 * 免登录（顾客页面）；数据走 GET /miniapp/blog?page=N（纯最新序分页，后端代理 WP）。
 * 交互：滚到底自动翻页（onReachBottom），hasMore=false 停；点卡进文章页。
 * 空态：一篇都没有（WP 挂/无文章）→ 一句话 + 回指引入口，不白屏。
 * 样式基线：浅色沉浸（米灰底 + 白卡 + 衬线标题），与指引/文章页同一套视觉语言。
 */
import { ref } from 'vue'
import { onLoad, onReachBottom, onShareAppMessage } from '@dcloudio/uni-app'
import { fetchBlogPage, type BlogCard } from '@/api/blog'

const items = ref<BlogCard[]>([])
const page = ref(1)
const hasMore = ref(true)
const loading = ref(false)
const loaded = ref(false)

async function loadPage(p: number): Promise<void> {
  if (loading.value) return
  loading.value = true
  try {
    const r = await fetchBlogPage(p)
    if (p === 1) {
      items.value = r.items
    } else {
      // 翻页去重：WP 缓存窗口内新发文可能让相邻两页出现同一篇
      const seen = new Set(items.value.map((c) => c.id))
      items.value.push(...r.items.filter((c) => !seen.has(c.id)))
    }
    page.value = r.page
    hasMore.value = r.hasMore
  } finally {
    loading.value = false
    loaded.value = true
  }
}

onLoad(() => {
  void loadPage(1)
})

onReachBottom(() => {
  if (hasMore.value && !loading.value) {
    void loadPage(page.value + 1)
  }
})

function openArticle(c: BlogCard): void {
  uni.navigateTo({ url: `/pkg-public/blog/article?id=${c.id}` })
}

function goBack(): void {
  uni.navigateBack({
    fail: () => uni.reLaunch({ url: '/pkg-public/guide/guide' })
  })
}

/** 到官网（web-view） */
function openOfficial(): void {
  uni.navigateTo({ url: `/pkg-public/blog/web?url=${encodeURIComponent('https://www.nbyimai.com/')}` })
}

onShareAppMessage(() => ({
  title: '一麦瑜伽 · 博客',
  path: '/pkg-public/blog/list'
}))
</script>

<template>
  <view class="ym-page blist">
    <!-- 头部：衬线标题 + 发丝线（与指引页栏目头同语言） -->
    <view class="blist__head">
      <text class="blist__title">一麦 · 博客</text>
      <view class="blist__rule" />
    </view>

    <!-- 列表 -->
    <view
      v-for="c in items"
      :key="c.id"
      class="blist__card ym-tap"
      @click="openArticle(c)"
    >
      <image v-if="c.cover" class="blist__cover" :src="c.cover" mode="aspectFill" />
      <view class="blist__main">
        <text class="blist__name">{{ c.title }}</text>
        <text class="blist__excerpt">{{ c.excerpt }}</text>
        <text class="blist__date">{{ c.sticky ? '置顶 · ' : '' }}{{ c.date }}</text>
      </view>
    </view>

    <!-- 首屏骨架 -->
    <view v-if="!loaded" class="blist__sk">
      <view v-for="i in 4" :key="i" class="blist__sk-card" />
    </view>

    <!-- 空态：WP 挂或没有文章 -->
    <view v-else-if="!items.length" class="blist__empty">
      <text class="blist__empty-text">暂时没有文章，稍后再来看看</text>
      <view class="blist__empty-btn ym-tap" @click="goBack">
        <text class="blist__empty-btn-text">回到店指引</text>
      </view>
    </view>

    <!-- 底部状态 -->
    <text v-if="loaded && items.length" class="blist__foot">
      {{ loading ? '正在加载…' : hasMore ? '上拉加载更多' : '已经到底啦' }}
    </text>

    <!-- 到官网逛逛（web-view；站内列表已全量，这是补充入口） -->
    <view v-if="loaded" class="blist__web ym-tap" @click="openOfficial">
      <text class="blist__web-text">到官网逛逛 · www.nbyimai.com</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

$serif: 'Songti SC', 'STSong', 'SimSun', serif;

.blist {
  min-height: 100vh;
  background: #f2f4f2;
  padding: 32rpx 32rpx calc(48rpx + env(safe-area-inset-bottom));

  &__head {
    display: flex;
    align-items: center;
    gap: 24rpx;
    margin: 8rpx 4rpx 28rpx;
  }

  &__title {
    font-family: $serif;
    font-size: 40rpx;
    font-weight: 600;
    letter-spacing: 0.1em;
    color: #22332b;
    white-space: nowrap;
  }

  &__rule {
    flex: 1;
    height: 1rpx;
    background: rgba(34, 51, 43, 0.12);
  }

  &__card {
    display: flex;
    gap: 24rpx;
    padding: 22rpx 24rpx;
    background: $white;
    border: 1rpx solid rgba(34, 51, 43, 0.08);
    border-radius: 6rpx;

    & + & {
      margin-top: 18rpx;
    }
  }

  &__cover {
    width: 200rpx;
    height: 148rpx;
    flex: none;
    border-radius: 8rpx;
    background: rgba(34, 51, 43, 0.06);
  }

  &__main {
    display: flex;
    flex-direction: column;
    gap: 12rpx;
    min-width: 0;
  }

  &__name {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    font-size: 28rpx;
    font-weight: 500;
    line-height: 1.45;
    color: $ink-800;
  }

  &__excerpt {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    font-size: 23rpx;
    line-height: 1.5;
    color: $ink-400;
  }

  &__date {
    font-size: 21rpx;
    letter-spacing: 0.08em;
    color: #8c9a92;
  }

  &__sk {
    display: flex;
    flex-direction: column;
    gap: 18rpx;
  }

  &__sk-card {
    height: 190rpx;
    border-radius: 6rpx;
    background: rgba(28, 96, 71, 0.06);
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-top: 140rpx;
  }

  &__empty-text {
    font-size: 27rpx;
    color: $ink-400;
  }

  &__empty-btn {
    margin-top: 44rpx;
    padding: 18rpx 64rpx;
    border: 1.5rpx solid #1c6047;
    border-radius: 4rpx;
  }

  &__empty-btn-text {
    font-size: 26rpx;
    letter-spacing: 0.1em;
    color: #1c6047;
  }

  &__foot {
    display: block;
    margin-top: 32rpx;
    text-align: center;
    font-size: 22rpx;
    letter-spacing: 0.1em;
    color: #a6b1aa;
  }

  /* 「到官网逛逛」：细字链 */
  &__web {
    display: flex;
    justify-content: center;
    margin-top: 16rpx;
    padding: 12rpx 0;
  }

  &__web-text {
    font-size: 22rpx;
    letter-spacing: 0.1em;
    color: #a6b1aa;
    text-decoration: underline;
  }
}
</style>
