<template>
  <div>
    <div class="mb-3">
      <ElButton type="primary" plain @click="addCoach">新增教练</ElButton>
      <span class="ml-2 text-xs text-gray-400">顺序即分享页展示顺序：置顶 / 上移 / 下移</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <ElCard v-for="(c, i) in list" :key="c.id" shadow="never">
        <template #header>
          <div class="flex-cb">
            <span class="font-500">{{ c.name || '未命名' }}</span>
            <div class="flex gap-1">
              <ElButton size="small" text :disabled="i === 0" @click="move(i, -1)">上移</ElButton>
              <ElButton size="small" text :disabled="i === 0" @click="top(i)">置顶</ElButton>
              <ElButton size="small" text :disabled="i === list.length - 1" @click="move(i, 1)">下移</ElButton>
            </div>
          </div>
        </template>
        <ElRow :gutter="10">
          <ElCol :span="8"><ElFormItem label="姓名"><ElInput v-model="c.name" maxlength="6" /></ElFormItem></ElCol>
          <ElCol :span="16"><ElFormItem label="职位"><ElInput v-model="c.title" maxlength="12" /></ElFormItem></ElCol>
        </ElRow>
        <ElFormItem label="能力标签"><ElInput :model-value="tagsText(c)" placeholder="逗号分隔，如：体态改善,核心床" @update:model-value="(v: string) => syncTags(c, v)" /></ElFormItem>
        <ElFormItem label="简介"><ElInput v-model="c.intro" type="textarea" :rows="2" maxlength="60" show-word-limit /></ElFormItem>
        <div class="flex gap-2">
          <ElButton size="small" type="primary" @click="saveAll()">保存</ElButton>
          <ElButton size="small" text type="danger" @click="removeCoach(i)">删除</ElButton>
        </div>
      </ElCard>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { useSalesStore } from '@/store/modules/sales'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'SalesCoaches' })

  const sales = useSalesStore()
  const list = ref(JSON.parse(JSON.stringify(sales.state.coaches)))

  function nextId() {
    return Math.max(0, ...list.value.map((c: { id: number }) => c.id)) + 1
  }

  function addCoach() {
    list.value.push({ id: nextId(), name: '', title: '', tags: [], intro: '' })
  }

  function tagsText(c: { tags: string[] }) {
    return c.tags.join(',')
  }

  function syncTags(c: { tags: string[] }, v: string) {
    c.tags = v.split(/[,，]/).map((s) => s.trim()).filter(Boolean)
  }

  function move(i: number, dir: -1 | 1) {
    const j = i + dir
    if (j < 0 || j >= list.value.length) return
    ;[list.value[i], list.value[j]] = [list.value[j], list.value[i]]
    saveAll(true)
  }

  function top(i: number) {
    if (i === 0) return
    const [item] = list.value.splice(i, 1)
    list.value.unshift(item)
    saveAll(true)
  }

  function removeCoach(i: number) {
    list.value.splice(i, 1)
    saveAll(true)
  }

  function saveAll(silent = false) {
    sales.saveCoaches(list.value)
    if (!silent) ElMessage.success('教练信息已保存')
  }
</script>
