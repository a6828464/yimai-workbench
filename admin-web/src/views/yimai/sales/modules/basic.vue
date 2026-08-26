<template>
  <div>
    <ElAlert title="字段口径：门店名≤12字 · 行业≤8字 · Slogan≤16字 · 一句介绍≤30字 · 地址≤24字" type="info" :closable="false" class="mb-4" />
    <ElForm label-width="90px">
      <ElRow :gutter="12">
        <ElCol :xs="24" :md="8"><ElFormItem label="门店名称"><ElInput v-model="form.name" maxlength="12" show-word-limit /></ElFormItem></ElCol>
        <ElCol :xs="24" :md="8"><ElFormItem label="行业属性"><ElInput v-model="form.industry" maxlength="8" show-word-limit /></ElFormItem></ElCol>
        <ElCol :xs="24" :md="8"><ElFormItem label="Slogan"><ElInput v-model="form.slogan" maxlength="16" show-word-limit /></ElFormItem></ElCol>
        <ElCol :span="24"><ElFormItem label="一句介绍"><ElInput v-model="form.intro" maxlength="30" show-word-limit /></ElFormItem></ElCol>
        <ElCol :xs="24" :md="14"><ElFormItem label="地址"><ElInput v-model="form.address" maxlength="24" show-word-limit /></ElFormItem></ElCol>
        <ElCol :xs="24" :md="10"><ElFormItem label="电话"><ElInput v-model="form.phone" maxlength="16" /></ElFormItem></ElCol>
      </ElRow>
      <ElButton type="primary" @click="save">保存基础信息</ElButton>
    </ElForm>
  </div>
</template>

<script setup lang="ts">
  import { useSalesStore } from '@/store/modules/sales'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'SalesBasic' })

  const sales = useSalesStore()
  const form = reactive({ ...sales.state.info })

  function save() {
    if (!form.name.trim()) {
      ElMessage.warning('门店名称必填')
      return
    }
    sales.updateInfo(form)
    ElMessage.success('已保存，分享页实时生效')
  }
</script>
