/**
 * 营销工具视图 ↔ `@/api/ai` 的唯一接缝
 * ====================================
 *
 * 本轮（RV2 修复）已把接缝层从「运行时字符串取值」收窄为 **静态 import + 真类型**：
 *
 * - 所有 api 符号都在这里**静态 re-export**，视图 import 本文件即拿到 api 的真实类型；
 *   `vue-tsc --noEmit` 因此能校验「界面选择 → 提示词入参」的形状契约，
 *   api 侧改字段名/改联合类型会在编译期报错，而不是运行时静默读到 `undefined`。
 * - 不再有 `api[key]` 取值、不再有形状归一化、不再有「能力可能缺失」的分支——
 *   那些都是 t2 并行重写期的临时手段，t2 落盘后即死代码。
 * - 本文件只保留**视图专有的纯格式化**（图片说明拼装、历史摘要、防折叠阈值文案），
 *   这些不属于 api 层语义，但同样不复制 api 的口径数值：
 *   一律由 api 常量插值/取值。
 *
 * 纪律：任何新增的 api 侧字段，都应在此 re-export 后由视图以真类型消费；
 * 视图层不得再出现 `Record<string, unknown>` 形参或手写的口径数字。
 *
 * @module marketing-api-bridge
 */

// ==================== api 层符号（静态 re-export，含真类型） ====================

export {
  // ---- 共用人设 / 素材 ----
  BRAND_NAME,
  BRAND_CITY,
  PERSONA_ROLES,
  SPECIALTY_OPTIONS,
  AUDIENCE_OPTIONS,
  // ---- 朋友圈 ----
  MOMENTS_CATALOG,
  MOMENTS_CATEGORIES,
  MOMENT_GOALS,
  MOMENT_GOALS_V2,
  MOMENT_LENGTHS,
  MOMENT_LENGTH_CUSTOM,
  EMOJI_USAGE_MODES,
  EMOJI_LEVELS,
  DEFAULT_EMOJI_USAGE_ID,
  MOMENT_TONES,
  MOMENT_EXPRESSIONS,
  MOMENTS_STANCE_RULE,
  MOMENTS_PRIORITY_CHAIN,
  MOMENTS_DE_AI_RULE,
  MOMENTS_ANTI_FOLD_RULES,
  MOMENTS_HASHTAG_RULE,
  MOMENTS_IMAGE_RULE,
  MOMENTS_MAX_IMAGES,
  FIRST_REPLY_MARKER,
  findMomentGoal,
  findMomentLength,
  findEmojiUsageMode,
  findMomentsInspiration,
  generateMomentsCopy,
  auditMomentsResult,
  countEmoji,
  expectedEmojiRange,
  // ---- 小红书 ----
  XHS_TOPIC_CATALOG,
  XHS_TOPIC_WRITING_GUIDE,
  XHS_IP_TYPES,
  XHS_IP_WRITING_RULES,
  XHS_LOCAL_TAG_RULE,
  XHS_LENGTHS,
  XHS_TITLE_MAX_CHARS,
  XHS_BODY_MAX_CHARS,
  XHS_TAG_MIN,
  XHS_TAG_MAX,
  XHS_TAG_KEYWORDS,
  XHS_NOTE_COUNT,
  XHS_AUDIENCE_MAX,
  XHS_STRENGTH_MAX,
  XHS_STYLE_MAX,
  XHS_STYLES,
  XHS_CONVERSIONS,
  XHS_COVER_RULE,
  XHS_NO_EMOJI_IN_TITLE,
  XHS_TEXT_ONLY_RULE,
  findXhsIpType,
  findXhsLength,
  findXhsTopic,
  findXhsTopicByLabel,
  resolveXhsIpType,
  isXhsLocalScenario,
  ensureLocalTags,
  generateXhsNote,
  auditXhsResult,
  // ---- 防折叠 ----
  ANTI_FOLD_THRESHOLDS,
  ANTI_FOLD_DEFAULT_VERSIONS,
  ANTI_FOLD_MAX_VERSIONS,
  generateAntiFoldCopy,
  auditAntiFold,
  auditVariantDiversity
} from '@/api/ai'

export type {
  // 朋友圈
  MomentsInput,
  MomentsPersona,
  MomentsDraft,
  MomentsResult,
  MomentsMatchReport,
  MomentsGoalDef,
  MomentLengthDef,
  MomentsCategoryItem,
  MomentsCategoryGroup,
  EmojiUsageMode,
  PromptCheck,
  DraftCheck,
  PromptLayer,
  // 小红书
  XhsInput,
  XhsProfile,
  XhsNote,
  XhsResult,
  XhsMatchReport,
  XhsIpDef,
  XhsIpType,
  XhsLengthDef,
  XhsTopicItem,
  XhsTopicGroup,
  // 防折叠
  AntiFoldInput,
  AntiFoldResult,
  AntiFoldVariant,
  AntiFoldCheck,
  AntiFoldCheckLabel
} from '@/api/ai'

import { ANTI_FOLD_THRESHOLDS, MOMENTS_MAX_IMAGES } from '@/api/ai'
import type { MomentsDraft } from '@/api/ai'

// ==================== 成稿展示行（视图形状，id 由视图强制唯一） ====================

/**
 * 朋友圈成稿的展示行。
 *
 * 为什么不直接用 api 的 `MomentsDraft`：它的 `id`（real/pro/convert）与 `label` 是**语义枚举**，
 * 只对「本次生成的三条差异化成稿」有意义；而卡片还要承载「历史记录」「收藏文案」这类
 * 用户数据 —— 那时并不存在 api 语义，硬套枚举会写出假值。所以视图统一持有本类型，
 * 唯一 key 由视图按顺序生成（d1..dN），api 的语义 label 仅用于展示。
 */
export interface MktDraftRow {
  /** 视图内唯一 key（d1..dN），与 api 语义 id 解耦 */
  key: string
  /** 卡片标题：生成结果用 api 的 label；载入历史/收藏时为来源名 */
  label: string
  content: string
  reply: string
  tags?: string[]
}

/** 生成结果 → 展示行（按顺序重排唯一 key，语义 id 不进入视图状态） */
export function toDraftRows(drafts: MomentsDraft[]): MktDraftRow[] {
  return drafts.map((d, i) => ({
    key: `d${i + 1}`,
    label: d.label,
    content: d.content,
    reply: d.reply,
    tags: d.tags
  }))
}

/**
 * 展示行 → api 质检入参。
 *
 * 依据：`auditMomentsResult` 只读取每条成稿的 `content` / `reply`，检查项名按**序号**生成
 * （`drafts.forEach((d, i) => { const n = \`第\${i + 1}条\` … })`），不使用 `id` / `label`。
 * 因此历史/收藏载入的行同样可被质检，不需要伪造 api 语义 id。
 * 这是本文件**唯一**的类型断言点（集中于一处便于审计；其余地方一律真类型直通）。
 */
export function toAuditDrafts(rows: MktDraftRow[]): MomentsDraft[] {
  return rows as unknown as MomentsDraft[]
}

// ==================== 视图专有的纯格式化 ====================

/**
 * 图片说明：把「每张图的名字 + 备注」拼成 api 层要的 `MomentsInput.imageSummary`。
 * 上限取 api 常量 `MOMENTS_MAX_IMAGES`（不重写数值）；调用方负责不让张数超限。
 */
export function composeImageSummary(images: { name: string; note: string }[]): string {
  const list = images.slice(0, MOMENTS_MAX_IMAGES)
  if (!list.length) return ''
  return list
    .map((img, i) => {
      const parts = [img.note.trim(), img.name.trim()].filter(Boolean)
      return `图${i + 1}：${parts.length ? parts.join('（') + (parts.length > 1 ? '）' : '') : '用户上传图片'}`
    })
    .join('；')
}

/**
 * 历史记录摘要：历史/收藏的 `content` 是整文（小红书侧 api 层把封面文案、标题、正文、标签
 * 依次写进同一字段），直接展示会糊成一坨。这里只取前 N 个非空行做速览，
 * 完整内容仍走「载入编辑」。纯字符串切取，不做语义判断。
 */
export function noteSnippet(content: string, lines = 3): string {
  return content
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .slice(0, lines)
    .join(' / ')
}

// ==================== 朋友圈配图：本地压缩 → dataURL 纯函数（全仓唯一定义处） ====================

/**
 * 为什么压缩要在浏览器做：`MomentsInput.imageUrls` 是随本次生成整体走 JSON 的 dataURL 数组，
 * 直接塞原图（手机拍照常见 3-8MB → base64 再涨 1/3）会把请求体和上游上下文一起打爆。
 * 这里按固定两档重编码，既保证「模型真的能看到图」，也保证请求体尺寸可控。
 *
 * 纪律：本组常量与函数**只在这里定义一处**，视图（moments-tool.vue）只调用，
 * 不复制阈值数字、不另写一份压缩逻辑（否则两处口径漂移时，界面承诺与实际载荷会不一致）。
 */

/** 原图上限：超过直接拒收，不做压缩尝试（手机原图常见 3-8MB，8MB 以上基本是误选） */
export const MOMENT_IMAGE_MAX_FILE_BYTES = 8 * 1024 * 1024

/**
 * 压缩后载荷上限：**base64 字符数** ≤ 1.2MB（1.2 × 1024 × 1024 ≈ 125.8 万字符）。
 * 口径说明：base64 每字符 1 字节，1.2MB 的 base64 解码后约 0.9MB 二进制，
 * 而「原始二进制 1.2MB」编码成 base64 恰好约 1.6MB 字符——两个数字是同一约束的两种写法，
 * 这里以**实际下发的 base64 长度**为准（探针与上游限制看的都是它）。
 */
export const MOMENT_IMAGE_MAX_BASE64_BYTES = Math.round(1.2 * 1024 * 1024)

/** 两档压缩参数：先 1024/0.8，仍超限再降一档 768/0.7（长边像素 / JPEG 质量） */
export const MOMENT_IMAGE_STEPS = [
  { maxEdge: 1024, quality: 0.8 },
  { maxEdge: 768, quality: 0.7 }
] as const

/** 图片过大（原图超 8MB，或两档压缩后 base64 仍超限）的统一文案 */
export const MOMENT_IMAGE_TOO_LARGE = '图片过大，请换一张更小的图'
/** 读图失败（非图片 / 损坏 / 浏览器解不开的格式，如部分 HEIC）的统一文案 */
export const MOMENT_IMAGE_FAILED = '图片处理失败，请换一张'

/** 压缩结果：成功给 dataURL，失败给**可直接 ElMessage 展示**的文案（视图不再判断错误种类） */
export type MomentImageResult = { ok: true; dataUrl: string } | { ok: false; message: string }

/** 取 dataURL 里 base64 载荷的字符数（去掉 `data:image/jpeg;base64,` 前缀；无前缀时按全长算） */
export function momentImagePayloadSize(dataUrl: string): number {
  const comma = dataUrl.indexOf(',')
  return comma < 0 ? dataUrl.length : dataUrl.length - comma - 1
}

/** 载入成 HTMLImageElement；失败（损坏/非图片/浏览器不支持该编码）时 reject */
function loadImageElement(objectUrl: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('IMAGE_DECODE_FAIL'))
    img.src = objectUrl
  })
}

/** 按长边上限等比缩放并重编码为 JPEG dataURL；无法绘制时返回 '' */
function renderToJpegDataUrl(img: HTMLImageElement, maxEdge: number, quality: number): string {
  const w = img.naturalWidth || img.width
  const h = img.naturalHeight || img.height
  if (!w || !h) return ''
  const scale = Math.min(1, maxEdge / Math.max(w, h))
  const cw = Math.max(1, Math.round(w * scale))
  const ch = Math.max(1, Math.round(h * scale))
  const canvas = document.createElement('canvas')
  canvas.width = cw
  canvas.height = ch
  const ctx = canvas.getContext('2d')
  if (!ctx) return ''
  // JPEG 没有透明通道：先铺白底，否则 PNG 的透明区在成图里会变成黑块
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, cw, ch)
  ctx.drawImage(img, 0, 0, cw, ch)
  return canvas.toDataURL('image/jpeg', quality)
}

/**
 * 视图选图后调用：原图 → 压缩 → `data:image/jpeg;base64,…`。
 *
 * 顺序：① 原图 >8MB 直接拒；② 按 `MOMENT_IMAGE_STEPS` 逐档压缩，命中 base64 上限即返回；
 * ③ 两档都超限 → 拒收（不进列表）；④ 读图/绘制任何一步失败 → 拒收。
 * 任何失败路径都返回 `ok:false` 而不是抛异常，视图只负责弹 `message` 与把图移出列表。
 */
export async function compressMomentImage(file: File): Promise<MomentImageResult> {
  if (file.size > MOMENT_IMAGE_MAX_FILE_BYTES) {
    return { ok: false, message: MOMENT_IMAGE_TOO_LARGE }
  }
  let objectUrl = ''
  try {
    objectUrl = URL.createObjectURL(file)
    const img = await loadImageElement(objectUrl)
    for (const step of MOMENT_IMAGE_STEPS) {
      const dataUrl = renderToJpegDataUrl(img, step.maxEdge, step.quality)
      if (!dataUrl) return { ok: false, message: MOMENT_IMAGE_FAILED }
      if (momentImagePayloadSize(dataUrl) <= MOMENT_IMAGE_MAX_BASE64_BYTES) {
        return { ok: true, dataUrl }
      }
    }
    return { ok: false, message: MOMENT_IMAGE_TOO_LARGE }
  } catch {
    return { ok: false, message: MOMENT_IMAGE_FAILED }
  } finally {
    if (objectUrl) URL.revokeObjectURL(objectUrl)
  }
}

/**
 * 防折叠说明文案：数字全部由 `ANTI_FOLD_THRESHOLDS` 插值，
 * 阈值调整时文案自动跟随，不在视图里重写「10 字 / 20% / 40%」这类数值。
 */
export function antiFoldThresholdText(): string {
  const t = ANTI_FOLD_THRESHOLDS
  const ratio = Math.round(t.maxSameSentenceRatio * 100)
  const deviation = Math.round(t.maxLengthDeviation * 100)
  const first = t.firstSentenceMustChange ? '开头必换' : '开头不强制更换'
  return `${first}、相同句占比不超过 ${ratio}%、任何位置不含连续 ${t.maxCommonSubstring} 字相同、篇幅偏差不超过 ±${deviation}%`
}

// ==================== 非超管展示用的「去数字」档位名 ====================
//
// 实现放在 `@/api/ai`（与档位常量同处一地，且引擎门禁可直接断言），
// 这里只做 re-export，视图照本文件的既有纪律从这里取。
// 背景见 ai.ts 中 `momentsLengthLabel` 上方的注释：常量同时喂提示词，故不能改常量，
// 只能在展示层去数字 —— 而 `ElOption :label` 会把数值渲染给非超管。
export { emojiUsageLabel, momentsLengthLabel, xhsLengthLabel } from '@/api/ai'
