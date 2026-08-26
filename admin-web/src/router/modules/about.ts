import { AppRouteRecord } from '@/types/router'

const ALL = ['R_SUPER', 'R_MANAGER', 'R_TEACHER', 'R_MEDIA']

/** 项目说明页（免权限直达，不显示在菜单） */
export const aboutRoutes: AppRouteRecord = {
  name: 'YimaiAboutPage',
  path: '/yimai-about',
  component: '/index/index',
  meta: {
    title: '项目说明',
    icon: 'ri:book-2-line',
    roles: ALL,
    isHide: true,
    isHideTab: true
  },
  children: [
    {
      path: 'main',
      name: 'YimaiAboutMain',
      component: '/yimai/about',
      meta: {
        title: '项目说明',
        icon: 'ri:book-2-line',
        roles: ALL,
        keepAlive: false,
        isHideTab: true
      }
    }
  ]
}