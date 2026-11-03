/**
 * R4 闸门运行时验证（不依赖微信工具，不需要截图）
 *
 * 背景：wx.request「只要收到返回，无论 statusCode 多少都进 success」。
 * 若 http.ts 只做 unwrap 不做 2xx 判定，会出现「密码错却显示登录成功但白屏」。
 *
 * 做法：用 esbuild 把 src/utils/http.ts 打成 CJS，注入假 uni / import.meta.env，
 * 逐条喂 statusCode + body，断言 resolve/reject 与错误信息、以及 401 的清 token + reLaunch。
 *
 * 用法：node verify/http-gate.test.mjs
 */
import { build } from 'esbuild'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { mkdirSync, rmSync, writeFileSync } from 'node:fs'

const __dirname = dirname(fileURLToPath(import.meta.url))
const OUT = resolve(__dirname, 'out')
rmSync(OUT, { recursive: true, force: true })
mkdirSync(OUT, { recursive: true })
const BUNDLE = resolve(OUT, 'http.bundle.cjs')

await build({
  entryPoints: [resolve(__dirname, '../src/utils/http.ts')],
  bundle: true,
  format: 'cjs',
  platform: 'node',
  target: 'node20',
  outfile: BUNDLE,
  define: { 'import.meta.env.VITE_API_BASE': '"https://api.test/api"' },
  logLevel: 'silent',
})

const require = createRequire(import.meta.url)

/** 每个用例前重置假环境 */
function makeEnv() {
  const calls = { toasts: [], relaunches: [], requests: [] }
  let nextResponse = { statusCode: 200, data: { code: 0, data: { ok: 1 } } }
  let nextFail = null

  global.uni = {
    getStorageSync: (k) => globalThis.__store?.[k] ?? '',
    setStorageSync: (k, v) => { (globalThis.__store ??= {})[k] = v },
    removeStorageSync: (k) => { if (globalThis.__store) delete globalThis.__store[k] },
    showToast: (o) => calls.toasts.push(o.title),
    reLaunch: (o) => calls.relaunches.push(o.url),
    request: (o) => {
      calls.requests.push(o)
      setTimeout(() => {
        if (nextFail) o.fail({ errMsg: nextFail })
        else o.success(nextResponse)
      }, 0)
      return { abort() {} }
    },
  }
  globalThis.__store = {}
  return {
    calls,
    reply: (r) => { nextResponse = r },
    fail: (m) => { nextFail = m },
  }
}

const results = []
function check(name, cond, detail) {
  results.push({ name, ok: !!cond, detail })
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + detail : ''}`)
}

const mod = require(BUNDLE)
const { http, setToken, getToken, HttpError } = mod

// ---------- 1. 主契约 code:0 → resolve data ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 200, data: { code: 0, data: { token: 'T1' } } })
  const r = await http.post('/auth/login', { userName: 'a', password: 'b' }, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('200 + {code:0} → resolve body.data', r.ok && r.v?.token === 'T1', JSON.stringify(r))
}

// ---------- 2. R4 核心：401 必须 reject + 清 token + reLaunch ----------
{
  const env = makeEnv()
  globalThis.__store['backend-token'] = 'OLD'
  env.reply({ statusCode: 401, data: { code: 0, message: 'ok-but-401' } })
  const r = await http.get('/me', undefined, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('401 即使 body 是 {code:0} 也 reject', !r.ok && r.e?.statusCode === 401, r.e?.message)
  check('401 清空 token', getToken() === '', `token="${getToken()}"`)
  await new Promise((s) => setTimeout(s, 700))
  check('401 触发 reLaunch 登录页', env.calls.relaunches.includes('/pages/login/login'),
    env.calls.relaunches.join(','))
}

// ---------- 3. 403 归一化提示 ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 403, data: { message: '无权访问该会员' } })
  const r = await http.get('/x', undefined, { silent: false }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('403 reject 且带 statusCode', !r.ok && r.e?.statusCode === 403, r.e?.message)
  check('403 非静默时弹 toast', env.calls.toasts.includes('无权访问该会员'), env.calls.toasts.join('|'))
}

// ---------- 4. 500 不得被当成功 ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 500, data: { code: 0, data: { fake: true } } })
  const r = await http.get('/x', undefined, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('500 + {code:0} → reject（不得透传假成功）', !r.ok, JSON.stringify(r.v ?? r.e?.message))
}

// ---------- 5. 422 业务校验失败 ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 422, data: { message: '账号或密码错误' } })
  const r = await http.post('/auth/login', {}, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('422 → reject 且透出后端 message', !r.ok && r.e?.message === '账号或密码错误', r.e?.message)
}

// ---------- 6. 200 但业务 code 非 0 ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 200, data: { code: 1, message: '当前未开放自助注册' } })
  const r = await http.get('/x', undefined, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('200 + {code:1} → reject 并带 code', !r.ok && r.e?.code === 1 && r.e?.message === '当前未开放自助注册',
    `code=${r.e?.code} msg=${r.e?.message}`)
}

// ---------- 7. {ok:false} 形态 ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 200, data: { ok: false, message: 'KY 失败' } })
  const r = await http.get('/x', undefined, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('{ok:false} → reject', !r.ok && r.e?.message === 'KY 失败', r.e?.message)
}

// ---------- 8. {errno,emsg} 形态 ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 200, data: { errno: 1001, emsg: 'KY errno 失败' } })
  const r = await http.get('/x', undefined, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('{errno:1001,emsg} → reject', !r.ok && r.e?.message === 'KY errno 失败', r.e?.message)
}

// ---------- 9. errno 为字符串 "0" 应放行 ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 200, data: { errno: '0', data: { v: 9 } } })
  const r = await http.get('/x', undefined, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('{errno:"0"} → resolve', r.ok && r.v?.v === 9, JSON.stringify(r))
}

// ---------- 10. 网络失败 fail 回调 ----------
{
  const env = makeEnv()
  env.fail('request:fail timeout')
  const r = await http.get('/x', undefined, { silent: true }).then(
    (v) => ({ ok: true, v }), (e) => ({ ok: false, e })
  )
  check('fail(timeout) → reject 超时文案', !r.ok && r.e?.message === '请求超时，请重试', r.e?.message)
}

// ---------- 11. Authorization 头注入 ----------
{
  const env = makeEnv()
  setToken('TOK-XYZ')
  env.reply({ statusCode: 200, data: { code: 0, data: 1 } })
  await http.get('/x', undefined, { silent: true })
  const h = env.calls.requests[0]?.header ?? {}
  check('已登录时注入 Bearer 头', h.Authorization === 'Bearer TOK-XYZ', JSON.stringify(h))
}

// ---------- 12. API_BASE 来自构建期变量 ----------
{
  const env = makeEnv()
  env.reply({ statusCode: 200, data: { code: 0, data: 1 } })
  await http.get('/me', undefined, { silent: true })
  const url = env.calls.requests[0]?.url ?? ''
  check('URL 由 VITE_API_BASE 拼接', url === 'https://api.test/api/me', url)
}

const failed = results.filter((r) => !r.ok)
const report = { total: results.length, passed: results.length - failed.length, failed: failed.length, results }
writeFileSync(resolve(OUT, 'http-gate-report.json'), JSON.stringify(report, null, 2))
console.log(`\nRESULT ${failed.length === 0 ? 'OK' : 'FAIL'}  ${report.passed}/${report.total} passed`)
process.exit(failed.length === 0 ? 0 : 1)
