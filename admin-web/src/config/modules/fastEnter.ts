/**
 * 快速入口配置
 * 包含：应用列表、快速链接等配置
 */
import type { FastEnterConfig } from '@/types/config'

const fastEnterConfig: FastEnterConfig = {
  // 显示条件（屏幕宽度）
  minWidth: 1200,
  // 应用列表
  applications: [
    {
      name: '工作台',
      description: '今日经营数据总览',
      icon: 'ri:sun-line',
      iconColor: '#377dff',
      enabled: true,
      order: 1,
      routeName: 'YimaiToday'
    },
    {
      name: '会员管理',
      description: '五清单与续费预警',
      icon: 'ri:vip-crown-line',
      iconColor: '#ff9f0a',
      enabled: true,
      order: 2,
      routeName: 'YimaiMembers'
    },
    {
      name: '留资管理',
      description: '新媒体客资跟进',
      icon: 'ri:radar-line',
      iconColor: '#13DEB9',
      enabled: true,
      order: 3,
      routeName: 'YimaiLeads'
    },
    {
      name: '任务中心',
      description: '任务分派与验收',
      icon: 'ri:file-list-3-line',
      iconColor: '#7A7FFF',
      enabled: true,
      order: 4,
      routeName: 'YimaiTasks'
    },
    {
      name: '营销工具',
      description: 'AI文案生成助手',
      icon: 'ri:megaphone-line',
      iconColor: '#ff3b30',
      enabled: true,
      order: 5,
      routeName: 'YimaiMarketing'
    },
    {
      name: '训练计划',
      description: 'AI生成训练草稿',
      icon: 'ri-heart-pulse-line',
      iconColor: '#38C0FC',
      enabled: true,
      order: 6,
      routeName: 'YimaiTraining'
    }
  ],
  // 快速链接
  quickLinks: [
    {
      name: '个人中心',
      enabled: true,
      order: 1,
       routeName: 'YimaiProfile'
    },
    {
      name: '模型配置',
      enabled: true,
      order: 2,
      routeName: 'YimaiAiConfig'
    }
  ]
}

export default Object.freeze(fastEnterConfig)
