<script setup lang="ts">
/**
 * 轻量图表（canvas 2d）
 *
 * 为什么不引 ECharts：主包闸门 1.2MB，echarts-for-weixin 单文件就 400KB+，
 * 而工作台只需要三种最基础的形态（折线 / 柱状 / 环形），自绘 200 行足够且完全可控。
 *
 * 兼容性：使用 `<canvas type="2d">`（基础库 2.9.0+）。查询节点失败时静默降级为
 * 一行文字摘要，页面不会白块——真机与 H5 预览都不会因为拿不到 node 而报错。
 */
import { getCurrentInstance, nextTick, onMounted, ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    /** 图表形态 */
    type?: 'line' | 'bar' | 'donut'
    /** 数值序列 */
    data?: number[]
    /** x 轴标签（与 data 等长；缺省不画） */
    labels?: string[]
    /** 高度（rpx） */
    height?: number
    /** 主色 */
    color?: string
    /** 次色（折线面积 / 第二组柱） */
    color2?: string
    /** 环形图分段配色（缺省按主色深浅轮转） */
    colors?: string[]
    /** 数值后缀，如「人」「单」 */
    unit?: string
    /** 小数位 */
    precision?: number
    /** 环形图中心标题 */
    centerLabel?: string
    /** 是否画水平网格线 */
    grid?: boolean
    /** 空数据提示 */
    emptyText?: string
  }>(),
  {
    type: 'line',
    data: () => [],
    labels: () => [],
    height: 300,
    color: '#2E7D5B',
    color2: '#B9D9C9',
    colors: () => [],
    unit: '',
    precision: 0,
    centerLabel: '',
    grid: true,
    emptyText: '暂无数据'
  }
)

const instance = getCurrentInstance()
const canvasId = `ymchart${Math.random().toString(36).slice(2, 9)}`
const canvasHeight = ref(0)
const failed = ref(false)

function px(rpx: number): number {
  return uni.upx2px(rpx)
}

function fmt(v: number): string {
  if (!Number.isFinite(v)) return '0'
  const fixed = v.toFixed(props.precision)
  return props.precision > 0 ? fixed : String(Math.round(v))
}

function summaryText(): string {
  if (!props.data.length) return props.emptyText
  const total = props.data.reduce((a, b) => a + (Number(b) || 0), 0)
  return `合计 ${fmt(total)}${props.unit}`
}

function hexToRgba(hex: string, alpha: number): string {
  const h = hex.replace('#', '')
  const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h
  const n = parseInt(full, 16)
  const r = (n >> 16) & 255
  const g = (n >> 8) & 255
  const b = n & 255
  return `rgba(${r},${g},${b},${alpha})`
}

function roundRect(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number
) {
  const radius = Math.min(r, w / 2, h / 2)
  ctx.beginPath()
  ctx.moveTo(x + radius, y)
  ctx.lineTo(x + w - radius, y)
  ctx.quadraticCurveTo(x + w, y, x + w, y + radius)
  ctx.lineTo(x + w, y + h)
  ctx.lineTo(x, y + h)
  ctx.lineTo(x, y + radius)
  ctx.quadraticCurveTo(x, y, x + radius, y)
  ctx.closePath()
}

function drawLine(ctx: CanvasRenderingContext2D, w: number, h: number) {
  const data = props.data.map((v) => Number(v) || 0)
  const padL = 8
  const padR = 8
  const padT = 14
  const padB = 22
  const innerW = Math.max(1, w - padL - padR)
  const innerH = Math.max(1, h - padT - padB)
  const max = Math.max(...data, 1)

  if (props.grid) {
    ctx.strokeStyle = 'rgba(16,24,21,0.06)'
    ctx.lineWidth = 1
    for (let i = 0; i <= 3; i++) {
      const y = padT + (innerH / 3) * i
      ctx.beginPath()
      ctx.moveTo(padL, y)
      ctx.lineTo(w - padR, y)
      ctx.stroke()
    }
  }

  const points = data.map((v, i) => {
    const x = data.length === 1 ? padL + innerW / 2 : padL + (innerW * i) / (data.length - 1)
    const y = padT + innerH * (1 - v / max)
    return { x, y, v }
  })

  // 面积
  const grad = ctx.createLinearGradient(0, padT, 0, h - padB)
  grad.addColorStop(0, hexToRgba(props.color, 0.22))
  grad.addColorStop(1, hexToRgba(props.color, 0.01))
  ctx.beginPath()
  ctx.moveTo(points[0].x, h - padB)
  points.forEach((p) => ctx.lineTo(p.x, p.y))
  ctx.lineTo(points[points.length - 1].x, h - padB)
  ctx.closePath()
  ctx.fillStyle = grad
  ctx.fill()

  // 折线
  ctx.beginPath()
  points.forEach((p, i) => (i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y)))
  ctx.strokeStyle = props.color
  ctx.lineWidth = 2
  ctx.lineJoin = 'round'
  ctx.lineCap = 'round'
  ctx.stroke()

  // 末点强调
  const last = points[points.length - 1]
  ctx.beginPath()
  ctx.arc(last.x, last.y, 3.4, 0, Math.PI * 2)
  ctx.fillStyle = '#fff'
  ctx.fill()
  ctx.beginPath()
  ctx.arc(last.x, last.y, 2.4, 0, Math.PI * 2)
  ctx.fillStyle = props.color
  ctx.fill()

  // x 轴标签（最多 4 个，均匀取样，避免糊成一团）
  if (props.labels.length) {
    ctx.fillStyle = 'rgba(107,118,113,0.9)'
    ctx.font = '10px sans-serif'
    ctx.textBaseline = 'top'
    const idxs = new Set<number>()
    const n = props.labels.length
    const step = Math.max(1, Math.ceil(n / 4))
    for (let i = n - 1; i >= 0; i -= step) idxs.add(i)
    idxs.forEach((i) => {
      const p = points[i]
      if (!p) return
      const text = props.labels[i] ?? ''
      const tw = ctx.measureText(text).width
      let x = p.x - tw / 2
      x = Math.max(0, Math.min(w - tw, x))
      ctx.fillText(text, x, h - padB + 6)
    })
  }
}

function drawBar(ctx: CanvasRenderingContext2D, w: number, h: number) {
  const data = props.data.map((v) => Number(v) || 0)
  const padT = 16
  const padB = 22
  const innerH = Math.max(1, h - padT - padB)
  const max = Math.max(...data, 1)
  const gap = data.length > 6 ? 4 : 10
  const barW = Math.max(4, (w - gap * (data.length - 1)) / data.length)

  data.forEach((v, i) => {
    const x = i * (barW + gap)
    const bh = Math.max(2, (innerH * v) / max)
    const y = padT + innerH - bh
    const isMax = v === max && v > 0
    roundRect(ctx, x, y, barW, bh, Math.min(6, barW / 2))
    ctx.fillStyle = isMax ? props.color : hexToRgba(props.color, 0.28)
    ctx.fill()
  })

  if (props.labels.length) {
    ctx.fillStyle = 'rgba(107,118,113,0.9)'
    ctx.font = '10px sans-serif'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'top'
    const step = Math.max(1, Math.ceil(data.length / 5))
    data.forEach((_, i) => {
      if (i % step !== 0 && i !== data.length - 1) return
      const text = props.labels[i] ?? ''
      const x = i * (barW + gap) + barW / 2
      ctx.fillText(text, Math.max(10, Math.min(w - 10, x)), h - padB + 6)
    })
    ctx.textAlign = 'left'
  }
}

function drawDonut(ctx: CanvasRenderingContext2D, w: number, h: number) {
  const data = props.data.map((v) => Math.max(0, Number(v) || 0))
  const total = data.reduce((a, b) => a + b, 0)
  const cx = w / 2
  const cy = h / 2
  const outer = Math.min(w, h) / 2 - 4
  const ring = Math.max(10, outer * 0.34)
  const radius = outer - ring / 2
  const palette =
    props.colors.length > 0
      ? props.colors
      : [props.color, props.color2, '#C19A4B', '#8FBFAA', '#D7DDD9']

  if (total <= 0) {
    ctx.beginPath()
    ctx.arc(cx, cy, radius, 0, Math.PI * 2)
    ctx.strokeStyle = 'rgba(16,24,21,0.08)'
    ctx.lineWidth = ring
    ctx.stroke()
  } else {
    let start = -Math.PI / 2
    data.forEach((v, i) => {
      if (v <= 0) return
      const angle = (v / total) * Math.PI * 2
      ctx.beginPath()
      ctx.arc(cx, cy, radius, start + 0.02, start + angle - 0.02)
      ctx.strokeStyle = palette[i % palette.length]
      ctx.lineWidth = ring
      ctx.lineCap = 'butt'
      ctx.stroke()
      start += angle
    })
  }

  ctx.fillStyle = '#16201C'
  ctx.font = '600 18px sans-serif'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(fmt(total), cx, cy - 6)
  if (props.centerLabel) {
    ctx.fillStyle = 'rgba(107,118,113,0.95)'
    ctx.font = '10px sans-serif'
    ctx.fillText(props.centerLabel, cx, cy + 14)
  }
  ctx.textAlign = 'left'
}

/**
 * 取查询作用域：小程序自定义组件里的节点必须 `in(组件实例)` 才查得到。
 * uni-app 在 mp 端把组件实例挂在 proxy.$scope 上，优先用它；H5/APP 用 proxy 即可。
 * 只传 proxy 也能跑，但在 wx 上偶发查不到（取决于编译器版本），故做双保险。
 */
function queryScope(): unknown {
  const proxy = instance?.proxy as unknown as { $scope?: unknown } | null
  return proxy?.$scope ?? proxy
}

function render(attempt = 0) {
  if (!props.data.length) return
  // 上一轮可能已把 failed 置真、canvas 被 v-if 摘掉；重绘前先复位，否则永远画不出来
  failed.value = false
  // uni 的类型声明里 NodesRef.fields 的 callback 被标成必填，实际运行时可选；
  // 这里收窄成局部 any，避免为一个上游类型缺陷污染整页。
  const query = uni.createSelectorQuery().in(queryScope() as never) as unknown as {
    select: (sel: string) => {
      fields: (opts: Record<string, unknown>) => { exec: (cb: (res: unknown[]) => void) => void }
    }
  }
  query
    .select(`#${canvasId}`)
    .fields({ node: true, size: true })
    .exec((res) => {
      const item = (res && res[0]) as
        | { node?: unknown; width?: number; height?: number }
        | undefined
      if (!item || !item.node) {
        // 首次挂载时组件可能还没完成布局（自定义组件 + canvas 2d 的时序问题），
        // 隔 260ms 重试一次再判失败。重试仍失败才降级为文字摘要，避免误报「无数据」。
        if (attempt < 2) {
          setTimeout(() => render(attempt + 1), 260)
          return
        }
        failed.value = true
        return
      }
      const canvas = item.node as unknown as {
        width: number
        height: number
        getContext: (t: string) => CanvasRenderingContext2D
      }
      const dpr = uni.getSystemInfoSync().pixelRatio || 2
      const w = Number(item.width ?? 0)
      const h = Number(item.height ?? 0)
      canvas.width = w * dpr
      canvas.height = h * dpr
      const ctx = canvas.getContext('2d')
      ctx.setTransform(1, 0, 0, 1, 0, 0)
      ctx.scale(dpr, dpr)
      ctx.clearRect(0, 0, w, h)
      if (props.type === 'bar') drawBar(ctx, w, h)
      else if (props.type === 'donut') drawDonut(ctx, w, h)
      else drawLine(ctx, w, h)
    })
}

function scheduleRender() {
  canvasHeight.value = px(props.height)
  nextTick(() => {
    setTimeout(() => render(0), 40)
  })
}

onMounted(scheduleRender)

watch(
  () => [props.data, props.labels, props.type, props.height, props.color],
  () => scheduleRender(),
  { deep: true }
)
</script>

<template>
  <view class="ym-chart">
    <canvas
      v-if="props.data.length && !failed"
      :id="canvasId"
      :canvas-id="canvasId"
      type="2d"
      class="ym-chart__canvas"
      :style="{ height: canvasHeight + 'px' }"
    />
    <view v-if="!props.data.length || failed" class="ym-chart__empty">
      <text>{{ failed ? summaryText() : props.emptyText }}</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.ym-chart {
  width: 100%;
}

.ym-chart__canvas {
  display: block;
  width: 100%;
}

.ym-chart__empty {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 200rpx;
  font-size: 24rpx;
  color: #aeb6b2;
}
</style>
