import rolesMatrix from '../../../../packages/shared-types/roles.json'
import { AppRouteRecord } from '@/types/router'

/**
 * 具名角色集合：唯一事实来源是仓库根的 `packages/shared-types/roles.json`。
 *
 * 收敛前这里是 5 行字面量数组，与 `admin-web/src/api/yimai.ts` 的 actor()、
 * `miniapp/src/utils/permission.ts` 的同名四组、以及后端 helpers.php 的逐处内联判定
 * 一共四份事实源。现在两端都从这份 JSON 派生，改角色集合只需改 JSON 一处。
 *
 * 铁律：**不要**在这个文件里就地手写 `['R_SUPER', 'R_MANAGER']` 这类数组。
 * `node packages/shared-types/verify-role-matrix.mjs` 会逐条检查每条路由的 meta.roles
 * 是否原样等于某个具名组，就地手写会当场 FAIL。
 *
 * 为什么不改成「后端 /me 下发 features[]」：见 roles.json 的 `_readme`
 * （后端没有功能/入口这个概念；31 条路由 ≠ 15 个小程序功能；packages/ 不进后端发布包）。
 */
const ALL = rolesMatrix.groups.ALL
const OPS = rolesMatrix.groups.OPS
// 老师侧：服务老师（会籍顾问）与授课老师（私教主教练）
const TEACHING = rolesMatrix.groups.TEACHING
const MGMT = rolesMatrix.groups.MGMT
const SUPER = rolesMatrix.groups.SUPER

/**
 * 一麦工作台 · 菜单结构（按业务域分级）
 * 工作台 / 客户经营 / 业务执行 / AI助手 / 数据中心 / 系统管理
 */
export const yimaiRoutes: AppRouteRecord[] = [
  // ---------- 工作台 ----------
  {
    name: 'Yimai',
    path: '/yimai',
    component: '/index/index',
    meta: {
      title: '工作台',
      icon: 'ri:sun-line',
      roles: ALL,
      fixedTab: true
    },
    children: [
      {
        path: 'today',
        name: 'YimaiToday',
        component: '/yimai/today',
        meta: { title: '工作台', icon: 'ri:sun-line', roles: ALL, keepAlive: false }
      },
      {
        // 保持原路径 /yimai/store-select（登录页与老师工作台有跳转）
        path: 'store-select',
        name: 'YimaiStoreSelect',
        component: '/yimai/store-select',
        meta: {
          title: '选择门店',
          icon: 'ri:door-open-line',
          roles: ALL,
          isHide: true,
          isHideTab: true,
          isFullPage: true,
          keepAlive: false
        }
      }
    ]
  },

  // ---------- 客户经营 ----------
  {
    name: 'Crm',
    path: '/crm',
    component: '/index/index',
    meta: {
      title: '客户经营',
      icon: 'ri:user-heart-line',
      roles: ALL
    },
    children: [
      {
        path: '/yimai/todo',
        name: 'YimaiTodo',
        component: '/yimai/todo',
        meta: { title: '今日待办', icon: 'ri:task-line', roles: ALL, keepAlive: false }
      },
      {
        path: '/yimai/members',
        name: 'YimaiMembers',
        component: '/yimai/members',
        meta: { title: '会员管理', icon: 'ri:vip-crown-line', roles: OPS, keepAlive: false }
      },
      {
        path: '/yimai/new-members',
        name: 'YimaiNewMembers',
        component: '/yimai/new-members',
        meta: { title: '新客培养', icon: 'ri:seedling-line', roles: ALL, keepAlive: false }
      },
      {
        path: '/yimai/leads',
        name: 'YimaiLeads',
        component: '/yimai/leads',
        meta: { title: '留资管理', icon: 'ri:radar-line', roles: ALL, keepAlive: false }
      },
      {
        path: '/yimai/customers',
        name: 'YimaiCustomers',
        component: '/yimai/customers',
        meta: { title: '客户经营池', icon: 'ri:database-2-line', roles: SUPER, keepAlive: false }
      }
    ]
  },

  // ---------- 业务执行 ----------
  {
    name: 'Biz',
    path: '/biz',
    component: '/index/index',
    meta: {
      title: '业务执行',
      icon: 'ri:rocket-2-line',
      roles: ALL
    },
    children: [
      {
        path: '/yimai/tasks',
        name: 'YimaiTasks',
        component: '/yimai/tasks',
        meta: { title: '任务中心', icon: 'ri:file-list-3-line', roles: OPS, keepAlive: false }
      },
      {
        path: '/yimai/approvals',
        name: 'YimaiApprovals',
        component: '/yimai/approvals',
        meta: { title: '价格审批', icon: 'ri:shield-check-line', roles: MGMT, keepAlive: false }
      },
      {
        path: '/yimai/post-class',
        name: 'YimaiPostClass',
        component: '/yimai/post-class',
        meta: { title: '课后分析', icon: 'ri-stethoscope-line', roles: OPS, keepAlive: false }
      },
      {
        path: '/yimai/training',
        name: 'YimaiTraining',
        component: '/yimai/training',
        meta: { title: '训练计划', icon: 'ri-heart-pulse-line', roles: TEACHING, keepAlive: false }
      }
    ]
  },

  // ---------- AI 助手 ----------
  {
    name: 'AiTools',
    path: '/ai-tools',
    component: '/index/index',
    meta: {
      title: 'AI 助手',
      icon: 'ri:magic-line',
      roles: ALL
    },
    children: [
      {
        path: '/yimai/marketing',
        name: 'YimaiMarketing',
        component: '/yimai/marketing',
        meta: { title: '营销工具', icon: 'ri:megaphone-line', roles: ALL, keepAlive: false }
      },
      {
        path: '/yimai/sales',
        name: 'YimaiSales',
        component: '/yimai/sales',
        meta: { title: '谈单工具', icon: 'ri:hand-coin-line', roles: MGMT, keepAlive: false }
      }
    ]
  },

  // ---------- 数据中心（仅超管） ----------
  {
    name: 'DataCenter',
    path: '/data-center',
    component: '/index/index',
    meta: {
      title: '数据中心',
      icon: 'ri:bar-chart-grouped-line',
      roles: SUPER
    },
    children: [
      {
        path: '/yimai/analytics',
        name: 'YimaiAnalytics',
        component: '/yimai/analytics',
        meta: { title: '经营看板', icon: 'ri:bar-chart-box-line', roles: SUPER, keepAlive: false }
      },
      {
        path: '/yimai/payroll',
        name: 'YimaiPayroll',
        component: '/yimai/payroll',
        meta: {
          title: '薪酬计算',
          icon: 'ri:money-cny-circle-line',
          roles: SUPER,
          keepAlive: false
        }
      },
      {
        path: '/yimai/sync',
        name: 'YimaiSync',
        component: '/yimai/sync',
        meta: { title: 'KeepYoga同步', icon: 'ri:refresh-line', roles: SUPER, keepAlive: false }
      }
    ]
  },

  // ---------- 系统管理（仅超管） ----------
  {
    name: 'Settings',
    path: '/settings',
    component: '/index/index',
    meta: {
      title: '系统管理',
      icon: 'ri:settings-3-line',
      roles: SUPER
    },
    children: [
      {
        path: '/yimai/accounts',
        name: 'YimaiAccounts',
        component: '/yimai/accounts',
        meta: { title: '人员管理', icon: 'ri:team-line', roles: SUPER, keepAlive: false }
      },
      {
        path: '/yimai/ai-config',
        name: 'YimaiAiConfig',
        component: '/yimai/ai-config',
        meta: { title: '模型配置', icon: 'ri:cpu-line', roles: SUPER, keepAlive: false }
      },
      {
        // 小程序设置：维护两家店在顾客端「到店指引」页展示的副标题/地址/电话/坐标/引导图。
        // 数据走后端 GET/PUT /miniapp/config（门店数量与名称由后端固定，页面上不可增删）。
        path: '/yimai/miniapp-config',
        name: 'YimaiMiniappConfig',
        component: '/yimai/miniapp-config',
        meta: { title: '小程序设置', icon: 'ri:wechat-line', roles: SUPER, keepAlive: false }
      },
      {
        path: '/yimai/audit',
        name: 'YimaiAudit',
        component: '/yimai/audit',
        meta: { title: '人员操作日志', icon: 'ri:history-line', roles: SUPER, keepAlive: false }
      },
      {
        path: '/yimai/system-logs',
        name: 'YimaiSystemLogs',
        component: '/yimai/system-logs',
        meta: { title: '系统日志', icon: 'ri:file-warning-line', roles: SUPER, keepAlive: false }
      },
      {
        path: '/yimai/model-generations',
        name: 'YimaiModelGenerations',
        component: '/yimai/model-generations',
        meta: { title: '模型生成记录', icon: 'ri:sparkling-2-line', roles: SUPER, keepAlive: false }
      },
      {
        path: '/yimai/backup',
        name: 'YimaiBackup',
        component: '/yimai/backup',
        meta: { title: '数据备份', icon: 'ri:database-2-line', roles: SUPER, keepAlive: false }
      },
      {
        path: '/yimai/version',
        name: 'YimaiVersion',
        component: '/yimai/version',
        meta: { title: '版本更新', icon: 'ri:install-line', roles: SUPER, keepAlive: false }
      }
    ]
  },

  // ---------- 个人中心（免权限直达 · 不显示在菜单） ----------
  {
    name: 'Personal',
    path: '/personal',
    component: '/index/index',
    meta: {
      title: '个人中心',
      icon: 'ri:user-3-line',
      roles: ALL,
      isHide: true,
      isHideTab: true
    },
    children: [
      {
        path: '/yimai/profile',
        name: 'YimaiProfile',
        component: '/yimai/profile',
        meta: {
          title: '个人中心',
          icon: 'ri:user-3-line',
          roles: ALL,
          isHide: true,
          keepAlive: false
        }
      }
    ]
  }
]
