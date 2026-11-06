/**
 * ECharts 插件配置
 *
 * 按需导入 ECharts 图表和组件，减小打包体积。
 * **只注册项目里真实存在调用方的图表与组件** —— 这张清单直接决定
 * `dist/assets/echarts-*.js` 的大小（改它之前先统计产物，改完再统计一次）。
 *
 * ## 注册清单的依据（2026-11 t12 实测，全仓 grep 计数）
 *
 * | 图表/组件 | 调用方 |
 * | --- | --- |
 * | LineChart | ArtLineChart ×13 |
 * | BarChart | ArtBarChart ×8 |
 * | PieChart | ArtRingChart ×3 |
 * | TreemapChart | ArtTreeMapChart ×2 |
 * | Title / Tooltip / Grid / Legend / MarkLine | `hooks/core/useChart.ts` 的统一配置 |
 *
 * ## 已移除的部分（全仓 0 调用点）
 *
 * - `ScatterChart` / `RadarChart` / `MapChart` / `CandlestickChart`：分别只被
 *   art-scatter-chart / art-radar-chart / （无调用方）/ art-k-line-chart 引用。
 *   这三个组件都是模板遗留物 —— `components.d.ts` 里只有自动生成的类型声明，
 *   没有任何模板真的用到它们，所以没被打进产物。
 * - `DataZoomComponent`：只有 art-k-line-chart 的 options 用。
 * - `MarkPointComponent` / `ToolboxComponent` / `BrushComponent` /
 *   `GeoComponent` / `VisualMapComponent`：全仓无 options 使用。
 *   （注意 `grep geo` 的大量命中是登录页 CSS 类名 `.geo-element`，与 echarts 无关。）
 *
 * ⚠️ 要复活上面任何一个组件（k 线 / 雷达 / 散点），必须把对应的 `*Chart` 与
 * `*Component` **同时**加回下面的 import 与 `echarts.use()`，否则运行时会报
 * “Component xxx is not exists. Load it first.”。
 *
 * @module plugins/echarts
 * @author Art Design Pro Team
 */

// ECharts 按需导入配置
import * as echarts from 'echarts/core'

// 导入图表类型
import { BarChart, LineChart, PieChart, TreemapChart } from 'echarts/charts'

// 导入组件
import {
  TitleComponent,
  TooltipComponent,
  GridComponent,
  LegendComponent,
  MarkLineComponent
} from 'echarts/components'

// 导入渲染器
import { CanvasRenderer } from 'echarts/renderers'

// 注册必要的组件
echarts.use([
  // 图表类型
  BarChart,
  LineChart,
  PieChart,
  TreemapChart,

  // 组件
  TitleComponent,
  TooltipComponent,
  GridComponent,
  LegendComponent,
  MarkLineComponent,

  // 渲染器
  CanvasRenderer
])

// 导出 echarts 实例和类型
export { echarts }
export type { EChartsOption, BarSeriesOption } from 'echarts'

// 导出常用的图形工具
export const graphic = echarts.graphic
