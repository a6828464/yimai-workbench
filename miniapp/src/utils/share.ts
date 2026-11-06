/**
 * 小程序「转发」统一出口
 *
 * ## 为什么要有这个文件
 * 小程序**没有全局默认分享**：`onShareAppMessage` 必须逐页注册，不注册的话右上角菜单里
 * 连「转发」都不出现。所以「每个页面都能分享」这件事的本质是「每个页面加一行」——
 * 但对象字面量绝不能复制 20 多份，否则以后改标题/改落地页要挨个改。
 * 这里只放**纯函数**（不 import 页面钩子），各页面自己写一行：
 *
 *     import { onShareAppMessage } from '@dcloudio/uni-app'
 *     import { shareToHome } from '@/utils/share'
 *     onShareAppMessage(() => shareToHome())
 *
 * ## 员工页为什么一律回首页（而不是分享自己）
 * 员工页（工作台 / 留资 / 会员 / 审批 / 待办 / 训练计划…）全部要求登录，`requireLogin()` 在
 * onShow 里直接 `reLaunch` 到登录页。把这类页面路径分享出去，对方点开就是「请登录」——
 * 既看不到任何内容，也把一次品牌曝光变成一次挫败。所以：
 * **员工页都注册分享（右上角有「转发」），但 path 一律回首页**，
 * 对方从首页能看到品牌、约课入口和到店指引。
 *
 * ## 员工页标题为什么不带业务信息
 * 分享卡片会被转发到群、截图、朋友圈。员工页标题若写「张三 · 待续费」就是客户隐私泄露。
 * 所以员工页统一用品牌标题，只有明确对外、内容本身就是给客户看的页面才带门店名。
 */

/** 品牌首页（员工页分享的落地页） */
export const HOME_PATH = '/pages/home/home'

/** 员工页统一标题：不带任何客户/业务信息 */
export const BRAND_TITLE = '一麦瑜伽·普拉提'

/** 与 `onShareAppMessage` 的返回值结构一致（title + path + 可选封面） */
export interface SharePayload {
  title: string
  path: string
  imageUrl?: string
}

/**
 * 员工页分享：回首页。
 * 标题用品牌名 —— 员工页的内容（客户名、任务、审批金额）不能出现在分享卡片上。
 */
export function shareToHome(): SharePayload {
  return { title: BRAND_TITLE, path: HOME_PATH }
}

/**
 * 对外页分享：指向页面自身。
 *
 * 只给「不需要登录、内容就是给客户看」的三页用：首页 / 到店指引 / 约课。
 * 其余页面请用 `shareToHome()`。
 */
export function shareSelf(title: string, path: string, imageUrl?: string): SharePayload {
  return imageUrl ? { title, path, imageUrl } : { title, path }
}
