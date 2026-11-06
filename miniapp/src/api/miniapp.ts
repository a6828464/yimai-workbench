/**
 * 小程序公共配置（到店指引等内容由后台维护）
 *
 * 后端：`GET /miniapp/config` —— **免登录**，返回门店清单（地址 / 电话 / 坐标 / 视频号 / 引导图）。
 *
 * ## 为什么不走 utils/http.ts
 * `http.ts` 的 401 分支是「清 token + reLaunch 登录页」——那是员工端的语义，本身没错。
 * 但到店指引是**顾客免登录页**：扫码进来的顾客根本不会有 token，只要这个接口因为任何
 * 原因回了 401（网关配置漏了白名单、后端误加中间件、token 过期残留在本地……），
 * 顾客会被当场踢到登录页，整页内容消失，连本地兜底都没机会显示。
 *
 * 所以这里自己发这次请求，只保留两件必须做的事：
 *  1. statusCode 闸门（非 2xx 一律 reject，不把错误页当数据）；
 *  2. 三形态拆包（`{code,data}` / `{ok}` / `{errno}`，与后端历史形态兼容）。
 * **401 不跳登录、不弹 toast** —— 失败由调用方（到店指引页）回退到本地默认值。
 * 基础地址与错误类型仍从 http.ts 复用，不产生第二份常量。
 *
 * ## 与发版不同步的容错
 * 后端字段是渐进加的（`channelsUser`、`images` 都可能缺）。所以：
 *  - `venues` 不是数组 → 当空处理（调用方回退默认值）；
 *  - 单条缺 `key` → 丢弃该条，不影响其它门店；
 *  - 坐标可能是 null / '' / 字符串数字 → 由页面归一成 number，拿不到就当 0（=「没有坐标」）。
 * 这个文件只负责「拿到什么就原样交出去」，不做业务降级判断（那属于页面）。
 */
import { API_BASE, HttpError, getToken } from '@/utils/http'

/** 单张引导图 */
export interface MiniappVenueImage {
  /** 包内静态图（/static/…）或 https 外链 */
  src?: string
  caption?: string
}

/** 单个门店的配置（全部字段都可能缺，前端按缺失降级） */
export interface MiniappVenueConfig {
  /** 门店键，与后端 venue 列取值一致（`绿地店` / `东部店`） */
  key?: string
  sub?: string
  address?: string
  /** 展示用号码，可带分隔符 */
  phone?: string
  /** 真实坐标；空 / null / 占位值都由页面判为「没有坐标」 */
  latitude?: number | string | null
  longitude?: number | string | null
  channelsUser?: string
  channelsLive?: string
  images?: MiniappVenueImage[]
}

export interface MiniappConfig {
  venues: MiniappVenueConfig[]
  /** 后台最后更新时间（展示用，可缺） */
  updatedAt?: string
  /**
   * 首页弹窗配置（v3.14 加）。
   *
   * 类型用 unknown 而不是精细结构：这份配置是**渐进增强**的——老后端根本没有这个键，
   * 新后端也可能继续加字段。调用方（home.vue）自己 `normalizePopup()` 归一，
   * 这里只负责「原样交出去」，不在这里做业务校验。
   *
   * 历史教训：这个键一度在 unwrapConfig 里被过滤掉，导致调用方拿不到，
   * 只能在页面里再写一份相同口径的请求——两处维护请求逻辑，早晚会漂。
   */
  popup?: unknown
}

/** 默认 5s：这一页是顾客首屏，宁可早点回退到本地默认值，也不要让顾客盯着空白等 20s */
const DEFAULT_TIMEOUT = 5000

function unwrapConfig(body: unknown): MiniappConfig {
  const b = (body ?? {}) as Record<string, unknown>
  // 形态 1：{code:0,data}（主契约）
  if (typeof b.code === 'number' && b.code !== 0) {
    throw new HttpError(String(b.message ?? b.msg ?? '配置接口返回失败'), b.code)
  }
  // 形态 2：{ok:false}
  if (b.ok === false) {
    throw new HttpError(String(b.message ?? b.emsg ?? '配置接口返回失败'))
  }
  // 形态 3：{errno,emsg}
  if (b.errno !== undefined && String(b.errno) !== '0') {
    throw new HttpError(String(b.emsg ?? b.message ?? '配置接口返回失败'))
  }
  // data 缺席时容忍「直接返回对象」的老写法
  const data = (b.data ?? b) as Record<string, unknown>
  const venues = Array.isArray(data.venues) ? (data.venues as MiniappVenueConfig[]) : []
  return {
    venues,
    updatedAt: typeof data.updatedAt === 'string' ? data.updatedAt : undefined,
    // 不要在这里过滤字段：调用方需要什么就交什么，过滤会把「以后新增的配置项」挡在门外
    popup: data.popup
  }
}

/**
 * 拉取小程序公共配置。
 * 失败（网络 / 非 2xx / 业务码非 0）一律 reject，由调用方回退默认值；
 * 这里**不**做任何 UI 副作用（不 toast、不清 token、不跳登录）。
 */
export function fetchMiniappConfig(timeout = DEFAULT_TIMEOUT): Promise<MiniappConfig> {
  const token = getToken()
  return new Promise<MiniappConfig>((resolve, reject) => {
    uni.request({
      url: API_BASE + '/miniapp/config',
      method: 'GET',
      timeout,
      header: {
        'Content-Type': 'application/json',
        // 后端要 JSON 才会返回 JSON 错误体（否则会 302 到 HTML，见 http.ts 的同款注释）
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        // 已登录就带着（后端在免登录接口上忽略它）；未登录时不发 Authorization
        ...(token ? { Authorization: `Bearer ${token}` } : {})
      },
      success: (res) => {
        const status = res.statusCode
        if (status < 200 || status >= 300) {
          // 关键差异：401/403 在这里只是「这次没拿到」，不触发任何登录跳转
          reject(new HttpError(`配置接口异常（${status}）`, status, status))
          return
        }
        try {
          resolve(unwrapConfig(res.data))
        } catch (e) {
          reject(e)
        }
      },
      fail: () => reject(new HttpError('配置接口请求失败', -1))
    })
  })
}
