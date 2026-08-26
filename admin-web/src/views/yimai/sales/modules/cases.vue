<template>
  <div>
    <ElAlert
      title="隐私与授权：案例必须取得会员书面/电子授权后才可对外展示；展示图建议颈部以下，不保留面部与敏感信息。未勾选授权的案例不会出现在分享页。"
      type="warning"
      show-icon
      :closable="false"
      class="mb-4"
    />
    <ElButton type="primary" plain class="mb-3" @click="addCase">新增案例</ElButton>

    <ElCollapse v-model="opened" accordion>
      <ElCollapseItem v-for="(c, i) in list" :key="c.id" :name="String(c.id)">
        <template #title>
          <div class="flex items-center gap-3 pr-4">
            <span class="font-500">{{ c.goal || `案例${i + 1}` }}</span>
            <ElTag size="small" :type="c.authorized ? 'success' : 'danger'">{{ c.authorized ? '已授权' : '未授权·不展示' }}</ElTag>
          </div>
        </template>

        <ElRow :gutter="12">
          <ElCol :xs="24" :md="8">
            <ElFormItem label="关联教练">
              <ElSelect v-model="c.coachId" clearable placeholder="未关联">
                <ElOption v-for="co in sales.state.coaches" :key="co.id" :label="co.name" :value="co.id" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol :xs="24" :md="16"><ElFormItem label="训练目标"><ElInput v-model="c.goal" maxlength="30" /></ElFormItem></ElCol>
        </ElRow>
        <ElFormItem label="案例说明"><ElInput v-model="c.desc" type="textarea" :rows="2" maxlength="120" show-word-limit /></ElFormItem>
        <ElFormItem label="展示授权"><ElSwitch v-model="c.authorized" active-text="已取得会员授权" /></ElFormItem>

        <ElFormItem label="阶段节点">
          <div class="w-full flex flex-wrap items-center gap-2">
            <div v-for="(s, si) in c.stages" :key="si" class="flex items-center gap-1">
              <ElTag size="small" effect="plain">{{ si === 0 ? '初始建档' : s.duration || `阶段${si}` }}</ElTag>
              <ElIcon v-if="si > 0" class="cursor-pointer text-gray-400" @click="removeStage(c, si)"><CircleClose /></ElIcon>
            </div>
            <ElInput v-model="newStageDuration" size="small" class="!w-28" placeholder="如：第8周" />
            <ElButton size="small" plain @click="addStage(c)">加阶段</ElButton>
          </div>
        </ElFormItem>

        <div class="flex gap-2">
          <ElButton size="small" type="primary" @click="saveAll()">保存</ElButton>
          <ElButton size="small" text type="danger" @click="removeCase(i)">删除</ElButton>
        </div>
      </ElCollapseItem>
    </ElCollapse>
  </div>
</template>

<script setup lang="ts">
  import { useSalesStore } from '@/store/modules/sales'
  import { CircleClose } from '@element-plus/icons-vue'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'SalesCases' })

  const sales = useSalesStore()
  const list = ref(JSON.parse(JSON.stringify(sales.state.cases)))
  const opened = ref(String(list.value[0]?.id ?? ''))
  const newStageDuration = ref('')

  function nextId() {
    return Math.max(0, ...list.value.map((c: { id: number }) => c.id)) + 1
  }

  function addCase() {
    const id = nextId()
    list.value.push({ id, coachId: '', goal: '', desc: '', authorized: false, stages: [{ duration: '' }] })
    opened.value = String(id)
  }

  function addStage(c: { stages: { duration: string }[] }) {
    if (c.stages.length >= 6) {
      ElMessage.warning('最多6个阶段')
      return
    }
    c.stages.push({ duration: newStageDuration.value.trim() })
    newStageDuration.value = ''
  }

  function removeStage(c: { stages: { duration: string }[] }, i: number) {
    c.stages.splice(i, 1)
  }

  function removeCase(i: number) {
    list.value.splice(i, 1)
    saveAll(true)
  }

  function saveAll(silent = false) {
    sales.saveCases(list.value)
    if (!silent) ElMessage.success('案例已保存（仅已授权案例进入分享页）')
  }
</script>
