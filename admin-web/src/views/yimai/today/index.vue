<template>
  <component :is="dashboardComponent" />
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'
  import ManagerDashboard from './modules/manager-dashboard.vue'
  import MediaDashboard from './modules/media-dashboard.vue'
  import BossDashboard from './modules/boss-dashboard.vue'
  import TeacherDashboard from './modules/teacher-dashboard.vue'

  defineOptions({ name: 'YimaiToday' })

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])

  const dashboardComponent = computed(() => {
    if (roles.value.includes('R_MANAGER')) return ManagerDashboard
    if (roles.value.includes('R_TEACHER')) return TeacherDashboard
    if (roles.value.includes('R_MEDIA')) return MediaDashboard
    return BossDashboard
  })
</script>
