<template>
  <div class="p-4">
    <ElAlert
      title="经营看板按架构书决策后置：先跑通经营闭环，再建设分析层"
      type="info"
      show-icon
      :closable="false"
      class="mb-4"
    />
    <ElRow :gutter="16" class="mb-4">
      <ElCol v-for="m in metrics" :key="m.label" :xs="12" :sm="12" :md="6" class="mb-4">
        <ElCard shadow="never">
          <div class="text-sm text-gray-500">{{ m.label }}</div>
          <div class="mt-2 text-2xl font-600 text-gray-300">{{ m.value }}</div>
          <div class="mt-1 text-xs text-gray-400">{{ m.hint }}</div>
        </ElCard>
      </ElCol>
    </ElRow>
    <ElCard shadow="never">
      <ElEmpty description="阶段1上线后此处呈现：新客漏斗、会员维护覆盖率、任务闭环率、特殊价格审批结果" />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  defineOptions({ name: 'YimaiAnalytics' })

  const metrics = [
    { label: '新客首次响应按时率', value: '待上线', hint: '目标 ≥90%（试点30天）' },
    { label: '在跟客户闭环率', value: '待上线', hint: '负责人+下次动作+时间 ≥95%' },
    { label: '成交移交及时率', value: '待上线', hint: '1个工作日内 ≥95%' },
    { label: '预警处理率', value: '待上线', hint: '临期/低频已处置 ≥85%' }
  ]
</script>
