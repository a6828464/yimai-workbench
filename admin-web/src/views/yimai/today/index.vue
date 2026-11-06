<template>
  <component :is="dashboardComponent" v-if="dashboardComponent" />
  <div v-else class="list-page">
    <ElEmpty description="当前账号无可用工作台" />
  </div>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'

  defineOptions({ name: 'YimaiToday' })

  /**
   * 四份工作台按角色**懒加载**。
   *
   * 改造前是四条静态 import —— 不管什么角色，登录落地页都会把四份 dashboard
   * 连同它们共同依赖的 echarts（757.5KB raw / 246.9KB gz）一起下载，而
   * `<component :is>` 实际只渲染其中一份。四份合计 89.2KB raw / 33KB gz，
   * 加上 echarts 就是每个角色首屏多付 ~280KB gz（t3 实测）。
   *
   * 现在 `defineAsyncComponent` 只在 computed 选中某一份时才真正调用对应的
   * `import()`：超管下 boss 那份，店长下 manager 那份，互不牵连。
   */
  const BossDashboard = defineAsyncComponent(() => import('./modules/boss-dashboard.vue'))
  const ManagerDashboard = defineAsyncComponent(() => import('./modules/manager-dashboard.vue'))
  const TeacherDashboard = defineAsyncComponent(() => import('./modules/teacher-dashboard.vue'))
  const MediaDashboard = defineAsyncComponent(() => import('./modules/media-dashboard.vue'))

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])

  const dashboardComponent = computed(() => {
    if (roles.value.includes('R_SUPER')) return BossDashboard
    if (roles.value.includes('R_MANAGER')) return ManagerDashboard
    if (roles.value.includes('R_SERVICE') || roles.value.includes('R_TEACHER'))
      return TeacherDashboard
    if (roles.value.includes('R_MEDIA')) return MediaDashboard
    return null
  })
</script>
