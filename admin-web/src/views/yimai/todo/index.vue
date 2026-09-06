<template>
  <div class="p-4">
    <YimaiTodayTodo variant="full" :initial-group="initialGroup" />
  </div>
</template>

<script setup lang="ts">
  import YimaiTodayTodo from '@/views/yimai/today/modules/today-todo.vue'

  defineOptions({ name: 'YimaiTodo' })

  const route = useRoute()
  const initialGroup = computed(() => (route.query.group as string) || '')
</script>
