import { AppRouteRecord } from '@/types/router'

const ALL = ['R_SUPER', 'R_MANAGER', 'R_TEACHER', 'R_MEDIA']

export const yimaiRoutes: AppRouteRecord = {
  name: 'Yimai',
  path: '/yimai',
  component: '/index/index',
  meta: {
    title: '一麦工作台',
    icon: 'ri:store-2-line',
    roles: ALL
  },
  children: [
    {
      path: 'today',
      name: 'YimaiToday',
      component: '/yimai/today',
      meta: {
        title: '工作台',
        icon: 'ri:sun-line',
        roles: ALL,
        keepAlive: false,
        fixedTab: true
      }
    },
    {
      path: 'members',
      name: 'YimaiMembers',
      component: '/yimai/members',
      meta: {
        title: '会员管理',
        icon: 'ri:vip-crown-line',
        roles: ['R_SUPER', 'R_MANAGER', 'R_TEACHER'],
        keepAlive: false
      }
    },
    {
      path: 'leads',
      name: 'YimaiLeads',
      component: '/yimai/leads',
      meta: {
        title: '留资管理',
        icon: 'ri:radar-line',
        roles: ALL,
        keepAlive: false
      }
    },
    {
      path: 'customers',
      name: 'YimaiCustomers',
      component: '/yimai/customers',
      meta: {
        title: '客户经营池',
        icon: 'ri:user-heart-line',
        roles: ['R_SUPER'],
        keepAlive: false
      }
    },
    {
      path: 'tasks',
      name: 'YimaiTasks',
      component: '/yimai/tasks',
      meta: {
        title: '任务中心',
        icon: 'ri:file-list-3-line',
        roles: ['R_SUPER', 'R_MANAGER', 'R_TEACHER'],
        keepAlive: false
      }
    },
    {
      path: 'approvals',
      name: 'YimaiApprovals',
      component: '/yimai/approvals',
      meta: {
        title: '价格审批',
        icon: 'ri:shield-check-line',
        roles: ['R_SUPER', 'R_MANAGER'],
        keepAlive: false
      }
    },
    {
      path: 'sync',
      name: 'YimaiSync',
      component: '/yimai/sync',
      meta: {
        title: 'KeepYoga同步',
        icon: 'ri:refresh-line',
        roles: ['R_SUPER'],
        keepAlive: false
      }
    },
    {
      path: 'analytics',
      name: 'YimaiAnalytics',
      component: '/yimai/analytics',
      meta: {
        title: '经营看板',
        icon: 'ri:bar-chart-grouped-line',
        roles: ['R_SUPER'],
        keepAlive: false
      }
    },
    {
      path: 'audit',
      name: 'YimaiAudit',
      component: '/yimai/audit',
      meta: {
        title: '操作留痕',
        icon: 'ri:history-line',
        roles: ['R_SUPER'],
        keepAlive: false
      }
    },
    {
      path: 'accounts',
      name: 'YimaiAccounts',
      component: '/yimai/accounts',
      meta: {
        title: '人员管理',
        icon: 'ri:team-line',
        roles: ['R_SUPER'],
        keepAlive: false
      }
    },
    {
      path: 'ai-config',
      name: 'YimaiAiConfig',
      component: '/yimai/ai-config',
      meta: {
        title: '模型配置',
        icon: 'ri:cpu-line',
        roles: ['R_SUPER'],
        keepAlive: false
      }
    },
    {
      path: 'training',
      name: 'YimaiTraining',
      component: '/yimai/training',
      meta: {
        title: '训练计划',
        icon: 'ri-heart-pulse-line',
        roles: ['R_SUPER', 'R_MANAGER', 'R_TEACHER'],
        keepAlive: false
      }
    },
    {
      path: 'sales',
      name: 'YimaiSales',
      component: '/yimai/sales',
      meta: {
        title: '谈单工具',
        icon: 'ri:rocket-2-line',
        roles: ['R_SUPER', 'R_MANAGER'],
        keepAlive: false
      }
    },
    {
      path: 'marketing',
      name: 'YimaiMarketing',
      component: '/yimai/marketing',
      meta: {
        title: '营销工具',
        icon: 'ri:megaphone-line',
        roles: ALL,
        keepAlive: false
      }
    },
    {
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
}
