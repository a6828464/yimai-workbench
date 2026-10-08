<template>
  <div>
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <ElButton type="primary" plain @click="addProduct">新增产品</ElButton>
      <span class="text-xs text-gray-400"
        >最多5个产品 · 每个产品有自己的一套表头与行（独立价目表）· 保存后进入分享页</span
      >
    </div>

    <ElCollapse v-model="opened" accordion>
      <ElCollapseItem v-for="(p, pi) in list" :key="p.id" :name="String(p.id)">
        <template #title>
          <div class="flex items-center gap-3 pr-4">
            <span class="font-500">{{ p.name || `产品${pi + 1}` }}</span>
            <ElTag size="small" :type="p.showPrice ? 'success' : 'info'">{{
              p.showPrice ? '展示价目' : '隐藏价目'
            }}</ElTag>
          </div>
        </template>

        <ElRow :gutter="12">
          <ElCol :xs="24" :md="8"
            ><ElFormItem label="产品名称"><ElInput v-model="p.name" maxlength="20" /></ElFormItem
          ></ElCol>
          <ElCol :xs="24" :md="16"
            ><ElFormItem label="产品说明"><ElInput v-model="p.desc" /></ElFormItem
          ></ElCol>
        </ElRow>
        <ElFormItem label="价目开关"
          ><ElSwitch v-model="p.showPrice" active-text="客户可见" inactive-text="隐藏"
        /></ElFormItem>

        <div
          class="border border-gray-100 dark:border-gray-700 rounded-lg p-3 mb-3 overflow-x-auto"
        >
          <table class="price-table">
            <thead>
              <tr>
                <th v-for="(c, ci) in p.cols" :key="'c' + ci">
                  <ElInput v-model="p.cols[ci]" size="small" placeholder="表头" />
                </th>
                <th class="w-10"
                  ><ElButton size="small" text type="danger" @click="removeCol(p)">−</ElButton></th
                >
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
          <div class="mt-2 flex flex-wrap gap-2">
            <ElButton size="small" plain @click="addRow(p)">加一行</ElButton>
            <ElButton size="small" plain @click="addCol(p)">加一列</ElButton>
            <ElButton size="small" text type="danger" @click="removeRow(p)">删末行</ElButton>
          </div>
        </div>

        <!--
          客户侧实际呈现预览：与本产品在分享页用的是**同一个** `productPriceTable()`。
          为什么要有这块：编辑期的表格看着填好了，不代表客户能看到 ——
          「价目开关」关掉、或整表为空时，分享页显示的是「价目详情请到店咨询」。
          直接给出客户视角，避免「我明明填了价目、客户说没有」这类来回。
        -->
        <div class="mb-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-800/40">
          <div class="mb-2 text-xs text-gray-400"
            >客户看到的价目表（本产品独立表头，不受模板影响）</div
          >
          <div v-if="customerTable(p)" class="overflow-x-auto">
            <table class="customer-table">
              <thead>
                <tr>
                  <th v-for="(c, ci) in customerTable(p)!.cols" :key="'cc' + ci">{{ c }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(cells, ri) in customerTable(p)!.rows" :key="'cr' + ri">
                  <td v-for="(cell, ci) in cells" :key="ci">{{ cell }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-xs text-gray-400"
            >客户看到「价目详情请到店咨询」（价目开关已关，或表头/内容为空）</p
          >
        </div>

        <div class="flex flex-wrap gap-2">
          <ElButton type="primary" size="small" @click="saveAll()">保存</ElButton>
          <ElButton
            size="small"
            text
            type="danger"
            :disabled="list.length <= 1"
            @click="removeProduct(pi)"
            >删除此产品</ElButton
          >
        </div>
      </ElCollapseItem>
    </ElCollapse>
  </div>
</template>

<script setup lang="ts">
  import {
    useSalesStore,
    emptySalesProduct,
    productPriceTable,
    type SalesPriceTable,
    type SalesProduct
  } from '@/store/modules/sales'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'SalesProducts' })

  const sales = useSalesStore()
  const list = ref<SalesProduct[]>(JSON.parse(JSON.stringify(sales.state.products)))
  const opened = ref(String(list.value[0]?.id ?? ''))

  /** 客户侧价目表（null = 分享页显示「到店咨询」文案），与本页预览共用同一函数 */
  function customerTable(p: SalesProduct): SalesPriceTable | null {
    return productPriceTable(p)
  }

  function nextId() {
    return Math.max(0, ...list.value.map((p) => p.id)) + 1
  }

  function addProduct() {
    if (list.value.length >= 5) {
      ElMessage.warning('最多5个产品')
      return
    }
    // 表头来自 store 的 SALES_DEFAULT_PRICE_COLS（单一定义）：
    // 此前这里与 store 的 seed 各写一份、列数还不一样（3 列 vs 4 列），见差距规格 T-11。
    const product = emptySalesProduct(nextId())
    list.value.push(product)
    opened.value = String(product.id)
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

  /* 客户侧价目表小样：与分享页的表格同构（只做示意，列宽自适应） */
  .customer-table {
    width: 100%;
    font-size: 12px;
    border-collapse: collapse;

    th,
    td {
      padding: 6px 8px;
      text-align: left;
      border: 1px solid var(--el-border-color-lighter);
    }

    th {
      font-weight: 500;
      background: #eef5f0;
    }
  }

  /* 移动端触控目标 ≥44px：店长常在手机上改价目，Element 默认 32px 点不中 */
  :deep(.el-button) {
    min-height: 44px;
  }
</style>
