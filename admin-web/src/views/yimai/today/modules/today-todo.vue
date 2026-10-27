<template>
  <ElCard shadow="never" :class="variant === 'full' ? '' : 'mb-4'">
    <template #header>
      <div class="flex-cb">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-500">今日待办</span>
          <ElTag size="small" effect="plain">{{ todo?.date ?? todayIso() }}</ElTag>
          <span class="text-xs text-gray-400">
            {{
              variant === 'full'
                ? '逐项处理：标记后当天消隐并自动流转（留资状态/最近触达）'
                : '今日预约会员 + 需要服务的客户汇总'
            }}
          </span>
        </div>
        <div class="flex items-center gap-2">
          <span v-if="todo?.generatedAt" class="text-xs text-gray-400"
            >生成于 {{ todo.generatedAt.slice(11) }}</span
          >
          <ElButton link type="primary" :loading="loading" @click="reload">刷新</ElButton>
        </div>
      </div>
      <div class="mt-1 text-xs text-gray-400">
        清单阈值（随「会员管理 → 调整标签阈值」实时同步）：待续费 {{ renewalRuleText }} ·
        过期有余额回溯窗 {{ expiredBalanceText }} · 预流失 {{ activeRules.predropMin }}-{{
          activeRules.predropMax
        }}
        天未到店 · 待复活 &gt;{{ activeRules.reviveDays }} 天 · VIP 实收 ≥{{
          formatMoney(activeRules.vipAmountThreshold)
        }}
        元
      </div>
    </template>

    <!-- 概要模式（工作台）：今日三件事聚合 + 分组统计，点击进入完整待办页 -->
    <div v-if="variant === 'summary'" v-loading="loading" class="min-h-20">
      <!-- 今日三件事（P0-1）：三个黄金窗口的优先级聚合，回答「今天最重要的 3 件事」 -->
      <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div
          v-for="item in top3Items"
          :key="item.key"
          class="cursor-pointer rounded-lg border border-gray-200 bg-gray-50 p-3 transition hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
          :class="{
            'border-red-300 bg-red-50 dark:border-red-500/50 dark:bg-red-500/10': item.urgent
          }"
          @click="$router.push({ path: '/yimai/todo', query: { group: item.tab } })"
        >
          <div class="flex flex-wrap items-center justify-between gap-1">
            <span class="text-sm font-500">{{ item.title }}</span>
            <ElTag size="small" :type="item.urgent ? 'danger' : 'info'" effect="plain">
              {{ item.urgencyLabel }}
            </ElTag>
          </div>
          <div
            class="mt-1 text-2xl font-600"
            :class="item.count > 0 ? 'text-primary' : 'text-gray-300'"
          >
            {{ item.count }}
          </div>
          <div class="mt-0.5 truncate text-xs text-gray-400">{{ item.detail }}</div>
        </div>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        <div
          v-for="tab in visibleTabs"
          :key="tab.key"
          class="cursor-pointer rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-center transition hover:bg-gray-100 dark:hover:bg-gray-700"
          @click="$router.push({ path: '/yimai/todo', query: { group: tab.key } })"
        >
          <div
            class="text-2xl font-600"
            :class="pendingCounts[tab.key] > 0 ? 'text-primary' : 'text-gray-300'"
          >
            {{ pendingCounts[tab.key] }}
          </div>
          <div class="mt-0.5 text-xs text-gray-500">{{ tab.label }}</div>
          <div class="mt-0.5 text-xs text-gray-400">{{ tab.timeWindow }}</div>
          <div class="mt-1 h-4 truncate text-xs text-gray-400">
            {{ pendingNames(tab.key) || '暂无' }}
          </div>
          <div v-if="doneCounts[tab.key] > 0" class="text-xs text-green-500">
            已处理 {{ doneCounts[tab.key] }}
          </div>
        </div>
      </div>
    </div>

    <!-- 完整模式（客户经营 → 今日待办）：分组列表 + 逐项操作 -->
    <!-- 手持设备：8 个分组用横向滚动的 chip。ElTabs 在窄屏会退化成左右滚动箭头，只剩 2~3 个可见 -->
    <div v-if="variant !== 'summary' && isHandheld" class="todo-tabbar">
      <button
        v-for="tab in visibleTabs"
        :key="tab.key"
        type="button"
        class="todo-tabbar__item"
        :class="{ 'is-active': activeTab === tab.key }"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
        <span v-if="pendingCounts[tab.key]" class="todo-tabbar__badge">{{
          pendingCounts[tab.key]
        }}</span>
      </button>
    </div>

    <ElTabs
      v-if="variant !== 'summary'"
      v-model="activeTab"
      :class="{ 'todo-tabs--mobile': isHandheld }"
    >
      <ElTabPane v-for="tab in visibleTabs" :key="tab.key" :name="tab.key">
        <template #label>
          <ElBadge
            :value="pendingCounts[tab.key]"
            :max="99"
            :hidden="!pendingCounts[tab.key]"
            type="danger"
          >
            <span class="px-1">{{ tab.label }}</span>
          </ElBadge>
        </template>

        <div v-loading="loading" class="min-h-24">
          <!-- 今日预约 -->
          <template v-if="tab.key === 'bookings'">
            <div
              v-for="b in shownBy('bookings', pendingItems(bookings))"
              :key="b.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="w-11 shrink-0 text-sm font-600 tabular-nums text-primary">{{
                b.time || '--:--'
              }}</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ b.memberName || '未留会员名' }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ b.phone || '' }}</span>
                  <ElTag v-if="b.birthdayToday" size="small" type="danger" effect="dark" round
                    >🎂 今日生日</ElTag
                  >
                  <ElTag v-for="flag in b.lists" :key="flag" size="small" :type="flagType(flag)">{{
                    flag
                  }}</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ b.course || '课程未命名' }} · {{ b.teacher || '老师待定' }} · {{ b.venue }}
                </div>
              </div>
              <ElTag size="small" :type="b.isTrial ? 'warning' : statusType(b.status)">
                {{ b.isTrial ? '体验课' : statusLabel(b.status) }}
              </ElTag>
              <TodoActions
                :actions="actionMenus.bookings"
                @pick="(a) => onMark('bookings', b, a)"
              />
            </div>
          </template>

          <!-- 待续费 -->
          <template v-else-if="tab.key === 'renewals'">
            <div
              v-for="c in shownBy('renewals', pendingItems(renewals))"
              :key="c.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ c.phone || '' }}</span>
                  <ElTag v-if="c.urgent" size="small" type="danger" effect="dark">7天内到期</ElTag>
                  <ElTag
                    v-for="flag in c.lists.filter((f) => f !== '待续课')"
                    :key="flag"
                    size="small"
                    :type="flagType(flag)"
                    >{{ flag }}</ElTag
                  >
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ c.mainCard }}（{{ mainCardStateLabel(renewalDisplay(c).mainCardState) }}） ·
                  {{ c.consultant || c.owner }} ·
                  {{
                    c.hasRenewalPlan ? '已建续费计划，按方案推进' : '建议：今天到店时当面推进续费'
                  }}
                </div>
                <!--
                  判定理由：与会员管理「判定依据」同一份后端 why / whyCodes，
                  前端只做可读映射，不在前端重算（也不解析中文）。
                -->
                <div
                  v-if="renewalWhy(c).length || renewalWhyTags(c).length"
                  class="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-gray-400"
                >
                  <ElTag v-for="t in renewalWhyTags(c)" :key="t" size="small" effect="plain">{{
                    t
                  }}</ElTag>
                  <span v-if="renewalWhy(c).length" class="min-w-0 truncate">{{
                    renewalWhy(c)[0]
                  }}</span>
                </div>
                <!-- 规则没生效时如实说明（与会员管理的降级提示同源） -->
                <div
                  v-if="renewalDegraded(c).length"
                  class="mt-0.5 truncate text-xs text-orange-500"
                  :title="renewalDegraded(c).join('\n')"
                >
                  ⚠ {{ renewalDegraded(c)[0] }}
                </div>
              </div>
              <div class="shrink-0 text-right text-xs">
                <div
                  :class="renewalDisplay(c).low ? 'text-red-500 font-600' : 'text-gray-500'"
                  :title="
                    renewalDisplay(c).exact
                      ? '判定口径：当前可用次卡（不含未开卡）'
                      : '旧快照没有判定口径，按展示口径近似'
                  "
                >
                  剩余 {{ renewalDisplay(c).displayCount ?? '-' }} 节
                </div>
                <div v-if="renewalDisplay(c).storedValue" class="text-gray-500">
                  储值 {{ fmtQty(renewalDisplay(c).storedValue ?? 0, '元') }}
                </div>
                <!-- 到期文案由 expireDateState 决定：历史卡显示「已过期 N 天」，不再一律「今日到期」 -->
                <div class="text-gray-400">{{ renewalDisplay(c).expireText }}</div>
              </div>
              <TodoActions
                :actions="actionMenus.renewals"
                @pick="(a) => onMark('renewals', c, a)"
              />
            </div>
          </template>

          <!-- 流失风险 -->
          <template v-else-if="tab.key === 'churnRisks'">
            <div
              v-for="c in shownBy('churnRisks', pendingItems(churnRisks))"
              :key="c.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ c.phone || '' }}</span>
                  <ElTag v-for="flag in c.lists" :key="flag" size="small" :type="flagType(flag)">{{
                    flag
                  }}</ElTag>
                  <ElTag v-if="c.needsHelp" size="small" type="danger">需协助</ElTag>
                  <ElTag v-if="c.evalLevel === 'low'" size="small" type="danger" effect="plain"
                    >评估低分</ElTag
                  >
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ c.consultant || c.owner }} ·
                  {{
                    c.stopReason
                      ? `停练原因：${c.stopReason}`
                      : '建议：预约一次当面沟通，重建训练节奏'
                  }}
                </div>
              </div>
              <div
                class="shrink-0 text-right text-xs"
                :class="(c.lastVisitDays ?? 0) > 30 ? 'text-red-500 font-600' : 'text-orange-500'"
              >
                {{ c.lastVisitDays !== null ? `${c.lastVisitDays}天未到店` : '无到店记录' }}
              </div>
              <TodoActions
                :actions="actionMenus.churnRisks"
                @pick="(a) => onMark('churnRisks', c, a)"
              />
            </div>
          </template>

          <!-- 生日关怀 -->
          <template v-else-if="tab.key === 'birthdays'">
            <div
              v-for="c in shownBy('birthdays', pendingItems(birthdays))"
              :key="c.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="shrink-0 text-lg">🎂</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ c.phone || '' }}</span>
                  <ElTag v-if="c.isToday" size="small" type="danger" effect="dark">今天生日</ElTag>
                  <ElTag v-else size="small" type="warning" effect="plain"
                    >{{ c.daysLater }}天后生日</ElTag
                  >
                  <ElTag v-for="flag in c.lists" :key="flag" size="small" :type="flagType(flag)">{{
                    flag
                  }}</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ c.birthday }} · 满 {{ c.age }} 岁 · {{ c.venue }} ·
                  {{ c.consultant || c.owner }}
                </div>
              </div>
              <div class="shrink-0 text-xs text-gray-400">
                {{ c.isToday ? '送祝福 + 到店礼' : '提前安排生日关怀' }}
              </div>
              <TodoActions
                :actions="actionMenus.birthdays"
                @pick="(a) => onMark('birthdays', c, a)"
              />
            </div>
          </template>

          <!-- 体验课 -->
          <template v-else-if="tab.key === 'trials'">
            <div
              v-for="t in shownBy('trials', pendingItems(trials))"
              :key="t.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="w-11 shrink-0 text-sm font-600 tabular-nums text-primary">{{
                t.time || '--:--'
              }}</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ t.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ t.phone || '' }}</span>
                  <ElTag
                    size="small"
                    :type="t.source === 'ky' ? 'primary' : 'warning'"
                    effect="plain"
                  >
                    {{ t.source === 'ky' ? '随心瑜预约' : '留资登记' }}
                  </ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ t.topic || '体验主题待定' }} · {{ t.teacher || '老师待定' }} · {{ t.venue }}
                </div>
              </div>
              <ElTag size="small" :type="statusType(t.status)">{{ statusLabel(t.status) }}</ElTag>
              <TodoActions :actions="actionMenus.trials" @pick="(a) => onMark('trials', t, a)" />
            </div>
          </template>

          <!-- 待填写课后分析 -->
          <template v-else-if="tab.key === 'reviews'">
            <div
              v-for="r in shownBy('reviews', pendingItems(reviews))"
              :key="r.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="w-11 shrink-0 text-sm font-600 tabular-nums text-primary">{{
                r.time || '--:--'
              }}</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ r.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ r.phone || '' }}</span>
                  <ElTag v-if="r.isTrial" size="small" type="warning" effect="dark">体验课</ElTag>
                  <ElTag v-else size="small" type="danger" effect="plain">私教</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ r.course || '课程待补充' }} · {{ r.teacher || '老师待定' }} · {{ r.venue }}
                </div>
              </div>
              <div class="shrink-0 text-xs text-gray-400">已签到，待出训练方向</div>
              <TodoActions :actions="actionMenus.reviews" @pick="(a) => onMark('reviews', r, a)" />
            </div>
          </template>

          <!-- 新客首响 -->
          <template v-else-if="tab.key === 'newLeads'">
            <div
              v-for="l in shownBy('newLeads', pendingItems(newLeads))"
              :key="l.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ l.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ l.phone || '' }}</span>
                  <ElTag v-if="l.stale" size="small" type="danger" effect="dark"
                    >超24小时未首响</ElTag
                  >
                  <ElTag v-if="l.grade" size="small" type="warning">{{ l.grade }} 级</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ l.source }} · {{ l.venue }} · {{ l.leadDate }} ·
                  {{ l.serviceTeacher || '待分配服务老师' }}
                </div>
              </div>
              <div class="shrink-0 text-xs text-gray-400">{{ l.demand || '需求待补充' }}</div>
              <TodoActions
                :actions="actionMenus.newLeads"
                @pick="(a) => onMark('newLeads', l, a)"
              />
            </div>
          </template>

          <!-- 今日任务 -->
          <template v-else-if="tab.key === 'tasks'">
            <div
              v-for="t in shownBy('tasks', tasks)"
              :key="t.id"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ t.title }}</span>
                  <span class="text-xs text-gray-400">{{ t.customerName }}</span>
                  <ElTag v-if="t.overdue" size="small" type="danger" effect="dark">已逾期</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ t.venue }} · 负责人 {{ t.owner }} · 截止 {{ t.deadline || '未设' }}
                </div>
              </div>
              <ElTag
                size="small"
                :type="t.priority === '高' ? 'danger' : t.priority === '中' ? 'warning' : 'info'"
              >
                {{ t.priority }}
              </ElTag>
              <ElTag size="small" :type="t.status === '待验收' ? 'success' : 'info'">{{
                t.status
              }}</ElTag>
            </div>
            <div v-if="counts.tasks > 0" class="pt-2 text-right">
              <ElButton link type="primary" @click="$router.push('/yimai/tasks')"
                >进入任务中心处理</ElButton
              >
            </div>
          </template>

          <ElEmpty
            v-if="!loading && pendingCounts[tab.key] === 0 && doneCounts[tab.key] === 0"
            :description="emptyText[tab.key]"
            :image-size="60"
          />
          <div v-else-if="pendingCounts[tab.key] > visibleCount" class="pt-2 text-center">
            <ElButton link type="primary" @click="expanded[tab.key] = !expanded[tab.key]">
              {{ expanded[tab.key] ? '收起' : `展开全部 ${pendingCounts[tab.key]} 条` }}
            </ElButton>
          </div>
          <div v-if="doneCounts[tab.key] > 0" class="pt-1 text-center text-xs text-gray-400">
            <ElButton link size="small" @click="showDone[tab.key] = !showDone[tab.key]">
              {{
                showDone[tab.key] ? '收起已处理' : `已处理 ${doneCounts[tab.key]} 条（点击展开）`
              }}
            </ElButton>
          </div>
          <template v-if="showDone[tab.key]">
            <div
              v-for="row in doneRows(tab.key)"
              :key="row.key"
              class="todo-item flex items-center gap-3 py-2 opacity-60"
            >
              <ElTag size="small" type="success" effect="plain">{{ row.doneAction }}</ElTag>
              <div class="min-w-0 flex-1 truncate text-xs text-gray-500">
                {{ row.name || row.memberName }} · {{ row.doneBy }} {{ row.doneAt }}
                <span v-if="row.doneRemark">· {{ row.doneRemark }}</span>
              </div>
            </div>
          </template>
          <div
            v-if="
              tab.key !== 'bookings' &&
              tab.key !== 'trials' &&
              tab.key !== 'newLeads' &&
              tab.key !== 'tasks' &&
              pendingCounts[tab.key] + doneCounts[tab.key] > 0
            "
            class="pt-1 text-right"
          >
            <ElButton link type="primary" @click="$router.push('/yimai/members')"
              >进入会员管理</ElButton
            >
          </div>
        </div>
      </ElTabPane>
    </ElTabs>

    <!-- 续课计划登记（从今日待办流转） -->
    <ElDialog
      v-model="planDlg.visible"
      title="续课计划登记（保存后自动标记待办已处理）"
      width="480px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="会员">
          <span class="font-500">{{ planDlg.row?.name || planDlg.row?.memberName }}</span>
        </ElFormItem>
        <ElFormItem label="续课意愿">
          <ElSelect v-model="planDlg.form.intent">
            <ElOption label="确认续课" value="确认续课" />
            <ElOption label="有意向待跟进" value="有意向待跟进" />
            <ElOption label="无法续课（填原因）" value="无法续课" />
          </ElSelect>
        </ElFormItem>
        <ElRow :gutter="10">
          <ElCol :span="8"
            ><ElFormItem label="预计时间"
              ><ElInput v-model="planDlg.form.time" placeholder="如：9月中" /></ElFormItem
          ></ElCol>
          <ElCol :span="8"
            ><ElFormItem label="预计金额"
              ><ElInput v-model="planDlg.form.amount" placeholder="如：¥6800" /></ElFormItem
          ></ElCol>
          <ElCol :span="8"
            ><ElFormItem label="续课课种"><ElInput v-model="planDlg.form.course" /></ElFormItem
          ></ElCol>
        </ElRow>
        <ElFormItem label="客户问题"
          ><ElInput v-model="planDlg.form.issue" type="textarea" :rows="2"
        /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="planDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="planDlg.saving" @click="savePlan"
          >保存并标记已处理</ElButton
        >
      </template>
    </ElDialog>

    <!-- 下降处置（从今日待办流转） -->
    <ElDialog
      v-model="declineDlg.visible"
      title="出勤降低处置（保存后自动标记待办已处理）"
      width="480px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="会员">
          <span class="font-500">{{ declineDlg.row?.name }}</span>
        </ElFormItem>
        <ElFormItem label="下降原因">
          <ElRadioGroup v-model="declineDlg.form.reason">
            <ElRadio value="训练意愿降低">训练意愿降低</ElRadio>
            <ElRadio value="工作生活节奏变化">工作生活节奏变化</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem label="解决方案"
          ><ElInput v-model="declineDlg.form.solution" type="textarea" :rows="2"
        /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="declineDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="declineDlg.saving" @click="saveDecline"
          >保存并标记已处理</ElButton
        >
      </template>
    </ElDialog>
  </ElCard>
</template>

<script setup lang="ts">
  import {
    getTodayTodo,
    getMemberRules,
    refreshMemberRules,
    markTodoAction,
    updateMemberFields,
    queryCustomers,
    type TodayTodo,
    type TodayTodoBookingItem,
    type TodayTodoRenewalItem,
    type TodayTodoChurnItem,
    type TodayTodoBirthdayItem,
    type TodayTodoTrialItem,
    type TodayTodoReviewItem,
    type TodayTodoLeadItem,
    type TodayTodoTaskItem,
    type MemberRules,
    type CardEntitlement,
    type RenewalReason
  } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import {
    expiredBalanceWindowText,
    expireDateText,
    fmtQty,
    lowRemainInfo,
    mainCardStateLabel,
    renewalRuleSummary,
    whyCodeLabel
  } from '@/views/yimai/shared/card-display'
  import { ElMessage } from 'element-plus'
  import TodoActions from './todo-actions.vue'

  defineOptions({ name: 'YimaiTodayTodo' })

  const props = withDefaults(
    defineProps<{
      /** summary=工作台概要卡（点击进完整页）；full=完整待办页（默认） */
      variant?: 'summary' | 'full'
      /** 完整模式初始分组（来自 /yimai/todo?group=xxx） */
      initialGroup?: string
    }>(),
    { variant: 'full', initialGroup: '' }
  )

  type TabKey =
    | 'bookings'
    | 'renewals'
    | 'churnRisks'
    | 'birthdays'
    | 'trials'
    | 'reviews'
    | 'newLeads'
    | 'tasks'

  interface TodoRow {
    key: string
    id?: number
    name?: string
    memberName?: string
    done?: boolean
    doneAction?: string
    doneBy?: string
    doneAt?: string
    doneRemark?: string
    customerId?: number | null
  }

  const userStore = useUserStore()
  const router = useRouter()
  const isMedia = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_MEDIA'))
  const isService = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_SERVICE'))

  const loading = ref(true)
  const todo = ref<TodayTodo | null>(null)
  const activeTab = ref<TabKey>('bookings')

  // 手持设备上分组切换改用横向滚动 chip（ElTabs 在窄屏只剩 2~3 个可见）
  const { isHandheld } = useDevice()
  const visibleCount = 8
  const expanded = reactive<Record<string, boolean>>({})
  const showDone = reactive<Record<string, boolean>>({})
  // 当前生效的清单阈值：优先取本次聚合结果携带的口径（后端/本地一致），展示与会员管理同步
  const activeRules = ref<MemberRules>(getMemberRules())

  function formatMoney(v: number): string {
    return Number(v).toLocaleString('zh-CN')
  }

  /**
   * 待续费阈值摘要：与「会员管理 → 调整标签阈值」共用同一份文案
   * （`views/yimai/shared/card-display`），两处不会各说一套。
   */
  const renewalRuleText = computed(() => renewalRuleSummary(activeRules.value))

  /** 过期有余额回溯窗（同一份阈值来源；它只影响 P3 提示，不再影响待续费） */
  const expiredBalanceText = computed(() => expiredBalanceWindowText(activeRules.value))

  const allTabs: { key: TabKey; label: string; timeWindow: string }[] = [
    { key: 'bookings', label: '今日预约', timeWindow: '⏰ 今日' },
    { key: 'renewals', label: '待续费', timeWindow: '⏰ 今日到店' },
    { key: 'churnRisks', label: '流失风险', timeWindow: '⚡ 持续监控' },
    { key: 'birthdays', label: '生日关怀', timeWindow: '📅 7 天窗口' },
    { key: 'trials', label: '体验课', timeWindow: '⏰ 今日' },
    { key: 'reviews', label: '课后分析', timeWindow: '⏰ 今日课后' },
    { key: 'newLeads', label: '新客首响', timeWindow: '⚡ 5 分钟规则' },
    { key: 'tasks', label: '今日任务', timeWindow: '⏰ 今日到期' }
  ]
  // 新媒体只看客资相关；服务老师（会籍顾问）不排训练，看不到「课后分析」
  const visibleTabs = computed(() => {
    if (isMedia.value) return allTabs.filter((t) => ['newLeads', 'trials'].includes(t.key))
    if (isService.value) return allTabs.filter((t) => t.key !== 'reviews')
    return allTabs
  })

  const bookings = computed<TodayTodoBookingItem[]>(() => todo.value?.bookings.items ?? [])
  const renewals = computed<TodayTodoRenewalItem[]>(() => todo.value?.renewals ?? [])
  const churnRisks = computed<TodayTodoChurnItem[]>(() => todo.value?.churnRisks ?? [])
  const birthdays = computed<TodayTodoBirthdayItem[]>(() => todo.value?.birthdays ?? [])
  const trials = computed<TodayTodoTrialItem[]>(() => todo.value?.trials ?? [])
  const reviews = computed<TodayTodoReviewItem[]>(() => todo.value?.reviews ?? [])
  const newLeads = computed<TodayTodoLeadItem[]>(() => todo.value?.newLeads ?? [])
  const tasks = computed<TodayTodoTaskItem[]>(() => todo.value?.tasks ?? [])

  /* ---------- 待续费行：展示口径（只读后端字段，不重算判定） ---------- */

  /**
   * 与会员管理**同一份**后端结论：`/customers?list=待续课` 返回的
   * `renewalReasons`（判定理由，含 why/hits/expireDateState/usableCount）
   * 与 `cardStats.entitlement`（唯一汇总口径）。
   *
   * 为什么要单独拉这份：`/today/todo` 的待续费行目前只带旧字段
   * （mainCard/remainTimes/expireDate/expireDays），没有 `expireDateState`，
   * 前端若自己按 `expireDays <= 0` 判「今日到期」，就会把「已过期 60 天」
   * 显示成今天到期（D08 的症状）。这里直接取判定层的原话，**不在前端算第二遍**。
   */
  interface RenewalSource {
    entitlement: CardEntitlement | null
    reason: RenewalReason | null
  }

  const renewalSources = ref<Record<number, RenewalSource>>({})

  /** 概要模式（工作台卡片）不拉这份重请求；完整页才需要逐行理由 */
  async function loadRenewalSources(): Promise<void> {
    if (props.variant === 'summary') return
    try {
      const res = await queryCustomers({
        list: '待续课',
        type: 'member',
        current: 1,
        size: 5000
      })
      const sources: Record<number, RenewalSource> = {}
      for (const row of res.records ?? []) {
        sources[row.id] = {
          entitlement: row.cardStats?.entitlement ?? row.entitlement ?? null,
          reason: res.renewalReasons?.[row.id] ?? null
        }
      }
      // 理由 map 里可能有本页没返回的 id（范围/分页差异），仍收进来按 id 命中
      for (const [key, reason] of Object.entries(res.renewalReasons ?? {})) {
        const id = Number(key)
        if (!sources[id]) sources[id] = { entitlement: null, reason }
      }
      renewalSources.value = sources
    } catch (e) {
      // 拿不到就按 item 自带字段展示（降级句式，不猜），不阻塞待办列表
      console.warn('[today-todo.loadRenewalSources]', e)
    }
  }

  interface RenewalDisplay {
    /** 展示口径次卡节数（含未开卡；= 旧 remainTimes） */
    displayCount: number | null
    /** 判定口径次卡节数（不含未开卡；缺失=旧后端） */
    usableCount: number | null
    /** 储值卡余额（元） */
    storedValue: number | null
    mainCardState: 'current' | 'unactivated' | 'history' | 'none'
    /** 到期文案：句式由 expireDateState 决定（§5.5），不再用 expireDays<=0 推「今日到期」 */
    expireText: string
    /** 是否标红（判定口径 usableCount ≤ 阈值；旧后端才退到展示口径并标记非精确） */
    low: boolean
    exact: boolean
  }

  function buildRenewalDisplay(c: TodayTodoRenewalItem): RenewalDisplay {
    const source = renewalSources.value[c.id]
    const reason = source?.reason ?? null
    const entitlement = c.entitlement ?? source?.entitlement ?? null
    const displayCount = entitlement?.displayCount ?? reason?.displayCount ?? c.remainTimes ?? null
    const usableCount = entitlement?.usableCount ?? reason?.usableCount ?? c.usableCount ?? null
    const storedValue = entitlement?.storedValue ?? c.storedValue ?? null
    const mainCardState =
      entitlement?.mainCardState ?? reason?.mainCardState ?? c.mainCardState ?? 'none'
    const expireText = expireDateText(
      entitlement?.expireDateState ?? reason?.expireDateState ?? c.expireDateState,
      entitlement?.nextExpireDays ?? c.nextExpireDays ?? reason?.expireDays ?? c.expireDays,
      c.expireDate
    )
    const info = lowRemainInfo(
      { usableCount, displayCount },
      activeRules.value.renewalThreshold ?? 10
    )
    return {
      displayCount,
      usableCount,
      storedValue,
      mainCardState,
      expireText,
      low: info.low,
      exact: info.exact
    }
  }

  /** 按 key 预算，模板里不重复算（理由/阈值都随 activeRules 变化，故依赖它） */
  const renewalDisplays = computed<Record<string, RenewalDisplay>>(() => {
    const out: Record<string, RenewalDisplay> = {}
    for (const c of renewals.value) out[c.key] = buildRenewalDisplay(c)
    return out
  })

  function renewalDisplay(c: TodayTodoRenewalItem): RenewalDisplay {
    return renewalDisplays.value[c.key] ?? buildRenewalDisplay(c)
  }

  /** 判定理由（后端 why 原话；与会员管理「判定依据」同一份） */
  function renewalWhy(c: TodayTodoRenewalItem): string[] {
    return renewalSources.value[c.id]?.reason?.why ?? c.why ?? []
  }

  /** 理由码 → 可读标签（与会员管理同一份映射，不解析中文） */
  function renewalWhyTags(c: TodayTodoRenewalItem): string[] {
    const codes = renewalSources.value[c.id]?.reason?.whyCodes ?? c.whyCodes ?? []
    return [...new Set(codes)].map((code) => whyCodeLabel(code))
  }

  /** 降级说明：非空表示有规则没生效（用户排查「阈值调了没反应」的唯一线索） */
  function renewalDegraded(c: TodayTodoRenewalItem): string[] {
    return renewalSources.value[c.id]?.reason?.degraded ?? c.degraded ?? []
  }

  const groupRows = computed<Record<TabKey, TodoRow[]>>(() => ({
    bookings: bookings.value as unknown as TodoRow[],
    renewals: renewals.value as unknown as TodoRow[],
    churnRisks: churnRisks.value as unknown as TodoRow[],
    birthdays: birthdays.value as unknown as TodoRow[],
    trials: trials.value as unknown as TodoRow[],
    reviews: reviews.value as unknown as TodoRow[],
    newLeads: newLeads.value as unknown as TodoRow[],
    tasks: tasks.value as unknown as TodoRow[]
  }))

  const pendingItems = <T extends { done?: boolean }>(rows: T[]): T[] => rows.filter((r) => !r.done)
  const doneRows = (group: TabKey): TodoRow[] =>
    (groupRows.value[group] ?? []).filter((r) => r.done)

  const counts = computed<Record<TabKey, number>>(() => {
    const c = todo.value?.counts
    return {
      bookings: c?.bookings ?? 0,
      renewals: c?.renewals ?? 0,
      churnRisks: c?.churnRisks ?? 0,
      birthdays: c?.birthdays ?? 0,
      trials: c?.trials ?? 0,
      reviews: c?.reviews ?? 0,
      newLeads: c?.newLeads ?? 0,
      tasks: c?.tasks ?? 0
    }
  })

  /** 待处理数 = 总数 - 今日已标记处理 */
  const pendingCounts = computed<Record<TabKey, number>>(() => {
    const out = {} as Record<TabKey, number>
    for (const tab of allTabs) {
      const rows = groupRows.value[tab.key] ?? []
      out[tab.key] = rows.length ? rows.filter((r) => !r.done).length : (counts.value[tab.key] ?? 0)
    }
    return out
  })
  const doneCounts = computed<Record<TabKey, number>>(() => {
    const out = {} as Record<TabKey, number>
    for (const tab of allTabs) {
      out[tab.key] = (groupRows.value[tab.key] ?? []).filter((r) => r.done).length
    }
    return out
  })

  /* ---------- 今日三件事（P0-1）：三个黄金窗口的优先级聚合 ---------- */

  /** 留资距今时长文案（小时级，超 24h 显示天数） */
  function leadAgoText(lead: TodayTodoLeadItem): string {
    if (!lead.createdAt) return '刚留资'
    const hours = (Date.now() - new Date(lead.createdAt).getTime()) / 3_600_000
    if (hours < 1) return '刚留资'
    if (hours < 24) return `已等 ${Math.floor(hours)} 小时`
    return `已等 ${Math.floor(hours / 24)} 天`
  }

  /**
   * 今日三件事：回答「今天最重要的 3 件事是什么」。
   * 三个黄金窗口分别对应 5 分钟规则、到店当面聊续费、体验课 24h 内跟进。
   */
  const top3Items = computed(() => {
    const leadPending = pendingItems(newLeads.value)
    const renewalPending = pendingItems(renewals.value)
    const trialPending = pendingItems(trials.value)
    const staleCount = leadPending.filter((l) => l.stale).length

    return [
      {
        key: 'top-new-leads',
        tab: 'newLeads' as TabKey,
        title: '新客首响',
        count: leadPending.length,
        detail:
          leadPending.length > 0
            ? `${leadPending[0].name} ${leadAgoText(leadPending[0])}`
            : '暂无待首响新客资',
        urgent: staleCount > 0,
        urgencyLabel: staleCount > 0 ? `${staleCount} 条超 24h` : '5 分钟规则'
      },
      {
        key: 'top-renewals',
        tab: 'renewals' as TabKey,
        title: '待续费会员（今天到店）',
        count: renewalPending.length,
        detail:
          renewalPending.length > 0
            ? renewalPending
                .slice(0, 2)
                .map((r) => r.name)
                .join('、')
            : '今天没有待续费会员到店约课',
        urgent: renewalPending.some((r) => r.urgent),
        urgencyLabel: renewalPending.some((r) => r.urgent) ? '有 7 天内到期' : '到店当面聊'
      },
      {
        key: 'top-trials',
        tab: 'trials' as TabKey,
        title: '体验课跟进',
        count: trialPending.length,
        detail:
          trialPending.length > 0
            ? `${trialPending[0].name} ${trialPending[0].time || ''}`.trim()
            : '今天暂无体验课',
        urgent: trialPending.length > 0,
        urgencyLabel: '24h 黄金窗口'
      }
    ]
  })

  const pendingNames = (group: TabKey): string =>
    pendingItems(groupRows.value[group] ?? [])
      .slice(0, 2)
      .map((r) => r.name || r.memberName || '')
      .filter(Boolean)
      .join('、')

  const emptyText = computed<Record<TabKey, string>>(() => ({
    bookings: '今天暂无预约课程（可去「约课管理」查看未来预约）',
    // 待续费 tab 已收敛为「今天约课且待续课」：全量还有待续费会员、只是今天没人约课时，
    // 提示店长去会员管理看全量，而不是误以为没有待续费
    renewals:
      Object.keys(renewalSources.value).length > 0
        ? `今天没有待续费会员到店约课（待续费共 ${Object.keys(renewalSources.value).length} 人，可去「会员管理 → 待续课」查看全量）`
        : '暂无待续费会员',
    churnRisks: '今天暂无流失风险会员（可去「会员管理 → 预流失」查看全量）',
    birthdays: '近期没有会员生日（可去「会员管理 → 生日关怀」查看全量）',
    trials: '今天暂无体验课（可去「营销工具 → 体验课」查看排期）',
    reviews: '今天没有待填写的课后分析',
    newLeads: '暂无待首响新客资（5 分钟规则：新留资产生后 5 分钟内首联）',
    tasks: '今天没有到期任务'
  }))

  /** 每组可执行的动作：action=文案；flow=额外业务流转（打开对应表单/更新留资状态） */
  const actionMenus: Record<
    string,
    {
      action: string
      flow?: 'plan' | 'decline' | 'revive' | 'leadContact' | 'leadLost' | 'postClass'
    }[]
  > = {
    bookings: [{ action: '已接待' }, { action: '已沟通' }],
    renewals: [
      { action: '已沟通待跟进' },
      { action: '登记续课计划', flow: 'plan' },
      { action: '暂不续费' }
    ],
    churnRisks: [
      { action: '已沟通待跟进' },
      { action: '登记下降处置', flow: 'decline' },
      { action: '转待复活', flow: 'revive' }
    ],
    birthdays: [{ action: '已送祝福' }, { action: '已邀约到店' }],
    trials: [{ action: '已接待' }, { action: '爽约' }],
    reviews: [{ action: '去填写', flow: 'postClass' }, { action: '无需填写' }],
    newLeads: [
      { action: '已首响', flow: 'leadContact' },
      { action: '无效客资', flow: 'leadLost' }
    ],
    tasks: []
  }

  function shownBy<T>(key: TabKey, rows: T[]): T[] {
    return expanded[key] ? rows : rows.slice(0, visibleCount)
  }

  function statusLabel(status: string): string {
    return (
      (
        {
          booked: '已约',
          signed: '已签到',
          cancelled: '已取消',
          no_show: '爽约',
          unknown: '待确认'
        } as Record<string, string>
      )[status] ?? status
    )
  }
  function statusType(status: string): 'success' | 'primary' | 'info' | 'danger' {
    if (status === 'signed') return 'success'
    if (status === 'cancelled' || status === 'no_show') return 'danger'
    if (status === 'unknown') return 'info'
    return 'primary'
  }
  function flagType(flag: string): 'danger' | 'warning' | 'success' | 'info' | 'primary' {
    if (['待续课', '出勤降低'].includes(flag)) return 'warning'
    if (['预流失', '待复活', '评估低分', '需协助', '7天内到期'].includes(flag)) return 'danger'
    if (flag === 'VIP') return 'success'
    return 'info'
  }

  function todayIso(): string {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  }

  async function doMark(group: TabKey, row: TodoRow, action: string): Promise<void> {
    // 今日任务有独立状态机，走任务中心处理，不在待办页标记
    if (group === 'tasks') return
    const menu = (actionMenus[group] ?? []).find((m) => m.action === action)
    if (menu?.flow === 'plan') {
      planDlg.row = row
      planDlg.form = { intent: '确认续课', time: '', amount: '', course: '', issue: '' }
      planDlg.visible = true

      return
    }
    if (menu?.flow === 'decline') {
      declineDlg.row = row
      declineDlg.form = { reason: '训练意愿降低', solution: '' }
      declineDlg.visible = true

      return
    }
    // 去填写课后分析：实际工作在「课后分析」页完成，这里只做跳转不标记完成
    if (menu?.flow === 'postClass') {
      router.push({ path: '/yimai/post-class', query: { pending: '1' } })

      return
    }
    try {
      await markTodoAction({
        type: group,
        key: row.key,
        action,
        customerId:
          group === 'renewals' || group === 'churnRisks' ? row.id : (row.customerId ?? null),
        // 体验课携带留资定位，后端据此回写留资状态机与体验课卡片（KY 来源传 null 由后端反查）
        leadId:
          group === 'newLeads'
            ? row.id
            : group === 'trials'
              ? ((row as TodayTodoTrialItem).leadId ?? null)
              : null,
        touch: group === 'renewals' || group === 'churnRisks'
      })
      ElMessage.success(`已标记：${action}`)
      await reload()
    } catch (e) {
      console.error('[today-todo.mark]', e)
      ElMessage.error('标记失败，请稍后重试')
    }
  }

  function onMark(group: TabKey, row: TodoRow, action: string): void {
    void doMark(group, row, action)
  }

  // 续课计划 / 下降处置 弹窗
  const planDlg = reactive({
    visible: false,
    saving: false,
    row: null as TodoRow | null,
    form: { intent: '确认续课', time: '', amount: '', course: '', issue: '' }
  })
  const declineDlg = reactive({
    visible: false,
    saving: false,
    row: null as TodoRow | null,
    form: { reason: '训练意愿降低', solution: '' }
  })

  async function savePlan(): Promise<void> {
    const row = planDlg.row
    if (!row?.id) return
    planDlg.saving = true
    try {
      await updateMemberFields(row.id, { renewalPlan: { ...planDlg.form } }, '续课预报')
      await markTodoAction({
        type: 'renewals',
        key: row.key,
        action: '已建续费计划',
        customerId: row.id
      })
      planDlg.visible = false
      ElMessage.success('已保存续课计划，待办已标记处理')
      await reload()
    } catch (e) {
      console.error('[today-todo.savePlan]', e)
      ElMessage.error('保存失败，请稍后重试')
    } finally {
      planDlg.saving = false
    }
  }

  async function saveDecline(): Promise<void> {
    const row = declineDlg.row
    if (!row?.id) return
    declineDlg.saving = true
    try {
      await updateMemberFields(row.id, { decline: { ...declineDlg.form } }, '下降处置')
      await markTodoAction({
        type: 'churnRisks',
        key: row.key,
        action: '已建下降处置',
        customerId: row.id,
        touch: true
      })
      declineDlg.visible = false
      ElMessage.success('已保存处置，待办已标记处理')
      await reload()
    } catch (e) {
      console.error('[today-todo.saveDecline]', e)
      ElMessage.error('保存失败，请稍后重试')
    } finally {
      declineDlg.saving = false
    }
  }

  async function reload(): Promise<void> {
    loading.value = true
    try {
      // 先拉最新清单阈值，保证待续费/流失风险等分组与「会员管理 → 调整标签阈值」同口径
      await refreshMemberRules().catch(() => {})
      activeRules.value = getMemberRules()
      try {
        todo.value = await getTodayTodo()
      } catch (e) {
        // 接口失败必须显式报错，避免静默显示全 0 误导
        todo.value = null
        ElMessage.error(
          '今日待办加载失败：' + String((e as { message?: string }).message ?? e).slice(0, 100)
        )
        console.error('[today-todo.reload]', e)

        return
      }
      if (todo.value?.rules) {
        activeRules.value = todo.value.rules
      }
      // 待续费逐行理由/展示字段：与会员管理同一接口、同一份后端结论（不重算）。
      // 概要模式（工作台卡片）在 loadRenewalSources 里直接跳过。
      await loadRenewalSources()
      // 完整页尊重 initialGroup；否则默认落在第一个有待处理的分组
      const wanted = props.initialGroup as TabKey | ''
      const wantedValid = wanted && visibleTabs.value.some((t) => t.key === wanted)
      if (wantedValid) {
        activeTab.value = wanted as TabKey
      } else {
        const firstNonEmpty = visibleTabs.value.find((t) => pendingCounts.value[t.key] > 0)
        const current = activeTab.value
        const currentPending = visibleTabs.value.some(
          (t) => t.key === current && pendingCounts.value[t.key] > 0
        )
        if (!current || (!currentPending && firstNonEmpty)) {
          activeTab.value = firstNonEmpty?.key ?? visibleTabs.value[0]?.key ?? 'bookings'
        }
      }
    } finally {
      loading.value = false
    }
  }

  onMounted(reload)
</script>

<style scoped lang="scss">
  .todo-item {
    border-bottom: 1px solid var(--el-border-color-lighter);

    &:last-of-type {
      border-bottom: 0;
    }
  }

  // 手持设备上的分组切换条：横向滚动，避免 8 个分组溢出
  .todo-tabbar {
    display: flex;
    gap: 8px;
    padding-bottom: 10px;
    margin-bottom: 4px;
    overflow-x: auto;
    // 横向滚动时不要让页面跟着左右晃
    overscroll-behavior-x: contain;
    border-bottom: 1px solid var(--el-border-color-lighter);
    scrollbar-width: none;

    &::-webkit-scrollbar {
      display: none;
    }

    &__item {
      display: inline-flex;
      flex: none;
      gap: 4px;
      align-items: center;
      min-height: $touch-target-min;
      padding: 0 14px;
      font-size: 14px;
      color: var(--el-text-color-regular);
      white-space: nowrap;
      cursor: pointer;
      background: var(--el-fill-color-light);
      border: 1px solid transparent;
      border-radius: 22px;

      &.is-active {
        font-weight: 600;
        color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
        border-color: var(--el-color-primary);
      }
    }

    &__badge {
      min-width: 18px;
      padding: 0 5px;
      font-size: 11px;
      line-height: 18px;
      color: #fff;
      text-align: center;
      background: var(--el-color-danger);
      border-radius: 9px;
    }
  }

  // 手机端把 ElTabs 的头藏掉（改成上面的 chip 条），只保留内容区
  .todo-tabs--mobile {
    :deep(.el-tabs__header) {
      display: none;
    }
  }

  @include mobile-only {
    // 名称块占满整行，状态标签与「处理」按钮换到第二行右对齐，
    // 避免名字被右侧控件挤到只剩几十像素
    .todo-item {
      flex-wrap: wrap;
      gap: 8px 12px;
      align-items: flex-start;

      > .min-w-0 {
        flex: 1 1 100%;
      }

      > :deep(.el-tag),
      > :deep(.el-dropdown) {
        margin-left: auto;
      }
    }
  }
</style>
