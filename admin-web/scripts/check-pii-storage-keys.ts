/**
 * 校验脚本：登出 PII 清理口径是否与「持久化写入端」一致
 *
 * 背景（缺陷）：持久化插件在 store/index.ts 里用
 *   key: (storeId) => storageKeyManager.getStorageKey(storeId)
 * 生成落盘键，实际键形如 sys-v{VITE_VERSION}-yimai-store；
 * 而登出清理若硬编码裸键 'yimai-store'，则永远清不到真正落盘的键，
 * 且 VITE_VERSION 每版必改 → 旧版本前缀的 PII 键永久累积。
 *
 * 本脚本把「写入端口径」与「清理端口径」对拍，任意一端漂移就以非 0 退出：
 *
 *  1) 写入端：从每个 store 模块源码抽出真实 persist.key，并断言 store/index.ts
 *     确实用 StorageKeyManager.getStorageKey 派生落盘键。
 *  2) 清理端：解析 clearBusinessPiiStorage() 源码，断言它不再硬编码裸键，
 *     并确实委托给 StorageKeyManager.purgeBusinessPiiKeys。
 *  3) 口径一致性：BUSINESS_PII_STORE_IDS 与「源码里声明的 PII store」双向对齐
 *     （声明了却没进清理名单 / 名单里有源码中不存在的键，都算漂移）。
 *  4) 行为验证：用假存储放入 当前版本键 / 历史版本键 / 裸键 / 训练计划按用户键 /
 *     非 PII 键，跑真实 purgeBusinessPiiKeys，断言 PII 全清、非 PII 保留。
 *  5) 自检（防「空检查」）：对「旧版裸键清理」这一坏样本跑同一套断言，
 *     必须被判为「有未覆盖的 PII 键」，否则说明检查器本身失效 → 同样非 0 退出。
 *
 * 用法：cd admin-web && ./node_modules/.bin/tsx scripts/check-pii-storage-keys.ts
 *
 * 不引入任何新依赖（仅用仓库已有的 vite / tsx）。
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { loadEnv } from 'vite'

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(SCRIPT_DIR, '..')

const color = {
  reset: '\x1b[0m',
  bold: '\x1b[1m',
  red: '\x1b[31m',
  green: '\x1b[32m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  gray: '\x1b[90m'
}

const failures: string[] = []
const notes: string[] = []

function log(message = ''): void {
  console.log(message)
}

function pass(message: string): void {
  log(`  ${color.green}✓${color.reset} ${message}`)
}

function fail(message: string): void {
  failures.push(message)
  log(`  ${color.red}✗${color.reset} ${message}`)
}

function info(message: string): void {
  log(`  ${color.gray}·${color.reset} ${message}`)
}

function section(title: string): void {
  log()
  log(`${color.bold}${color.blue}${title}${color.reset}`)
}

function readSource(relativePath: string): string {
  return fs.readFileSync(path.resolve(ROOT, relativePath), 'utf-8')
}

/** 取出某个函数体的源码（按花括号配平），用于校验清理函数内部实现 */
function extractFunctionBody(source: string, signature: string): string | null {
  const start = source.indexOf(signature)
  if (start === -1) return null

  const openIndex = source.indexOf('{', start)
  if (openIndex === -1) return null

  let depth = 0
  for (let i = openIndex; i < source.length; i++) {
    if (source[i] === '{') depth++
    else if (source[i] === '}') {
      depth--
      if (depth === 0) return source.slice(openIndex, i + 1)
    }
  }
  return null
}

/**
 * localStorage 替身（Node 环境没有 DOM）
 *
 * 关键：真实 localStorage 会把每个键挂成**自有可枚举属性**，
 * 因此 `Object.keys(localStorage)` 能列出全部键（仓库内多处依赖该行为，
 * 例如 purgeBusinessPiiKeys / findExistingKey）。
 * 本替身同样把键设为自有可枚举属性，保证验证的是真实语义。
 */
class FakeStorage {
  [key: string]: unknown

  get length(): number {
    return Object.keys(this).length
  }

  key(index: number): string | null {
    return Object.keys(this)[index] ?? null
  }

  getItem(key: string): string | null {
    return Object.prototype.hasOwnProperty.call(this, key) ? String(this[key]) : null
  }

  setItem(key: string, value: string): void {
    Object.defineProperty(this, key, {
      value: String(value),
      writable: true,
      enumerable: true,
      configurable: true
    })
  }

  removeItem(key: string): void {
    delete this[key]
  }

  keys(): string[] {
    return Object.keys(this)
  }
}

/**
 * 从若干 store 模块源码里抽出所有 persist.key
 * （这些模块 import 了 vue/router，Node 下无法直接 import，故按源码抽取）
 */
function parsePersistKeys(source: string): string[] {
  const keys: string[] = []
  const pattern = /persist:\s*\{([\s\S]*?)\n\s*\}/g
  let match: RegExpExecArray | null

  while ((match = pattern.exec(source)) !== null) {
    const keyMatch = match[1].match(/\bkey:\s*'([^']+)'/)
    if (keyMatch) keys.push(keyMatch[1])
  }
  return keys
}

async function main(): Promise<void> {
  log()
  log(`${color.bold}PII 持久化清理口径校验${color.reset} ${color.gray}(admin-web)${color.reset}`)

  // ---------------------------------------------------------------------
  // 版本解析：与 vite 构建一致（__APP_VERSION__ = VITE_VERSION）
  // ---------------------------------------------------------------------
  const env = loadEnv('development', ROOT)
  const version = env.VITE_VERSION
  if (!version) {
    log(`${color.red}致命：.env 未定义 VITE_VERSION，无法确定写入端键名口径${color.reset}`)
    process.exit(1)
  }
  Object.defineProperty(globalThis, '__APP_VERSION__', { value: version, configurable: true })

  const { StorageConfig } = await import('../src/utils/storage/storage-config')
  const { StorageKeyManager } = await import('../src/utils/storage/storage-key-manager')

  // Node 无 DOM：在调用任何读写 localStorage 的 API 前注入替身
  // （getStorageKey / purgeBusinessPiiKeys 都会读全局 localStorage）
  Object.defineProperty(globalThis, 'localStorage', {
    value: new FakeStorage(),
    configurable: true,
    writable: true
  })

  section('1. 写入端（持久化插件实际落盘键）')

  const writerSource = readSource('src/store/index.ts')
  if (
    /key:\s*\(storeId(: string)?\)\s*=>\s*storageKeyManager\.getStorageKey\(storeId\)/.test(
      writerSource
    )
  ) {
    pass('store/index.ts 由 storageKeyManager.getStorageKey(storeId) 派生落盘键')
  } else {
    fail('store/index.ts 的持久化 key 生成方式已变，请同步本脚本与清理端')
  }

  // getStorageKey 是写入端真实调用，用它算出每个 store 的实际落盘键
  const manager = new StorageKeyManager()
  const piiStoreIds = [...StorageConfig.BUSINESS_PII_STORE_IDS]
  const piiPrefixes = [...StorageConfig.BUSINESS_PII_KEY_PREFIXES]
  const writerKeys: Record<string, string> = {}
  for (const storeId of piiStoreIds) {
    writerKeys[storeId] = manager.getStorageKey(storeId)
  }

  if (writerKeys['yimai-store'] === StorageConfig.generateStorageKey('yimai-store')) {
    pass(`写入端键名 = StorageConfig.generateStorageKey()：${writerKeys['yimai-store']}`)
  } else {
    fail(`写入端键名 ${writerKeys['yimai-store']} 与 generateStorageKey() 不一致`)
  }

  // ---------------------------------------------------------------------
  // 2. 源码声明的 PII store 与清理名单双向对齐
  // ---------------------------------------------------------------------
  section('2. 口径一致性（源码 persist.key ↔ 清理名单）')

  const storeModules = [
    'src/store/modules/ai-config.ts',
    'src/store/modules/sales.ts',
    'src/store/modules/setting.ts',
    'src/store/modules/table.ts',
    'src/store/modules/user.ts',
    'src/store/modules/worktab.ts',
    'src/store/modules/yimai.ts'
  ]

  const declaredPiiKeys = new Set<string>()
  for (const modulePath of storeModules) {
    const keys = parsePersistKeys(readSource(modulePath))
    if (keys.length === 0) {
      fail(`${modulePath} 未解析到 persist.key（解析规则可能已失效）`)
      continue
    }
    info(`${modulePath} → ${keys.join(', ')}`)
    const isPii = piiStoreIds.some((storeId) => keys.includes(storeId))
    if (isPii) keys.forEach((key) => declaredPiiKeys.add(key))
  }

  for (const storeId of piiStoreIds) {
    if (declaredPiiKeys.has(storeId)) {
      pass(`清理名单覆盖源码声明的 PII store：${storeId}`)
    } else {
      fail(`清理名单含 ${storeId}，但没有任何 store 模块声明该 persist.key（名单已漂移）`)
    }
  }

  // 训练计划按用户维度分键，靠前缀表达
  const trainingSource = readSource('src/store/modules/training.ts')
  const trainingPrefix = trainingSource.match(/STORAGE_PREFIX\s*=\s*'([^']+)'/)?.[1]
  if (trainingPrefix && piiPrefixes.includes(trainingPrefix.replace(/:user:$/, ''))) {
    pass(`训练计划按用户键前缀已纳入清理：${trainingPrefix}{userId}`)
  } else if (trainingPrefix) {
    fail(`训练计划前缀 ${trainingPrefix} 未纳入 BUSINESS_PII_KEY_PREFIXES`)
  } else {
    fail('未能在 training.ts 中解析 STORAGE_PREFIX，清理口径可能已失效')
  }

  // ---------------------------------------------------------------------
  // 3. 清理端实现：不得硬编码裸键
  // ---------------------------------------------------------------------
  section('3. 清理端实现（clearBusinessPiiStorage）')

  const userSource = readSource('src/store/modules/user.ts')
  const cleanupBody = extractFunctionBody(userSource, 'export function clearBusinessPiiStorage()')
  if (!cleanupBody) {
    fail('user.ts 中未找到 clearBusinessPiiStorage() 函数体')
  } else {
    const bareKeyLiterals = [...cleanupBody.matchAll(/['"](yimai-[a-z0-9:-]*)['"]/g)].map(
      (m) => m[1]
    )
    if (bareKeyLiterals.length === 0) {
      pass('clearBusinessPiiStorage() 内不再出现硬编码 yimai-* 裸键字面量')
    } else {
      fail(
        `clearBusinessPiiStorage() 仍硬编码裸键 ${bareKeyLiterals.join(', ')}；` +
          '持久化键带 sys-v{版本}- 前缀，裸键清理无效'
      )
    }

    if (/purgeBusinessPiiKeys\s*\(/.test(cleanupBody)) {
      pass(
        'clearBusinessPiiStorage() 委托 StorageKeyManager.purgeBusinessPiiKeys()（与写入端同源）'
      )
    } else {
      fail('clearBusinessPiiStorage() 未委托 StorageKeyManager.purgeBusinessPiiKeys()')
    }

    if (/localStorage\.removeItem\s*\(\s*['"]/.test(cleanupBody)) {
      fail('clearBusinessPiiStorage() 仍直接 localStorage.removeItem(字面量键)')
    }
  }

  // ---------------------------------------------------------------------
  // 4. 行为验证：真实 purge 对各类键的实际效果
  // ---------------------------------------------------------------------
  section('4. 行为验证（当前版本 / 历史版本 / 裸键 / 非 PII）')

  const currentKey = StorageConfig.generateStorageKey('yimai-store')
  const legacyKeys = [
    StorageConfig.generateStorageKey('yimai-store', '3.1.30'),
    StorageConfig.generateStorageKey('yimai-ai-config', '3.1.30'),
    StorageConfig.generateStorageKey('yimai-sales-store', '3.1.44')
  ]
  // 版本号带连字符的形态：createKeyPattern 的 [^-]+ 会静默漏匹配，
  // 必须同样被清理，否则该键永远无清理路径（PII 残留）
  const hyphenVersionKeys = [
    StorageConfig.generateStorageKey('yimai-store', '3.1.44-beta'),
    StorageConfig.generateStorageKey('yimai-ai-config', '3.1.44-rc.1')
  ]
  const bareKeys = ['yimai-store', 'yimai-ai-config', 'yimai-sales-store']
  const trainingKeys = ['yimai-training-store:user:7', 'yimai-training-store']
  const nonPiiKeys = [
    StorageConfig.generateStorageKey('setting'),
    StorageConfig.generateStorageKey('table'),
    StorageConfig.generateStorageKey('worktab'),
    StorageConfig.generateStorageKey('userStore'),
    'sys-theme',
    'sys-version',
    'backend-token'
  ]

  const expectedPurged = [
    ...new Set([currentKey, ...legacyKeys, ...hyphenVersionKeys, ...bareKeys, ...trainingKeys])
  ]

  const storage = new FakeStorage()
  for (const key of [...expectedPurged, ...nonPiiKeys]) {
    storage.setItem(key, JSON.stringify({ phone: '13800000000' }))
  }
  info(`种植 ${storage.length} 个键（PII ${expectedPurged.length} / 非 PII ${nonPiiKeys.length}）`)

  const purged = manager.purgeBusinessPiiKeys(storage)

  const survivingPii = expectedPurged.filter((key) => storage.getItem(key) !== null)
  if (survivingPii.length === 0) {
    pass(`PII 键全部清除（含当前版本 ${currentKey} 与 ${legacyKeys.length} 个历史版本残留）`)
  } else {
    fail(`仍有 PII 键残留：${survivingPii.join(', ')}`)
  }

  const missingExpected = expectedPurged.filter((key) => !purged.includes(key))
  if (missingExpected.length === 0) {
    pass(`清理返回值与预期一致（${purged.length} 个键）`)
  } else {
    fail(`预期应清理但未处理：${missingExpected.join(', ')}`)
  }

  const wronglyPurged = nonPiiKeys.filter((key) => storage.getItem(key) === null)
  if (wronglyPurged.length === 0) {
    pass(`非 PII 键未被误删（${nonPiiKeys.length} 个保留，含登录态 backend-token）`)
  } else {
    fail(`误删了非 PII 键：${wronglyPurged.join(', ')}`)
  }

  if (purged.includes(currentKey)) {
    pass('写入端当前版本键已被清理覆盖（同源派生，不随 VITE_VERSION 漂移）')
  } else {
    fail(`写入端实际键 ${currentKey} 未被清理覆盖`)
  }

  // 连字符版本：这正是 createKeyPattern 的 [^-]+ 会漏掉的形态
  const missedByPattern = hyphenVersionKeys.filter((key) =>
    StorageConfig.createKeyPattern('yimai-store').test(key)
  )
  if (missedByPattern.length === 0) {
    info('（已确认 createKeyPattern 无法匹配连字符版本键，由 matchesStoreIdKey 兜底）')
  }
  const survivingHyphen = hyphenVersionKeys.filter((key) => storage.getItem(key) !== null)
  if (survivingHyphen.length === 0) {
    pass(`连字符版本键同样被清理覆盖（${hyphenVersionKeys.join(', ')}）`)
  } else {
    fail(`连字符版本键残留：${survivingHyphen.join(', ')}；createKeyPattern 的 [^-]+ 会静默漏匹配`)
  }

  // ---------------------------------------------------------------------
  // 5. 自检：坏样本必须被同一套断言判为「未覆盖」
  // ---------------------------------------------------------------------
  section('5. 检查器自检（防空检查）')

  // 旧实现：只 removeItem 裸键 → 当前版本键必然漏掉
  const legacyBadCleanup = (target: FakeStorage): string[] => {
    const doomed = bareKeys.filter((key) => target.getItem(key) !== null)
    doomed.forEach((key) => target.removeItem(key))
    return doomed
  }

  const badStorage = new FakeStorage()
  for (const key of expectedPurged) badStorage.setItem(key, '{}')
  const badPurged = legacyBadCleanup(badStorage)
  const uncoveredByBad = expectedPurged.filter((key) => !badPurged.includes(key))

  if (uncoveredByBad.length > 0) {
    pass(
      `坏样本（旧裸键清理）被判为漏清理 ${uncoveredByBad.length} 个键，` +
        `含 ${uncoveredByBad[0]} → 断言有效`
    )
  } else {
    fail('自检失败：坏样本未被检测出漏清理，本脚本的断言失去意义')
  }

  // 反向自检：清理名单若漏掉某个 PII store，必须被判为漂移
  const droppedStoreId = piiStoreIds[0]
  const shrunk = StorageKeyManager.collectBusinessPiiKeys(
    [StorageConfig.generateStorageKey(droppedStoreId)],
    piiStoreIds.filter((storeId) => storeId !== droppedStoreId),
    piiPrefixes
  )
  if (shrunk.length === 0) {
    pass(`名单漂移可被检测（剔除 ${droppedStoreId} 后其当前版本键不再被覆盖）`)
  } else {
    fail(`自检失败：剔除 ${droppedStoreId} 后仍被判为覆盖，名单漂移无法检测`)
  }

  // ---------------------------------------------------------------------
  // 结果
  // ---------------------------------------------------------------------
  log()
  const piiSummary = expectedPurged.join(', ')
  info(`当前版本键：${currentKey}`)
  info(`清理覆盖的 PII 键：${piiSummary}`)
  notes.forEach((note) => info(note))

  if (failures.length > 0) {
    log()
    log(`${color.red}${color.bold}✗ 校验失败：${failures.length} 项${color.reset}`)
    failures.forEach((message, index) => log(`   ${index + 1}. ${message}`))
    log()
    process.exit(1)
  }

  log()
  log(`${color.green}${color.bold}✓ 校验通过：清理口径与实际持久化键一致${color.reset}`)
  log()
}

main().catch((error) => {
  log()
  log(
    `${color.red}校验脚本执行异常：${error instanceof Error ? error.message : String(error)}${color.reset}`
  )
  process.exit(1)
})
