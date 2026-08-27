<!-- 布局容器 -->
<template>
  <div class="app-layout">
    <aside id="app-sidebar">
      <ArtSidebarMenu />
    </aside>

    <main id="app-main">
      <div id="app-header">
        <ArtHeaderBar />
      </div>
      <div id="app-content">
        <ArtPageContent />
      </div>
    </main>

    <div id="app-global">
      <ArtGlobalComponent />
    </div>
  </div>
</template>

<script setup lang="ts">
  defineOptions({ name: 'AppLayout' })

  // 启动时把服务端数据库里的 AI 配置水合进前端 store（多设备共用同一份密钥配置）
  onMounted(() => {
    void import('@/api/ai').then(({ initAiConfig }) => initAiConfig())
  })
</script>

<style lang="scss" scoped>
  @use './style';
</style>
