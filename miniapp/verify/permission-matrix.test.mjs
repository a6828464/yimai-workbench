/**
 * 权限矩阵验收（离线，不依赖微信工具）
 *
 * 目的：把 src/utils/permission.ts 的 FEATURES 注册表冻结成可回归的断言，
 * 防止「新页面加了却忘了登记角色」或「角色集合被误改」导致小程序先把无权入口画出来。
 *
 * 口径来源：admin-web/src/router/modules/yimai.ts 的 meta.roles
 *   ALL      = SUPER / MANAGER / SERVICE / TEACHER / MEDIA
 *   OPS      = SUPER / MANAGER / SERVICE / TEACHER（不含新媒体）
 *   TEACHING = SUPER / MANAGER / TEACHER
 *   MGMT     = SUPER / MANAGER
 *   SUPER    = R_SUPER
 *
 * 做法：esbuild 把 permission.ts 打成 CJS（该文件无外部依赖），
 * 对 5 个角色分别求 visibleFeatures() 全量集合，逐条断言，并把结果落盘 verify/out/permission-matrix.json。
 *
 * 用法：cd miniapp && node verify/permission-matrix.test.mjs
 */
import { build } from 'esbuild'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs'

const __dirname = dirname(fileURLToPath(import.meta.url))
const OUT = resolve(__dirname, 'out')
mkdirSync(OUT, { recursive: true })
const BUNDLE = resolve(OUT, 'permission.bundle.cjs')

await build({
  entryPoints: [resolve(__dirname, '../src/utils/permission.ts')],
  bundle: true,
  format: 'cjs',
  platform: 'node',
  target: 'node20',
  outfile: BUNDLE,
  logLevel: 'silent',
})

const require = createRequire(import.meta.url)
const perm = require(BUNDLE)
const { FEATURES, visibleFeatures, canUse, primaryRole, roleLabel, ALL_ROLES, isSuper, isManagerOrAbove, isMediaOnly } = perm

const ALL_KEYS = Object.keys(FEATURES)
const keysOf = (roles) => visibleFeatures(roles).map((f) => f.key)
const areaKeys = (roles, area) => visibleFeatures(roles, area).map((f) => f.key)
const has = (roles, key) => keysOf(roles).includes(key)

const results = []
function check(name, cond, detail) {
  results.push({ name, ok: !!cond, detail })
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + detail : ''}`)
}

/** 断言某角色「看不到」某组 key（每个 key 单独一条用例，失败时能精确定位） */
function deny(label, roles, keys) {
  for (const key of keys) {
    check(`${label} 看不到 ${key}`, !has(roles, key), `visible=${JSON.stringify(keysOf(roles))}`)
  }
}
/** 断言某角色「看得到」某组 key */
function allow(label, roles, keys) {
  for (const key of keys) {
    check(`${label} 看得到 ${key}`, has(roles, key), `visible=${JSON.stringify(keysOf(roles))}`)
  }
}

const SUPER = ['R_SUPER']
const MANAGER = ['R_MANAGER']
const SERVICE = ['R_SERVICE']
const TEACHER = ['R_TEACHER']
const MEDIA = ['R_MEDIA']

// ---------- 0. 注册表自检 ----------
check('FEATURES 非空', ALL_KEYS.length > 0, `count=${ALL_KEYS.length}`)
check('5 个角色常量齐全', JSON.stringify(ALL_ROLES) === JSON.stringify(['R_SUPER', 'R_MANAGER', 'R_SERVICE', 'R_TEACHER', 'R_MEDIA']), JSON.stringify(ALL_ROLES))
check(
  '每个功能都登记了 roles',
  Object.values(FEATURES).every((f) => Array.isArray(f.roles) && f.roles.length > 0),
  Object.entries(FEATURES).filter(([, f]) => !Array.isArray(f.roles) || f.roles.length === 0).map(([k]) => k).join(',') || 'all-ok'
)
check(
  '每个功能的 roles 都是合法角色码',
  Object.values(FEATURES).every((f) => f.roles.every((r) => ALL_ROLES.includes(r))),
  Object.entries(FEATURES).filter(([, f]) => !f.roles.every((r) => ALL_ROLES.includes(r))).map(([k]) => k).join(',') || 'all-ok'
)

// ---------- 1. R_SUPER 全量 ----------
check('R_SUPER 全量（数量与注册表一致）', keysOf(SUPER).length === ALL_KEYS.length, `${keysOf(SUPER).length}/${ALL_KEYS.length}`)
allow('R_SUPER', SUPER, ALL_KEYS)
check('R_SUPER 四板块都有入口', ['crm', 'biz', 'ai', 'me'].every((a) => areaKeys(SUPER, a).length > 0),
  ['crm', 'biz', 'ai', 'me'].map((a) => `${a}:${areaKeys(SUPER, a).length}`).join(' '))

// ---------- 2. R_MEDIA（新媒体）：看不到 members / sales / pool ----------
deny('R_MEDIA', MEDIA, ['members', 'sales', 'pool'])
allow('R_MEDIA', MEDIA, ['todo', 'newMembers', 'leads', 'marketing', 'profileEdit', 'notifications', 'password'])
check('R_MEDIA 看不到业务执行区任何入口（tasks/approvals/reviews/plans）',
  areaKeys(MEDIA, 'biz').length === 0, `biz=${JSON.stringify(areaKeys(MEDIA, 'biz'))}`)

// ---------- 3. R_TEACHER（授课老师）：看不到 approvals ----------
deny('R_TEACHER', TEACHER, ['approvals'])
allow('R_TEACHER', TEACHER, ['todo', 'members', 'newMembers', 'leads', 'tasks', 'reviews', 'plans', 'marketing'])
deny('R_TEACHER', TEACHER, ['sales', 'pool'])

// ---------- 4. R_SERVICE（服务老师）：看不到 plans ----------
deny('R_SERVICE', SERVICE, ['plans'])
allow('R_SERVICE', SERVICE, ['todo', 'members', 'newMembers', 'leads', 'tasks', 'reviews', 'marketing'])
deny('R_SERVICE', SERVICE, ['approvals', 'sales', 'pool'])

// ---------- 5. R_MANAGER（店长）：看不到 pool（仅超管） ----------
deny('R_MANAGER', MANAGER, ['pool'])
allow('R_MANAGER', MANAGER, ['todo', 'members', 'newMembers', 'leads', 'tasks', 'approvals', 'reviews', 'plans', 'marketing', 'sales', 'profileEdit', 'notifications', 'password'])
check('R_MANAGER 全量减去 pool', keysOf(MANAGER).length === ALL_KEYS.length - 1,
  `${keysOf(MANAGER).length}/${ALL_KEYS.length} 缺=${ALL_KEYS.filter((k) => !has(MANAGER, k)).join(',')}`)

// ---------- 6. 多角色叠加（并集，不是交集） ----------
check('R_SERVICE+R_TEACHER 叠加后能看到 plans', has(['R_SERVICE', 'R_TEACHER'], 'plans'), JSON.stringify(keysOf(['R_SERVICE', 'R_TEACHER'])))
check('R_MEDIA+R_TEACHER 叠加后能看到 members（并集而非交集）', has(['R_MEDIA', 'R_TEACHER'], 'members'), JSON.stringify(keysOf(['R_MEDIA', 'R_TEACHER'])))
check('空角色集合 → 无任何入口', keysOf([]).length === 0, JSON.stringify(keysOf([])))
check('undefined 角色 → 无任何入口', visibleFeatures(undefined).length === 0, `${visibleFeatures(undefined).length}`)

// ---------- 7. 面积分区与 canUse 一致性 ----------
check('canUse(R_SUPER,pool)=true 且 canUse(R_MANAGER,pool)=false',
  canUse(SUPER, 'pool') === true && canUse(MANAGER, 'pool') === false,
  `super=${canUse(SUPER, 'pool')} manager=${canUse(MANAGER, 'pool')}`)
check('canUse 对未登记 key 返回 false', canUse(SUPER, '__not_exist__') === false)
check('visibleFeatures 按板块过滤与全量集合一致',
  ['crm', 'biz', 'ai', 'me'].every((a) => {
    const byArea = areaKeys(SUPER, a)
    const expect = ALL_KEYS.filter((k) => FEATURES[k].area === a)
    return JSON.stringify(byArea) === JSON.stringify(expect)
  }),
  ['crm', 'biz', 'ai', 'me'].map((a) => `${a}:${areaKeys(SUPER, a).join('/')}`).join(' '))

// ---------- 8. 主角色与标签 ----------
check('primaryRole 优先级 超管>店长>服务>授课>新媒体',
  primaryRole(['R_MEDIA', 'R_TEACHER', 'R_SERVICE', 'R_MANAGER', 'R_SUPER']) === 'R_SUPER' &&
  primaryRole(['R_MEDIA', 'R_TEACHER']) === 'R_TEACHER' &&
  primaryRole(['R_MEDIA']) === 'R_MEDIA' &&
  primaryRole([]) === null,
  String(primaryRole(['R_MEDIA', 'R_TEACHER', 'R_SERVICE', 'R_MANAGER', 'R_SUPER'])))
check('roleLabel 空角色 → 未分配角色', roleLabel([]) === '未分配角色', roleLabel([]))
check('isSuper / isManagerOrAbove / isMediaOnly 口径',
  isSuper(SUPER) && !isSuper(MANAGER) &&
  isManagerOrAbove(MANAGER) && !isManagerOrAbove(SERVICE) &&
  isMediaOnly(['R_MEDIA']) && !isMediaOnly(['R_MEDIA', 'R_TEACHER']),
  `isSuper(M)=${isSuper(MANAGER)} isMediaOnly(media+teacher)=${isMediaOnly(['R_MEDIA', 'R_TEACHER'])}`)

// ---------- 9. 注册表路径必须在 pages.json 里真实注册 ----------
{
  const pagesJson = JSON.parse(readFileSync(resolve(__dirname, '../src/pages.json'), 'utf8'))
  const registered = new Set((pagesJson.pages || []).map((p) => '/' + p.path))
  // W7-2：分包页也算注册（route = /<root>/<path>），否则 20 个分包页会被误报「未注册」
  for (const sub of pagesJson.subPackages ?? []) {
    for (const p of sub.pages) registered.add('/' + sub.root + '/' + p.path)
  }
  const tabPages = new Set((pagesJson.tabBar?.list || []).map((t) => '/' + t.pagePath))
  const missing = Object.values(FEATURES)
    .map((f) => f.path.split('?')[0])
    .filter((p) => !registered.has(p))
  check('FEATURES 的所有 path 都在 pages.json 注册', missing.length === 0, missing.join(',') || 'all-registered')
  const tabFeature = Object.values(FEATURES).filter((f) => tabPages.has(f.path.split('?')[0])).map((f) => f.key)
  check('没有功能入口直接指向 tabBar 页（会触发 switchTab 报错）', tabFeature.length === 0, tabFeature.join(',') || 'none')
}

// ---------- 汇总 ----------
const failed = results.filter((r) => !r.ok)
const report = {
  generatedAt: new Date().toISOString(),
  source: 'miniapp/src/utils/permission.ts',
  featureCount: ALL_KEYS.length,
  featureKeys: ALL_KEYS,
  roles: {
    R_SUPER: keysOf(SUPER),
    R_MANAGER: keysOf(MANAGER),
    R_SERVICE: keysOf(SERVICE),
    R_TEACHER: keysOf(TEACHER),
    R_MEDIA: keysOf(MEDIA)
  },
  areas: {
    crm: ALL_KEYS.filter((k) => FEATURES[k].area === 'crm'),
    biz: ALL_KEYS.filter((k) => FEATURES[k].area === 'biz'),
    ai: ALL_KEYS.filter((k) => FEATURES[k].area === 'ai'),
    me: ALL_KEYS.filter((k) => FEATURES[k].area === 'me')
  },
  total: results.length,
  passed: results.length - failed.length,
  failed: failed.length,
  ok: failed.length === 0,
  cases: results
}
writeFileSync(resolve(OUT, 'permission-matrix.json'), JSON.stringify(report, null, 2) + '\n', 'utf8')
console.log(`\n${report.passed}/${report.total} passed → verify/out/permission-matrix.json`)
if (failed.length) {
  console.error('FAILED:\n' + failed.map((f) => ` - ${f.name} :: ${f.detail}`).join('\n'))
  process.exit(1)
}
