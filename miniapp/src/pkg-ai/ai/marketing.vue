<script setup lang="ts">
/**
 * 营销工具（朋友圈 / 小红书文案生成）
 *
 * 方案 §5.6。三段结构：
 *  1. 表单：平台（wd-segmented）/ 主题 / 人设 / 语气 / 长度 / 门店卖点
 *  2. 结果：最多 3 条候选，每条可复制 / 收藏 / 换一条（换一条只替换该条，不动另外两条）
 *  3. 历史与收藏：服务端持久化（GET/POST/DELETE /marketing/*）
 *
 * 契约要点（已核对后端源码，不是照方案占位写的）：
 *  - 平台值是中文「朋友圈」/「小红书」；历史查询参数是 `platform` 而不是 `kind`。
 *  - POST /ai/chat 返回 `{content}`（http 层已解包），超时按 120s 给（后端最长等上游 120s）。
 *  - 未配置模型时后端 422 `尚未配置 API Key`：要出可读提示卡，不是红屏/toast 一闪。
 *  - 收藏写失败要把后端中文原文透出（上限 300 条是 422 + 中文说明）。
 *
 * 不做「演示模式本地兜底」：收藏是劳动成果，本机有云端无正是要修的跨设备不一致。
 */

import { computed, ref } from 'vue'
import { onShow, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { copyText, requireFeature } from '@/utils/nav'
import { http } from '@/utils/http'
import { useUserStore, type MyProfile } from '@/store/user'
import {
  DEFAULT_PERSONA_ROLE,
  LENGTHS,
  MARKETING_PLATFORMS,
  PERSONA_ROLES,
  PLATFORM_FEATURE,
  TONES,
  aiChat,
  aiReadableError,
  buildSystemPrompt,
  buildUserPrompt,
  favoriteWriteError,
  fetchMarketingFavorites,
  fetchMarketingHistory,
  formatLocalTime,
  isMarketingPlatform,
  pushRecentGeneration,
  removeMarketingFavorite,
  removeMarketingHistory,
  saveMarketingFavorite,
  saveMarketingHistory,
  splitCandidates,
  type LengthOption,
  type MarketingFavoriteItem,
  type MarketingHistoryItem,
  type MarketingPlatform,
  type Tone
} from '@/api/marketing'

const user = useUserStore()

/* ---------- 表单 ---------- */
const platform = ref<MarketingPlatform>('朋友圈')
const theme = ref('')
const personaRole = ref(DEFAULT_PERSONA_ROLE)
const personaOptions = ref<string[]>([...PERSONA_ROLES])
const tone = ref<Tone>('自然真实')
const lengthLabel = ref('中')
const sellingPoint = ref('')

const lengthOption = computed<LengthOption>(
  () => LENGTHS.find((l) => l.label === lengthLabel.value) ?? LENGTHS[1]
)
const lengthLabels = LENGTHS.map((l) => l.label)
const toneOptions = [...TONES]

const platformTip = computed(() =>
  platform.value === '朋友圈'
    ? '120-200 字口语化文案，生成后直接复制发圈'
    : '标题 + 分段 + 5 个话题标签，生成后直接发笔记'
)
const themePlaceholder = computed(() =>
  platform.value === '朋友圈'
    ? '例如：今天早课后一位会员说肩颈松了'
    : '例如：宁波久坐族肩颈放松的 3 个动作'
)

/* ---------- 生成 ---------- */
interface Candidate {
  id: string
  text: string
  platform: MarketingPlatform
}

const candidates = ref<Candidate[]>([])
const generating = ref(false)
const swappingIndex = ref(-1)
const aiError = ref('')
const lastAt = ref('')

let uidSeed = 0
function uid(): string {
  uidSeed += 1
  return `${Date.now()}-${uidSeed}`
}

/* ---------- 历史 / 收藏 ---------- */
/**
 * 历史 / 收藏切换。
 * 之所以存中文标签而不是 'history'|'favorites'：wd-segmented 的 options 用字符串数组时，
 * value 就是显示文案本身，两者不一致会出现「点了没反应」或选中态错位。
 */
const bucket = ref('生成历史')
const bucketOptions = ['生成历史', '收藏']
const isFavTab = computed(() => bucket.value === '收藏')
const history = ref<MarketingHistoryItem[]>([])
const favorites = ref<MarketingFavoriteItem[]>([])
const historyLoaded = ref(false)
const favoritesLoaded = ref(false)
const historyError = ref('')
const favoritesError = ref('')
/**
 * 单条操作（收藏 / 取消收藏 / 删除）的失败提示。
 * http.ts 已经统一弹过 toast，这里只留一个**内联**错误态：既不重复弹窗，
 * 也不至于「点了没反应又什么都没说」。写死成列表级 error 会把整个列表换成空态，所以单独一个 ref。
 */
const actionError = ref('')
const busyId = ref('')

/**
 * 渲染条数上限（每次 +30）。
 * 后端 history/favorites 一次最多各回 300 条；小程序 WebView 里一次铺 300 行嵌套 view
 * 会明显卡顿，所以拿到全量后按 30 条切片渲染，底部给「显示更多」。
 */
const PAGE_STEP = 30
const historyShown = ref(PAGE_STEP)
const favoritesShown = ref(PAGE_STEP)
const historyVisible = computed(() => history.value.slice(0, historyShown.value))
const favoritesVisible = computed(() => favorites.value.slice(0, favoritesShown.value))

onShow(() => {
  if (!requireFeature('marketing')) return
  loadBuckets()
  // 每次回到本页都刷一次人设：人设页（pages/profile/persona）可能刚被改过，
  // 只做一次会让「改了人设，生成还是老身份」。
  void loadPersona()
})

/* ==========================================================================
 * 数据加载
 * ========================================================================== */

/** 从 /my/profile 的 profile.persona.role 取人设（不存在就用「会籍顾问」） */
function personaFromProfile(profile: unknown): string {
  if (!profile || typeof profile !== 'object' || Array.isArray(profile)) return ''
  const persona = (profile as Record<string, unknown>).persona
  if (!persona || typeof persona !== 'object' || Array.isArray(persona)) return ''
  const role = (persona as Record<string, unknown>).role
  return typeof role === 'string' ? role.trim() : ''
}

function applyPersona(role: string) {
  if (!role) return
  personaRole.value = role
  if (!personaOptions.value.includes(role)) personaOptions.value = [...personaOptions.value, role]
}

async function loadPersona() {
  // 先用 pinia 里已有的 profile（个人信息页/人设页填过就有），再向后端要一次最新值
  applyPersona(personaFromProfile(user.profile?.profile))
  try {
    const raw = await http.get<Record<string, unknown>>('/my/profile', undefined, { silent: true })
    applyPersona(personaFromProfile(raw?.profile))
    // 整包回写 pinia：api/marketing.ts 的 buildUserPrompt 会从 user.profile.profile.persona
    // 读「目标人群」（persona.audiences），不写回来人群那一行就永远是空的
    if (raw && typeof raw === 'object') user.setProfile(raw as unknown as MyProfile)
  } catch {
    /* 取不到就保持兜底人设，不打扰用户 */
  }
}

function goPersona() {
  uni.navigateTo({ url: '/pkg-me/profile/persona' })
}

async function loadHistory() {
  try {
    history.value = await fetchMarketingHistory()
    historyError.value = ''
    historyShown.value = PAGE_STEP
  } catch (e) {
    historyError.value = e instanceof Error ? e.message : '生成历史读取失败'
  } finally {
    historyLoaded.value = true
  }
}

async function loadFavorites() {
  try {
    favorites.value = await fetchMarketingFavorites()
    favoritesError.value = ''
    favoritesShown.value = PAGE_STEP
  } catch (e) {
    favoritesError.value = e instanceof Error ? e.message : '收藏读取失败'
  } finally {
    favoritesLoaded.value = true
  }
}

function loadBuckets() {
  void loadHistory()
  void loadFavorites()
}

/* ==========================================================================
 * 生成
 * ========================================================================== */

/** 候选标题：首行去标记后截断（小红书首行就是标题） */
function titleOf(text: string, fallback = ''): string {
  const first =
    text
      .split('\n')
      .map((s) => s.replace(/^[#\s【】]+|【|】$/g, '').trim())
      .find((s) => s.length > 0) ?? ''
  const title = first || fallback
  return title.slice(0, 40)
}

/** 小红书话题标签：从正文里抽 #xxx（最多 10 个，够覆盖后端 tags 上限） */
function tagsOf(text: string): string[] {
  const matched = text.match(/#[^\s#]+/g) ?? []
  return Array.from(new Set(matched.map((t) => t.replace(/^#/, '')))).slice(0, 10)
}

/**
 * 生成。
 * mode=all 覆盖整批（首次生成 / 重试）；mode=one 只替换 index 那一条（「换一条」）。
 */
async function runGenerate(mode: 'all' | 'one' = 'all', index = -1, avoid = '') {
  const t = theme.value.trim()
  if (!t) {
    uni.showToast({ title: '先写一句主题', icon: 'none' })
    return
  }
  if (generating.value) {
    uni.showToast({ title: '正在生成，请稍等', icon: 'none' })
    return
  }

  /**
   * 目标平台：整批生成用表单当前平台；「换一条」用该条候选自己的平台 ——
   * 用户生成完再切到小红书时，换朋友圈那条不应该突然变成小红书文案。
   */
  const target: MarketingPlatform =
    mode === 'one' && index >= 0 && candidates.value[index]
      ? candidates.value[index].platform
      : platform.value

  generating.value = true
  aiError.value = ''
  if (mode === 'one') swappingIndex.value = index

  try {
    const content = await aiChat({
      messages: [
        { role: 'system', content: buildSystemPrompt(target) },
        {
          role: 'user',
          content: buildUserPrompt({
            platform: target,
            theme: t,
            personaRole: personaRole.value || DEFAULT_PERSONA_ROLE,
            tone: tone.value,
            length: lengthOption.value,
            sellingPoint: sellingPoint.value,
            avoid
          })
        }
      ],
      temperature: 0.8,
      maxTokens: 1200,
      featureType: PLATFORM_FEATURE[target]
    })

    const texts = splitCandidates(content, platform.value)
    if (!texts.length) throw new Error('没有拿到内容，请重试')

    if (mode === 'one' && index >= 0 && candidates.value[index]) {
      candidates.value[index] = { id: uid(), text: texts[0], platform: target }
      uni.showToast({ title: '已换一条', icon: 'none' })
      return
    }

    candidates.value = texts
      .slice(0, 3)
      .map((text) => ({ id: uid(), text, platform: target }))
    lastAt.value = formatLocalTime()
    // 本机最近记录（AI 助手 hub 首屏用）+ 服务端历史（跨设备）；两者都失败不影响已拿到的文案
    pushRecentGeneration({
      platform: target,
      title: titleOf(texts[0], t),
      preview: texts[0].slice(0, 200)
    })
    void persistHistory(texts, target)
  } catch (e) {
    aiError.value = aiReadableError(e)
  } finally {
    generating.value = false
    swappingIndex.value = -1
  }
}

/** 服务端历史：一次生成落一条（3 条候选用 --- 拼回整段，便于日后整段复制） */
async function persistHistory(texts: string[], target: MarketingPlatform) {
  try {
    await saveMarketingHistory({
      platform: target,
      title: theme.value.trim().slice(0, 40),
      content: texts.join('\n\n---\n\n'),
      source: 'llm'
    })
    await loadHistory()
  } catch {
    /* 历史存不上不影响本次结果展示，下次再点生成会重试 */
  }
}

function regenerateOne(index: number) {
  const old = candidates.value[index]?.text ?? ''
  void runGenerate('one', index, old)
}

/* ==========================================================================
 * 复制 / 收藏 / 删除
 * ========================================================================== */

function copy(text: string) {
  copyText(text, '文案已复制')
}

async function favoriteCandidate(c: Candidate) {
  if (busyId.value) return
  busyId.value = c.id
  try {
    await saveMarketingFavorite({
      platform: c.platform,
      title: titleOf(c.text, theme.value.trim()),
      content: c.text,
      // 朋友圈用首评位、小红书用封面文案：都与 web 端收藏字段一一对应
      reply: '',
      coverText: c.platform === '小红书' ? titleOf(c.text, theme.value.trim()) : '',
      tags: c.platform === '小红书' ? tagsOf(c.text) : []
    })
    uni.showToast({ title: '已收藏', icon: 'none' })
    void loadFavorites()
  } catch (e) {
    uni.showModal({ title: '收藏失败', content: favoriteWriteError(e), showCancel: false })
  } finally {
    busyId.value = ''
  }
}

async function favoriteHistory(item: MarketingHistoryItem) {
  const p: MarketingPlatform = isMarketingPlatform(item.platform) ? item.platform : '朋友圈'
  busyId.value = `h-${item.id}`
  try {
    await saveMarketingFavorite({
      platform: p,
      title: item.title || theme.value.trim(),
      content: item.content,
      reply: item.reply ?? '',
      coverText: p === '小红书' ? item.title || titleOf(item.content) : '',
      tags: p === '小红书' ? tagsOf(item.content) : []
    })
    uni.showToast({ title: '已收藏', icon: 'none' })
    void loadFavorites()
  } catch (e) {
    uni.showModal({ title: '收藏失败', content: favoriteWriteError(e), showCancel: false })
  } finally {
    busyId.value = ''
  }
}

function confirmThen(title: string, content: string, run: () => Promise<void>) {
  uni.showModal({
    title,
    content,
    confirmText: '删除',
    success: (res) => {
      if (!res.confirm) return
      void run()
    }
  })
}

async function deleteHistory(item: MarketingHistoryItem) {
  confirmThen('删除这条历史', '删除后不可恢复，收藏不受影响。', async () => {
    try {
      await removeMarketingHistory(item.id)
      history.value = history.value.filter((h) => h.id !== item.id)
      actionError.value = ''
      uni.showToast({ title: '已删除', icon: 'none' })
    } catch (e) {
      // http 层已 toast 过失败原因：这里只写内联错误态，不再二次弹窗（F23）
      actionError.value = e instanceof Error ? e.message : '删除失败，请稍后重试'
    }
  })
}

async function deleteFavorite(item: MarketingFavoriteItem) {
  confirmThen('取消收藏', '将从你的收藏里移除这条文案。', async () => {
    try {
      await removeMarketingFavorite(item.id)
      favorites.value = favorites.value.filter((f) => f.id !== item.id)
      actionError.value = ''
      uni.showToast({ title: '已取消收藏', icon: 'none' })
    } catch (e) {
      // 同上：统一 toast 已由 http 层负责，catch 只留可见的内联错误
      actionError.value = e instanceof Error ? e.message : '取消收藏失败，请稍后重试'
    }
  })
}

/** 列表摘要：正文首行，压缩空白 */
function snippet(text: string, len = 48): string {
  const line = (text ?? '').replace(/\s+/g, ' ').trim()
  return line.length > len ? `${line.slice(0, len)}…` : line
}

function wordCount(text: string): number {
  return (text ?? '').replace(/\s/g, '').length
}

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="ym-page mk">
    <!-- 平台切换 -->
    <view class="mk__segcard">
      <wd-segmented v-model:value="platform" :options="MARKETING_PLATFORMS" />
      <view class="mk__persona ym-tap" @click="goPersona">
        <text class="mk__persona-label">当前人设</text>
        <text class="mk__persona-val ym-ellipsis">{{ personaRole }}</text>
        <text class="mk__persona-entry">人设设置 ›</text>
      </view>
      <text class="mk__seg-tip">{{ platformTip }}</text>
    </view>

    <!-- 表单 -->
    <ym-section title="告诉 AI 写什么" desc="主题写得越具体，文案越像本人">
      <view class="mk__theme">
        <wd-textarea
          v-model="theme"
          :maxlength="200"
          show-word-limit
          label="主题"
          :placeholder="themePlaceholder"
        />
      </view>
      <wd-picker
        v-model="personaRole"
        label="人设"
        :columns="personaOptions"
        :placeholder="DEFAULT_PERSONA_ROLE"
      />
      <wd-picker v-model="tone" label="语气" :columns="toneOptions" placeholder="自然真实" />
      <wd-picker v-model="lengthLabel" label="长度" :columns="lengthLabels" placeholder="中" />
      <wd-textarea
        v-model="sellingPoint"
        :maxlength="120"
        label="门店卖点"
        placeholder="可选，例如：东部店新馆 / 产后修复私教 / 小班 4 人"
      />

      <view class="mk__submit">
        <wd-button type="primary" block :loading="generating" @click="runGenerate('all')">
          {{ candidates.length ? '重新生成 3 条' : '生成文案' }}
        </wd-button>
        <text class="mk__submit-tip">每次出 3 条候选；不满意可逐条换，或补一句卖点再生成</text>
      </view>
    </ym-section>

    <!-- 未配置 / 失败 -->
    <view v-if="aiError" class="mk__error">
      <view class="mk__error-head">
        <wd-icon name="warning" size="16px" color="#B5761F" />
        <text class="mk__error-title">这次没生成出来</text>
      </view>
      <text class="mk__error-text">{{ aiError }}</text>
      <view class="mk__error-btn">
        <wd-button size="small" plain @click="runGenerate('all')">重试</wd-button>
      </view>
    </view>

    <!-- 生成中骨架 -->
    <ym-section v-if="generating && !candidates.length" title="正在写" desc="通常 5-15 秒">
      <view v-for="i in 3" :key="i" class="mk__sk">
        <view class="mk__sk-bar" />
        <view class="mk__sk-bar is-mid" />
        <view class="mk__sk-bar is-short" />
      </view>
    </ym-section>

    <!-- 结果 -->
    <ym-section
      v-if="candidates.length"
      title="生成结果"
      :desc="`${candidates.length} 条候选${lastAt ? ' · ' + lastAt : ''}`"
    >
      <!-- 微信审核要求：AI 生成内容必须带显著标识（深度合成类目整改，2026-11-07） -->
      <view class="mk__ai-mark">
        <text class="mk__ai-mark-text">内容由人工智能（AI）生成</text>
      </view>
      <view v-for="(c, i) in candidates" :key="c.id" class="mk__cand">
        <view class="mk__cand-head">
          <ym-badge :text="`第 ${i + 1} 条`" tone="muted" />
          <text class="mk__cand-count">{{ wordCount(c.text) }} 字</text>
        </view>
        <text class="mk__cand-text">{{ c.text }}</text>
        <view class="mk__cand-actions">
          <view class="mk__act ym-tap" @click="copy(c.text)">
            <wd-icon name="copy" size="14px" color="#2E7D5B" />
            <text>复制</text>
          </view>
          <view class="mk__act ym-tap" @click="favoriteCandidate(c)">
            <wd-icon name="star" size="14px" color="#2E7D5B" />
            <text>{{ busyId === c.id ? '收藏中' : '收藏' }}</text>
          </view>
          <view
            class="mk__act ym-tap"
            :class="{ 'is-busy': swappingIndex === i }"
            @click="regenerateOne(i)"
          >
            <wd-icon name="refresh" size="14px" color="#2E7D5B" />
            <text>{{ swappingIndex === i ? '换稿中' : '换一条' }}</text>
          </view>
        </view>
      </view>
      <text class="mk__review-tip">发布前请人工核对：价格、活动、人名与效果描述</text>
    </ym-section>

    <!-- 单条操作失败：复用生成失败那套内联错误样式，不再额外 toast（http 层已经弹过） -->
    <view v-if="actionError" class="mk__error">
      <view class="mk__error-head">
        <wd-icon name="warning" size="16px" color="#B5761F" />
        <text class="mk__error-title">刚才这步没成功</text>
      </view>
      <text class="mk__error-text">{{ actionError }}</text>
    </view>

    <!-- 历史 / 收藏 -->
    <ym-section title="历史与收藏" desc="随账号同步，换手机也在">
      <view class="mk__bucket">
        <wd-segmented v-model:value="bucket" :options="bucketOptions" size="small" />
      </view>

      <template v-if="!isFavTab">
        <view v-if="history.length" class="mk__list">
          <view v-for="(h, i) in historyVisible" :key="h.id" class="mk__row" :class="{ 'is-line': i < historyVisible.length - 1 }">
            <view class="mk__row-main">
              <view class="mk__row-head">
                <ym-badge :text="h.platform || '文案'" :tone="h.platform === '小红书' ? 'gold' : 'brand'" />
                <text class="mk__row-time">{{ h.createdAt }}</text>
              </view>
              <text class="mk__row-title ym-ellipsis">{{ h.title || snippet(h.content, 24) }}</text>
              <text class="mk__row-snippet">{{ snippet(h.content) }}</text>
            </view>
            <view class="mk__row-actions">
              <view class="mk__mini ym-tap" @click="copy(h.content)">复制</view>
              <view class="mk__mini ym-tap" @click="favoriteHistory(h)">收藏</view>
              <view class="mk__mini is-danger ym-tap" @click="deleteHistory(h)">删除</view>
            </view>
          </view>
          <view
            v-if="history.length > historyVisible.length"
            class="mk__more ym-tap"
            @click="historyShown += PAGE_STEP"
          >
            显示更多（还有 {{ history.length - historyVisible.length }} 条）
          </view>
        </view>
        <ym-empty
          v-else-if="historyLoaded && !historyError"
          compact
          text="还没有生成历史"
          desc="生成成功的文案会自动存到这里"
        />
        <ym-empty
          v-else-if="historyError"
          compact
          text="历史读取失败"
          :desc="historyError"
          action-text="重试"
          @action="loadHistory"
        />
        <view v-else class="mk__loading">加载中…</view>
      </template>

      <template v-else>
        <view v-if="favorites.length" class="mk__list">
          <view
            v-for="(f, i) in favoritesVisible"
            :key="f.id"
            class="mk__row"
            :class="{ 'is-line': i < favoritesVisible.length - 1 }"
          >
            <view class="mk__row-main">
              <view class="mk__row-head">
                <ym-badge :text="f.platform || '文案'" :tone="f.platform === '小红书' ? 'gold' : 'brand'" />
                <text class="mk__row-time">{{ f.createdAt }}</text>
              </view>
              <text class="mk__row-title ym-ellipsis">{{ f.title || snippet(f.content, 24) }}</text>
              <text class="mk__row-snippet">{{ snippet(f.content) }}</text>
              <view v-if="f.tags.length" class="mk__tags">
                <text v-for="t in f.tags.slice(0, 5)" :key="t" class="mk__tag">#{{ t }}</text>
              </view>
            </view>
            <view class="mk__row-actions">
              <view class="mk__mini ym-tap" @click="copy(f.content)">复制</view>
              <view class="mk__mini is-danger ym-tap" @click="deleteFavorite(f)">删除</view>
            </view>
          </view>
          <view
            v-if="favorites.length > favoritesVisible.length"
            class="mk__more ym-tap"
            @click="favoritesShown += PAGE_STEP"
          >
            显示更多（还有 {{ favorites.length - favoritesVisible.length }} 条）
          </view>
        </view>
        <ym-empty
          v-else-if="favoritesLoaded && !favoritesError"
          compact
          icon="star"
          text="还没有收藏"
          desc="生成结果里点「收藏」就会留在这里"
        />
        <ym-empty
          v-else-if="favoritesError"
          compact
          text="收藏读取失败"
          :desc="favoritesError"
          action-text="重试"
          @action="loadFavorites"
        />
        <view v-else class="mk__loading">加载中…</view>
      </template>
    </ym-section>

    <view class="mk__foot">
      <wd-icon name="info-circle" size="14px" color="#8A948F" />
      <text class="mk__foot-text">AI 生成内容需人工审核后发布</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.mk {
  padding-top: 20rpx;

  &__segcard {
    padding: 24rpx;
    margin: 0 24rpx;
    background: $white;
    border-radius: $r-lg;
    box-shadow: $shadow-card;
  }

  &__persona {
    display: flex;
    gap: 12rpx;
    align-items: center;
    min-height: 88rpx;
    margin-top: 16rpx;
    padding: 0 20rpx;
    background: $ink-50;
    border-radius: $r-md;
  }

  &__persona-label {
    flex-shrink: 0;
    font-size: 23rpx;
    color: $ink-400;
  }

  &__persona-val {
    flex: 1;
    min-width: 0;
    font-size: 26rpx;
    color: $ink-800;
  }

  &__persona-entry {
    flex-shrink: 0;
    font-size: 23rpx;
    color: $brand-600;
  }

  &__seg-tip {
    display: block;
    margin-top: 16rpx;
    font-size: 22rpx;
    color: $ink-400;
  }

  /* 主题是主输入：给足 3 行高度，避免小程序原生 textarea 默认高度只有一两行 */
  &__theme :deep(.wd-textarea__inner) {
    min-height: 150rpx;
  }

  &__submit {
    margin-top: 24rpx;
  }

  &__submit-tip {
    display: block;
    margin-top: 12rpx;
    font-size: 22rpx;
    color: $ink-400;
    text-align: center;
  }

  &__error {
    padding: 24rpx 28rpx;
    margin: 20rpx 24rpx;
    background: $warn-100;
    border-radius: $r-lg;
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
    margin-top: 10rpx;
    font-size: 24rpx;
    line-height: 1.6;
    color: $ink-600;
  }

  &__error-btn {
    display: flex;
    justify-content: flex-end;
    margin-top: 12rpx;
  }

  &__sk {
    padding: 18rpx 0;
  }

  &__sk-bar {
    height: 22rpx;
    margin-bottom: 14rpx;
    background: $ink-50;
    border-radius: 999rpx;
    animation: mk-shimmer 1.2s ease-in-out infinite;

    &.is-mid {
      width: 82%;
    }

    &.is-short {
      width: 46%;
      margin-bottom: 0;
    }
  }

  &__cand {
    padding: 22rpx;
    margin-bottom: 18rpx;
    background: $brand-50;
    border-radius: $r-md;

    &:last-child {
      margin-bottom: 0;
    }
  }

  &__cand-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  &__cand-count {
    font-size: 22rpx;
    color: $ink-400;
    font-variant-numeric: tabular-nums;
  }

  &__cand-text {
    display: block;
    margin-top: 14rpx;
    font-size: 28rpx;
    line-height: 1.75;
    color: $ink-800;
    white-space: pre-wrap;
    word-break: break-word;
  }

  &__cand-actions {
    display: flex;
    gap: 12rpx;
    margin-top: 18rpx;
  }

  &__act {
    display: flex;
    align-items: center;
    gap: 6rpx;
    /* 方案 §4.4：触控目标 ≥ 88rpx 高 */
    min-height: 88rpx;
    padding: 0 26rpx;
    font-size: 24rpx;
    color: $brand-600;
    background: $white;
    border: 2rpx solid $brand-200;
    border-radius: 999rpx;

    &.is-busy {
      opacity: 0.5;
    }
  }

  &__review-tip {
    display: block;
    margin-top: 16rpx;
    font-size: 22rpx;
    color: $ink-400;
  }

  /* AI 生成标识（微信深度合成整改要求：显著、可识别） */
  &__ai-mark {
    display: flex;
    justify-content: center;
    padding: 10rpx 0 4rpx;
    margin-bottom: 16rpx;
    border: 1rpx solid rgba(46, 125, 91, 0.35);
    border-radius: 8rpx;
    background: rgba(46, 125, 91, 0.06);
  }

  &__ai-mark-text {
    font-size: 22rpx;
    letter-spacing: 0.06em;
    color: #2e7d5b;
    font-weight: 500;
  }

  &__bucket {
    margin-bottom: 8rpx;
  }

  &__list {
    margin-top: 6rpx;
  }

  &__row {
    display: flex;
    flex-direction: column;
    gap: 4rpx;
    padding: 20rpx 0;

    &.is-line {
      border-bottom: 2rpx solid $hairline;
    }
  }

  &__row-main {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 6rpx;
    min-width: 0;
  }

  &__row-head {
    display: flex;
    align-items: center;
    gap: 12rpx;
  }

  &__row-time {
    font-size: 22rpx;
    color: $ink-300;
  }

  &__row-title {
    max-width: 100%;
    font-size: 28rpx;
    color: $ink-800;
  }

  &__row-snippet {
    font-size: 22rpx;
    color: $ink-400;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }

  &__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8rpx;
    margin-top: 4rpx;
  }

  &__tag {
    font-size: 20rpx;
    color: $brand-600;
  }

  &__row-actions {
    display: flex;
    gap: 12rpx;
    margin-top: 6rpx;
  }

  &__mini {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 128rpx;
    /* 方案 §4.4：触控目标 ≥ 88rpx 高 */
    min-height: 88rpx;
    font-size: 24rpx;
    color: $ink-600;
    background: $ink-50;
    border-radius: 999rpx;

    &.is-danger {
      color: $danger-600;
      background: $danger-100;
    }
  }

  &__more {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 88rpx;
    margin-top: 8rpx;
    font-size: 24rpx;
    color: $brand-600;
    background: $brand-50;
    border-radius: 999rpx;
  }

  &__loading {
    padding: 40rpx 0;
    font-size: 24rpx;
    color: $ink-400;
    text-align: center;
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

@keyframes mk-shimmer {
  0%,
  100% {
    opacity: 0.55;
  }

  50% {
    opacity: 1;
  }
}
</style>
