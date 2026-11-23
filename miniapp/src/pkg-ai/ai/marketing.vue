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
  MOMENTS_IMAGE_MAX_BYTES,
  MOMENTS_MAX_IMAGES,
  PERSONA_ROLES,
  PLATFORM_FEATURE,
  TONES,
  aiChat,
  aiReadableError,
  buildSystemPrompt,
  buildUserPrompt,
  extractMomentsImageReadback,
  favoriteWriteError,
  fetchAiModelInfo,
  fetchMarketingFavorites,
  fetchMarketingHistory,
  formatLocalTime,
  isMarketingPlatform,
  pushRecentGeneration,
  removeMarketingFavorite,
  removeMarketingHistory,
  resolveVisionGuard,
  saveMarketingFavorite,
  saveMarketingHistory,
  splitCandidates,
  type LengthOption,
  type MarketingFavoriteItem,
  type MarketingHistoryItem,
  type MarketingPlatform,
  type MomentsImageReadback,
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

/* ==========================================================================
 * 朋友圈配图（读图）
 *
 * 与 web 端「朋友圈工具」同一套行为，只是载体换成小程序：
 *  选图（≤3 张）→ 本机压成 dataURL → 每张一句说明 → 随 /ai/chat 一起下发
 *  → 结果区回显模型自述的「图片理解」。
 *
 * 为什么必须在本机压：后端 `VisionAttachments` 单张上限 1.5MB、多图合计 4MB，
 * 微信相册原图 base64 后动辄 4~10MB，直接发一定 413/422。这里按 1280/1080/860/640
 * 四档降宽 + 降质试压到单张 ≤200KB，留足余量。
 *
 * 小程序没有 DOM Canvas，也不用页面里的旧版 canvas（营销页没有画布节点），
 * 走 `uni.compressImage` + `getFileSystemManager().readFile({encoding:'base64'})`。
 * ========================================================================== */

interface PickedImage {
  id: string
  /** `data:image/(jpeg|png|webp);base64,...` —— 直接给 /ai/chat 的 attachments */
  dataUrl: string
  /** 这张图是什么（可选，随提示词一起下发） */
  note: string
  /** 解码后字节数（用于显示与上限判断） */
  bytes: number
}

const images = ref<PickedImage[]>([])
const compressingCount = ref(0)
/** 本次带图生成、模型真的说出来的「图片理解」（无图或模型没说时为 null，不伪造） */
const imageReadback = ref<MomentsImageReadback | null>(null)
/** 本次带图但模型没先给图片理解：如实提示，不补写内容 */
const readbackMissing = ref(false)
/** 当前配置的模型名（读不到就是空串 → 不做能力判断，也不打扰） */
const aiModelName = ref('')
/** 是否已接入并启用大模型（null = 还没问到） */
const aiModelConfigured = ref<boolean | null>(null)

/** 图片只在「朋友圈」这条链路里出现（小红书是另一种体裁，读图链路未验证过） */
const imageEnabled = computed(() => platform.value === '朋友圈')

const visionGuard = computed(() =>
  resolveVisionGuard({
    hasImages: imageEnabled.value && images.value.length > 0,
    imageCount: images.value.length,
    model: aiModelName.value,
    aiReady: aiModelConfigured.value !== false
  })
)

const imageTotalKb = computed(() => Math.round(images.value.reduce((sum, it) => sum + it.bytes, 0) / 1024))

/** 单张体积展示（模板里不直接调 Math：渲染函数里走本组件 helper 更稳） */
function kbOf(bytes: number): number {
  return Math.round((Number(bytes) || 0) / 1024)
}

/** 压缩档位：先保清晰度，超 200KB 再逐档降宽降质 */
const IMAGE_PLANS: Array<{ width: number; quality: number }> = [
  { width: 1280, quality: 82 },
  { width: 1080, quality: 72 },
  { width: 860, quality: 64 },
  { width: 640, quality: 56 },
  // PNG（截图）不吃 quality，只能靠继续降宽；这两档专给它兜底
  { width: 520, quality: 50 },
  { width: 420, quality: 46 }
]

interface FsManagerLike {
  readFile?: (opts: {
    filePath: string
    encoding: string
    success: (res: { data: string | ArrayBuffer }) => void
    fail: (err: unknown) => void
  }) => void
}

/** getFileSystemManager 是微信专有 API（uni 类型里没有）→ 受控断言 + 存在性检查 */
function fsManager(): FsManagerLike | undefined {
  const holder = uni as unknown as { getFileSystemManager?: () => FsManagerLike }
  return typeof holder.getFileSystemManager === 'function' ? holder.getFileSystemManager() : undefined
}

/** 读临时文件转 base64（异步版：200KB 级别的读盘不阻塞渲染） */
function readFileBase64(filePath: string): Promise<string> {
  return new Promise((resolve, reject) => {
    const mgr = fsManager()
    if (!mgr || typeof mgr.readFile !== 'function') {
      reject(new Error('当前环境不支持读取图片文件'))
      return
    }
    mgr.readFile({
      filePath,
      encoding: 'base64',
      success: (res) => {
        if (typeof res?.data === 'string' && res.data) resolve(res.data)
        else reject(new Error('读取图片失败，请重试'))
      },
      fail: () => reject(new Error('读取图片失败，请重试'))
    })
  })
}

/** 按 base64 头判真实格式（后端只收 jpeg/png/webp，格式与目录名不一致时以内容为准） */
function mimeOfBase64(base64: string): string {
  if (base64.startsWith('/9j/')) return 'image/jpeg'
  if (base64.startsWith('iVBOR')) return 'image/png'
  if (base64.startsWith('UklGR')) return 'image/webp'
  return 'image/jpeg'
}

function base64Bytes(base64: string): number {
  const pad = base64.endsWith('==') ? 2 : base64.endsWith('=') ? 1 : 0
  return Math.max(0, Math.floor((base64.length * 3) / 4) - pad)
}

function compressOnce(src: string, compressedWidth: number, quality: number): Promise<string> {
  return new Promise((resolve, reject) => {
    uni.compressImage({
      src,
      quality,
      compressedWidth,
      success: (res) => {
        if (res?.tempFilePath) resolve(res.tempFilePath)
        else reject(new Error('压缩图片失败'))
      },
      fail: () => reject(new Error('压缩图片失败'))
    })
  })
}

function getImageInfo(src: string): Promise<{ width: number; height: number; path: string }> {
  return new Promise((resolve, reject) => {
    uni.getImageInfo({
      src,
      success: (res) => {
        const width = Number(res?.width) || 0
        const height = Number(res?.height) || 0
        if (!width || !height) reject(new Error('读不出图片尺寸'))
        else resolve({ width, height, path: res?.path || src })
      },
      fail: () => reject(new Error('读不出图片尺寸'))
    })
  })
}

/**
 * 单张图片 → dataURL（解码后 ≤200KB）。
 * 压缩接口不可用（个别机型/基础库）时退回原图直读，原图本身够小也照样能用；
 * 一路都压不下来就把真实体积报出来，让用户换张图，而不是发出去挨 422。
 */
async function imageToDataUrl(src: string): Promise<{ dataUrl: string; bytes: number }> {
  let originWidth = IMAGE_PLANS[0].width
  try {
    const info = await getImageInfo(src)
    if (info.width > 0) originWidth = Math.min(IMAGE_PLANS[0].width, info.width)
  } catch {
    /* 拿不到尺寸就按首档宽度压，不影响主流程 */
  }

  let lastBytes = 0
  let compressible = true
  for (const plan of IMAGE_PLANS) {
    let path = src
    if (compressible) {
      try {
        path = await compressOnce(src, Math.min(plan.width, originWidth), plan.quality)
      } catch {
        // 压缩失败：转成「直读原图」再试一次，仍超限就如实报错
        compressible = false
        path = src
      }
    }
    const base64 = await readFileBase64(path)
    lastBytes = base64Bytes(base64)
    if (lastBytes <= MOMENTS_IMAGE_MAX_BYTES) {
      return { dataUrl: `data:${mimeOfBase64(base64)};base64,${base64}`, bytes: lastBytes }
    }
    if (!compressible) break
  }
  throw new Error(
    `这张图压到 ${Math.ceil(lastBytes / 1024)}KB，仍超过单张 ${Math.round(MOMENTS_IMAGE_MAX_BYTES / 1024)}KB 上限。` +
      '建议换一张相机拍的照片，或先在相册里裁剪小一点再选（截图类 PNG 最难压）。'
  )
}

/** 问一次当前模型（只在需要判断读图能力时问，失败就当未知） */
async function loadAiModel() {
  if (aiModelConfigured.value !== null) return
  try {
    const info = await fetchAiModelInfo()
    aiModelName.value = info.model
    aiModelConfigured.value = info.configured && info.enabled
  } catch {
    /* 读不到模型名就不下判断（宁可不提示，也不误报「你的模型看不了图」） */
  }
}

/** 选图：最多 3 张，逐张本机压缩，单张失败不拖累其余 */
function pickImages() {
  const remain = MOMENTS_MAX_IMAGES - images.value.length
  if (remain <= 0) {
    uni.showToast({ title: `最多 ${MOMENTS_MAX_IMAGES} 张`, icon: 'none' })
    return
  }
  if (compressingCount.value > 0) {
    uni.showToast({ title: '图片还在压缩，请稍等', icon: 'none' })
    return
  }
  uni.chooseImage({
    count: remain,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
    success: async (res) => {
      // uni 的类型把 tempFilePaths 写成 `string | string[]`（H5 单张时是字符串）→ 统一成数组
      const picked = res?.tempFilePaths
      const paths: string[] = (Array.isArray(picked) ? picked : picked ? [picked] : []).filter(Boolean)
      if (!paths.length) return
      if (paths.length > remain) {
        uni.showToast({ title: `最多 ${MOMENTS_MAX_IMAGES} 张，多余的已忽略`, icon: 'none' })
      }
      void loadAiModel()
      const accepted = paths.slice(0, remain)
      const failed: string[] = []
      compressingCount.value += accepted.length
      for (const path of accepted) {
        try {
          const out = await imageToDataUrl(path)
          images.value.push({ id: uid(), dataUrl: out.dataUrl, note: '', bytes: out.bytes })
        } catch (e) {
          failed.push(e instanceof Error ? e.message : '这张图没能读取')
        } finally {
          compressingCount.value = Math.max(0, compressingCount.value - 1)
        }
      }
      if (failed.length) {
        uni.showModal({ title: '有图片没加上', content: failed.join('\n'), showCancel: false })
      }
    },
    fail: (err) => {
      const msg = String((err as { errMsg?: string } | undefined)?.errMsg ?? '')
      if (/cancel/i.test(msg)) return
      uni.showToast({ title: '打不开相册/相机，请检查微信的相册权限', icon: 'none' })
    }
  })
}

/**
 * 逐图说明输入。
 * 不用 v-model：小程序原生 input 的取值在 `event.detail.value`，这里显式读，
 * 免得 H5/小程序两端的 v-model 编译差异把输入吃掉。
 */
function onNoteInput(img: PickedImage, e: unknown) {
  const detail = (e as { detail?: { value?: unknown } } | undefined)?.detail
  img.note = typeof detail?.value === 'string' ? detail.value : ''
}

function removeImage(id: string) {
  images.value = images.value.filter((it) => it.id !== id)
}

function clearImages() {
  images.value = []
  imageReadback.value = null
  readbackMissing.value = false
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

  /**
   * 本次真正随请求下发的图片：只有朋友圈链路带图（`attachments` 契约与 PC 端一致）。
   * 小红书即使表单里留着图也不发，避免把「朋友圈配图」硬塞进笔记链路。
   */
  const useImages = target === '朋友圈' ? images.value.slice(0, MOMENTS_MAX_IMAGES) : []
  if (useImages.length && compressingCount.value > 0) {
    uni.showToast({ title: '图片还在压缩，请稍等再生成', icon: 'none' })
    return
  }

  generating.value = true
  aiError.value = ''
  if (mode === 'one') swappingIndex.value = index

  try {
    const attachments = useImages.map((it) => it.dataUrl)
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
            // 无图时不传这个字段：提示词与加图片功能之前逐字一致
            imageNotes: attachments.length ? useImages.map((it) => it.note) : undefined,
            avoid
          })
        }
      ],
      temperature: 0.8,
      maxTokens: 1200,
      featureType: PLATFORM_FEATURE[target],
      attachments
    })

    /**
     * 「图片理解」：只在本次真的带图时解析（无图正文里出现「图1：」是正常文案，不能吞）。
     * 解析不出就整块不显示，并在结果区如实说一句「模型没说它看到了什么」——
     * 绝不拿主题/图片说明拼一段看起来像理解的内容。
     */
    let body = content
    let readback: MomentsImageReadback | undefined
    if (attachments.length) {
      const parsed = extractMomentsImageReadback(content, attachments.length)
      // 只回了图片理解、没写正文：当成这次没生成出来，别把「图片理解」当文案让人复制发圈
      if (parsed.readback && !parsed.cleaned.trim()) {
        throw new Error('模型这次只描述了图片，没有写出文案，请重试一次')
      }
      body = parsed.cleaned || content
      readback = parsed.readback
    }
    if (mode === 'all') {
      imageReadback.value = readback ?? null
      readbackMissing.value = attachments.length > 0 && !readback
    }

    const texts = splitCandidates(body, platform.value)
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

/** 真正执行删除（详情弹层与列表行共用同一套：成功 toast、失败只留内联错误） */
async function removeHistoryNow(item: MarketingHistoryItem): Promise<boolean> {
  try {
    await removeMarketingHistory(item.id)
    history.value = history.value.filter((h) => h.id !== item.id)
    actionError.value = ''
    uni.showToast({ title: '已删除', icon: 'none' })
    return true
  } catch (e) {
    // http 层已 toast 过失败原因：这里只写内联错误态，不再二次弹窗（F23）
    actionError.value = e instanceof Error ? e.message : '删除失败，请稍后重试'
    return false
  }
}

async function removeFavoriteNow(item: MarketingFavoriteItem): Promise<boolean> {
  try {
    await removeMarketingFavorite(item.id)
    favorites.value = favorites.value.filter((f) => f.id !== item.id)
    actionError.value = ''
    uni.showToast({ title: '已取消收藏', icon: 'none' })
    return true
  } catch (e) {
    // 同上：统一 toast 已由 http 层负责，catch 只留可见的内联错误
    actionError.value = e instanceof Error ? e.message : '取消收藏失败，请稍后重试'
    return false
  }
}

function deleteHistory(item: MarketingHistoryItem) {
  confirmThen('删除这条历史', '删除后不可恢复，收藏不受影响。', async () => {
    const ok = await removeHistoryNow(item)
    if (ok && detail.value?.kind === 'history' && detail.value.id === item.id) detailOpen.value = false
  })
}

function deleteFavorite(item: MarketingFavoriteItem) {
  confirmThen('取消收藏', '将从你的收藏里移除这条文案。', async () => {
    const ok = await removeFavoriteNow(item)
    if (ok && detail.value?.kind === 'favorite' && detail.value.id === item.id) detailOpen.value = false
  })
}

/* ==========================================================================
 * 详情弹层（「历史与收藏」的每一条都能点开看全文）
 *
 * 之前列表项只有复制/收藏/删除三个动作，点不进内容 —— 文案一长，列表里只剩截断的一行，
 * 等于历史记录不可读。这里把同一条数据的完整字段摊开，并把原有动作原样搬进来
 * （复制 / 收藏·取消收藏 / 删除），两个列表都不再是「只能操作、不能看」。
 * ========================================================================== */

interface DetailState {
  kind: 'history' | 'favorite'
  id: number
  platform: string
  title: string
  content: string
  reply: string
  coverText: string
  tags: string[]
  createdAt: string
  source: string
}

const detailOpen = ref(false)
const detail = ref<DetailState | null>(null)

/** 判「这条历史是否已经在收藏里」：按去空白后的正文比对（收藏无外键，只能这么判） */
function sameContent(a: string, b: string): boolean {
  const norm = (s: string): string => (s ?? '').replace(/\s+/g, '')
  const x = norm(a)
  return !!x && x === norm(b)
}

const detailFavorited = computed(() => {
  const d = detail.value
  if (!d) return false
  if (d.kind === 'favorite') return true
  return favorites.value.some((f) => sameContent(f.content, d.content))
})

/** 平台 + 生成时间：一眼交代这条文案的来源与时间 */
const detailMeta = computed(() => {
  const d = detail.value
  return d ? `${d.platform || '文案'} · ${d.createdAt || '时间未知'}` : ''
})

/** 详情正文是否够长到需要固定高度滚动（短文案自然收缩，不留一块空白） */
const detailLong = computed(() => (detail.value?.content.length ?? 0) > 220)

function openHistoryDetail(h: MarketingHistoryItem) {
  detail.value = {
    kind: 'history',
    id: h.id,
    platform: h.platform,
    title: h.title,
    content: h.content,
    reply: h.reply ?? '',
    coverText: '',
    tags: [],
    createdAt: h.createdAt,
    source: h.source ?? ''
  }
  detailOpen.value = true
}

function openFavoriteDetail(f: MarketingFavoriteItem) {
  detail.value = {
    kind: 'favorite',
    id: f.id,
    platform: f.platform,
    title: f.title,
    content: f.content,
    reply: f.reply ?? '',
    coverText: f.coverText ?? '',
    tags: f.tags ?? [],
    createdAt: f.createdAt,
    source: ''
  }
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
}

/** 详情里复制：整段原文（不截断） */
function copyDetail() {
  if (!detail.value) return
  copy(detail.value.content)
}

/** 详情里收藏（历史详情用）：字段口径与该行「收藏」按钮完全一致 */
function favoriteDetail() {
  const d = detail.value
  if (!d || d.kind !== 'history' || busyId.value) return
  // 已在收藏里就不再写一条（收藏是按条存的，重复点会留两份一样的文案）
  if (detailFavorited.value) {
    uni.showToast({ title: '这条已经在收藏里了', icon: 'none' })
    return
  }
  void favoriteHistory({
    id: d.id,
    platform: d.platform,
    title: d.title,
    content: d.content,
    reply: d.reply,
    source: d.source,
    createdAt: d.createdAt
  })
}

/** 详情里取消收藏（收藏详情用） */
function unfavoriteDetail() {
  const d = detail.value
  if (!d || d.kind !== 'favorite') return
  void deleteFavorite({
    id: d.id,
    platform: d.platform,
    title: d.title,
    content: d.content,
    reply: d.reply,
    coverText: d.coverText,
    tags: d.tags,
    createdAt: d.createdAt
  })
}

/** 详情里删除（历史详情用；收藏详情走 unfavoriteDetail，同一个动作不设两个入口） */
function deleteDetail() {
  const d = detail.value
  if (!d || d.kind !== 'history') return
  void deleteHistory({
    id: d.id,
    platform: d.platform,
    title: d.title,
    content: d.content,
    reply: d.reply,
    source: d.source,
    createdAt: d.createdAt
  })
}

/** 「图片理解」整段复制（逐张时按「图N：…」逐行） */
function copyReadback() {
  const rb = imageReadback.value
  if (!rb) return
  copy(rb.text)
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
      <view v-if="!imageEnabled && images.length" class="mk__seg-note">
        <wd-icon name="tips" size="12px" color="#B5761F" />
        <text class="mk__seg-note-text">
          已选 {{ images.length }} 张图，切回「朋友圈」才会读图；小红书这条链路不带图。
        </text>
      </view>
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

      <!-- 配图（读图）：只有朋友圈这条链路带图，与 web 端朋友圈工具同一套行为。
           图片真的会被模型看到，所以隐私那句必须如实写：发模型服务商 / 不落本系统。 -->
      <view v-if="imageEnabled" class="mk__imgs">
        <view class="mk__imgs-head">
          <text class="mk__imgs-title">配图（可选，最多 {{ MOMENTS_MAX_IMAGES }} 张）</text>
          <text class="mk__imgs-count">{{ images.length }}/{{ MOMENTS_MAX_IMAGES }}</text>
        </view>
        <text class="mk__imgs-privacy">
          图片会随本次生成发送给「模型配置」里所选的大模型服务商用于识别，不会保存到本系统；请勿上传含会员隐私（人脸/姓名/手机号/病历）的照片。
        </text>

        <view class="mk__imglist">
          <view v-for="(img, i) in images" :key="img.id" class="mk__imgitem">
            <view class="mk__imgbox">
              <image class="mk__imgthumb" :src="img.dataUrl" mode="aspectFill" />
              <view class="mk__imgdel ym-tap" @tap.stop="removeImage(img.id)">
                <wd-icon name="close" size="12px" color="#FFFFFF" />
              </view>
              <text class="mk__imgtag">图{{ i + 1 }}</text>
            </view>
            <input
              class="mk__imgnote"
              :value="img.note"
              :maxlength="24"
              placeholder="这张图是什么（可选）"
              placeholder-style="color:#AEB6B2;font-size:22rpx"
              @input="onNoteInput(img, $event)"
            />
            <text class="mk__imgsize">{{ kbOf(img.bytes) }}KB</text>
          </view>

          <view v-if="images.length < MOMENTS_MAX_IMAGES" class="mk__imgadd ym-tap" @click="pickImages">
            <wd-icon name="add" size="20px" color="#8A948F" />
            <text class="mk__imgadd-text">加图</text>
          </view>
        </view>

        <view v-if="compressingCount" class="mk__imgs-doing">
          <text>正在压缩 {{ compressingCount }} 张图片…</text>
        </view>
        <view v-if="images.length" class="mk__imgs-sum">
          <text>已选 {{ images.length }} 张，共 {{ imageTotalKb }}KB</text>
          <view class="mk__imgs-clear ym-tap" @tap.stop="clearImages">全部清空</view>
        </view>

        <!-- 读图守卫：选好图就立刻说清「这次图片会不会被识别」，不硬拦生成 -->
        <view v-if="visionGuard.blocked" class="mk__vision">
          <view class="mk__vision-head">
            <wd-icon name="tips" size="14px" color="#B5761F" />
            <text class="mk__vision-title">{{ visionGuard.title }}</text>
          </view>
          <text class="mk__vision-text">{{ visionGuard.detail }}</text>
        </view>
      </view>

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

      <!-- 「图片理解」回显：这是「模型真的看了图」唯一可感知的证据。
           内容全部来自模型原文（api 层解析），视图不拼、不补、不美化；
           模型没说就整块不显示，改由下面那行如实说明，绝不伪造一段像样的描述。 -->
      <view v-if="imageReadback" class="mk__readback">
        <view class="mk__readback-head">
          <wd-icon name="eye" size="14px" color="#26714F" />
          <text class="mk__readback-title">图片理解（模型先看图，再写文案）</text>
          <view class="mk__readback-copy ym-tap" @tap.stop="copyReadback">复制</view>
        </view>
        <view v-for="it in imageReadback.items" :key="it.index" class="mk__readback-item">
          <text class="mk__readback-idx">图{{ it.index }}</text>
          <text class="mk__readback-detail">{{ it.detail }}</text>
        </view>
        <text v-if="!imageReadback.items.length" class="mk__readback-text">{{ imageReadback.text }}</text>
        <text class="mk__readback-tip">请对照图片核对上面描述：说错了就是没看清图，换读图模型或换张更清楚的照片重试。</text>
      </view>
      <view v-else-if="readbackMissing" class="mk__readback-missing">
        <text class="mk__readback-missing-text">
          这次模型没有先说明它在图里看到了什么，无法核对文案是否真的参考了图片。可以重试一次；若反复如此，通常是模型不支持读图，可在电脑端「设置 → 模型配置」换成读图型号。
        </text>
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
            <!-- 正文那片可点 → 打开详情看全文（按钮不在它的子树里，结构上不会互相抢事件）；
                 动作区再用 @tap.stop 兜一层：view 上是原生事件，uni-app 会编译成 catchtap。 -->
            <view class="mk__row-main ym-tap" @click="openHistoryDetail(h)">
              <view class="mk__row-head">
                <ym-badge :text="h.platform || '文案'" :tone="h.platform === '小红书' ? 'gold' : 'brand'" />
                <text class="mk__row-time">{{ h.createdAt }}</text>
                <view class="mk__row-cue">
                  <text class="mk__row-cue-text">查看全文</text>
                  <wd-icon name="chevron-right" size="12px" color="#AEB6B2" />
                </view>
              </view>
              <text class="mk__row-title ym-ellipsis">{{ h.title || snippet(h.content, 24) }}</text>
              <text class="mk__row-snippet">{{ snippet(h.content) }}</text>
            </view>
            <view class="mk__row-actions" @tap.stop>
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
            <view class="mk__row-main ym-tap" @click="openFavoriteDetail(f)">
              <view class="mk__row-head">
                <ym-badge :text="f.platform || '文案'" :tone="f.platform === '小红书' ? 'gold' : 'brand'" />
                <text class="mk__row-time">{{ f.createdAt }}</text>
                <view class="mk__row-cue">
                  <text class="mk__row-cue-text">查看全文</text>
                  <wd-icon name="chevron-right" size="12px" color="#AEB6B2" />
                </view>
              </view>
              <text class="mk__row-title ym-ellipsis">{{ f.title || snippet(f.content, 24) }}</text>
              <text class="mk__row-snippet">{{ snippet(f.content) }}</text>
              <view v-if="f.tags.length" class="mk__tags">
                <text v-for="t in f.tags.slice(0, 5)" :key="t" class="mk__tag">#{{ t }}</text>
              </view>
            </view>
            <view class="mk__row-actions" @tap.stop>
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

    <!-- 详情弹层：历史与收藏共用一套（kind 决定底部给「收藏」还是「取消收藏」）。
         文案可能很长：正文用 scroll-view，短文案自然收缩、长文案才切成固定高度滚动。 -->
    <wd-popup
      v-model="detailOpen"
      position="bottom"
      safe-area-inset-bottom
      custom-style="border-radius: 28rpx 28rpx 0 0;"
    >
      <view v-if="detail" class="dtl">
        <view class="dtl__head">
          <view class="dtl__titles">
            <text class="dtl__title ym-ellipsis">{{ detail.title || '文案详情' }}</text>
            <text class="dtl__meta">{{ detailMeta }}</text>
          </view>
          <view class="dtl__close ym-tap" @tap.stop="closeDetail">
            <wd-icon name="close" size="16px" color="#8A948F" />
          </view>
        </view>

        <view class="dtl__state">
          <ym-badge
            :text="detail.kind === 'favorite' ? '我的收藏' : '生成历史'"
            :tone="detail.kind === 'favorite' ? 'gold' : 'muted'"
          />
          <ym-badge
            :text="detailFavorited ? '已收藏' : '未收藏'"
            :tone="detailFavorited ? 'brand' : 'muted'"
            plain
          />
          <text class="dtl__words">{{ wordCount(detail.content) }} 字</text>
        </view>

        <scroll-view scroll-y class="dtl__body" :class="{ 'is-long': detailLong }">
          <text class="dtl__text">{{ detail.content }}</text>
          <view v-if="detail.reply" class="dtl__reply">
            <text class="dtl__reply-label">首评 / 补充</text>
            <text class="dtl__reply-text">{{ detail.reply }}</text>
          </view>
          <view v-if="detail.tags.length" class="mk__tags dtl__tags">
            <text v-for="t in detail.tags" :key="t" class="mk__tag">#{{ t }}</text>
          </view>
        </scroll-view>

        <view class="dtl__acts">
          <view class="mk__mini ym-tap" @click="copyDetail">复制</view>
          <template v-if="detail.kind === 'history'">
            <view class="mk__mini ym-tap" :class="{ 'is-off': detailFavorited }" @click="favoriteDetail">
              {{ detailFavorited ? '已收藏' : '收藏' }}
            </view>
            <view class="mk__mini is-danger ym-tap" @click="deleteDetail">删除</view>
          </template>
          <view v-else class="mk__mini is-danger ym-tap" @click="unfavoriteDetail">取消收藏</view>
        </view>
      </view>
    </wd-popup>
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

  &__seg-note {
    display: flex;
    align-items: flex-start;
    gap: 8rpx;
    padding: 12rpx 16rpx;
    margin-top: 12rpx;
    background: $warn-100;
    border-radius: $r-sm;
  }

  &__seg-note-text {
    flex: 1;
    font-size: 21rpx;
    line-height: 1.6;
    color: $warn-600;
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

  /* ---------- 配图（读图） ---------- */
  &__imgs {
    padding-top: 24rpx;
    margin-top: 24rpx;
    border-top: 2rpx solid $hairline;
  }

  &__imgs-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  &__imgs-title {
    font-size: 26rpx;
    font-weight: 500;
    color: $ink-700;
  }

  &__imgs-count {
    font-size: 22rpx;
    color: $ink-400;
    font-variant-numeric: tabular-nums;
  }

  &__imgs-privacy {
    display: block;
    margin-top: 10rpx;
    font-size: 21rpx;
    line-height: 1.6;
    color: $ink-400;
  }

  &__imglist {
    display: flex;
    flex-wrap: wrap;
    gap: 16rpx;
    margin-top: 18rpx;
  }

  &__imgitem {
    width: calc((100% - 32rpx) / 3);
  }

  &__imgbox {
    position: relative;
    width: 100%;
    height: 160rpx;
    overflow: hidden;
    background: $ink-50;
    border-radius: $r-md;
  }

  &__imgthumb {
    display: block;
    width: 100%;
    height: 100%;
  }

  /* 删除按视觉小、点的区域大（40rpx 视觉 + 外扩 padding 由父级 overflow 收口） */
  &__imgdel {
    position: absolute;
    top: 4rpx;
    right: 4rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44rpx;
    height: 44rpx;
    background: rgba(16, 24, 21, 0.55);
    border-radius: 50%;
  }

  &__imgtag {
    position: absolute;
    left: 8rpx;
    bottom: 8rpx;
    padding: 2rpx 10rpx;
    font-size: 20rpx;
    color: $white;
    background: rgba(16, 24, 21, 0.5);
    border-radius: 999rpx;
  }

  &__imgnote {
    width: 100%;
    height: 64rpx;
    margin-top: 8rpx;
    padding: 0 14rpx;
    font-size: 22rpx;
    color: $ink-700;
    background: $ink-50;
    border-radius: $r-sm;
  }

  &__imgsize {
    display: block;
    margin-top: 4rpx;
    font-size: 20rpx;
    color: $ink-300;
    font-variant-numeric: tabular-nums;
  }

  &__imgadd {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6rpx;
    width: calc((100% - 32rpx) / 3);
    height: 160rpx;
    background: $ink-50;
    border: 2rpx dashed $ink-200;
    border-radius: $r-md;
  }

  &__imgadd-text {
    font-size: 22rpx;
    color: $ink-400;
  }

  &__imgs-doing {
    margin-top: 12rpx;
    font-size: 22rpx;
    color: $warn-600;
  }

  &__imgs-sum {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 12rpx;
    font-size: 22rpx;
    color: $ink-400;
  }

  &__imgs-clear {
    padding: 8rpx 16rpx;
    font-size: 22rpx;
    color: $brand-600;
  }

  &__vision {
    padding: 18rpx 20rpx;
    margin-top: 16rpx;
    background: $warn-100;
    border-radius: $r-md;
  }

  &__vision-head {
    display: flex;
    align-items: flex-start;
    gap: 8rpx;
  }

  &__vision-title {
    flex: 1;
    font-size: 24rpx;
    font-weight: 600;
    line-height: 1.5;
    color: $warn-600;
  }

  &__vision-text {
    display: block;
    margin-top: 8rpx;
    font-size: 22rpx;
    line-height: 1.65;
    color: $ink-600;
  }

  /* ---------- 「图片理解」回显 ---------- */
  &__readback {
    padding: 20rpx 22rpx;
    margin-bottom: 18rpx;
    background: $white;
    border: 2rpx solid $brand-200;
    border-radius: $r-md;
  }

  &__readback-head {
    display: flex;
    align-items: center;
    gap: 8rpx;
  }

  &__readback-title {
    flex: 1;
    font-size: 24rpx;
    font-weight: 500;
    color: $brand-700;
  }

  &__readback-copy {
    padding: 6rpx 14rpx;
    font-size: 22rpx;
    color: $brand-600;
    background: $brand-50;
    border-radius: 999rpx;
  }

  &__readback-item {
    display: flex;
    gap: 10rpx;
    margin-top: 12rpx;
  }

  &__readback-idx {
    flex-shrink: 0;
    font-size: 22rpx;
    color: $brand-600;
  }

  &__readback-detail {
    flex: 1;
    min-width: 0;
    font-size: 24rpx;
    line-height: 1.65;
    color: $ink-700;
  }

  &__readback-text {
    display: block;
    margin-top: 12rpx;
    font-size: 24rpx;
    line-height: 1.7;
    color: $ink-700;
    white-space: pre-wrap;
  }

  &__readback-tip {
    display: block;
    margin-top: 12rpx;
    font-size: 21rpx;
    line-height: 1.6;
    color: $ink-300;
  }

  &__readback-missing {
    padding: 16rpx 20rpx;
    margin-bottom: 18rpx;
    background: $ink-50;
    border-radius: $r-md;
  }

  &__readback-missing-text {
    font-size: 22rpx;
    line-height: 1.65;
    color: $ink-500;
  }

  /* 行内「查看全文」提示：把可点这件事说在明面上，不再只靠一个箭头 */
  &__row-cue {
    display: flex;
    align-items: center;
    gap: 4rpx;
    margin-left: auto;
  }

  &__row-cue-text {
    font-size: 21rpx;
    color: $ink-300;
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

    /* 动作已经成立但当前不可再做（如这条已在收藏里） */
    &.is-off {
      color: $ink-300;
      opacity: 0.7;
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

/* ==========================================================================
 * 详情弹层（历史 / 收藏共用）
 * 文案可能很长：正文放 scroll-view，短文案 max-height 自然收缩、不铺一块空白；
 * 超过 220 字才切成固定高度真正启用滚动（微信官方要求固定高度才可靠滚动）。
 * ========================================================================== */
.dtl {
  display: flex;
  flex-direction: column;
  padding: 28rpx 28rpx 12rpx;

  &__head {
    display: flex;
    align-items: flex-start;
    gap: 12rpx;
  }

  &__titles {
    flex: 1;
    min-width: 0;
  }

  &__title {
    display: block;
    max-width: 100%;
    font-size: 30rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__meta {
    display: block;
    margin-top: 6rpx;
    font-size: 22rpx;
    color: $ink-400;
  }

  &__close {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 64rpx;
    height: 64rpx;
    margin: -8rpx -8rpx 0 0;
  }

  &__state {
    display: flex;
    align-items: center;
    gap: 10rpx;
    margin-top: 16rpx;
  }

  &__words {
    margin-left: auto;
    font-size: 21rpx;
    color: $ink-300;
    font-variant-numeric: tabular-nums;
  }

  &__body {
    max-height: 52vh;
    margin-top: 16rpx;

    &.is-long {
      height: 52vh;
    }
  }

  &__text {
    font-size: 28rpx;
    line-height: 1.8;
    color: $ink-800;
    white-space: pre-wrap;
    word-break: break-word;
  }

  &__reply {
    padding: 16rpx 18rpx;
    margin-top: 20rpx;
    background: $brand-50;
    border-radius: $r-md;
  }

  &__reply-label {
    display: block;
    font-size: 21rpx;
    color: $brand-600;
  }

  &__reply-text {
    display: block;
    margin-top: 6rpx;
    font-size: 24rpx;
    line-height: 1.7;
    color: $ink-700;
    white-space: pre-wrap;
  }

  &__tags {
    margin-top: 16rpx;
  }

  &__acts {
    display: flex;
    gap: 12rpx;
    padding: 20rpx 0 8rpx;
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
