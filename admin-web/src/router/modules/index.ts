import { AppRouteRecord } from '@/types/router'
import { yimaiRoutes } from './yimai'

/**
 * 导出所有模块化路由（仅保留业务菜单，模板演示菜单已移除）
 */
export const routeModules: AppRouteRecord[] = [...yimaiRoutes]
