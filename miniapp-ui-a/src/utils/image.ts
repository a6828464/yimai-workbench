/**
 * 图片处理工具（「我的 → 个人信息」头像专用，E 任务独占文件）
 *
 * 为什么要压缩：后端 `PUT /my/profile` 的 avatar 字段是**内联 data URI**，
 * 硬上限 300000 字符（`strlen($avatar) > 300000 → 422`），而且**不上传文件**——
 * 手机相册原图动辄 3~8MB，直接 base64 会是 4~11MB 字符串，一定被拒。
 * 所以这里把原图居中裁成正方形、缩到 160px、转 JPEG 再 base64，
 * 实测约 8~20KB，离上限有两个数量级余量。
 *
 * 平台口径（只有 mp-weixin 需要照顾，package.json 里也只有 `-p mp-weixin`）：
 *  - `uni.createCanvasContext` 在小程序端**直接透传** `wx.createCanvasContext`，
 *    返回的是微信原生 CanvasContext，因此 `drawImage` 的参数顺序是
 *    微信口径 `(img, sx, sy, sWidth, sHeight, dx, dy, dWidth, dHeight)`（源在前、目标在后）。
 *    uni 的类型声明写的是「目标在前」，与运行时相反——所以这里不用它的类型，
 *    显式声明一份 WX 口径的接口（CanvasCtxLike），避免照错类型写出镜像图。
 *  - 画布是旧版 canvas（`canvas-id`），必须真的渲染在页面上才能画，
 *    因此页面里放了一个离屏的 `<canvas canvas-id="ym-avatar-canvas">`（fixed 到屏幕外）。
 */

/** 头像输出边长（px；后端注释给的口径是 96~128px，这里取 160 更清晰） */
export const AVATAR_SIZE = 160

/** data URI 字符上限，与后端 ProfileController 的 300000 完全一致 */
export const AVATAR_MAX_BYTES = 300000

/** 默认画布 id（页面里的 `<canvas canvas-id>` 必须同名） */
export const AVATAR_CANVAS_ID = 'ym-avatar-canvas'

export interface AvatarImage {
  /** data:image/jpeg;base64,... —— 可直接预览、直接提交 */
  dataUrl: string
  /** dataUrl 的字符数（后端按字符数卡 300KB） */
  bytes: number
  width: number
  height: number
}

export interface AvatarCompressOptions {
  /** 画布 id，默认 AVATAR_CANVAS_ID */
  canvasId?: string
  /** 画布所在页面的组件实例（Vue3 传 getCurrentInstance()?.proxy），否则找不到自定义组件内的画布 */
  scope?: unknown
  /** 输出边长，默认 160 */
  size?: number
  /** 首次尝试的 JPEG 质量，默认 0.82；超限会自动降档重试 */
  quality?: number
  /** 字符上限，默认 AVATAR_MAX_BYTES */
  maxBytes?: number
}

/** 微信口径的旧版画布上下文（只声明用到的方法，避免引入 uni 里方向写反的类型） */
interface CanvasCtxLike {
  fillStyle: string
  fillRect(x: number, y: number, width: number, height: number): void
  drawImage(
    imageResource: string,
    sx: number,
    sy: number,
    sWidth: number,
    sHeight: number,
    dx: number,
    dy: number,
    dWidth: number,
    dHeight: number
  ): void
  draw(reserve?: boolean, callback?: () => void): void
}

interface FsManagerLike {
  readFileSync?: (filePath: string, encoding: string) => string
}

interface ImageInfoLike {
  width: number
  height: number
  path: string
}

/** 选一张照片：返回临时路径；用户主动取消返回 null（不算错误，不弹提示） */
export function chooseImageSource(): Promise<string | null> {
  return new Promise((resolve, reject) => {
    uni.chooseImage({
      count: 1,
      sizeType: ['compressed'],
      sourceType: ['album', 'camera'],
      success: (res) => {
        const paths = res?.tempFilePaths
        const first = Array.isArray(paths) ? paths[0] : paths
        if (!first) {
          reject(new Error('没有取到图片，请重新选择'))
          return
        }
        resolve(first)
      },
      fail: (err) => {
        // 取消选择在微信里也走 fail，errMsg 形如 "chooseImage:fail cancel"
        const msg = String((err as { errMsg?: string } | undefined)?.errMsg ?? '')
        if (/cancel/i.test(msg)) {
          resolve(null)
          return
        }
        reject(new Error('打不开相册/相机，请检查微信的相册权限后重试'))
      }
    })
  })
}

function getImageInfo(src: string): Promise<ImageInfoLike> {
  return new Promise((resolve, reject) => {
    uni.getImageInfo({
      src,
      success: (res) => {
        const width = Number(res?.width) || 0
        const height = Number(res?.height) || 0
        if (!width || !height) {
          reject(new Error('读不出图片尺寸，请换一张试试'))
          return
        }
        resolve({ width, height, path: res?.path || src })
      },
      fail: () => reject(new Error('读取图片失败，请换一张试试'))
    })
  })
}

function canvasCtx(canvasId: string, scope?: unknown): CanvasCtxLike {
  const ctx = uni.createCanvasContext(canvasId, scope)
  if (!ctx) throw new Error('画布初始化失败，请重试')
  return ctx as unknown as CanvasCtxLike
}

/** 等 `ctx.draw` 真正落盘；回调不来就报可读错误，避免页面一直转圈 */
function flushCanvas(ctx: CanvasCtxLike, timeoutMs = 4000): Promise<void> {
  return new Promise((resolve, reject) => {
    let settled = false
    const timer = setTimeout(() => {
      if (settled) return
      settled = true
      reject(new Error('头像渲染超时，请重试'))
    }, timeoutMs)
    ctx.draw(false, () => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      resolve()
    })
  })
}

/** draw 回调之后立刻导出，个别安卓机会拿到空白图；让出 30ms 再导出，代价可忽略 */
function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function exportJpeg(canvasId: string, scope: unknown, size: number, quality: number): Promise<string> {
  return new Promise((resolve, reject) => {
    uni.canvasToTempFilePath(
      {
        canvasId,
        x: 0,
        y: 0,
        width: size,
        height: size,
        destWidth: size,
        destHeight: size,
        fileType: 'jpg',
        quality,
        success: (res) => {
          const path = res?.tempFilePath
          if (!path) {
            reject(new Error('导出头像失败，请重试'))
            return
          }
          resolve(path)
        },
        fail: () => reject(new Error('导出头像失败，请重试'))
      },
      scope
    )
  })
}

/**
 * 读临时文件并转 base64。
 * `getFileSystemManager` 是微信专有 API，uni 的类型里没有 → 用受控断言 + 存在性检查，
 * 不写 any，也不假设它一定存在（H5/App 端会走不到这里）。
 */
function readBase64(filePath: string): string {
  const holder = uni as unknown as { getFileSystemManager?: () => FsManagerLike }
  const manager = typeof holder.getFileSystemManager === 'function' ? holder.getFileSystemManager() : undefined
  if (!manager || typeof manager.readFileSync !== 'function') {
    throw new Error('当前环境不支持读取图片文件')
  }
  try {
    return manager.readFileSync(filePath, 'base64')
  } catch {
    throw new Error('读取头像文件失败，请重试')
  }
}

/**
 * 把任意图片转成 160px 正方形 JPEG data URI。
 *
 * 流程：取尺寸 → 居中裁最大正方形 → 画到画布 → 导出 jpg → 读 base64。
 * 若一次导出超过 maxBytes（几乎不会发生），按 0.7 / 0.55 / 0.4 降质量重试；
 * 仍超限则抛可读错误，让用户换图，而不是把超限数据发出去挨 422。
 */
export async function imageToAvatarDataUrl(
  src: string,
  options: AvatarCompressOptions = {}
): Promise<AvatarImage> {
  const canvasId = options.canvasId || AVATAR_CANVAS_ID
  const size = Math.max(32, Math.round(options.size ?? AVATAR_SIZE))
  const maxBytes = Math.max(1024, Math.round(options.maxBytes ?? AVATAR_MAX_BYTES))
  const info = await getImageInfo(src)

  const side = Math.min(info.width, info.height)
  const sx = Math.round((info.width - side) / 2)
  const sy = Math.round((info.height - side) / 2)

  const qualities = [options.quality ?? 0.82, 0.7, 0.55, 0.4]
  let lastBytes = 0

  for (const quality of qualities) {
    const ctx = canvasCtx(canvasId, options.scope)
    // 白底：源图带透明通道时，JPEG 的透明区会变黑，先铺白更接近头像正常观感
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, size, size)
    ctx.drawImage(info.path, sx, sy, side, side, 0, 0, size, size)
    await flushCanvas(ctx)
    await sleep(30)

    const tempPath = await exportJpeg(canvasId, options.scope, size, quality)
    const dataUrl = `data:image/jpeg;base64,${readBase64(tempPath)}`
    lastBytes = dataUrl.length
    if (lastBytes <= maxBytes) {
      return { dataUrl, bytes: lastBytes, width: size, height: size }
    }
  }

  throw new Error(`压缩后仍有 ${Math.ceil(lastBytes / 1024)}KB，超过 300KB 上限，请换一张小一些的照片`)
}
