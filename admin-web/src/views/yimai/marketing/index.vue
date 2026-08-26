<template>
  <div class="p-4">
    <ElAlert :title="bannerText" type="info" show-icon :closable="false" class="mb-4">
      <template #default>
        <div class="flex items-center justify-between flex-wrap gap-2">
          <span>{{ bannerText }}</span>
          <ElButton v-if="isSuper" link type="primary" @click="$router.push('/yimai/ai-config')">
            前往配置
          </ElButton>
        </div>
      </template>
    </ElAlert>

    <ElCard shadow="never">
      <ElTabs v-model="activeTab">
        <ElTabPane name="moments">
          <template #label>
            <span class="flex items-center gap-1"><ElIcon><ChatDotRound /></ElIcon> 朋友圈</span>
          </template>
          <MomentsTool />
        </ElTabPane>
        <ElTabPane name="xhs">
          <template #label>
            <span class="flex items-center gap-1"><ElIcon><EditPen /></ElIcon> 小红书</span>
          </template>
          <XhsTool />
        </ElTabPane>
      </ElTabs>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import MomentsTool from './modules/moments-tool.vue'
  import XhsTool from './modules/xhs-tool.vue'
  import { useAiConfigStore } from '@/store/modules/ai-config'
  import { useUserStore } from '@/store/modules/user'
  import { ChatDotRound, EditPen } from '@element-plus/icons-vue'

  defineOptions({ name: 'YimaiMarketing' })

  const activeTab = ref<'moments' | 'xhs'>('moments')
  const aiStore = useAiConfigStore()
  const userStore = useUserStore()
  const isSuper = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_SUPER'))

  const bannerText = computed(() =>
    aiStore.isReady()
      ? `AI已接入：${aiStore.config.providerLabel} · ${aiStore.config.model}（生成内容由人工审核后发布）`
      : '当前为本地模板草稿模式 · 超管在「模型配置」接入大模型API后可启用AI生成'
  )
</script>
