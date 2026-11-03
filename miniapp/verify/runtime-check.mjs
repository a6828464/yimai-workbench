/**
 * 小程序运行时验证（连接模式，无截图依赖）
 * IDE 已用 `cli open --port 9420` + `cli auto --auto-port 9421` 拉起，
 * 这里直接连自动化 websocket，用 evaluate/getCurrentPages 读取真实运行时状态，
 * 逐页断言路由与页面数据关键字，采集 console 与异常。全程不产生图片。
 *
 * 用法：node verify/runtime-check.mjs [ws://127.0.0.1:9421]
 */
import automator from 'miniprogram-automator'
import { resolve } from 'node:path'
import { mkdirSync, writeFileSync, rmSync } from 'node:fs'

const WS = process.argv[2] ?? 'ws://127.0.0.1:9421'
const OUT = resolve('verify/out')
rmSync(OUT, { recursive: true, force: true })
mkdirSync(OUT, { recursive: true })

const logs = []
const push = (kind, text) => logs.push({ kind, text: String(text).slice(0, 1500), at: Date.now() })
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

const report = { ws: WS, steps: [], console: logs, ok: false }
function step(name, ok, detail) {
  report.steps.push({ name, ok, detail })
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + detail : ''}`)
}

/** 读当前页：路由 + 页面数据里的可见文案骨架 */
const probe = `
(() => {
  const stack = getCurrentPages();
  const p = stack[stack.length - 1];
  if (!p) return { route: null, depth: 0 };
  let data = {};
  try { data = JSON.parse(JSON.stringify(p.data ?? {})); } catch (e) { data = { __unserializable: String(e) }; }
  const flat = JSON.stringify(data);
  return { route: p.route, depth: stack.length, dataKeys: Object.keys(data), flat: flat.slice(0, 4000), len: flat.length };
})()
`

let mp
try {
  mp = await automator.connect({ wsEndpoint: WS })
  mp.on('console', (m) => push('console', `[${m.type}] ${(m.args ?? []).join(' ')}`))
  mp.on('exception', (e) => push('exception', e.message ?? JSON.stringify(e)))
  step('连接自动化通道', true, WS)

  const info = await mp.systemInfo()
  step('基础库可用', !!info.SDKVersion, `SDK=${info.SDKVersion} 平台=${info.platform}`)

  // 冷启一次，确保从零态开始
  await mp.reLaunch('/pages/login/login').catch((e) => push('warn', 'reLaunch login: ' + e.message))
  await sleep(2000)

  let st = await mp.evaluate(probe)
  step('首屏落地', !!st.route, `route=${st.route} depth=${st.depth} keys=${(st.dataKeys ?? []).join(',')}`)

  const loginOk = st.route === 'pages/login/login'
  if (loginOk) {
    const hasFields = /account|username|password|手机|账号|密码/.test(st.flat ?? '')
    step('登录页字段就绪', hasFields, `文案命中=${hasFields} 数据长度=${st.len}`)
  } else {
    step('登录页字段就绪', true, `已有登录态，落在 ${st.route}`)
  }

  const pages = [
    { path: 'pages/workbench/workbench', tab: true, kw: /workbench|工作台|今日|todo|任务|统计/ },
    { path: 'pages/todo/todo', tab: true, kw: /todo|待办|分组|日期/ },
    { path: 'pages/tasks/tasks', tab: true, kw: /task|任务|状态/ },
    { path: 'pages/profile/profile', tab: true, kw: /user|profile|我|退出|角色/ },
    { path: 'pages/review/review', tab: false, kw: /review|课后|分析|会员|课程/ },
    { path: 'pages/plan/plan', tab: false, kw: /plan|计划|训练|周/ },
    { path: 'pages/approvals/approvals', tab: false, kw: /approval|审批|待审|通过/ },
  ]

  for (const p of pages) {
    const url = '/' + p.path
    const before = logs.length
    try {
      if (p.tab) {
        try { await mp.switchTab(url) } catch { await mp.reLaunch(url) }
      } else {
        await mp.navigateTo(url)
      }
      await sleep(1600)
      st = await mp.evaluate(probe)
      const hitRoute = st.route === p.path
      const hitKw = p.kw.test(st.flat ?? '')
      const newErr = logs.slice(before).filter((l) => l.kind === 'exception' || /\[error\]/.test(l.text))
      step(`页面 ${p.path}`, hitRoute && hitKw && newErr.length === 0,
        `route=${st.route} 关键字=${hitKw} 数据长度=${st.len} 新增错误=${newErr.length}` +
        (newErr.length ? ' :: ' + newErr.map((e) => e.text).join(' || ').slice(0, 300) : ''))
    } catch (e) {
      step(`页面 ${p.path}`, false, `失败: ${e.message}`)
    }
  }

  const errs = logs.filter((l) => l.kind === 'exception' || /\[error\]/.test(l.text))
  step('全程无运行期异常', errs.length === 0,
    errs.length ? errs.slice(0, 5).map((e) => e.text).join(' || ') : '0 exception / 0 error')
  report.ok = report.steps.every((s) => s.ok)
} catch (e) {
  step('连接/执行', false, e.message)
  push('fatal', e.stack ?? e.message)
} finally {
  writeFileSync(resolve(OUT, 'report.json'), JSON.stringify(report, null, 2))
  console.log('\n--- console 尾部 ---')
  for (const l of logs.slice(-20)) console.log(`[${l.kind}] ${l.text}`)
  console.log(`\nRESULT ${report.ok ? 'OK' : 'FAIL'}  报告: ${resolve(OUT, 'report.json')}`)
  if (mp) await mp.disconnect().catch(() => {})
  process.exit(report.ok ? 0 : 1)
}
