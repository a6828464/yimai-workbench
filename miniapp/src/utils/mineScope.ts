/**
 * 「只看我负责的」列表开关（口径唯一来源）
 *
 * ## 为什么要有这个开关
 *
 * 2026-11 员工真机反馈（服务老师在群里提的）：
 *   「会员管理包括留资管理里面，都会出现不属于自己归属的客户」。
 *
 * 系统的可见范围是**并集**：本人归属 ∪ 授课关系（`scopeCustomersForUser()` /
 * `scopeLeadsForUser()`）。授课老师会看到自己上过私教课的学员、上过体验课的客资 ——
 * 那些人的会籍顾问/负责人往往是别人。这个并集本身是对的（上课要看学员卡项、出勤、
 * 有没有过敏史），所以答案不是砍通道，而是给列表一个**叠加式**的收窄开关：
 * 打开就只留「我负责的」，关掉回到原并集。
 *
 * ## 口径
 *
 * - 会员：`consultant`（会籍顾问）或 `owner`（负责人）命中本人 → 我负责的；
 * - 留资：`service_teacher`（服务老师）命中本人 → 我负责的；
 * - **不含**私教学员、体验课老师（那是「我教过的」，不是「我负责的」）。
 * - 后端是纯叠加（角色范围 ∩ 我负责），所以这个开关**不可能放大**任何人的可见范围。
 *
 * ## 只对「按人」角色显示
 *
 * 店长看本店全部、超管看双店、新媒体看部门客资 —— 对他们来说「我负责的」不是产品语言，
 * 给了只会让页面多一个点了没用的开关。判据与后端 `userIsTeacherSide()` 对齐
 * （`R_SERVICE` / `R_TEACHER`），不是按页面各猜一份。
 */
import { computed, ref } from 'vue'
import { hasAnyRole, type RoleCode } from './permission'
import { getJSON, setJSON } from './storage'
import { useUserStore } from '@/store/user'

/** 持久化键：与门店筛选一样跨页面、跨冷启动共用（员工第二天进来还是上次的选择） */
export const MINE_SCOPE_STORAGE_KEY = 'yimai-list-scope-mine'

/** 「按人」角色：只有他们才有「我负责的客户」这一说 */
const PERSONAL_ROLES: RoleCode[] = ['R_SERVICE', 'R_TEACHER']

/** 该账号是否显示这个开关 */
export function canScopeMine(roles: string[] | undefined | null): boolean {
  return hasAnyRole(roles, PERSONAL_ROLES)
}

function readPicked(): boolean {
  return getJSON<boolean>(MINE_SCOPE_STORAGE_KEY) === true
}

function savePicked(value: boolean): void {
  setJSON(MINE_SCOPE_STORAGE_KEY, value)
}

/**
 * 页面级开关状态。
 *
 * 每个页面各建一份 ref，但读写同一个 storage 键 —— 与 `useVenueFilter()` 同一模式：
 * 页面之间不需要互相 import 状态，只要都调这个 hook，切页回来就是一致的口径。
 */
export function useMineScope() {
  const user = useUserStore()
  const visible = computed(() => canScopeMine(user.roles))

  // 账号不是按人角色时强制关掉：否则换账号登录后 storage 里的 true 会残留，
  // 而请求带了 scope=mine 又看不到开关 → 页面「莫名少了一堆人」且无法恢复
  const onlyMine = ref<boolean>(visible.value && readPicked())

  /** 请求参数：关掉 / 不可见时**不带** scope（保持后端默认并集） */
  const scopeParam = computed(() => (visible.value && onlyMine.value ? 'mine' : undefined))

  function toggle(): void {
    if (!visible.value) return
    onlyMine.value = !onlyMine.value
    savePicked(onlyMine.value)
  }

  /** 显式设置（深链 / 恢复默认时用） */
  function apply(raw: boolean): void {
    if (!visible.value) {
      onlyMine.value = false

      return
    }
    onlyMine.value = raw
    savePicked(raw)
  }

  /** 写回 storage（某些页面用 picker/checkbox 双向绑定，改完调一次） */
  function commit(): void {
    if (!visible.value) {
      onlyMine.value = false
      savePicked(false)

      return
    }
    savePicked(onlyMine.value)
  }

  return { visible, onlyMine, scopeParam, toggle, apply, commit }
}
