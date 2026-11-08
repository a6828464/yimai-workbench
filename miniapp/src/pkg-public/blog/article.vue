<script setup lang="ts">
/**
 * 博客文章页（pkg-public/blog/article）—— 到店指引博客栏目的落点页。
 *
 * 从 guide 页栏目卡片点进来（`?id=1762`）。
 * 免登录（顾客页面）；数据走后端代理 GET /miniapp/blog/{id}（见 api/blog.ts 头注释）。
 *
 * 三态：
 *  1. 加载中 —— 顶部骨架（标题条 + 两行灰条），不整页转圈；
 *  2. 成功 —— 标题 + 日期 + 封面 + rich-text 全文（WP 渲染好的 HTML）；
 *  3. 失败 —— 一句能操作的话（文章被删 / 网络失败），外加「回指引」。
 *
 * 样式基线：到店指引浅色沉浸（米灰底 #F2F4F2 + 墨绿 + 衬线大标），
 * 与 guide 页同一套视觉语言 —— 顾客从指引点进来不应该觉得换了 app。
 */
import { ref } from 'vue'
import { onLoad, onShareAppMessage } from '@dcloudio/uni-app'
import { fetchBlogArticle, type BlogArticle } from '@/api/blog'

const article = ref<BlogArticle | null>(null)
const loading = ref(true)
const errorText = ref('')

async function load(id: number): Promise<void> {
  loading.value = true
  errorText.value = ''
  try {
    article.value = await fetchBlogArticle(id)
  } catch (e) {
    const msg = e instanceof Error ? e.message : '文章加载失败'
    errorText.value = msg.includes('不存在') ? '这篇文章不存在或已下架' : '文章加载失败，请稍后再试'
  } finally {
    loading.value = false
  }
}

onLoad((q) => {
  const id = Number((q as Record<string, unknown> | undefined)?.id)
  if (Number.isFinite(id) && id > 0) {
    void load(id)
  } else {
    loading.value = false
    errorText.value = '文章链接无效'
  }
})

/** 转发：带文章 id 与标题，朋友点开直达同一篇 */
onShareAppMessage(() => {
  const title = article.value?.title ? `${article.value.title} · 一麦瑜伽` : '一麦瑜伽 · 好文'
  return {
    title,
    path: `/pkg-public/blog/article?id=${article.value?.id ?? ''}`
  }
})

function goBack(): void {
  // 从分享直接进来时没有上级页，navigateBack 会失败 —— 兜底回指引
  uni.navigateBack({
    fail: () => uni.reLaunch({ url: '/pkg-public/guide/guide' })
  })
}
</script>

<template>
  <view class="ym-page art">
    <!-- 加载骨架 -->
    <view v-if="loading" class="art__sk">
      <view class="art__sk-title" />
      <view class="art__sk-line" />
      <view class="art__sk-line is-long" />
      <view class="art__sk-line" />
    </view>

    <!-- 错误态 -->
    <view v-else-if="errorText" class="art__err">
      <text class="art__err-text">{{ errorText }}</text>
      <view class="art__err-btn ym-tap" @click="goBack">
        <text class="art__err-btn-text">回到店指引</text>
      </view>
    </view>

    <!-- 正文 -->
    <template v-else-if="article">
      <view class="art__head">
        <text class="art__date">{{ article.date }}</text>
        <text v-if="article.sticky" class="art__pin">置顶</text>
      </view>
      <text class="art__title">{{ article.title }}</text>
      <image
        v-if="article.cover"
        class="art__cover"
        :src="article.cover"
        mode="widthFix"
      />
      <rich-text class="art__body" :nodes="article.content" />
      <view class="art__foot">
        <view class="art__foot-rule" />
        <text class="art__foot-text">一麦瑜伽 · 普拉提</text>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

$serif: 'Songti SC', 'STSong', 'SimSun', serif;

.art {
  min-height: 100vh;
  background: #f2f4f2;
  padding: 24rpx 40rpx calc(56rpx + env(safe-area-inset-bottom));

  &__sk {
    padding-top: 40rpx;
  }

  &__sk-title {
    height: 56rpx;
    margin-bottom: 36rpx;
    background: rgba(28, 96, 71, 0.12);
    border-radius: 8rpx;
  }

  &__sk-line {
    height: 28rpx;
    margin-bottom: 22rpx;
    background: rgba(28, 96, 71, 0.08);
    border-radius: 6rpx;

    &.is-long {
      width: 88%;
    }
  }

  &__err {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-top: 160rpx;
  }

  &__err-text {
    font-size: 28rpx;
    color: $ink-400;
  }

  &__err-btn {
    margin-top: 48rpx;
    padding: 18rpx 64rpx;
    border: 1.5rpx solid #1c6047;
    border-radius: 4rpx;
  }

  &__err-btn-text {
    font-size: 26rpx;
    letter-spacing: 0.1em;
    color: #1c6047;
  }

  &__head {
    display: flex;
    align-items: center;
    gap: 16rpx;
    margin-top: 16rpx;
  }

  &__date {
    font-size: 22rpx;
    letter-spacing: 0.12em;
    color: #8c9a92;
  }

  &__pin {
    padding: 2rpx 12rpx;
    font-size: 20rpx;
    color: #a08749;
    border: 1rpx solid rgba(160, 135, 73, 0.45);
    border-radius: 6rpx;
  }

  &__title {
    display: block;
    margin-top: 20rpx;
    font-family: $serif;
    font-size: 44rpx;
    font-weight: 600;
    line-height: 1.4;
    color: #22332b;
  }

  &__cover {
    width: 100%;
    margin-top: 36rpx;
    border-radius: 12rpx;
  }

  /* rich-text 全文：WP 的 HTML 自带 <p>/<img>，这里只兜字号与间距，
     让文章在小屏上可读；不深度覆写（WP 排版本身是品牌的一部分） */
  &__body {
    display: block;
    margin-top: 36rpx;
    font-size: 30rpx;
    line-height: 1.9;
    color: #2f3e37;
  }

  &__foot {
    display: flex;
    align-items: center;
    gap: 20rpx;
    margin-top: 64rpx;
  }

  &__foot-rule {
    flex: 1;
    height: 1rpx;
    background: rgba(34, 51, 43, 0.12);
  }

  &__foot-text {
    font-size: 21rpx;
    letter-spacing: 0.3em;
    color: #a6b1aa;
  }
}
</style>
