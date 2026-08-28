import { AppRouteRecord } from '@/types/router'

const ALL = ['R_SUPER', 'R_MANAGER', 'R_TEACHER', 'R_MEDIA']
const OPS = ['R_SUPER', 'R_MANAGER', 'R_TEACHER']
const MGMT = ['R_SUPER', 'R_MANAGER']
const SUPER = ['R_SUPER']

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
        path: '/yimai/members',
        name: 'YimaiMembers',
        component: '/yimai/members',
        meta: { title: '会员管理', icon: 'ri:vip-crown-line', roles: OPS, keepAlive: false }
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
        path: '/yimai/training',
        name: 'YimaiTraining',
        component: '/yimai/training',
        meta: { title: '训练计划', icon: 'ri-heart-pulse-line', roles: OPS, keepAlive: false }
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
        path: '/yimai/audit',
        name: 'YimaiAudit',
        component: '/yimai/audit',
        meta: { title: '操作留痕', icon: 'ri:history-line', roles: SUPER, keepAlive: false }
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
