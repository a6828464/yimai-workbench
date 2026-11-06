/**
 * 门店筛选口径（唯一来源）
 *
 * ## 为什么要有这个文件
 * v3.11 之前，「能不能切店」在留资页写的是 `isSuper || isMediaOnly`、会员页写的是
 * `isSuper`、新客培养页写的又是 `isSuper` —— 三处判据三份实现，结果就是**拥有两个门店
 * 权限但不是超管的账号，在所有页面都看不到切换标签**（数据被固定在一家店上）。
 *
 * 现在的判据只有一条：**账号授权门店数 > 1**（`user.venues`，后端 /me 下发）。
 * 门店清单、存储键、参数收敛全部收在这个文件里，页面不再各写一份。
 *
 * ## 和「后端最终锁定谁」的关系（不改后端，只能如实标注）
 * | 角色 | 后端实际范围 |
 * |---|---|
 * | 超管 | 全量，`venue` 参数生效 |
 * | 新媒体 | 部门范围（跨店），`venue` 参数生效 |
 * | 店长 | `where venue = 本人门店`（传 venue 无效） |
 * | 服务 / 授课 | `where venue = 本人门店` + 按人（传 venue 无效） |
 * 也就是说：单店账号给了 picker 就是**假选项**（点了没反应），所以仍然只显示锁定标签；
 * 双店账号（本任务要修的这批）必须能切。判据与后端能力在这里对齐，不在页面里猜。
 */
import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { isSuper } from './permission'
import { storage } from './storage'
import { useUserStore } from '@/store/user'

/** 「不筛门店」的哨兵值：选中它时请求里**不带** venue 参数（保持后端默认范围） */
export const VENUE_ALL = '全部门店'

/** 后端 venue 列的真实取值（`*_venues` 校验规则也是这两个字面量） */
export const KNOWN_VENUES: readonly string[] = ['绿地店', '东部店']

/** 选择结果的持久化键：跨页面、跨冷启动共用一份（「我现在在看哪家店」） */
export const VENUE_STORAGE_KEY = 'yimai-venue-filter'

export interface VenueOption {
  label: string
  value: string
}

/**
 * 门店选项：`全部门店` + 该账号被授权的门店。
 *
 * - 超管：后端是全量，选项恒为两家（不因为账号上只勾了一家就把另一家藏掉）；
 * - 其他角色：只列授权门店（后端 `applyVenueScope` 对新媒体就是按 venues 收窄的）；
 * - venues 为空（迁移前建的老账号该列可能为 null）：退回两家，别让选项渲染不出来。
 */
export function venueOptions(
  authorized?: string[] | null,
  roles?: string[] | null
): VenueOption[] {
  const mine = (authorized ?? []).filter((v) => KNOWN_VENUES.includes(v))
  const all = isSuper(roles) || mine.length === 0
  const stores = all ? [...KNOWN_VENUES] : KNOWN_VENUES.filter((v) => mine.includes(v))
  return [{ label: VENUE_ALL, value: VENUE_ALL }, ...stores.map((v) => ({ label: v, value: v }))]
}

/**
 * 是否显示门店切换标签。
 *
 * 主判据（本任务修的就是这条）：**账号授权门店数 > 1** —— 以前按角色判断，
 * 于是「两个门店权限但不是超管」的账号在所有页面都被固定在一家店上。
 *
 * 另留超管一档：超管后端本来就是全量范围，账号上 `venues` 只勾了一家时
 * 不给切换等于把既有能力改坏（这不是「按角色判断」，是超管本就有跨店权限）。
 * 单店的非超管账号仍然不显示：后端锁本店，给了 picker 也是假选项。
 */
export function canSwitchVenue(
  venues?: string[] | null,
  roles?: string[] | null
): boolean {
  if ((venues ?? []).length > 1) return true
  return isSuper(roles)
}

/** 读上次的选择（未选过 / 存储损坏都返回「全部门店」） */
export function readPickedVenue(): string {
  const raw = storage.getItem(VENUE_STORAGE_KEY)
  return raw && raw.trim() !== '' ? raw.trim() : VENUE_ALL
}

/** 落盘上次的选择（失败静默：存不下只是下次回到默认，不影响本次请求） */
export function savePickedVenue(venue: string): void {
  const v = (venue ?? '').trim()
  storage.setItem(VENUE_STORAGE_KEY, v === '' ? VENUE_ALL : v)
}

export interface VenueFilter {
  /** 是否显示切换标签 */
  canSwitch: ComputedRef<boolean>
  /** 全部 / 绿地店 / 东部店（按账号授权收敛） */
  options: ComputedRef<VenueOption[]>
  /** 当前选择（v-model 给 wd-drop-menu-item 用） */
  venue: Ref<string>
  /** 真正放进请求的 venue（「全部门店」/ 单店账号 → undefined） */
  venueParam: ComputedRef<string | undefined>
  /** 单店账号展示用的锁定标签 */
  lockedLabel: ComputedRef<string>
  /** 用户改了选择：落盘（页面自己再 load(true)） */
  commit: () => void
  /** 深链参数（?venue=绿地店）：生效并落盘，返回是否真的切了 */
  apply: (raw?: string | null) => boolean
  /** 重置为「全部门店」 */
  reset: () => void
}

/**
 * 页面里的门店筛选。只能在 setup 顶层调用（内部读 pinia）。
 *
 * 语义：
 *  - 初始值来自 storage → **切页返回、冷启动再进都还是上次那家店**；
 *  - 值不在当前账号的可选集合里（管理员收回权限、门店改名）→ 自动收敛到「全部」；
 *  - 单店账号 `canSwitch=false`、`venueParam=undefined`，页面显示锁定标签。
 */
export function useVenueFilter(): VenueFilter {
  const user = useUserStore()

  const canSwitch = computed(() => canSwitchVenue(user.venues, user.roles))
  const options = computed(() => venueOptions(user.venues, user.roles))

  /** 把任意来源的值收敛成当前合法选项（非法值一律回到「全部门店」） */
  function normalize(raw: unknown): string {
    const v = typeof raw === 'string' ? raw.trim() : ''
    if (!v) return VENUE_ALL
    return options.value.some((o) => o.value === v) ? v : VENUE_ALL
  }

  const venue = ref<string>(normalize(readPickedVenue()))

  const venueParam = computed(() =>
    canSwitch.value && venue.value !== VENUE_ALL ? venue.value : undefined
  )

  const lockedLabel = computed(() => user.venue || (user.venues ?? [])[0] || '本店')

  function commit(): void {
    venue.value = normalize(venue.value)
    savePickedVenue(venue.value)
  }

  function apply(raw?: string | null): boolean {
    const v = normalize(raw)
    if (v === VENUE_ALL) return false
    venue.value = v
    savePickedVenue(v)
    return true
  }

  function reset(): void {
    venue.value = VENUE_ALL
    savePickedVenue(VENUE_ALL)
  }

  return { canSwitch, options, venue, venueParam, lockedLabel, commit, apply, reset }
}
