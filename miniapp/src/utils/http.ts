/**
 * HTTP core（wx.request 封装）
 *
 * 复刻 admin-web/src/api/backend.ts 的契约（docs/miniapp-migration/04 的 R4 闸门）：
 * 1. statusCode 闸门：wx.request「只要收到返回，无论 statusCode 多少都进 success」，
 *    必须自己判 2xx，否则「密码错也显示登录成功但白屏」。
 * 2. 三形态 unwrap：
 *    - {code:0, data}  主契约（code!==0 → throw message）
 *    - {ok:false}      KeepYoga 风格失败
 *    - {errno,emsg}    KeepYoga 风格失败
 * 3. 401 会话过期：清 token → reLaunch 登录页（带防抖，与 web 端同策略）。
 *
 * ── W7-1 请求取消（2026-11-06）────────────────────────────────────────────
 * 背景：26 个页面没有任何 onUnload/onHide，timeout 20s，页面退出后回调仍在跑；
 *       用户「点进→立刻返回→再点进」时，旧回包会把新数据盖掉（尤其列表页）。
 *
 * 三层能力，全部**向后兼容**（16 个 api 模块 + 26 个页面一行不改也能编译通过）：
 *  ① `signal` 选项：`http.get(path, data, { signal })`，signal 一旦 abort，
 *     立刻 task.abort() 且 reject `CanceledError`（不 toast、不落错误态）。
 *  ② `createRequestScope()/usePageScope()`：页面级取消域。`usePageScope()` 把
 *     scope 挂到「当前页面实例」上，之后**这一页发出的所有请求自动带 signal**
 *     —— 因此 api 模块（fetchCustomers 等）签名不用加参数。页面 inUnload 里
 *     `scope.abort()` 即可批量取消在飞请求。
 *  ③ 页面存活闸（默认开启，fail-open）：请求发起时记住当时的页面实例，回包时若
 *     该页已不在页面栈（且栈里没有同 route 的新实例）→ 判定为已销毁，直接丢弃
 *     回包，`.then` 里的赋值不再执行。任何一步判断不确定就**放行**，绝不误杀。
 *
 * 接线清单（页面侧，各 3 行）见 docs/miniapp-migration 与 W7 任务 output；
 * 未接线的页面靠 ③ 兜底，接线后 ① 才真正把请求掐断（省流量、省等待）。
 */

import { storage } from './storage'

const TOKEN_KEY = 'backend-token'

export const API_BASE: string =
  (import.meta.env.VITE_API_BASE as string | undefined) || 'https://oa.nbyimai.com/api'

export class HttpError extends Error {
  code: number
  statusCode?: number
  constructor(message: string, code = -1, statusCode?: number) {
    super(message)
    this.name = 'HttpError'
    this.code = code
    this.statusCode = statusCode
  }
}

/**
 * 请求被「取消」时抛出的错误（用户退出页面 / 主动 abort）。
 * code = -2 与后端业务码、网络错误(-1) 区分开；页面 catch 里用 isCanceled() 判定，
 * 不要把它渲染成「加载失败」。
 */
export class CanceledError extends HttpError {
  /** 显式标记：跨 bundle/多实例场景下 instanceof 可能失效，双保险 */
  readonly canceled = true
  constructor(message = '请求已取消') {
    super(message, -2)
    this.name = 'CanceledError'
  }
}

/** 是否是取消（而非真失败） */
export function isCanceled(err: unknown): boolean {
  if (err instanceof CanceledError) return true
  return !!err && typeof err === 'object' && (err as { canceled?: boolean }).canceled === true
}

/**
 * AbortSignal 的最小可用子集。
 * 小程序基础库没有 AbortController（部分机型/低版本），所以这里不用它，
 * 只依赖「aborted 标记 + addEventListener('abort')」两个能力，
 * 与 DOM AbortSignal 形状兼容（将来换原生实现也不用改调用方）。
 */
export interface AbortSignalLike {
  readonly aborted: boolean
  addEventListener(type: 'abort', listener: () => void): void
  removeEventListener?(type: 'abort', listener: () => void): void
}

export interface RequestScope {
  /** 传给 http.* 的 signal */
  readonly signal: AbortSignalLike
  readonly aborted: boolean
  /** 取消该 scope 下所有在飞请求（幂等） */
  abort(reason?: string): void
  /** 复用同一个 scope（onHide 取消、onShow 重新开始时调） */
  reset(): void
}

/** 新建一个取消域：一个页面 / 一串交互一个 */
export function createRequestScope(label = 'scope'): RequestScope {
  let aborted = false
  let listeners: Array<() => void> = []

  const signal: AbortSignalLike = {
    get aborted() {
      return aborted
    },
    addEventListener(_type, listener) {
      listeners.push(listener)
    },
    removeEventListener(_type, listener) {
      const i = listeners.indexOf(listener)
      if (i >= 0) listeners.splice(i, 1)
    }
  }

  return {
    signal,
    get aborted() {
      return aborted
    },
    abort(reason?: string) {
      if (aborted) return
      aborted = true
      const pending = listeners
      listeners = []
      for (const fn of pending) {
        try {
          fn()
        } catch (e) {
          console.warn(`[http] scope(${label}) abort listener failed${reason ? `: ${reason}` : ''}`, e)
        }
      }
    },
    reset() {
      aborted = false
      listeners = []
    }
  }
}

/* --------------------------------------------------------------------------
 * 页面级取消域 + 页面存活闸
 * ------------------------------------------------------------------------ */

type PageRef = object

/** 页面实例 → 取消域。用 WeakMap：页面销毁后 key 自动回收，不会攒内存 */
const pageScopes = new WeakMap<PageRef, RequestScope>()

function currentPage(): PageRef | null {
  try {
    if (typeof getCurrentPages !== 'function') return null
    const stack = getCurrentPages() as unknown as PageRef[]
    return stack && stack.length ? stack[stack.length - 1] : null
  } catch {
    return null
  }
}

/**
 * 在页面 setup 里调用一次：把取消域绑到当前页面实例。
 * 之后这一页发出的**所有**请求会自动带上它的 signal（含 api 模块内部发出的），
 * 所以 16 个 api 模块不需要任何改动。
 *
 *   const scope = usePageScope()
 *   onUnload(() => scope.abort('leave'))
 */
export function usePageScope(): RequestScope {
  const scope = createRequestScope('page')
  const page = currentPage()
  if (page) pageScopes.set(page, scope)
  return scope
}

/** 取当前页面已绑定的、尚未取消的 scope（没有则 null） */
function activePageScope(): RequestScope | null {
  const page = currentPage()
  if (!page) return null
  const scope = pageScopes.get(page)
  return scope && !scope.aborted ? scope : null
}

/**
 * 页面是否还活着。
 * **fail-open**：getCurrentPages 不可用、拿不到 route、比对过程抛错，一律返回 true。
 * 只有「确实已经不在栈里，且栈里也没有同 route 的新实例」才判死 —— 宁可少取消，
 * 也不能因为环境差异把所有请求当成已取消（那会整站白屏）。
 */
function pageAlive(page: PageRef | null): boolean {
  if (!page) return true
  try {
    const stack = getCurrentPages() as unknown as Array<PageRef & { route?: string }>
    if (!Array.isArray(stack)) return true
    if (stack.length === 0) return false
    if (stack.indexOf(page) >= 0) return true
    const route = (page as { route?: string }).route
    // 拿不到 route 就无法可靠比对 → 放行
    if (!route) return true
    // 同 route 的新实例（页面被 reLaunch/redirect 重建）视为存活
    return stack.some((p) => p && (p as { route?: string }).route === route)
  } catch {
    return true
  }
}

/* --------------------------------------------------------------------------
 * 会话
 * ------------------------------------------------------------------------ */

export function getToken(): string {
  return storage.getItem(TOKEN_KEY) ?? ''
}

export function setToken(token: string): void {
  storage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  storage.removeItem(TOKEN_KEY)
}

/** 401 防抖：3s 内只触发一次跳登录 */
let sessionExpiring = false
let sessionExpireTimer: ReturnType<typeof setTimeout> | null = null

function expireSession(): void {
  if (sessionExpiring) return
  sessionExpiring = true
  clearToken()
  uni.showToast({ title: '登录已过期，请重新登录', icon: 'none' })
  setTimeout(() => {
    uni.reLaunch({ url: '/pages/login/login' })
  }, 600)
  sessionExpireTimer = setTimeout(() => {
    sessionExpiring = false
    sessionExpireTimer = null
  }, 3000)
}

interface RawBody {
  code?: number
  data?: unknown
  message?: string
  msg?: string
  ok?: boolean
  errno?: number | string
  emsg?: string
}

/** 三形态解包（与 web 端 unwrap 逐行对齐） */
function unwrap<T>(body: RawBody | null | undefined): T {
  if (body === null || body === undefined) throw new HttpError('响应为空')
  if (body.code !== undefined && body.code !== 0) {
    throw new HttpError(body.message || body.msg || '请求失败', body.code)
  }
  if (body.ok === false) throw new HttpError(body.message || body.emsg || '请求失败')
  if (body.errno !== undefined && String(body.errno) !== '0') {
    throw new HttpError(body.emsg || body.message || '请求失败')
  }
  return body.data as T
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** query（GET/DELETE）或 body（其余） */
  data?: Record<string, unknown>
  timeout?: number
  /** 静默模式：不弹错误 toast */
  silent?: boolean
  header?: Record<string, string>
  /** 取消信号（createRequestScope()/usePageScope() 产出，或任意 AbortSignal） */
  signal?: AbortSignalLike
  /**
   * 页面存活闸（默认 true）：回包时若发起请求的页面已销毁，丢弃回包且 reject CanceledError。
   * 传 false 可关闭（例如全局轮询、非页面上下文发起的请求）。
   */
  pageGuard?: boolean
}

/** uni.request 返回的 RequestTask 只用到 abort() */
interface TaskLike {
  abort?: () => void
}

function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const {
    method = 'GET',
    data,
    timeout = 20000,
    silent = false,
    header = {},
    signal: explicitSignal,
    pageGuard = true
  } = options
  const token = getToken()
  // 显式 signal 优先；否则用当前页面绑定的 scope（页面里 usePageScope() 过就有）
  const signal = explicitSignal ?? activePageScope()?.signal
  const origin = pageGuard ? currentPage() : null

  return new Promise<T>((resolve, reject) => {
    let settled = false
    let task: TaskLike | null = null

    const detach = (): void => {
      if (signal?.removeEventListener) {
        try {
          signal.removeEventListener('abort', onAbort)
        } catch {
          /* 某些实现的 removeEventListener 会抛，忽略 */
        }
      }
    }
    /** 只结算一次；结算后立刻摘掉 abort 监听，避免 scope 越攒越多 */
    const ok = (value: T): void => {
      if (settled) return
      settled = true
      detach()
      resolve(value)
    }
    const fail = (err: unknown): void => {
      if (settled) return
      settled = true
      detach()
      reject(err)
    }
    const cancel = (msg?: string): void => fail(new CanceledError(msg))
    function onAbort(): void {
      // 先结算再 abort：task.abort() 会回调 fail('request:fail abort')，
      // 那时 settled 已是 true，不会再走「网络异常」toast。
      cancel()
      try {
        task?.abort?.()
      } catch {
        /* 已结束的 task abort 会抛，忽略 */
      }
    }

    // 已经取消的 scope：直接不发请求（省一次无效网络往返）
    if (signal?.aborted) {
      cancel('请求已取消')
      return
    }
    if (signal) signal.addEventListener('abort', onAbort)

    task = uni.request({
      url: API_BASE + path,
      // uni.request 的 method 联合类型不含 PATCH，但 mp-weixin 运行时支持；按 any 断言
      method: method as 'GET',
      data,
      timeout,
      header: {
        'Content-Type': 'application/json',
        // 必须显式声明 JSON：Laravel 对「不是 JSON 期望」的请求会把 ValidationException
        // 渲染成 302 重定向（登录密码错时前端因此拿不到 422 文案，只看到跳转后的 404/HTML）。
        // 这是登录报「服务异常（404）」的根因，不是网络问题。
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...header
      },
      success: (res) => {
        if (settled) return
        // 页面已销毁：丢弃回包（不再进 unwrap / 不再赋值到已卸载组件）
        if (!pageAlive(origin)) {
          cancel('页面已关闭')
          return
        }
        // statusCode 闸门（R4）：非 2xx 一律不进 unwrap
        const status = res.statusCode
        if (status === 401) {
          expireSession()
          fail(new HttpError('登录已过期', 401, 401))
          return
        }
        if (status === 403) {
          const b = res.data as RawBody | undefined
          const msg = b?.message || b?.emsg || '无权限执行此操作'
          if (!silent) uni.showToast({ title: msg, icon: 'none' })
          fail(new HttpError(msg, 403, 403))
          return
        }
        if (status === 429) {
          // 限流文案必须说人话：直接透出 Laravel 的 "Too Many Attempts." 用户只会以为系统坏了。
          const msg = '操作太频繁了，请等 1 分钟再试'
          if (!silent) uni.showToast({ title: msg, icon: 'none' })
          fail(new HttpError(msg, 429, 429))
          return
        }
        if (status < 200 || status >= 300) {
          // 三形态错误体都要能读出人话：主契约 {code,message}，KeepYoga 风格 {ok,message/emsg}、
          // {errno,emsg}。只读 message 时，`POST /shares/disable` 的 503 `{errno,emsg}`
          // 会被降级成「服务异常（503）」，用户拿不到「数据库结构升级未完成」这种可操作提示。
          const b = res.data as RawBody | undefined
          const msg =
            b?.message ||
            b?.emsg ||
            b?.msg ||
            (status === 404 ? '接口不存在（404），请检查小程序环境或后端部署' : `服务异常（${status}）`)
          if (!silent) uni.showToast({ title: msg, icon: 'none' })
          fail(new HttpError(msg, status, status))
          return
        }
        try {
          ok(unwrap<T>(res.data as RawBody))
        } catch (e) {
          if (!silent && e instanceof Error) uni.showToast({ title: e.message, icon: 'none' })
          fail(e)
        }
      },
      fail: (err) => {
        if (settled) return
        // 主动 abort 之后 wx 仍会回调 fail('request:fail abort')，不能当网络错误处理
        if (signal?.aborted) {
          cancel('请求已取消')
          return
        }
        if (!pageAlive(origin)) {
          cancel('页面已关闭')
          return
        }
        const msg = err.errMsg?.includes('timeout') ? '请求超时，请重试' : '网络异常，请检查网络'
        if (!silent) uni.showToast({ title: msg, icon: 'none' })
        fail(new HttpError(msg, -1))
      }
    }) as unknown as TaskLike
  })
}

export const http = {
  get<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'GET', data })
  },
  post<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'POST', data })
  },
  put<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'PUT', data })
  },
  patch<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'PATCH', data })
  },
  del<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'DELETE', data })
  }
}
