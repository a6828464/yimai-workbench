<template>
  <div>
    <div class="mb-3 flex items-center gap-2">
      <ElButton type="primary" plain @click="addProduct">新增产品</ElButton>
      <span class="text-xs text-gray-400">最多5个产品 · 价目表行列均可增减，保存后进入分享页</span>
    </div>

    <ElCollapse v-model="opened" accordion>
      <ElCollapseItem v-for="(p, pi) in list" :key="p.id" :name="String(p.id)">
        <template #title>
          <div class="flex items-center gap-3 pr-4">
            <span class="font-500">{{ p.name || `产品${pi + 1}` }}</span>
            <ElTag size="small" :type="p.showPrice ? 'success' : 'info'">{{ p.showPrice ? '展示价目' : '隐藏价目' }}</ElTag>
          </div>
        </template>

        <ElRow :gutter="12">
          <ElCol :xs="24" :md="8"><ElFormItem label="产品名称"><ElInput v-model="p.name" maxlength="20" /></ElFormItem></ElCol>
          <ElCol :xs="24" :md="16"><ElFormItem label="产品说明"><ElInput v-model="p.desc" /></ElFormItem></ElCol>
        </ElRow>
        <ElFormItem label="价目开关"><ElSwitch v-model="p.showPrice" active-text="客户可见" inactive-text="隐藏" /></ElFormItem>

        <div class="border border-gray-100 dark:border-gray-700 rounded-lg p-3 mb-3 overflow-x-auto">
          <table class="price-table">
            <thead>
              <tr>
                <th v-for="(c, ci) in p.cols" :key="'c' + ci">
                  <ElInput v-model="p.cols[ci]" size="small" placeholder="表头" />
                </th>
                <th class="w-10"><ElButton size="small" text type="danger" @click="removeCol(p)">−</ElButton></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, ri) in p.rows" :key="'r' + ri">
                <td v-for="(cell, ci) in row" :key="ci">
                  <ElInput v-model="row[ci]" size="small" placeholder="内容" />
                </td>
                <td />
              </tr>
            </tbody>
          </table>
          <div class="mt-2 flex gap-2">
            <ElButton size="small" plain @click="addRow(p)">加一行</ElButton>
            <ElButton size="small" plain @click="addCol(p)">加一列</ElButton>
            <ElButton size="small" text type="danger" @click="removeRow(p)">删末行</ElButton>
          </div>
        </div>

        <div class="flex gap-2">
          <ElButton type="primary" size="small" @click="saveAll()">保存</ElButton>
          <ElButton size="small" text type="danger" :disabled="list.length <= 1" @click="removeProduct(pi)">删除此产品</ElButton>
        </div>
      </ElCollapseItem>
    </ElCollapse>
  </div>
</template>

<script setup lang="ts">
  import { useSalesStore } from '@/store/modules/sales'
  import type { SalesProduct } from '@/store/modules/sales'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'SalesProducts' })

  const sales = useSalesStore()
  const list = ref<SalesProduct[]>(JSON.parse(JSON.stringify(sales.state.products)))
  const opened = ref(String(list.value[0]?.id ?? ''))

  function nextId() {
    return Math.max(0, ...list.value.map((p) => p.id)) + 1
  }

  function addProduct() {
    if (list.value.length >= 5) {
      ElMessage.warning('最多5个产品')
      return
    }
    const id = nextId()
    list.value.push({ id, name: '', desc: '', showPrice: true, cols: ['卡项', '次数', '标准价'], rows: [['', '', '']] })
    opened.value = String(id)
  }

  function addRow(p: SalesProduct) {
    p.rows.push(new Array(p.cols.length).fill(''))
  }

  function addCol(p: SalesProduct) {
    if (p.cols.length >= 6) {
      ElMessage.warning('最多6列')
      return
    }
    p.cols.push('')
    p.rows.forEach((r) => r.push(''))
  }

  function removeRow(p: SalesProduct) {
    if (p.rows.length > 1) p.rows.pop()
  }

  function removeCol(p: SalesProduct) {
    if (p.cols.length > 1) {
      p.cols.pop()
      p.rows.forEach((r) => r.pop())
    }
  }

  function removeProduct(idx: number) {
    list.value.splice(idx, 1)
    saveAll(true)
  }

  function saveAll(silent = false) {
    sales.saveProducts(list.value)
    if (!silent) ElMessage.success('产品与价目表已保存')
  }
</script>

<style scoped lang="scss">
  .price-table {
    min-width: 100%;

    th,
    td {
      padding: 2px 4px;
    }

    th {
      font-weight: 400;
    }
  }
</style>
