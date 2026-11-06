#!/usr/bin/env node
/**
 * 角色 → 功能矩阵枚举 / 跨端一致性证据脚本（不参与任何构建，只用于审计与回归）
 *
 * 存在理由：`roles.json` 是本仓库「角色 → 角色集合 → 功能可见性」的唯一事实来源，
 * 网页端路由与小程序的 FEATURES 注册表都从它派生。这个脚本负责把「派生结果」
 * 反算成一张 5 角色 × N 功能的矩阵，用来证明两件事：
 *
 *   1. **收敛前后逐格一致**：`--dump` 存基线、改完再 dump，`--compare` 逐格比对。
 *   2. **两端真的都从同一份派生**：网页端每条路由的 meta.roles 必须等于
 *      roles.json 里某个具名组（ALL/OPS/TEACHING/MGMT/SUPER）的原样数组，
 *      不允许出现「就地手写一份角色数组」的第四份事实源。
 *
 * 用法（在仓库根执行）：
 *   node packages/shared-types/verify-role-matrix.mjs --dump /tmp/role-matrix.before.json
 *   node packages/shared-types/verify-role-matrix.mjs --dump /tmp/role-matrix.after.json
 *   node packages/shared-types/verify-role-matrix.mjs --compare /tmp/role-matrix.before.json /tmp/role-matrix.after.json
 *
 * 为什么用 esbuild 现打现跑而不是正则抓源码：正则只能证明「文本长得像」，
 * 而这里要证明的是「求值结果」。两者在有人写了条件分支时就会分叉。
 */
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = resolve(__dirname, '../..')

// esbuild 装在 miniapp/node_modules（admin-web 也有一份）；本目录没有 node_modules，
// 所以显式从 miniapp 的 package.json 位置解析，避免脚本自身要装依赖。
const req = createRequire(resolve(ROOT, 'miniapp/package.json'))
const esbuild = req('esbuild')

const TMP = mkdtempSync(resolve(tmpdir(), 'role-matrix-'))

/** 把一个 TS 入口打成 CJS 并 require 出来 */
function loadTs(entry, alias) {
  const outfile = resolve(TMP, entry.replace(/[\\/]/g, '_').replace(/\.ts$/, '') + '.cjs')
  esbuild.buildSync({
    entryPoints: [resolve(ROOT, entry)],
    bundle: true,
    format: 'cjs',
    platform: 'node',
    target: 'node20',
    outfile,
    logLevel: 'silent',
    alias: alias ?? {}
  })
  return req(outfile)
}

const ROLE_ORDER = ['R_SUPER', 'R_MANAGER', 'R_SERVICE', 'R_TEACHER', 'R_MEDIA']

function readRolesJson() {
  try {
    return JSON.parse(readFileSync(resolve(__dirname, 'roles.json'), 'utf8'))
  } catch {
    return null
  }
}

/** 小程序端：FEATURES × 5 角色 */
function enumerateMiniapp() {
  const perm = loadTs('miniapp/src/utils/permission.ts')
  const keys = Object.keys(perm.FEATURES)
  const visible = {}
  for (const role of ROLE_ORDER) {
    visible[role] = perm.visibleFeatures([role]).map((f) => f.key)
  }
  const matrix = {}
  for (const key of keys) {
    matrix[key] = {}
    for (const role of ROLE_ORDER) matrix[key][role] = visible[role].includes(key)
  }
  const featureRoles = {}
  for (const key of keys) featureRoles[key] = perm.FEATURES[key].roles
  return { keys, visible, matrix, featureRoles, areaOf: Object.fromEntries(keys.map((k) => [k, perm.FEATURES[k].area])) }
}

/** 网页端：每条路由/分组的 meta.roles */
function enumerateAdminWeb() {
  const mod = loadTs('admin-web/src/router/modules/yimai.ts', {
    '@': resolve(ROOT, 'admin-web/src')
  })
  const out = []
  const walk = (nodes, parent) => {
    for (const n of nodes) {
      out.push({
        name: n.name ?? null,
        path: n.path,
        parent: parent ?? null,
        title: n.meta?.title ?? null,
        roles: n.meta?.roles ?? null
      })
      if (n.children) walk(n.children, n.name ?? parent)
    }
  }
  walk(mod.yimaiRoutes, null)
  return out
}

function dump(target) {
  const mini = enumerateMiniapp()
  const web = enumerateAdminWeb()
  const roles = readRolesJson()

  const report = {
    generatedAt: new Date().toISOString(),
    rolesJson: roles ? 'present' : 'absent',
    roleOrder: ROLE_ORDER,
    miniapp: { keys: mini.keys, visible: mini.visible, matrix: mini.matrix, featureRoles: mini.featureRoles },
    adminWeb: { routes: web }
  }

  // ---- 校验 A：roles.json 派生的矩阵 == 小程序求值矩阵（roles.json 存在时） ----
  const checks = []
  if (roles) {
    const groups = roles.groups
    let mismatch = 0
    for (const key of mini.keys) {
      const groupName = roles.featureGroups[key]
      const expect = groups[groupName] ?? null
      const actual = mini.featureRoles[key]
      const same = Array.isArray(expect) && JSON.stringify(expect) === JSON.stringify(actual)
      if (!same) {
        mismatch += 1
        checks.push({ name: `小程序 ${key} 的 roles 与 roles.json(${groupName}) 不一致`, ok: false, detail: `json=${JSON.stringify(expect)} code=${JSON.stringify(actual)}` })
      }
    }
    checks.push({
      name: '小程序每个功能的 roles 都等于 roles.json 里它声明的组',
      ok: mismatch === 0,
      detail: mismatch ? `${mismatch} 个不一致` : `${mini.keys.length} 项全一致`
    })

    // 反向：roles.json 声明了但 FEATURES 没登记
    const extra = Object.keys(roles.featureGroups).filter((k) => !mini.keys.includes(k))
    checks.push({
      name: 'roles.json 里没有「声明了却不存在的功能」',
      ok: extra.length === 0,
      detail: extra.join(',') || 'none'
    })

    // ---- 校验 B：网页端每条路由的 roles 必须原样等于某个具名组 ----
    const groupEntries = Object.entries(groups)
    const adHoc = []
    for (const r of web) {
      if (!Array.isArray(r.roles)) continue
      const hit = groupEntries.find(([, arr]) => JSON.stringify(arr) === JSON.stringify(r.roles))
      if (!hit) adHoc.push(`${r.name}:${JSON.stringify(r.roles)}`)
    }
    checks.push({
      name: '网页端每条路由的 meta.roles 都等于 roles.json 里某个具名组（无就地手写）',
      ok: adHoc.length === 0,
      detail: adHoc.join(' | ') || 'all-from-shared'
    })

    // ---- 校验 C：没有角色码逃出 roles.json 的白名单 ----
    const known = new Set(roles.roles)
    const unknown = new Set()
    for (const arr of [...groupEntries.map(([, a]) => a), ...web.map((r) => r.roles).filter(Boolean)]) {
      for (const r of arr) if (!known.has(r)) unknown.add(r)
    }
    checks.push({
      name: '出现的角色码都在 roles.json.roles 白名单内',
      ok: unknown.size === 0,
      detail: [...unknown].join(',') || 'none'
    })
  }

  // ---- 校验 D：会员清单「页签 → 清单名」映射与后端 $lists 键逐字一致 ----
  // 这一条是 X-2 的硬证据：前端只剩名字映射，名字对不上时后端 filteredIds() 会
  // **静默返回空数组**（memberListIds()[$list] ?? []），页面上表现为「页签有、列表空」。
  {
    const tabSrc = readFileSync(resolve(ROOT, 'miniapp/src/api/members.ts'), 'utf8')
    const tabBlock = tabSrc.match(/MEMBER_LIST_OF_TAB[^{]*\{([\s\S]*?)\n\}/)
    // 键是标识符（renewal: '待续课'），值是单引号中文字符串
    const front = tabBlock ? [...tabBlock[1].matchAll(/(\w+)\s*:\s*'([^']+)'/g)].map((m) => [m[1], m[2]]) : []
    const phpSrc = readFileSync(resolve(ROOT, 'backend/app/Support/helpers.php'), 'utf8')
    // $lists 在 computeMemberLists() 里是**单行**赋值（含若干个 [] 空数组，不能用 [^\]]*）
    const phpBlock = phpSrc.match(/\$lists = \[[^\n]*\];/)
    const back = phpBlock ? [...phpBlock[0].matchAll(/'([^']+)'\s*=>/g)].map((m) => m[1]) : []
    const frontNames = front.map(([, v]) => v)
    const missing = frontNames.filter((n) => !back.includes(n))
    const extra = back.filter((n) => !frontNames.includes(n))
    checks.push({
      name: 'miniapp MEMBER_LIST_OF_TAB 的清单名 ⊆ 后端 computeMemberLists() 的 $lists 键',
      ok: front.length > 0 && back.length > 0 && missing.length === 0,
      detail: `前端 ${front.length} 项 / 后端 ${back.length} 项 / 前端多出=${missing.join(',') || 'none'} / 后端独有=${extra.join(',') || 'none'}`
    })
    report.memberListOfTab = { front: Object.fromEntries(front), backendListKeys: back }
  }

  report.checks = checks

  // ---- 打印 ----
  const pad = (s, n) => s + ' '.repeat(Math.max(0, n - [...s].reduce((w, c) => w + (c.charCodeAt(0) > 127 ? 2 : 1), 0)))
  console.log('## 小程序 FEATURES × 角色矩阵（✅ 可见 / ❌ 不可见）\n')
  console.log('| 功能 | ' + ROLE_ORDER.map((r) => pad(r.replace('R_', ''), 8)).join(' | ') + ' | 角色组 |')
  console.log('|' + '---|'.repeat(ROLE_ORDER.length + 2))
  for (const key of mini.keys) {
    const cells = ROLE_ORDER.map((r) => pad(mini.matrix[key][r] ? '✅' : '❌', 8))
    const groupOf = roles ? (roles.featureGroups[key] ?? '—') : '—'
    console.log(`| ${pad(key, 14)} | ${cells.join(' | ')} | ${groupOf} |`)
  }
  console.log('')
  for (const r of ROLE_ORDER) console.log(`- ${r}: ${mini.visible[r].length}/${mini.keys.length} → ${mini.visible[r].join(',')}`)
  console.log('')
  if (checks.length) {
    for (const c of checks) console.log(`${c.ok ? 'PASS' : 'FAIL'}  ${c.name} :: ${c.detail}`)
    console.log('')
  }

  const failed = checks.filter((c) => !c.ok)
  if (failed.length) process.exitCode = 1

  if (target) {
    writeFileSync(target, JSON.stringify(report, null, 2) + '\n', 'utf8')
    console.log(`→ ${target}`)
  }
  return report
}

/** 逐格比对两份矩阵 */
function compare(beforePath, afterPath) {
  const before = JSON.parse(readFileSync(beforePath, 'utf8'))
  const after = JSON.parse(readFileSync(afterPath, 'utf8'))
  const rows = []
  const diffs = []

  const keys = [...new Set([...before.miniapp.keys, ...after.miniapp.keys])]
  for (const key of keys) {
    for (const role of ROLE_ORDER) {
      const b = before.miniapp.matrix[key]?.[role]
      const a = after.miniapp.matrix[key]?.[role]
      rows.push({ feature: key, role, before: b, after: a, same: b === a })
      if (b !== a) diffs.push(`${key} × ${role}: ${b} → ${a}`)
    }
  }

  // 网页端路由角色也逐条比对（按 path 对齐）
  const bMap = new Map((before.adminWeb?.routes ?? []).map((r) => [r.path, r.roles]))
  const aMap = new Map((after.adminWeb?.routes ?? []).map((r) => [r.path, r.roles]))
  const routeDiffs = []
  for (const [p, roles] of bMap) {
    if (!aMap.has(p)) routeDiffs.push(`${p}: 改后不存在`)
    else if (JSON.stringify(roles) !== JSON.stringify(aMap.get(p))) {
      routeDiffs.push(`${p}: ${JSON.stringify(roles)} → ${JSON.stringify(aMap.get(p))}`)
    }
  }
  for (const p of aMap.keys()) if (!bMap.has(p)) routeDiffs.push(`${p}: 改后新增`)

  console.log(`## 逐格比对（${rows.length} 格）\n`)
  console.log(`- 小程序矩阵：${diffs.length === 0 ? '完全一致 ✅' : `${diffs.length} 格变化 ❌`}`)
  if (diffs.length) for (const d of diffs) console.log(`  - ${d}`)
  console.log(`- 网页端路由（${bMap.size} 条）：${routeDiffs.length === 0 ? '完全一致 ✅' : `${routeDiffs.length} 条变化 ❌`}`)
  if (routeDiffs.length) for (const d of routeDiffs) console.log(`  - ${d}`)
  console.log('')
  if (diffs.length || routeDiffs.length) {
    console.log('VERDICT: CHANGED')
    process.exitCode = 1
  } else {
    console.log('VERDICT: IDENTICAL')
  }
}

const [mode, arg1, arg2] = process.argv.slice(2)
if (mode === '--compare') {
  compare(arg1, arg2)
} else if (mode === '--dump') {
  dump(arg1)
} else {
  dump(null)
}
