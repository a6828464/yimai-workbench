/**
 * 真机反馈修复轮（t9~t14）静态验收骨架 —— grep 级断言，不跑业务、不需要微信工具
 *
 * 背景：本轮六项修复是「交互/筛选/权限/邮箱/人设/老师端表单」，都属可在源码里判定的
 * 结构性改动（有没有绑定 click、有没有 sticky、参数有没有带 venue、email 是否还在渲染）。
 * 用一份可重复执行的脚本来判，避免「验收时靠肉眼翻 diff」。
 *
 * 口径说明（为什么只做 grep 级）：
 *  - 这里判的是「结构存在性」，不是「行为正确性」。行为（点击是否真的跳到对的地方、
 *    筛选是否真的生效）仍需真机/模拟器；本脚本的作用是把「根本没写」这类低级缺口先挡掉。
 *  - 断言全部指向**文件内的关键标记**，不做 AST，也不 import 任何 src 代码，
 *    因此可以在六项修复并行编写的中途安全运行（不会因为语法半成品而崩）。
 *
 * 用法：
 *   node verify/fix-round.test.mjs            # 中途跑：FAIL 只报告，不改变退出码
 *   node verify/fix-round.test.mjs --strict   # 终验跑：任何 FAIL → 退出码 1（可做闸门）
 *
 * 产物：verify/out/fix-round/fix-round.json（按 2026-11-04 新规矩：各脚本写自己的子目录）
 */
import { readFileSync, existsSync, mkdirSync, writeFileSync, readdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = resolve(__dirname, '..')
const OUT = resolve(__dirname, 'out/fix-round')
const STRICT = process.argv.includes('--strict')

const read = (rel) => {
  const p = resolve(ROOT, rel)
  if (!existsSync(p)) return null
  return readFileSync(p, 'utf8')
}
const count = (text, re) => (text ? (text.match(re) ?? []).length : 0)

/* ---------------- 页面注册表 ---------------- */
const pagesJson = JSON.parse(read('src/pages.json'))
const registered = new Set(pagesJson.pages.map((p) => '/' + p.path))
const tabPages = new Set(pagesJson.tabBar.list.map((t) => '/' + t.pagePath))

const results = []
function check(id, name, fn) {
  let ok = false
  let detail = ''
  try {
    const r = fn()
    if (typeof r === 'boolean') ok = r
    else {
      ok = !!r.ok
      detail = r.detail ?? ''
    }
  } catch (e) {
    detail = 'ERR ' + e.message
  }
  results.push({ id, name, ok, detail })
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${id}  ${name}${detail ? ' :: ' + detail : ''}`)
}

/** 断言某页面存在关键标记，返回命中次数 */
function hits(rel, re, label) {
  const t = read(rel)
  if (t === null) return { ok: false, detail: `${rel} 不存在` }
  const n = count(t, re)
  return { ok: n > 0, detail: `${label}=${n} 次` }
}

/* ---------------- A. 新增页面已注册且文件真实存在 ---------------- */
check('A1', 'pages.json 注册 pages/profile/persona', () => ({
  ok: registered.has('/pages/profile/persona'),
  detail: registered.has('/pages/profile/persona') ? '' : '未注册'
}))
check('A2', 'pages.json 注册 pages/review/detail', () => ({
  ok: registered.has('/pages/review/detail'),
  detail: registered.has('/pages/review/detail') ? '' : '未注册'
}))
check('A3', 'pages.json 注册 pages/plan/detail', () => ({
  ok: registered.has('/pages/plan/detail'),
  detail: registered.has('/pages/plan/detail') ? '' : '未注册'
}))
/** 陷阱：注册了但 .vue 不存在 —— 编译只出警告（pages/xxx not found），产物只有孤儿 json，运行即空白页 */
for (const [id, rel] of [
  ['A4', 'src/pages/profile/persona.vue'],
  ['A5', 'src/pages/review/detail.vue'],
  ['A6', 'src/pages/plan/detail.vue']
]) {
  check(id, `${rel} 文件真实存在`, () => {
    const ok = existsSync(resolve(ROOT, rel))
    return { ok, detail: ok ? '' : '注册了但文件缺失 → 编译仅警告，运行空白页' }
  })
}

/* ---------------- B. 工作台 4 张指标卡可点击 ---------------- */
const wbRel = 'src/pages/workbench/workbench.vue'
check('B1', '工作台定义了 ≥4 张卡的跳转目标（to 字段）', () => {
  const n = count(read(wbRel), /\bto:\s*['"]\/pages\//g)
  return { ok: n >= 4, detail: `to= ${n} 个` }
})
check('B2', '工作台模板把卡片点击接到跳转函数（openCard + v-for cards）', () => {
  const t = read(wbRel) ?? ''
  const click = count(t, /@(click|tap)="openCard\(/g)
  const loop = /v-for="[a-z]+ in cards"/.test(t)
  return { ok: click >= 1 && loop, detail: `openCard 绑定=${click} 处；v-for cards=${loop}` }
})
check('B3', '工作台卡片目标路径全部已在 pages.json 注册', () => {
  const t = read(wbRel) ?? ''
  const targets = [...t.matchAll(/\bto:\s*['"](\/pages\/[^'"?]+)/g)].map((m) => m[1])
  const bad = targets.filter((p) => !registered.has(p))
  return { ok: targets.length > 0 && bad.length === 0, detail: bad.length ? `未注册: ${bad.join(',')}` : `${targets.length} 个全部命中` }
})

/* ---------------- C. 今日待办顶部可切换标签 ---------------- */
const todoRel = 'src/pages/todo/todo.vue'
check('C1', 'todo 顶部标签条 sticky 吸顶', () => hits(todoRel, /position:\s*sticky/g, 'sticky'))
check('C2', 'todo 有标签容器（.tabbar + scroll-view，按实际实现）', () => {
  const t = read(todoRel) ?? ''
  const cls = /class="tabbar"/.test(t)
  const scroll = /<scroll-view[^>]*scroll-x/.test(t)
  return { ok: cls && scroll, detail: `.tabbar=${cls}；scroll-view scroll-x=${scroll}` }
})
check('C3', 'todo 标签顺序 = 全部/今日预约/待续费/流失风险/生日关怀/体验课/课后分析/新客首响', () => {
  const t = read(todoRel) ?? ''
  /** 产品标签以 GROUPS 常量为准（是「生日关怀」「新客首响」，不是「生日」「新客资」） */
  const groupOrder = ['今日预约', '待续费', '流失风险', '生日关怀', '体验课', '课后分析', '新客首响']
  const m = t.match(/const GROUPS[^=]*=\s*\[([\s\S]*?)\n\]/)
  const scope = m ? m[1] : t
  const idx = groupOrder.map((k) => scope.indexOf(k))
  const missing = groupOrder.filter((_, i) => idx[i] < 0)
  const sorted = idx.every((v, i) => i === 0 || (v >= 0 && idx[i - 1] >= 0 && v > idx[i - 1]))
  /** 「全部」由 ALL_TAB 提供，TABS = [ALL_TAB, ...GROUPS] → 全部天然排第一 */
  const allFirst = /label:\s*['"]全部['"]/.test(t) && /\[ALL_TAB,\s*\.\.\.GROUPS\]/.test(t)
  return {
    ok: missing.length === 0 && sorted && allFirst,
    detail: missing.length
      ? `缺: ${missing.join('/')}`
      : `GROUPS 顺序${sorted ? '正确' : '错乱: ' + idx.join(',')}；全部置顶=${allFirst}`
  }
})
check('C4', 'todo 支持 ?tab= 直达（onLoad 读 options.tab 并校验合法值）', () => {
  const t = read(todoRel) ?? ''
  const hasLoad = /onLoad\s*\(/.test(t)
  const readsTab = /(options|query|param)[^\n]{0,60}\btab\b|\btab\b[^\n]{0,30}(options|query)/.test(t)
  const validated = /TAB_TYPES|TAB_SET|has\(\s*t\s*\)|includes\(\s*t\s*\)/.test(t)
  return { ok: hasLoad && readsTab && validated, detail: `onLoad=${hasLoad} 读 tab=${readsTab} 合法值校验=${validated}` }
})

/* ---------------- D. 四个列表页带门店参数 ---------------- */
for (const [id, rel, label] of [
  ['D1', 'src/pages/members/members.vue', '会员管理'],
  ['D2', 'src/pages/leads/leads.vue', '留资管理'],
  ['D3', 'src/pages/crm/pool.vue', '客户经营池'],
  ['D4', 'src/pages/crm/new-members.vue', '新客培养']
]) {
  check(id, `${label} 请求带 venue 门店参数`, () => {
    const t = read(rel)
    if (t === null) return { ok: false, detail: `${rel} 不存在` }
    const used = /\bvenue\b/.test(t)
    const asParam = /\bvenue\s*[:=,]/.test(t) || /venue\s*\}/.test(t)
    return { ok: used && asParam, detail: `venue 出现=${count(t, /venue/g)} 次；作为参数=${asParam}` }
  })
}

/* ---------------- E. 留资 FAB 走权限判定，不写死角色 ---------------- */
check('E1', '留资悬浮录入按权限判定（canUse(user.roles,\'leads\')）', () => {
  const t = read('src/pages/leads/leads.vue') ?? ''
  const n = count(t, /canUse\([^)]*['"]leads['"]/g)
  return { ok: n > 0, detail: `canUse(…'leads') =${n} 处` }
})
check('E2', '留资页不写死角色码（roles.includes(R_xxx) 为 0）', () => {
  const n = count(read('src/pages/leads/leads.vue'), /roles[^\n]{0,20}includes\(\s*['"]R_/g)
  return { ok: n === 0, detail: `写死角色=${n} 处` }
})

/* ---------------- F. 邮箱在 miniapp 渲染层彻底下线 ---------------- */
check('F1', 'src/pages 与 src/components 的 .vue 不再出现 email（渲染层 0 处）', () => {
  const offenders = []
  for (const rel of vueFiles()) {
    const n = count(read(rel), /email/i)
    if (n > 0) offenders.push(`${rel}(${n})`)
  }
  return { ok: offenders.length === 0, detail: offenders.length ? offenders.join(' ') : '0 处' }
})
check('F2', 'api 层允许保留 email 可选字段（仅类型/载荷，不渲染）', () => {
  const t = read('src/api/profile.ts') ?? ''
  return { ok: t.length > 0, detail: 'api 层不参与渲染判定，仅供参考' }
})

/* ---------------- G. 人设页入口 ---------------- */
check('G1', '「我的」页有 persona 入口', () => {
  const t = read('src/pages/profile/profile.vue') ?? ''
  const n = count(t, /persona/g)
  return { ok: n > 0, detail: `persona 出现=${n} 次` }
})
check('G2', '营销工具页有 persona 入口', () => {
  const t = read('src/pages/ai/marketing.vue') ?? ''
  const n = count(t, /persona/g)
  return { ok: n > 0, detail: `persona 出现=${n} 次` }
})

/* ---------------- H. 授课老师端：列表可点 + 详情 + 写接口 ---------------- */
check('H1', '课后分析列表行可点并跳 review/detail', () => {
  const t = read('src/pages/review/review.vue') ?? ''
  return {
    ok: count(t, /@(click|tap)=/g) > 0 && /\/pages\/review\/detail/.test(t),
    detail: `@click=${count(t, /@(click|tap)=/g)} 处；跳 detail=${/\/pages\/review\/detail/.test(t)}`
  }
})
check('H2', '训练计划列表行可点并跳 plan/detail', () => {
  const t = read('src/pages/plan/plan.vue') ?? ''
  return {
    ok: count(t, /@(click|tap)=/g) > 0 && /\/pages\/plan\/detail/.test(t),
    detail: `@click=${count(t, /@(click|tap)=/g)} 处；跳 detail=${/\/pages\/plan\/detail/.test(t)}`
  }
})
check('H3', '课后分析有写接口（POST/PUT 到 /post-class-reviews）', () => {
  const t = read('src/api/review.ts') ?? ''
  const ok = /http\.(post|put|patch)/.test(t) && /post-class-reviews/.test(t)
  return { ok, detail: `写接口标记=${count(t, /http\.(post|put|patch)/g)}` }
})
check('H4', '训练计划有写接口（POST/PUT 到 /training-plans）或复用 review 模块', () => {
  const plan = read('src/api/plan.ts')
  const review = read('src/api/review.ts') ?? ''
  const src = plan ?? review
  const ok = /http\.(post|put|patch)/.test(src) && /training-plans/.test(src)
  return { ok, detail: `模块=${plan ? 'api/plan.ts' : 'api/review.ts(回落)'}；写标记=${count(src, /http\.(post|put|patch)/g)}` }
})

/* ---------------- I. 注册表自检（每题都跑） ---------------- */
check('I1', 'tabBar 仍为 4 项且均为已注册页面', () => {
  const tb = pagesJson.tabBar.list
  const bad = tb.filter((t) => !registered.has('/' + t.pagePath))
  return { ok: tb.length === 4 && bad.length === 0, detail: `tabBar=${tb.length}；异常=${bad.length}` }
})

/* ---------------- 汇总 ---------------- */
function vueFiles() {
  const out = []
  const walk = (dir) => {
    const abs = resolve(ROOT, dir)
    if (!existsSync(abs)) return
    for (const e of readdirSync(abs, { withFileTypes: true })) {
      const rel = `${dir}/${e.name}`
      if (e.isDirectory()) walk(rel)
      else if (e.name.endsWith('.vue')) out.push(rel)
    }
  }
  walk('src/pages')
  walk('src/components')
  return out.sort()
}

const failed = results.filter((r) => !r.ok)
const report = {
  generatedAt: new Date().toISOString(),
  mode: STRICT ? 'strict' : 'skeleton',
  note: 'grep 级结构断言；行为正确性仍需真机/模拟器。中途运行时 FAIL 属预期（六项修复并行编写中）',
  total: results.length,
  passed: results.length - failed.length,
  failed: failed.length,
  cases: results
}
mkdirSync(OUT, { recursive: true })
writeFileSync(resolve(OUT, 'fix-round.json'), JSON.stringify(report, null, 2) + '\n', 'utf8')
console.log(`\n${report.passed}/${report.total} passed → verify/out/fix-round/fix-round.json${STRICT ? '（strict 模式）' : ''}`)
if (failed.length) console.log('FAIL 明细：\n' + failed.map((f) => ` - ${f.id} ${f.name} :: ${f.detail}`).join('\n'))
if (STRICT && failed.length) process.exit(1)
