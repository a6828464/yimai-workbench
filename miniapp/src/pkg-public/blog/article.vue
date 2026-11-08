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

/** 到店引导位：进站内到店指引（选门店 → 导航/电话/路线全在站内） */
function goGuide(): void {
  uni.navigateTo({ url: '/pkg-public/guide/guide' })
}

/** 到店引导位：电话咨询（绿地店总机；两家店号码都在指引页里，这里给一个最快的通道） */
function callUs(): void {
  uni.makePhoneCall({
    phoneNumber: '0574-87214863',
    fail: () => uni.showToast({ title: '拨号取消或失败', icon: 'none' })
  })
}

/** 到官网（web-view）：有原文链接就直去该篇，没有就去官网首页 */
function openOfficial(): void {
  const target = article.value?.link || 'https://www.nbyimai.com/'
  uni.navigateTo({ url: `/pkg-public/blog/web?url=${encodeURIComponent(target)}` })
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

      <!-- 到店引导位：读完整篇 = 高意向顾客，这里放转化入口（选店指引 + 电话咨询） -->
      <view class="art__cta">
        <text class="art__cta-title">想来体验？</text>
        <text class="art__cta-desc">两家门店都有专业老师带你入门，第一次来可以先看指引</text>
        <view class="art__cta-row">
          <view class="art__cta-btn ym-tap" @click="goGuide">
            <text class="art__cta-btn-text">查看门店指引</text>
          </view>
          <view class="art__cta-btn art__cta-btn--solid ym-tap" @click="callUs">
            <text class="art__cta-btn-text is-solid">电话咨询</text>
          </view>
        </view>
      </view>

      <!-- 到官网：站内看全文已闭环，这里给「官网逛逛」的补充入口（web-view） -->
      <view class="art__web ym-tap" @click="openOfficial">
        <text class="art__web-text">到官网看更多 · www.nbyimai.com</text>
      </view>

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

  /* rich-text 全文：后端 normalizeContent 已给标题/列表/引用/图打上 wp-* 语义 class，
     这里按 class 配层级样式（小程序 rich-text 吃不到 scoped 后代选择器，类名直连是唯一通道）。
     视觉基线 = 官网文章页：衬线大标题、品牌绿小标题、发丝线引用块。 */
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

  /* 到店引导位：文章读到底 = 高意向，给一条「内容 → 到店」的转化路径 */
  &__cta {
    margin-top: 64rpx;
    padding: 36rpx 32rpx;
    background: $white;
    border: 1rpx solid rgba(34, 51, 43, 0.1);
    border-radius: 8rpx;
    text-align: center;
  }

  &__cta-title {
    display: block;
    font-family: $serif;
    font-size: 34rpx;
    font-weight: 600;
    letter-spacing: 0.14em;
    color: #22332b;
  }

  &__cta-desc {
    display: block;
    margin-top: 14rpx;
    font-size: 23rpx;
    line-height: 1.6;
    color: #7c8a82;
  }

  &__cta-row {
    display: flex;
    gap: 20rpx;
    margin-top: 28rpx;
  }

  &__cta-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 80rpx;
    border: 1.5rpx solid #1c6047;
    border-radius: 4rpx;

    &--solid {
      background: #1c6047;
    }
  }

  &__cta-btn-text {
    font-size: 26rpx;
    letter-spacing: 0.1em;
    color: #1c6047;

    &.is-solid {
      color: #fcfbf8;
    }
  }

  /* 「到官网看更多」：细字链（转化位之下，弱一级） */
  &__web {
    display: flex;
    justify-content: center;
    margin-top: 24rpx;
    padding: 12rpx 0;
  }

  &__web-text {
    font-size: 22rpx;
    letter-spacing: 0.1em;
    color: #a6b1aa;
    text-decoration: underline;
  }
}

/* ---------- rich-text 内部节点（wp-* 语义 class，后端 normalizeContent 打的） ---------- */
.wp-h2 {
  display: block;
  margin: 56rpx 0 20rpx;
  font-family: 'Songti SC', 'STSong', 'SimSun', serif;
  font-size: 38rpx;
  font-weight: 600;
  line-height: 1.45;
  color: #22332b;
}

.wp-h3 {
  display: block;
  margin: 44rpx 0 16rpx;
  font-family: 'Songti SC', 'STSong', 'SimSun', serif;
  font-size: 32rpx;
  font-weight: 600;
  line-height: 1.5;
  color: #1c6047;
}

.wp-h4 {
  display: block;
  margin: 36rpx 0 14rpx;
  font-size: 30rpx;
  font-weight: 600;
  line-height: 1.5;
  color: #1c6047;
}

.wp-list {
  display: block;
  margin: 24rpx 0;
  padding-left: 40rpx;
}

.wp-li {
  display: list-item;
  margin-bottom: 12rpx;
}

.wp-quote {
  display: block;
  margin: 32rpx 0;
  padding: 20rpx 28rpx;
  border-left: 3rpx solid #c19a4b;
  background: rgba(193, 154, 75, 0.07);
  font-size: 28rpx;
  color: #5b6b62;
}

.wp-img {
  max-width: 100% !important;
  width: 100%;
  height: auto;
  display: block;
  margin: 28rpx auto;
  border-radius: 10rpx;
}
</style>
