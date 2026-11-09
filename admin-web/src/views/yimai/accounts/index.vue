<template>
  <div class="list-page list-page--fill">
    <ElCard>
      <template #header>
        <div class="flex-cb">
          <span class="font-500">账号与角色</span>
          <div class="flex-c gap-2">
            <ElButton size="small" @click="openMapping">身份归一</ElButton>
            <ElButton type="primary" size="small" @click="openCreate">开通新账号</ElButton>
          </div>
        </div>
      </template>

      <!--
        排序条
        ------
        与列头箭头读写同一份 sortState（理由见 script 里「排序」段的注释）。放在表格
        上方而不是卡片标题行：手持端也要用，而标题行在 390px 上已经被两个按钮占满；
        `.filter-bar` 的档位与换行规则由全站统一样式给，这里不写任何像素宽度。
      -->
      <div class="filter-bar">
        <span class="text-sm text-gray-400">排序</span>
        <ElSelect v-model="sortState.prop" class="f-xl" @change="onSortPropChange">
          <ElOption v-for="o in SORT_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
        </ElSelect>
        <!-- 升降序按钮在「默认」下不渲染：默认模式没有方向，留一个禁用的「降序」会让人
             读成「默认 降序」，以为排过序 -->
        <ElButton v-if="sortState.prop !== 'default'" @click="toggleSortOrder">
          {{ sortState.order === 'ascending' ? '升序' : '降序' }}
        </ElButton>
        <!-- 自动排序只能表达「按哪个字段」，表达不了「店长在首位、老师按资历」这类业务顺序，
             那类顺序靠拖动定，存进 users.sort_order 后就是列表的「默认」 -->
        <ElButton @click="openOrder">调整顺序</ElButton>
        <div class="filter-bar__spacer" />
        <span class="text-xs text-gray-400">
          共 {{ accounts.length }} 个账号<template v-if="disabledCount > 0">
            （{{ disabledCount }} 个停用）</template
          >
        </span>
      </div>

      <!-- 手持设备：改用卡片列表。宽表格的「操作」列 fixed=right 宽 300px，
           390px 视口里左右固定列就吃满了可见宽度，中间的数据列会被挤成零宽 -->
      <ElTable
        v-if="!isHandheld"
        ref="tableRef"
        :data="sortedAccounts"
        border
        stripe
        v-loading="loading"
        :max-height="tableMaxHeight"
        @sort-change="onSortChange"
      >
        <ElTableColumn
          prop="userName"
          label="姓名"
          width="120"
          sortable="custom"
          :sort-orders="SORT_ORDERS"
        />
        <ElTableColumn
          prop="key"
          label="登录名"
          width="130"
          sortable="custom"
          :sort-orders="SORT_ORDERS"
        >
          <template #default="{ row }">
            <span>{{ row.key }}</span>
            <ElTag v-if="row.self" size="small" effect="plain" class="ml-1">本人</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          prop="roles"
          label="角色"
          width="190"
          sortable="custom"
          :sort-orders="SORT_ORDERS"
        >
          <template #default="{ row }">
            <ElTag
              v-for="r in row.roles"
              :key="r"
              size="small"
              :type="roleType(r)"
              effect="dark"
              class="mr-1"
            >
              {{ ROLE_OPTIONS[r] ?? r }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          prop="venues"
          label="门店范围"
          min-width="160"
          sortable="custom"
          :sort-orders="SORT_ORDERS"
        >
          <template #default="{ row }">
            <ElTag v-for="v in row.venues" :key="v" size="small" effect="plain" class="mr-1">{{
              v
            }}</ElTag>
            <span v-if="!row.venues?.length" class="text-xs text-gray-400">双店</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          prop="status"
          label="状态"
          width="90"
          sortable="custom"
          :sort-orders="SORT_ORDERS"
        >
          <template #default="{ row }">
            <ElTag size="small" :type="row.status === '启用' ? 'success' : 'info'">{{
              row.status
            }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="email" label="邮箱" min-width="180" />
        <ElTableColumn label="操作" width="300" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" :disabled="row.self" @click="openEdit(row)"
              >编辑</ElButton
            >
            <ElButton
              v-if="row.status === '启用'"
              link
              type="danger"
              size="small"
              :disabled="row.self"
              @click="doDisable(row)"
              >停用</ElButton
            >
            <ElButton v-else link type="success" size="small" @click="doEnable(row)">启用</ElButton>
            <ElButton
              link
              type="warning"
              size="small"
              :disabled="row.self"
              @click="doResetPassword(row)"
              >重置密码</ElButton
            >
            <ElButton link type="danger" size="small" :disabled="row.self" @click="doDelete(row)"
              >删除</ElButton
            >
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表。账号管理最该一眼看到的是「这个人是谁 / 什么身份 / 能不能登录」，
           所以卡片保留 姓名+登录名+角色+门店+状态，操作按危险程度分区（主操作平铺、破坏性操作收进「更多」） -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="row in cardRows"
          :key="row.key"
          :title="row.userName"
          :subtitle="`登录名 ${row.key}${row.email ? ` · ${row.email}` : ''}`"
          :tags="row.tags"
          :metrics="row.metrics"
          :note="row.note"
          :note-label="row.noteLabel"
          :actions="row.actions"
        />
        <div v-if="!loading && !cardRows.length" class="m-card-list__empty">暂无账号</div>
      </div>
    </ElCard>

    <!-- 新增账号 -->
    <ElDialog v-model="createDlg" title="开通新账号" width="460px" destroy-on-close>
      <ElForm label-width="92px">
        <ElFormItem label="姓名">
          <ElInput v-model="form.name" placeholder="真实姓名，用于展示与会籍归属" maxlength="20" />
        </ElFormItem>
        <ElFormItem label="登录名">
          <ElInput v-model="form.userName" placeholder="字母数字，将用于登录" />
        </ElFormItem>
        <ElFormItem label="角色">
          <ElCheckboxGroup v-model="form.roles">
            <ElCheckbox v-for="(label, code) in ROLE_OPTIONS" :key="code" :value="code">
              {{ label }}
            </ElCheckbox>
          </ElCheckboxGroup>
          <div class="text-xs text-gray-400">
            可多选：权限叠加，可见范围取并集（例如同时是服务老师 + 授课老师时，
            名下会籍会员与私教课学员都能看到；叠加店长则放大到全店）
          </div>
        </ElFormItem>
        <ElFormItem label="门店范围">
          <ElCheckboxGroup v-model="form.venues">
            <ElCheckbox value="绿地店">绿地店</ElCheckbox>
            <ElCheckbox value="东部店">东部店</ElCheckbox>
          </ElCheckboxGroup>
          <span v-if="!needsVenue(form.roles)" class="text-xs text-gray-400">
            仅超管/新媒体时默认为双店；勾选店长或老师侧角色后锁定单一门店
          </span>
        </ElFormItem>
        <ElFormItem label="初始化密码">
          <ElInput
            v-model="form.password"
            type="password"
            show-password
            placeholder="至少8位，首次登录后建议修改"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="createDlg = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="doCreate">开通</ElButton>
      </template>
    </ElDialog>

    <!-- 重置密码 -->
    <ElDialog
      v-model="pwdDlg.visible"
      :title="`重置密码 · ${pwdDlg.row?.userName ?? ''}`"
      width="400px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="新密码">
          <ElInput v-model="pwdForm.password" type="password" show-password placeholder="至少8位" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="pwdDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="doReset">确认重置</ElButton>
      </template>
    </ElDialog>

    <!--
      人员身份归一

      同一个人在三处可能被写成三个名字：随心瑜登记的花名、留资时手输的名字、
      工作台注册时的真名。而归属列（service_teacher / owner / consultant /
      teacher_name / created_by）存的是**姓名字符串**，不是外键 ——「谁能看到这条
      数据」完全取决于姓名能不能对上账号。

      这里做三件事，缺一件就会有人看不到自己的客资：
        ① 认领：把悬空姓名挂到账号上（写别名 + 回填历史行 id）；
        ② 补齐：把所有归属列里还没落 id 的行按当前名字池补上（可重复执行）；
        ③ 合并：同一个人被建了两个账号时，连人带数据并成一个。
    -->
    <ElDialog v-model="mapDlg.visible" title="人员身份归一" width="920px" destroy-on-close>
      <ElAlert v-if="mapDlg.unmapped.length" type="warning" :closable="false" class="mb-3">
        有 {{ mapDlg.unmapped.length }} 个姓名出现在业务数据里、但对不上任何账号<template
          v-if="mapDlg.totals && mapDlg.totals.missingIds"
          >；共 {{ mapDlg.totals.missingIds }} 行归属还没落到账号 id</template
        >。这些数据的归属是悬空的（本人看不到），认领后立刻恢复。
      </ElAlert>
      <ElAlert v-else type="success" :closable="false" class="mb-3">
        所有归属姓名都能对上账号。<template v-if="mapDlg.totals && mapDlg.totals.missingIds"
          >另有 {{ mapDlg.totals.missingIds }} 行还没落 id，点右侧「补齐归属 id」即可。</template
        >
      </ElAlert>

      <div class="mb-3 flex flex-wrap items-center gap-2">
        <ElButton
          size="small"
          type="primary"
          plain
          :loading="mapDlg.repairing"
          @click="doRepair(false)"
        >
          补齐归属 id
        </ElButton>
        <ElButton size="small" :loading="mapDlg.repairing" @click="doRepair(true)">
          清理悬挂 id 并补齐
        </ElButton>
        <span class="text-xs text-gray-400">可重复执行：只填空值，不改已归属好的行</span>
      </div>

      <div v-if="mapDlg.unmapped.length" class="mb-2 text-sm font-500">悬空姓名 → 认领到账号</div>
      <ElTable
        v-if="mapDlg.unmapped.length"
        :data="mapDlg.unmapped"
        border
        size="small"
        max-height="260"
      >
        <ElTableColumn prop="name" label="姓名" width="120" />
        <ElTableColumn label="出现在" min-width="180">
          <template #default="{ row }">
            <ElTag v-for="(c, k) in row.counts" :key="k" size="small" class="mr-1 mb-1">
              {{ k }} × {{ c }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="认领到账号" width="250">
          <template #default="{ row }">
            <ElSelect
              v-model="mapDlg.picks[row.name]"
              placeholder="选择账号"
              size="small"
              filterable
              class="!w-full"
            >
              <ElOption
                v-for="a in mapDlg.accounts"
                :key="a.key"
                :label="`${a.name}（${a.key}）`"
                :value="a.key"
              />
            </ElSelect>
            <div v-if="row.suggestions && row.suggestions.length" class="text-xs text-gray-400">
              建议 {{ row.suggestions[0].name }}：{{ row.suggestions[0].reason }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="80" align="center">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              :loading="mapDlg.assigning === row.name"
              @click="assignOne(row)"
            >
              认领
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="mt-4 mb-2 text-sm font-500">各账号的名字与名下数据</div>
      <div class="text-xs text-gray-400 mb-2">
        一个名字只能属于一个账号。规范名恒被认；别名（含昵称/改名前的旧名/随心瑜里的叫法）在这里补，
        保存后历史行会一并回填 id。
      </div>
      <ElTable :data="mapDlg.accounts" border size="small" max-height="260">
        <ElTableColumn label="账号" width="150">
          <template #default="{ row }">
            <div class="text-sm">{{ row.name }}</div>
            <div class="text-xs text-gray-400">
              {{ row.key }}<template v-if="row.nickname"> · 昵称 {{ row.nickname }}</template>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="别名（可编辑）" min-width="240">
          <template #default="{ row }">
            <ElSelect
              v-model="mapDlg.aliases[row.key]"
              multiple
              filterable
              allow-create
              default-first-option
              size="small"
              class="!w-full"
              placeholder="该账号在业务数据里出现过的其它姓名"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="名下数据" min-width="180">
          <template #default="{ row }">
            <ElTag
              v-for="(c, k) in row.footprint || {}"
              :key="k"
              size="small"
              type="info"
              class="mr-1 mb-1"
            >
              {{ k }} × {{ c }}
            </ElTag>
            <span
              v-if="!row.footprint || !Object.keys(row.footprint).length"
              class="text-xs text-gray-400"
            >
              名下暂无数据
            </span>
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="mt-4 mb-2 text-sm font-500">合并账号（同一个人被建了两次）</div>
      <div class="flex flex-wrap items-center gap-2">
        <ElSelect
          v-model="mapDlg.mergeInto"
          placeholder="并入到…"
          size="small"
          filterable
          style="width: 200px"
        >
          <ElOption
            v-for="a in mapDlg.accounts"
            :key="a.key"
            :label="`${a.name}（${a.key}）`"
            :value="a.key"
          />
        </ElSelect>
        <ElSelect
          v-model="mapDlg.mergeFrom"
          multiple
          placeholder="要合并掉的账号（可多选）"
          size="small"
          filterable
          style="flex: 1; min-width: 220px"
        >
          <ElOption
            v-for="a in mapDlg.accounts.filter((x) => x.key !== mapDlg.mergeInto)"
            :key="a.key"
            :label="`${a.name}（${a.key}）`"
            :value="a.key"
          />
        </ElSelect>
        <ElButton
          size="small"
          type="danger"
          plain
          :loading="mapDlg.merging"
          :disabled="!mapDlg.mergeInto || mapDlg.mergeFrom.length === 0"
          @click="doMerge"
        >
          合并
        </ElButton>
      </div>
      <div class="text-xs text-gray-400 mt-1">
        合并会把源账号的会员/客资/排课改成目标账号的姓名与
        id，并**停用**源账号（数据不删，可追溯）。
      </div>

      <div v-if="mapDlg.staleIds.length" class="mt-4">
        <div class="mb-1 text-sm font-500">待清理：归属 id 指向已不存在的账号</div>
        <div class="text-xs text-gray-400 mb-2">
          账号删掉重建后会留下这些行。本人仍能通过姓名看到数据，但建议清掉 id
          以免将来被两人同时认领（可用上方「清理悬挂 id 并补齐」）。
        </div>
        <ElTag
          v-for="(s, i) in mapDlg.staleIds"
          :key="`${s.table}-${s.column}-${i}`"
          size="small"
          type="warning"
          class="mr-1 mb-1"
        >
          {{ s.table }}.{{ s.column }} → 账号 {{ s.user_id }}（{{ s.rows }} 行）
        </ElTag>
      </div>

      <template #footer>
        <ElButton @click="mapDlg.visible = false">关闭</ElButton>
        <ElButton type="primary" :loading="mapDlg.saving" @click="saveMapping">保存别名</ElButton>
      </template>
    </ElDialog>

    <!-- 编辑账号（姓名 / 角色 / 门店范围） -->
    <ElDialog
      v-model="editDlg.visible"
      :title="`编辑账号 · ${editDlg.row?.userName ?? ''}`"
      width="460px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="姓名">
          <ElInput v-model="editForm.name" maxlength="20" placeholder="真实姓名" />
          <div class="text-xs text-gray-400">
            这是<strong>归属名</strong>：留资/会员的「会籍顾问」存的就是这个名字。
            必须与随心瑜里登记的叫法能从「身份归一」对上，本人才能看到自己的数据。
            改名后旧名会自动登记为别名，历史数据不受影响。
          </div>
        </ElFormItem>
        <ElFormItem label="角色">
          <ElCheckboxGroup v-model="editForm.roles">
            <ElCheckbox v-for="(label, code) in ROLE_OPTIONS" :key="code" :value="code">
              {{ label }}
            </ElCheckbox>
          </ElCheckboxGroup>
          <div class="text-xs text-gray-400">
            调整角色会立即失效该账号的登录状态，需要重新登录后生效
          </div>
        </ElFormItem>
        <ElFormItem label="门店范围">
          <ElCheckboxGroup v-model="editForm.venues">
            <ElCheckbox value="绿地店">绿地店</ElCheckbox>
            <ElCheckbox value="东部店">东部店</ElCheckbox>
          </ElCheckboxGroup>
          <span v-if="!needsVenue(editForm.roles)" class="text-xs text-gray-400">
            仅超管/新媒体时默认为双店；勾选店长或老师侧角色后锁定单一门店
          </span>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="editDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="doEdit">保存</ElButton>
      </template>
    </ElDialog>

    <!--
      调整顺序（拖动排序）

      为什么是弹窗里的独立名单，而不是直接拖表格行：
        · 表格带 fixed=right 的操作列，横向滚动时固定列与拖拽预览会互相错位；
        · 手持端根本没有表格（卡片列表），两套渲染各写一份拖拽等于两份维护;
        · 拖动是「改全局顺序」的高影响动作，放进一个明确边界的面板里，
          配「取消」比在长表格里误拖安全。
      拖动库是全站已在用的 vue-draggable-plus（表头列自定义顺序也用它），
      handle 限定在把手，避免点按钮/选文字时被误判成拖动。
    -->
    <ElDialog v-model="orderDlg.visible" title="调整人员顺序" width="520px" destroy-on-close>
      <div class="text-xs text-gray-400 mb-3">
        按住左侧把手上下拖动。保存后这就是列表的「默认」顺序（新开通的账号一律排在末尾）。
      </div>
      <ElScrollbar max-height="52vh">
        <VueDraggable v-model="orderDlg.list" :animation="150" handle=".order-row__handle">
          <div v-for="(row, i) in orderDlg.list" :key="row.key" class="order-row">
            <span class="order-row__handle" title="拖动">
              <ArtSvgIcon icon="ri:drag-move-2-fill" />
            </span>
            <span class="order-row__idx">{{ i + 1 }}</span>
            <span class="order-row__name">{{ row.userName }}</span>
            <span class="order-row__key">{{ row.key }}</span>
            <span class="order-row__tags">
              <ElTag
                v-for="r in row.roles"
                :key="r"
                size="small"
                :type="roleType(r)"
                effect="plain"
                class="mr-1"
              >
                {{ ROLE_OPTIONS[r] ?? r }}
              </ElTag>
              <ElTag size="small" :type="row.status === '启用' ? 'success' : 'info'" effect="plain">
                {{ row.status }}
              </ElTag>
            </span>
          </div>
        </VueDraggable>
      </ElScrollbar>
      <template #footer>
        <ElButton @click="orderDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="orderDlg.saving" @click="saveOrder">保存顺序</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import {
    assignStaffName,
    createAccount,
    getStaffMapping,
    listAccounts,
    mergeStaffAccount,
    reorderAccounts,
    repairStaffMapping,
    saveStaffAliases,
    updateAccount
  } from '@/api/auth'
  import type { AccountRow, StaffMapping } from '@/api/auth'
  import type { TableInstance } from 'element-plus'
  import { VueDraggable } from 'vue-draggable-plus'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'
  import { ElMessage, ElMessageBox, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiAccounts' })

  // 手持设备上用卡片列表代替宽表格（见下方 cardRows）
  const { isHandheld } = useDevice()

  // 表格高度自适应：减项由 hook 运行时量出，本页不写任何像素
  const { tableMaxHeight, tableRef } = useTableHeight()

  const ROLE_OPTIONS: Record<string, string> = {
    R_MANAGER: '店长',
    R_SERVICE: '服务老师',
    R_TEACHER: '授课老师',
    R_MEDIA: '新媒体',
    R_SUPER: '超管'
  }

  const loading = ref(false)
  const saving = ref(false)
  const accounts = ref<AccountRow[]>([])

  // ---------- 排序 ----------
  //
  // ## 为什么是前端全量排序，而不是像「留资管理」那样下推给接口
  //
  // `/accounts` 一次返回**全部**账号（`User::orderBy('id')`，没有分页 —— 一个馆也就
  // 十几到几十个号）。全量在手，排序就只是一个 computed：改顺序不产生请求，也没有
  // 「第 2 页按什么排」的问题。留资管理必须下推是因为它服务端分页（一页 20~200 条），
  // 前端只能在当前页里重排 —— 这里是相反的前提，别照抄那边的做法。
  //
  // ## 为什么两个入口共用一份 sortState
  //
  // 表格宽 1170px（7 列），笔记本 + 侧边栏时「状态」列头会横向滚出视野，光靠列头箭头
  // 看不出「现在按什么排的」；而手持端是卡片列表，**根本没有列头**，排序条是唯一入口。
  // 所以：列头（桌面，符合表格直觉）+ 排序条（永远可见、跨端一致）读写同一个状态。
  //
  // 列头用 `sortable="custom"`：数据由下面的 `sortedAccounts` 排好，让 EP 再排一次
  // 会与卡片列表的顺序打架（同一个 computed 喂两处渲染）。
  //
  // ## 默认值为什么是「手动顺序」而不是姓名
  //
  // 默认顺序必须与后端 `/accounts` 的返回顺序一致（`sort_order` 优先、其次 id）。
  // 若前端默认改成姓名序，表头没有任何箭头、数据却不是后端顺序，排查"新开通的号
  // 去哪了"时会对不上。`sort_order` 没被拖动过时全员为 0，后端顺序即开通先后 ——
  // 所以「默认」在拖过之后是手动顺序，没拖过就是开通先后，两者由后端一处决定。
  const SORT_OPTIONS = [
    { value: 'default', label: '默认（手动顺序）' },
    { value: 'userName', label: '姓名' },
    { value: 'key', label: '登录名' },
    { value: 'roles', label: '角色' },
    { value: 'venues', label: '门店' },
    { value: 'status', label: '状态' }
  ] as const

  type SortProp = (typeof SORT_OPTIONS)[number]['value']
  type SortOrder = 'ascending' | 'descending'

  /**
   * 列头的三档排序：升 / 降 / **清空**。
   *
   * 这里保留「清空」这一档（与留资管理刻意去掉的做法相反）：留资管理清空后会变成
   * 「箭头没了但数据仍按日期倒序」，表头与数据自相矛盾；本页清空有确切含义 —— 回到
   * 后端原始顺序（排序条的「默认」）。EP 的三档默认值即此，显式写出来是为了说明意图。
   */
  const SORT_ORDERS: (SortOrder | null)[] = ['ascending', 'descending', null]

  const sortState = reactive<{ prop: SortProp; order: SortOrder }>({
    prop: 'default',
    order: 'ascending'
  })

  /**
   * 角色排序位（数字越小越靠前）
   *
   * 与后端 `AccountController::ASSIGNABLE` 的「顺序即权限从大到小」保持一致：
   * 超管 > 店长 > 授课老师 > 服务老师 > 新媒体。多角色账号取**最高**那个身份 ——
   * 人读的是「他主要是谁」，不是「他还兼了什么」。
   */
  const ROLE_RANK: Record<string, number> = {
    R_SUPER: 0,
    R_MANAGER: 1,
    R_TEACHER: 2,
    R_SERVICE: 3,
    R_MEDIA: 4
  }

  function roleRank(row: AccountRow): number {
    const roles = row.roles?.length ? row.roles : [row.roleCode]
    const ranks = roles.filter(Boolean).map((r) => ROLE_RANK[r] ?? 90)
    return ranks.length ? Math.min(...ranks) : 99
  }

  /**
   * 门店排序位：绿地店 → 东部店 → 双店。
   *
   * `venues` 为空数组在后端表示「双店（不限店）」，通常是超管/新媒体。升序时把它排在
   * 最后：先看得到「属于某个店的人」，管理层集中在末尾，比混在中间更好数。
   */
  const VENUE_RANK: Record<string, number> = { 绿地店: 0, 东部店: 1 }

  function venueRank(row: AccountRow): number {
    if (!row.venues?.length) return 2
    return Math.min(...row.venues.map((v) => VENUE_RANK[v] ?? 9))
  }

  /** 姓名序：用浏览器 Intl 的中文排序（拼音），与会员/留资页的 `localeCompare(x,'zh')` 同口径 */
  function byName(a: AccountRow, b: AccountRow): number {
    return a.userName.localeCompare(b.userName, 'zh')
  }

  function compareBy(a: AccountRow, b: AccountRow, prop: SortProp): number {
    switch (prop) {
      case 'userName':
        return byName(a, b)
      case 'key':
        return a.key.localeCompare(b.key)
      case 'status':
        // 升序 = 启用在前：停用（离职/交接）的账号沉到底部，别占着第一屏
        return (a.status === '启用' ? 0 : 1) - (b.status === '启用' ? 0 : 1)
      case 'roles':
        return roleRank(a) - roleRank(b)
      case 'venues':
        return venueRank(a) - venueRank(b)
      default:
        return 0
    }
  }

  /**
   * 排序后的账号列表 —— 表格与手持卡片**共用这一份**，两处不可能出现不同顺序。
   *
   * 两级比较：主键按 `sortState.order` 定方向，主键相同的行**始终**按姓名升序收尾。
   * 若第二级也跟着降序，同角色/同状态的人会倒着排，扫视时人名来回跳。
   */
  const sortedAccounts = computed<AccountRow[]>(() => {
    const { prop, order } = sortState
    if (prop === 'default') return accounts.value // 后端原始顺序，原样返回（不拷贝）
    const dir = order === 'ascending' ? 1 : -1
    // 拷贝再排：就地 sort 会把 ref 里缓存的数组改掉，切回「默认」就回不到开通先后了
    return [...accounts.value].sort((a, b) => {
      const d = compareBy(a, b, prop)
      if (d !== 0) return d * dir
      // 主键相同：姓名升序兜底；按姓名排时用登录名兜底（同名不同号也能稳定）
      return prop === 'userName' ? a.key.localeCompare(b.key) : byName(a, b)
    })
  })

  /** 统计行：排序后顺手给出量级，省得数行 */
  const disabledCount = computed(() => accounts.value.filter((a) => a.status !== '启用').length)

  /**
   * 排序条切换字段
   *
   * 必须把状态回灌给 ElTable：列头的箭头由 EP 内部维护，只改 `sortedAccounts` 不改
   * 表格的话，用户会看到「数据按姓名排好了，表头箭头还停在状态上」。`sort()` 会回抛
   * sort-change（init 为空），落到 onSortChange 时值与这里刚写的一样，不会来回弹。
   */
  function onSortPropChange() {
    const table = tableRef.value as TableInstance | undefined
    if (sortState.prop === 'default') {
      // clearSort 是 silent 的（EP 内部 silent: true），不会回抛事件
      table?.clearSort()
      return
    }
    table?.sort(sortState.prop, sortState.order)
  }

  /** 升降序翻转（排序条按钮；「默认」态该按钮不渲染，所以这里收不到 default） */
  function toggleSortOrder() {
    if (sortState.prop === 'default') return
    sortState.order = sortState.order === 'ascending' ? 'descending' : 'ascending'
    const table = tableRef.value as TableInstance | undefined
    table?.sort(sortState.prop, sortState.order)
  }

  /** 列头排序 → 排序条跟随（含第三下清空：清空即回到「默认（开通先后）」） */
  function onSortChange(payload: { prop: string | null; order: string | null }) {
    if (!payload.order) {
      sortState.prop = 'default'
      return
    }
    sortState.prop = (payload.prop ?? 'default') as SortProp
    sortState.order = payload.order === 'ascending' ? 'ascending' : 'descending'
  }

  // ---------- 手动顺序（拖动排序） ----------
  //
  // 自动排序（按姓名/角色/门店…）表达不了「店长在首位、老师按资历排、离职的放末尾」
  // 这类业务顺序，那类顺序只能由人拖着定。存到后端 users.sort_order 之后，它就是
  // 列表的「默认」顺序 —— 也就是所有人打开页面看到的第一个样子。
  const orderDlg = reactive<{ visible: boolean; saving: boolean; list: AccountRow[] }>({
    visible: false,
    saving: false,
    list: []
  })

  function openOrder() {
    // 起点用**当前屏幕上看到的顺序**，而不是 accounts 的原始顺序：用户是照着屏幕拖的，
    // 若给他一个和屏幕不同的起点，第一下拖动就会「跳位」，看着像拖错了行。
    // （当前是「按角色」排的话，起点就是角色序 —— 保存后它才变成默认顺序。）
    orderDlg.list = [...sortedAccounts.value]
    orderDlg.visible = true
  }

  async function saveOrder() {
    orderDlg.saving = true
    try {
      const r = await reorderAccounts(orderDlg.list.map((x) => x.key))
      // 保存后立刻切回「默认」：手动顺序就是默认顺序。若停在「按姓名」，
      // 表格看起来没变化，用户会以为没保存成功。
      sortState.prop = 'default'
      sortState.order = 'ascending'
      const table = tableRef.value as TableInstance | undefined
      table?.clearSort()
      orderDlg.visible = false
      await load()
      ElMessage.success(`顺序已保存（${r.updated} 个账号）`)
    } catch (e) {
      ElMessage.error(e instanceof Error ? e.message : '保存顺序失败')
    } finally {
      orderDlg.saving = false
    }
  }

  // ---------- 人员身份归一 ----------
  const mapDlg = reactive({
    visible: false,
    saving: false,
    repairing: false,
    merging: false,
    /** 正在认领的姓名（按钮 loading 用；按名字而不是按行，因为同名只可能有一行） */
    assigning: '',
    accounts: [] as StaffMapping['accounts'],
    unmapped: [] as StaffMapping['unmapped'],
    staleIds: [] as StaffMapping['staleIds'],
    coverage: [] as NonNullable<StaffMapping['coverage']>,
    totals: undefined as StaffMapping['totals'],
    aliases: {} as Record<string, string[]>,
    // 「未映射的姓名 → 要映射到哪个账号」，保存时并入该账号的别名
    picks: {} as Record<string, string>,
    // 合并账号
    mergeInto: '',
    mergeFrom: [] as string[]
  })

  function openMapping() {
    mapDlg.visible = true
    void loadMapping()
  }

  async function loadMapping() {
    try {
      const d = await getStaffMapping()
      mapDlg.accounts = d.accounts
      mapDlg.unmapped = d.unmapped
      mapDlg.staleIds = d.staleIds
      mapDlg.coverage = d.coverage ?? []
      mapDlg.totals = d.totals
      mapDlg.aliases = Object.fromEntries(d.accounts.map((a) => [a.key, [...a.aliases]]))
      mapDlg.mergeFrom = []
      // 未映射姓名预填「系统建议的账号」：大多数情况下点一下「认领」就完事，
      // 不用每次都在几十个账号里翻。建议为空的保持空，让人自己选。
      mapDlg.picks = Object.fromEntries(
        d.unmapped.map((u) => [u.name, u.suggestions?.[0]?.key ?? ''])
      )
    } catch (e) {
      ElMessage.error(e instanceof Error ? e.message : '身份归一加载失败')
    }
  }

  /** 认领一个悬空姓名：写别名 + 回填历史行 id，本人立刻能看到 */
  async function assignOne(row: StaffMapping['unmapped'][number]) {
    const key = mapDlg.picks[row.name]
    if (!key) {
      ElMessage.warning('先选择要认领到哪个账号')
      return
    }
    mapDlg.assigning = row.name
    try {
      const r = await assignStaffName(row.name, key)
      ElMessage.success(
        r.backfilled > 0
          ? `已把「${row.name}」归到该账号，回填 ${r.backfilled} 行历史数据`
          : `已把「${row.name}」归到该账号`
      )
      await loadMapping()
      await load()
    } catch (e) {
      ElMessage.error(e instanceof Error ? e.message : '认领失败')
    } finally {
      mapDlg.assigning = ''
    }
  }

  /** 全量补齐归属 id；clearStale=true 时先清掉指向已删账号的悬挂 id */
  async function doRepair(clearStale: boolean) {
    mapDlg.repairing = true
    try {
      const r = await repairStaffMapping({ clearStale })
      const parts = [`回填 ${r.backfilled} 行`]
      if (r.cleared > 0) parts.push(`清理悬挂 id ${r.cleared} 行`)
      ElMessage.success(parts.join('，'))
      await loadMapping()
      await load()
    } catch (e) {
      ElMessage.error(e instanceof Error ? e.message : '修复失败')
    } finally {
      mapDlg.repairing = false
    }
  }

  /** 账号合并：把多余的账号连人带数据并进保留的那个 */
  async function doMerge() {
    if (!mapDlg.mergeInto || mapDlg.mergeFrom.length === 0) return
    const target = mapDlg.accounts.find((a) => a.key === mapDlg.mergeInto)
    mapDlg.merging = true
    try {
      await ElMessageBox.confirm(
        `把「${mapDlg.mergeFrom.join('、')}」合并到「${target?.name ?? mapDlg.mergeInto}」？\n` +
          '源账号名下的会员/客资/排课会改成目标账号，源账号将被停用（数据不删）。',
        '合并账号',
        { type: 'warning', confirmButtonText: '确认合并', cancelButtonText: '取消' }
      )
    } catch {
      mapDlg.merging = false
      return
    }
    try {
      const r = await mergeStaffAccount(mapDlg.mergeInto, mapDlg.mergeFrom)
      ElMessage.success(`已合并：迁移 ${r.rows} 行数据、${r.aliases} 个别名`)
      if (r.skipped?.length) {
        ElMessage.warning(`跳过：${r.skipped.join('；')}`)
      }
      await loadMapping()
      await load()
    } catch (e) {
      ElMessage.error(e instanceof Error ? e.message : '合并失败')
    } finally {
      mapDlg.merging = false
    }
  }

  async function saveMapping() {
    mapDlg.saving = true
    try {
      // 先把「未映射 → 账号」的选择并进该账号的别名，再整体保存
      for (const u of mapDlg.unmapped) {
        const key = mapDlg.picks[u.name]
        if (!key) continue
        const list = mapDlg.aliases[key] ?? []
        if (!list.includes(u.name)) mapDlg.aliases[key] = [...list, u.name]
      }
      for (const a of mapDlg.accounts) {
        await saveStaffAliases(a.key, mapDlg.aliases[a.key] ?? [])
      }
      ElMessage.success('已保存，归属立即生效')
      await loadMapping()
      await load()
    } catch (e) {
      ElMessage.error(e instanceof Error ? e.message : '保存失败')
    } finally {
      mapDlg.saving = false
    }
  }

  /** 需要锁定单一门店的角色（与后端 VENUE_BOUND 一致） */
  const VENUE_BOUND = ['R_MANAGER', 'R_SERVICE', 'R_TEACHER']
  function needsVenue(roles: string[]): boolean {
    return roles.some((r) => VENUE_BOUND.includes(r))
  }

  const createDlg = ref(false)
  const form = reactive({
    name: '',
    userName: '',
    roles: ['R_TEACHER'] as string[],
    venues: ['绿地店'] as string[],
    password: ''
  })

  const pwdDlg = reactive<{ visible: boolean; row: AccountRow | null }>({
    visible: false,
    row: null
  })
  const pwdForm = reactive({ password: '' })

  const editDlg = reactive<{ visible: boolean; row: AccountRow | null }>({
    visible: false,
    row: null
  })
  const editForm = reactive({
    name: '',
    roles: ['R_TEACHER'] as string[],
    venues: ['绿地店'] as string[]
  })

  function roleType(code: string): 'warning' | 'success' | 'primary' | 'info' | 'danger' {
    if (code === 'R_SUPER') return 'warning'
    if (code === 'R_MANAGER') return 'primary'
    if (code === 'R_SERVICE') return 'success'
    if (code === 'R_TEACHER') return 'danger'
    return 'info'
  }

  async function load() {
    loading.value = true
    try {
      accounts.value = await listAccounts()
    } finally {
      loading.value = false
    }
  }

  function openCreate() {
    Object.assign(form, {
      name: '',
      userName: '',
      roles: ['R_TEACHER'],
      venues: ['绿地店'],
      password: ''
    })
    createDlg.value = true
  }

  async function doCreate() {
    if (!form.userName.trim()) return ElMessage.warning('请填写登录名')
    if (!/^[A-Za-z0-9]+$/.test(form.userName)) return ElMessage.warning('登录名只能包含字母和数字')
    if (form.password.length < 8) return ElMessage.warning('密码至少8位')
    if (!form.roles.length) return ElMessage.warning('请至少选择一个角色')
    const venues = needsVenue(form.roles) ? [...form.venues] : ['绿地店', '东部店']
    if (!venues.length) return ElMessage.warning('请至少选择一个门店')
    saving.value = true
    try {
      await createAccount({
        userName: form.userName.trim(),
        name: form.name.trim() || form.userName.trim(),
        roles: [...form.roles],
        venues,
        password: form.password
      })
      ElMessage.success('账号已开通')
      createDlg.value = false
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      saving.value = false
    }
  }

  function openEdit(row: AccountRow) {
    editDlg.row = row
    editForm.name = row.userName
    editForm.roles = [...(row.roles?.length ? row.roles : [row.roleCode])]
    editForm.venues = [...(row.venues?.length ? row.venues : ['绿地店', '东部店'])]
    editDlg.visible = true
  }

  async function doEdit() {
    if (!editDlg.row) return
    if (!editForm.roles.length) return ElMessage.warning('请至少选择一个角色')
    const venues = needsVenue(editForm.roles) ? [...editForm.venues] : ['绿地店', '东部店']
    if (!venues.length) return ElMessage.warning('请至少选择一个门店')
    const name = editForm.name.trim()
    if (!name) return ElMessage.warning('姓名不能为空')
    saving.value = true
    try {
      // 姓名一起提交：它是归属列的权威名（service_teacher / consultant 存的就是它）。
      // 后端会校验重名，并把旧名自动登记为别名，历史数据不会因为改名而消失。
      await updateAccount(editDlg.row.key, 'update', {
        name,
        roles: [...editForm.roles],
        venues
      })
      ElMessage.success(
        name !== editDlg.row.userName
          ? '已更新（旧名已登记为别名，历史数据仍归本人）'
          : '账号信息已更新'
      )
      editDlg.visible = false
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      saving.value = false
    }
  }

  async function doDisable(row: AccountRow) {
    await ElMessageBox.confirm(
      `确定停用「${row.userName}」？停用后该账号无法再登录。`,
      '停用账号',
      { type: 'warning' }
    )
    try {
      await updateAccount(row.key, 'disable')
      ElMessage.success('已停用')
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    }
  }

  async function doEnable(row: AccountRow) {
    try {
      await updateAccount(row.key, 'enable')
      ElMessage.success('已启用')
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    }
  }

  async function doDelete(row: AccountRow) {
    await ElMessageBox.confirm(
      `确定删除账号「${row.userName}」（登录名 ${row.key}）？删除后无法恢复。`,
      '删除账号',
      { type: 'error' }
    )
    try {
      await updateAccount(row.key, 'delete')
      ElMessage.success('已删除')
      await load()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    }
  }

  function doResetPassword(row: AccountRow) {
    pwdDlg.row = row
    pwdForm.password = ''
    pwdDlg.visible = true
  }

  async function doReset() {
    if (!pwdDlg.row) return
    if (pwdForm.password.length < 8) return ElMessage.warning('密码至少8位')
    saving.value = true
    try {
      await updateAccount(pwdDlg.row.key, 'resetPassword', { password: pwdForm.password })
      ElMessage.success('密码已重置')
      pwdDlg.visible = false
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      saving.value = false
    }
  }

  onMounted(load)

  // ---------- 手持设备卡片 ----------
  //
  // 卡片不是把 7 列表格换个样子：账号管理最该一眼看到的是「这个人是谁 / 什么身份 / 能不能登录」。
  // 保留：姓名（标题）、登录名 + 邮箱（副标题）、角色标签、门店标签、状态标签、本人标记。
  // 对比表格少放的：没有独立成行展示的门店/邮箱 —— 门店改用标签、邮箱并入副标题，
  // 信息都还在卡片上，只是换了更省纵向空间的位置。
  const cardRows = computed(() =>
    sortedAccounts.value.map((row) => {
      const tags: MobileCardTag[] = []
      for (const r of row.roles ?? []) {
        tags.push({ text: ROLE_OPTIONS[r] ?? r, type: roleType(r), effect: 'dark' })
      }
      // 门店：空数组在后端表示「双店」，与表格里的「双店」占位一致，不能静默丢掉
      if (row.venues?.length) {
        for (const v of row.venues) tags.push({ text: v, effect: 'plain' })
      } else {
        tags.push({ text: '双店', effect: 'plain' })
      }
      tags.push({
        text: row.status,
        type: row.status === '启用' ? 'success' : 'info',
        effect: 'plain'
      })
      if (row.self) tags.push({ text: '本人', effect: 'plain' })

      const metrics: MobileCardMetric[] = []

      const actions: MobileCardAction[] = []
      // 本人账号不能改自己的角色/停用/删除（与表格 :disabled 一致）；
      // 主操作平铺，破坏性操作交给 MobileCard 收进「更多」
      actions.push({ text: '编辑', type: 'primary', show: !row.self, onClick: () => openEdit(row) })
      actions.push({
        text: '重置密码',
        type: 'warning',
        show: !row.self,
        onClick: () => doResetPassword(row)
      })
      if (row.status === '启用') {
        actions.push({ text: '停用', show: !row.self, onClick: () => doDisable(row) })
      } else {
        // 停用状态可重新启用，是管理员的常见操作，放在平铺位
        actions.push({ text: '启用', type: 'success', onClick: () => doEnable(row) })
      }
      actions.push({ text: '删除', type: 'danger', show: !row.self, onClick: () => doDelete(row) })

      return {
        key: row.key,
        userName: row.userName,
        email: row.email,
        tags,
        metrics,
        // 自己的账号不能改自己的角色、也不能停用/删除（与表格的 :disabled 一致）。
        // 卡片上没有按钮时要说清原因，不然用户会以为界面坏了。
        note: row.self ? '当前登录账号：为避免把自己锁在外面，不能停用、删除或修改自己的角色' : '',
        noteLabel: row.self ? '本人账号' : '',
        actions
      }
    })
  )
</script>

<style scoped lang="scss">
  /*
    调整顺序弹窗里的行
    ------------------
    这一行只是「给人拖着看的」：身份、状态都保留（拖的时候要认得出是谁），
    但不再画成表格 —— 弹窗里放不下 7 列，横向滚动时拖动预览还会错位。
    把手单独占一格且 cursor:grab，明确「只有抓这里才会拖」，避免选中文字也被判成拖动。
  */
  .order-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1px solid var(--art-border-color, #e5e7eb);
    border-radius: 6px;
    background: var(--art-main-bg-color, #fff);
    margin-bottom: 6px;

    &__handle {
      display: flex;
      align-items: center;
      flex: none;
      color: var(--art-text-gray-500, #9ca3af);
      cursor: grab;

      &:active {
        cursor: grabbing;
      }
    }

    // 序号跟着拖动实时变：这是「保存后会变成第几位」的唯一提示
    &__idx {
      flex: none;
      width: 20px;
      text-align: right;
      color: var(--art-text-gray-400, #b0b4bb);
      font-variant-numeric: tabular-nums;
    }

    &__name {
      flex: none;
      min-width: 64px;
      font-weight: 500;
    }

    &__key {
      flex: none;
      min-width: 80px;
      color: var(--art-text-gray-400, #9ca3af);
      font-size: 12px;
    }

    &__tags {
      flex: 1 1 auto;
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 2px 0;
    }

    @include handheld-only {
      gap: 6px;

      &__key {
        display: none;
      }
    }
  }
</style>
