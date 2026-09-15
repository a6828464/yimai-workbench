/**
 * MobileCard 的类型定义
 *
 * 单独放在 .ts 里是为了让业务页面能 `import type` 拿到类型，
 * 组件本身由 unplugin-vue-components 自动引入，不需要手动 import。
 */

/** Element Plus 标签/按钮的语义色 */
export type MobileCardSemantic = 'primary' | 'success' | 'info' | 'warning' | 'danger'

/** 卡片顶部标签（如：门店、清单归属、状态） */
export interface MobileCardTag {
  text: string
  /** 语义色，不传为默认灰 */
  type?: MobileCardSemantic
  /** dark 更醒目，用于「待续课」这类需要立刻处理的标记 */
  effect?: 'dark' | 'light' | 'plain'
}

/**
 * 卡片中部的关键指标
 *
 * 卡片上最多放 3–4 个指标；更多信息应该放进 note 或详情页，
 * 塞太多指标等于把表格换了个样子，并没有变好用。
 */
export interface MobileCardMetric {
  label: string
  value: string | number
  /** 值后面的单位，做小字显示（如「节」「天」） */
  unit?: string
  /** 需要警示时标红加粗（如出勤降低、剩余课时不足） */
  danger?: boolean
}

/** 卡片底部的操作按钮 */
export interface MobileCardAction {
  text: string
  type?: MobileCardSemantic
  onClick: () => void
  /** 传 false 时该按钮不渲染，便于页面按行内数据条件显示 */
  show?: boolean
}
