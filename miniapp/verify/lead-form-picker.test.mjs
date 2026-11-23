/**
 * 留资录入页（pkg-crm/leads/form.vue）pickers / 清洗 / 校验的**行为级**验收脚本
 *
 * 为什么要单独写这一份，而不是靠 grep（对比同目录的 fix-round.test.mjs）：
 * 这次的真 bug 是「事件载荷形状对不上」—— grep 只能证明代码里写了 pickerValue，
 * 证明不了 `{detail:{value}}` 与 `{value}` 两种载荷都能取到值、能回显、能落库干净。
 * 所以这里把 form.vue 的 `<script setup>` 原样抽出来（esbuild 去 TS 类型 + 打桩依赖）
 * 后在 node 里跑，直接对**上线的那份函数**断言。
 *
 * 口径边界：
 *  - 只打桩 import（vue 的 ref/computed/watch、uni.*、@/api/* 等），不复制任何业务逻辑；
 *  - 断言失败 = 退出码 1（可做闸门）；--lenient 时只报告不改变退出码。
 *  - 构建产物（pick 绑定方式）由 --dist 段检查：需要先跑过 build:mp-weixin。
 *
 * 用法：
 *   node verify/lead-form-picker.test.mjs
 *   node verify/lead-form-picker.test.mjs --lenient
 */
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = resolve(__dirname, '..')
const OUT = resolve(__dirname, 'out/lead-form-picker')
const LENIENT = process.argv.includes('--lenient')
const requireFromApp = createRequire(resolve(ROOT, 'package.json'))
const { transformSync } = requireFromApp('esbuild')

const SRC = resolve(ROOT, 'src/pkg-crm/leads/form.vue')

let failed = 0
const rows = []
function ok(name, cond, detail) {
  rows.push({ name, ok: Boolean(cond), detail })
  if (!cond) failed += 1
  const tail = detail === undefined ? '' : `  → ${JSON.stringify(detail)}`
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${tail}`)
}
function eq(name, actual, expected) {
  const a = JSON.stringify(actual)
  const e = JSON.stringify(expected)
  ok(name, a === e, a === e ? undefined : { actual, expected })
}

/* ============================ 1. 抽取并打桩运行 ============================ */
const vue = readFileSync(SRC, 'utf8')
const m = /<script setup lang="ts">([\s\S]*?)<\/script>/.exec(vue)
if (!m) {
  console.error('无法定位 <script setup lang="ts">：form.vue 结构变了，本脚本需要同步')
  process.exit(1)
}
let script = m[1]
// 去掉全部 import（依赖全部在下面的桩里）
script = script.replace(/^import[\s\S]*?from\s+'[^']+'\s*$/gm, '')

const stubs = `
const ref = (v) => ({ value: v })
const computed = (fn) => ({ get value() { return fn() } })
const watch = () => () => {}
const onLoad = (fn) => { globalThis.__onLoad = fn }
const onShow = (fn) => { globalThis.__onShow = fn }
const onUnload = (fn) => { globalThis.__onUnload = fn }
const onShareAppMessage = () => {}
const shareToHome = () => ({})
const requireLogin = () => true
const requireFeature = () => true
const goPath = () => {}
const getJSON = () => null
const setJSON = () => {}
const storage = { removeItem() {}, setItem() {}, getItem() {} }
const useUserStore = () => ({ venue: '绿地店', roles: ['老板'] })
const FALLBACK_LEAD_ENUMS = { sources: ['大众点评', '抖音'], orderPlatforms: ['美团'] }
const LEAD_STATUSES = ['新留资', '已联系', '已约体验', '已体验', '已成交', '已流失', '爽约']
const LEAD_TRIAL_TYPES = ['定制私教', '私教小班', '精品团课', '其他']
globalThis.__calls = { create: [], update: [], draftTakes: 0, draft: null }
const checkLead = async () => ({ exists: false, matches: [] })
const createLead = async (p) => { globalThis.__calls.create.push(p); return { id: 1, warnings: [] } }
const updateLead = async (id, p) => { globalThis.__calls.update.push({ id, p }); return { warnings: [] } }
const fetchLeads = async () => ({ enums: null, list: [], total: 0 })
const takeLeadDraft = () => { globalThis.__calls.draftTakes += 1; return globalThis.__calls.draft }
const fetchStaffOptions = async () => []
const matchStaff = (list, kw) => (kw ? list.filter((s) => String(s.name).includes(kw)) : list)
const ROLE_SHORT = {}
const uni = {
  showToast(o) { globalThis.__calls.toast = o },
  showModal(o) { globalThis.__calls.modal = o },
  navigateBack() {},
  setStorageSync() {}, getStorageSync() { return null }, removeStorageSync() {}
}
`

const expose = `
globalThis.__FORM = {
  form, mode, id, origin, saving, submitted, validate, submit,
  pickerValue, cleanText, cleanNumber, splitTimeText, normalizeCardState,
  onCardState, onCardDate, onCardTime, cardStateIndex, cardStateLabel,
  cardDateValue, cardTimeValue, toTrialPayload, cardSignature, hydrate,
  buildCreatePayload, buildPatch, addCard, removeCard, applyDraft, opts
}
`

const code = transformSync(`${stubs}\n${script}\n${expose}`, { loader: 'ts', format: 'cjs' }).code
new Function(code)()
const F = globalThis.__FORM
const calls = globalThis.__calls

/* ============================ 2. 用例 ============================ */
console.log('—— 一、picker 取值：两种载荷形状 ——')

// 原生 <picker>：{detail:{value}}
eq('pickerValue(原生 detail.value)', F.pickerValue({ detail: { value: '19:20' } }), '19:20')
// wd-picker：{value}
eq('pickerValue(wd-picker value)', F.pickerValue({ value: '19:20' }), '19:20')
// 多列 picker：数组取末位
eq('pickerValue(数组取末位)', F.pickerValue({ detail: { value: ['甲', '乙'] } }), '乙')
// 完全空的事件：不能产出 "undefined"
eq('pickerValue({}) 不产出 undefined', F.pickerValue({}), '')
eq('pickerValue(null)', F.pickerValue(null), '')

console.log('—— 二、体验课卡片：时间/日期/状态（真机两处 bug）——')

// 造一张空卡片
F.form.value.trialCards = [
  { session: 1, uid: 1, date: '', time: '', topic: '', teacher: '', couponName: '', voucherCode: '', redeem: '', total: '', attended: false, noShow: false, cancelled: false }
]
F.onCardTime(0, { detail: { value: '19:20' } })
eq('选时间（原生载荷）→ c.time', F.form.value.trialCards[0].time, '19:20')
F.onCardDate(0, { detail: { value: '2026-11-03' } })
eq('选日期（原生载荷）→ c.date', F.form.value.trialCards[0].date, '2026-11-03')
eq('日期+时间拼进提交体 time', F.toTrialPayload(F.form.value.trialCards)[0].time, '2026-11-03 19:20')
ok('格子上不会再出现 undefined', !F.form.value.trialCards[0].time.includes('undefined') && !F.form.value.trialCards[0].date.includes('undefined'))

// 时间补两位（原生 range 可能给 9:20）
F.onCardTime(0, { detail: { value: '9:20' } })
eq('时间补零 → 09:20', F.form.value.trialCards[0].time, '09:20')

// 非法载荷不改值（旧代码会写进 "undefined"）
F.form.value.trialCards[0].time = '08:30'
F.onCardTime(0, {})
eq('空载荷不改已有时间', F.form.value.trialCards[0].time, '08:30')
F.onCardDate(0, { detail: { value: 'undefined' } })
eq('脏载荷不改已有日期', F.form.value.trialCards[0].date, '2026-11-03')

// 状态：原生 range picker 给的是下标数字
F.onCardState(0, { detail: { value: 1 } })
eq('选「已上课」→ 三个布尔', [F.form.value.trialCards[0].attended, F.form.value.trialCards[0].noShow, F.form.value.trialCards[0].cancelled], [true, false, false])
eq('选「已上课」→ 回显文案', F.cardStateLabel(F.form.value.trialCards[0]), '已上课')
F.onCardState(0, { detail: { value: 2 } })
eq('选「已爽约」→ 三态互斥', [F.form.value.trialCards[0].attended, F.form.value.trialCards[0].noShow, F.form.value.trialCards[0].cancelled], [false, true, false])
F.onCardState(0, { detail: { value: 3 } })
eq('选「已取消」→ 回显', [F.cardStateLabel(F.form.value.trialCards[0]), F.form.value.trialCards[0].cancelled], ['已取消', true])
F.onCardState(0, { detail: { value: 0 } })
eq('选「未开始」→ 三个全 false', [F.form.value.trialCards[0].attended, F.form.value.trialCards[0].noShow, F.form.value.trialCards[0].cancelled], [false, false, false])
// 越界/非数字：忽略而不是写坏
F.onCardState(0, { detail: { value: 9 } })
eq('越界下标被忽略', F.cardStateIndex(F.form.value.trialCards[0]), 0)

console.log('—— 三、提交体清洗（trial_cards[*].time 绝不带 undefined）——')

const dirty = [
  { session: 1, uid: 1, date: '2026-11-03', time: '19:20', topic: ' 内观流 ', teacher: 'null', couponName: 'undefined', voucherCode: 'NaN', redeem: 'undefined', total: '12', attended: false, noShow: false, cancelled: false },
  { session: 2, uid: 2, date: '2026-11-05', time: 'undefined', topic: '', teacher: '', couponName: '', voucherCode: '', redeem: '', total: '', attended: false, noShow: false, cancelled: false },
  { session: 3, uid: 3, date: 'undefined', time: 'undefined', topic: '', teacher: '', couponName: '', voucherCode: '', redeem: '0', total: '0', attended: false, noShow: false, cancelled: false }
]
const payload = F.toTrialPayload(dirty)
eq('① 干净卡片 time', payload[0].time, '2026-11-03 19:20')
eq('② 只有日期 → time 只拼日期', payload[1].time, '2026-11-05')
eq('③ 日期与时间都脏 → time 空串', payload[2].time, '')
const textOf = (arr) =>
  arr.flatMap((c) => [c.date, c.time, c.topic, c.teacher, c.couponName, c.voucherCode]).filter(Boolean).join(' | ')
ok('提交体文本字段里没有任何 undefined/null/NaN', !/undefined|null|NaN/.test(textOf(payload)), textOf(payload))
eq('文本字段被清洗（teacher/coupon/voucher）', [payload[0].teacher, payload[0].couponName, payload[0].voucherCode], ['', '', ''])
eq('topic 去首尾空格', payload[0].topic, '内观流')
eq('redeem 脏值 → null', payload[0].redeem, null)
eq('total 正常数字', payload[0].total, 12)
eq('redeem 0 保留为 0（不是 null）', payload[2].redeem, 0)
eq('session 按顺序重排', payload.map((c) => c.session), [1, 2, 3])
eq('date 脏值 → 不提交该键', 'date' in payload[2], false)
eq('redeem/total 脏值 → 真 null（不是 NaN）', [payload[0].redeem, payload[0].total, payload[2].total], [null, 12, 0])
ok('uid 不进提交体', !('uid' in payload[0]))

console.log('—— 四、编辑回填：库里的历史脏值 ——')

// 库里真可能出现的一行："2026-11-03 undefined"
F.hydrate({
  id: 9, name: '张三', venue: '绿地店', source: '抖音', status: '已体验',
  trialCards: [{ session: 1, time: '2026-11-03 undefined', topic: ' 内观流 ', teacher: '王老师', redeem: null, total: null }]
})
const h0 = F.form.value.trialCards[0]
eq('回填：date 拆出来', h0.date, '2026-11-03')
eq('回填：time 里的 undefined 被丢掉', h0.time, '')
eq('回填：topic 清洗', h0.topic, '内观流')
eq('回填后再提交，time 不含 undefined', F.toTrialPayload(F.form.value.trialCards)[0].time, '2026-11-03')
ok('回填后的提交体干净', !/undefined|null|NaN/.test(textOf(F.toTrialPayload(F.form.value.trialCards))), textOf(F.toTrialPayload(F.form.value.trialCards)))

// 全脏的一行
F.hydrate({
  id: 10, name: '李四', venue: '绿地店', source: '抖音', status: '新留资',
  trialCards: [{ session: 1, time: 'undefined', date: 'null', topic: 'NaN', teacher: 'undefined' }]
})
eq('全脏回填 → date/time 都空', [F.form.value.trialCards[0].date, F.form.value.trialCards[0].time], ['', ''])
eq('全脏回填 → 提交体 time 空串', F.toTrialPayload(F.form.value.trialCards)[0].time, '')

// 干净数据 + 三态互斥（库里两条同时为 true 的行）
F.hydrate({
  id: 11, name: '王五', venue: '绿地店', source: '抖音', status: '已体验',
  trialCards: [{ session: 3, time: '2026-11-01 10:00', attended: true, noShow: true, cancelled: true }]
})
const h2 = F.form.value.trialCards[0]
eq('回填：三态归一到优先级最高者', [h2.attended, h2.noShow, h2.cancelled], [false, true, false])
eq('回填：session 按数组重排', h2.session, 1)
eq('回填：回显文案与归一结果一致', F.cardStateLabel(h2), '已爽约')

// 回填 → 不提交（签名一致）：脏值不应被当成「用户改动」
const fixture = {
  id: 12, name: '赵六', venue: '绿地店', source: '抖音', status: '已体验',
  trialCards: [{ session: 1, time: '2026-11-01 10:00', attended: true, noShow: true }]
}
F.hydrate(fixture)
const sig = F.cardSignature(F.form.value.trialCards)
F.hydrate(fixture)
eq('脏库值回填两次 → 签名一致（不会被误判为有改动）', F.cardSignature(F.form.value.trialCards), sig)

// 同一份数据、键顺序不同的原始卡片（后端 JSON 顺序不承诺）→ 签名也必须相等
F.hydrate({ id: 13, name: '甲', venue: '绿地店', source: '抖音', trialCards: [{ session: 1, time: '2026-11-01 10:00', platform: '美团', remaining: 2 }] })
const sigA = F.cardSignature(F.form.value.trialCards)
F.hydrate({ id: 14, name: '乙', venue: '绿地店', source: '抖音', trialCards: [{ remaining: 2, platform: '美团', time: '2026-11-01 10:00', session: 1 }] })
eq('键顺序不同 → 签名仍然相等（不会误判成改动）', F.cardSignature(F.form.value.trialCards), sigA)

console.log('—— 五、校验：脏值不静默提交 ——')

function resetForm(patch = {}) {
  F.form.value = {
    trialCards: [], name: '张三', phone: '', wechat: '', venue: '绿地店', source: '抖音',
    orderPlatform: '', demand: '', serviceTeacher: '', status: '新留资', grade: '',
    dealCard: '', dealAmount: '', couponName: '', voucherCode: '', redeemAmount: '', remark: '',
    ...patch
  }
}
resetForm()
eq('必填齐全 → 通过', F.validate(), '')
resetForm({ phone: '138' })
ok('手机号 3 位 → 拦下', F.validate().includes('11 位'))
resetForm({ phone: '13800000001' })
eq('手机号 11 位 → 通过', F.validate(), '')
resetForm({ dealAmount: '-5' })
ok('负金额 → 拦下', F.validate().includes('不能为负'))
resetForm({ redeemAmount: 'abc' })
ok('非数字金额 → 拦下', F.validate().includes('数字'))
resetForm({
  trialCards: [{ session: 1, uid: 1, date: '', time: '', topic: '', teacher: '', couponName: '', voucherCode: '', redeem: 'x', total: '', attended: false, noShow: false, cancelled: false }]
})
ok('卡片核销额非数字 → 拦下并指出第几节', F.validate().includes('第 1 节'))
resetForm({
  trialCards: [{ session: 1, uid: 1, date: '2026/11/03', time: '', topic: '', teacher: '', couponName: '', voucherCode: '', redeem: '', total: '', attended: false, noShow: false, cancelled: false }]
})
ok('卡片日期格式不对 → 拦下', F.validate().includes('日期格式'))

console.log('—— 六、卡片增删：session 重排 / uid 稳定 ——')

resetForm()
F.addCard(); F.addCard(); F.addCard()
eq('新增三节 → session 1..3', F.form.value.trialCards.map((c) => c.session), [1, 2, 3])
const uidsBefore = F.form.value.trialCards.map((c) => c.uid)
F.removeCard(1)
eq('删中间一节 → session 重排', F.form.value.trialCards.map((c) => c.session), [1, 2])
eq('删除后剩余行的 uid 不变（v-for key 稳定）', F.form.value.trialCards.map((c) => c.uid), [uidsBefore[0], uidsBefore[2]])
F.removeCard(0); F.removeCard(0)
eq('删到空 → 不残留空卡片', F.form.value.trialCards.length, 0)

console.log('—— 七、提交路径：共用映射 / 连点只提交一次 ——')

resetForm()
F.form.value.trialCards = [{ session: 1, uid: 1, date: '2026-11-03', time: '19:20', topic: '', teacher: '', couponName: '', voucherCode: '', redeem: '', total: '', attended: false, noShow: false, cancelled: false }]
const createBody = F.buildCreatePayload()
eq('新建体带体验课卡片（走同一份映射）', createBody.trialCards[0].time, '2026-11-03 19:20')
ok('新建体金额为 null 而不是 NaN', createBody.dealAmount === null && createBody.redeemAmount === null)

// hydrate 后签名一致 → buildPatch 不带 trialCards
F.origin.value = { id: 20, name: '张三', venue: '绿地店', source: '抖音', status: '新留资', trialCards: [{ session: 1, time: '2026-11-03 19:20', topic: '', teacher: '', couponName: '', voucherCode: '', redeem: null, total: null, attended: false, noShow: false, cancelled: false }] }
F.hydrate(F.origin.value)
const patch = F.buildPatch()
eq('未改动卡片 → PATCH 不带 trialCards', patch.trialCards, undefined)
// 只改一节课的状态 → 必须带 trialCards（曾经漏了，导致假成功）
F.onCardState(0, { detail: { value: 1 } })
ok('只改状态 → PATCH 带 trialCards', Array.isArray(F.buildPatch().trialCards))
eq('PATCH 里的卡片状态正确', [F.buildPatch().trialCards[0].attended, F.buildPatch().trialCards[0].noShow], [true, false])

// 连点：第二次 submit 必须被 saving 闸挡住（不会创建两条）
resetForm()
F.mode.value = 'create'
F.saving.value = false
F.submitted.value = false
calls.create.length = 0
const p1 = F.submit()
const p2 = F.submit()
await Promise.all([p1, p2])
eq('连点两次 → 只创建一条留资', calls.create.length, 1)
eq('成功提交后 submitted 置真（挡住 500ms 窗口期的二次点击）', F.submitted.value, true)

/* ============================ 3. 构建产物：picker 绑定方式 ============================ */
console.log('—— 八、构建产物：三处原生 picker 的绑定方式 ——')
const distWxml = resolve(ROOT, 'dist/build/mp-weixin/pkg-crm/leads/form.wxml')
if (!existsSync(distWxml)) {
  ok('构建产物存在（需先 npm run build:mp-weixin）', false, distWxml)
} else {
  const wxml = readFileSync(distWxml, 'utf8')
  const pickers = [...wxml.matchAll(/<picker[^>]*>/g)].map((x) => x[0])
  ok('构建产物里有 3 个原生 picker', pickers.length === 3, pickers.length)
  const joined = pickers.join('\n')
  ok('date picker 绑 mode="date" + bindchange', /mode="date"[^>]*bindchange/.test(joined) || /bindchange[^>]*mode="date"/.test(joined))
  ok('time picker 绑 mode="time" + bindchange', /mode="time"[^>]*bindchange/.test(joined) || /bindchange[^>]*mode="time"/.test(joined))
  ok('状态 picker 绑 range + bindchange', /range="\{\{/.test(joined) && /bindchange/.test(joined))
  ok('原生 picker 上没有 .stop / catch 之类组件事件修饰残留', !/catchchange/.test(joined))
  console.log(pickers.map((p) => `        ${p}`).join('\n'))
}

/* ============================ 4. 产物 ============================ */
mkdirSync(OUT, { recursive: true })
writeFileSync(resolve(OUT, 'lead-form-picker.json'), JSON.stringify({ at: new Date().toISOString(), failed, total: rows.length, rows }, null, 2))
console.log(`\n${failed === 0 ? '✅ 全部通过' : `❌ ${failed} / ${rows.length} 条不通过`}（明细见 verify/out/lead-form-picker/lead-form-picker.json）`)
process.exit(failed === 0 || LENIENT ? 0 : 1)
