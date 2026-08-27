<template>
  <div class="p-4">
    <ElCard shadow="never">
      <template #header><span class="font-500">个人中心</span></template>
      <p class="text-sm text-gray-500 mb-4">统一维护营销助手的个人发圈人设，保存后朋友圈生成会自动使用。</p>
      <MomentsTool />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
import MomentsTool from '../marketing/modules/moments-tool.vue'
defineOptions({ name: 'YimaiProfile' })
</script>
