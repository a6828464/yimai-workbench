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
  import { publishShare } from '@/api/yimai'
  import { USE_BACKEND } from '@/api/backend'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiSales' })

  const sales = useSalesStore()
  const tab = ref('basic')

  /** 开启/更新分享时，把当前工作台内容发布为服务端快照（H5 跨设备可访问） */
  async function onShareToggle(enabled: boolean) {
    sales.setShareEnabled(enabled)
    if (!USE_BACKEND || !enabled) return
    try {
      await publishShare('sales', sales.state.share.code, {
        share: sales.state.share,
        info: sales.state.info,
        products: sales.state.products,
        coaches: sales.state.coaches,
        cases: sales.state.cases
      })
      ElMessage.success('已发布到线上，客户可在微信中打开')
    } catch (e) {
      ElMessage.error(`线上发布失败：${String(e).slice(0, 80)}`)
    }
  }

  function preview() {
    const url = `${window.location.origin}${window.location.pathname}#/s/${sales.state.share.code}`
    window.open(url, '_blank')
  }
</script>
