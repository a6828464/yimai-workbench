<template>
  <div class="p-4">
    <!-- 分享控制 -->
    <ElCard shadow="never" class="mb-4">
      <div class="flex flex-wrap items-center gap-4">
        <div>
          <div class="font-500 mb-1">H5 分享页</div>
          <span class="text-xs text-gray-400">手机竖屏版式 · 客户在微信内打开 · 草稿不影响已发布内容（阶段1引入发布版本隔离）</span>
        </div>
        <div class="flex-1" />
        <ElTag size="small" :type="sales.state.share.enabled ? 'success' : 'danger'">
          {{ sales.state.share.enabled ? '分享中' : '已停用' }}
        </ElTag>
        <span class="text-xs text-gray-400">访问量 {{ sales.state.share.views }}</span>
        <ElSwitch
          :model-value="sales.state.share.enabled"
          active-text="开启"
          inactive-text="停用"
          @change="(v: string | number | boolean) => onShareToggle(Boolean(v))"
        />
        <ElButton type="primary" @click="preview">预览 / 发送 H5</ElButton>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <ElTabs v-model="tab">
        <ElTabPane name="basic" label="门店信息"><Basic /></ElTabPane>
        <ElTabPane name="products" label="产品与价目"><Products /></ElTabPane>
        <ElTabPane name="coaches" label="推荐教练"><Coaches /></ElTabPane>
        <ElTabPane name="cases" label="学员案例"><Cases /></ElTabPane>
      </ElTabs>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import Basic from './modules/basic.vue'
  import Products from './modules/products.vue'
  import Coaches from './modules/coaches.vue'
  import Cases from './modules/cases.vue'
  import { useSalesStore } from '@/store/modules/sales'
  import { publishShare, disableShare, getCurrentShare } from '@/api/yimai'
  import { USE_BACKEND } from '@/api/backend'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiSales' })

  const sales = useSalesStore()
  const tab = ref('basic')
  /** 开关请求进行中，避免连点造成「开了又停」的竞态 */
  const toggling = ref(false)

  /**
   * 打开页面时以服务端为准回填分享状态。
   *
   * 分享码由服务端签发后，本地持久化的那份可能已经过期（换发过码、或仍是历史
   * 可猜常量），页面必须拿到权威 token 才能给出正确的分享链接。
   */
  async function syncShareState() {
    if (!USE_BACKEND) return
    try {
      const cur = await getCurrentShare('sales')
      sales.setShareEnabled(cur.enabled)
      if (cur.enabled && cur.token) sales.setShareCode(cur.token)
    } catch {
      /* 拉取失败不阻塞页面，保持本地状态 */
    }
  }

  /**
   * 开启/停用分享，前后端状态必须一致。
   *
   * - 开启：把当前工作台内容发布为服务端快照（H5 跨设备可访问），并采用
   *   服务端签发的分享码 —— 公开 URL 不再由客户端可猜常量决定；
   * - 停用：调用服务端把记录置 enabled=false（公开接口据此返回 404）。
   *   原先停用只改本地状态就 return，服务端记录原样保留，旧链接依然能打开。
   * 任一调用失败都回退本地开关，避免「界面显示已停用、线上其实还能访问」。
   */
  async function onShareToggle(enabled: boolean) {
    if (!USE_BACKEND) {
      sales.setShareEnabled(enabled)
      return
    }
    if (toggling.value) return
    toggling.value = true
    try {
      if (enabled) {
        const res = await publishShare('sales', sales.state.share.code, {
          share: sales.state.share,
          info: sales.state.info,
          products: sales.state.products,
          coaches: sales.state.coaches,
          // 提交全量案例，由服务端按 authorized 过滤（服务端是过滤的权威方）
          cases: sales.state.cases
        })
        if (res?.token) sales.setShareCode(res.token)
        sales.setShareEnabled(true)
        ElMessage.success('已发布到线上，客户可在微信中打开')
      } else {
        await disableShare('sales')
        sales.setShareEnabled(false)
        ElMessage.success('已停用分享，旧链接打开会提示已失效')
      }
    } catch (e) {
      sales.setShareEnabled(!enabled)
      ElMessage.error(`${enabled ? '线上发布' : '停用'}失败：${String(e).slice(0, 80)}`)
    } finally {
      toggling.value = false
    }
  }

  onMounted(syncShareState)

  function preview() {
    const url = `${window.location.origin}${window.location.pathname}#/s/${sales.state.share.code}`
    window.open(url, '_blank')
  }
</script>
