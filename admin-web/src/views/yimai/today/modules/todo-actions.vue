<template>
  <ElDropdown trigger="click" @command="(a: string) => emit('pick', a)">
    <ElButton size="small" type="primary" plain>
      处理
      <ElIcon class="ml-0.5"><ArrowDown /></ElIcon>
    </ElButton>
    <template #dropdown>
      <ElDropdownMenu>
        <ElDropdownItem v-for="a in actions" :key="a.action" :command="a.action">
          {{ a.action }}
        </ElDropdownItem>
      </ElDropdownMenu>
    </template>
  </ElDropdown>
</template>

<script setup lang="ts">
  import { ArrowDown } from '@element-plus/icons-vue'

  defineOptions({ name: 'YimaiTodoActions' })

  defineProps<{
    /** 本组可选动作（action=文案；flow 由父级解释） */
    actions: { action: string }[]
  }>()

  const emit = defineEmits<{ pick: [action: string] }>()
</script>
