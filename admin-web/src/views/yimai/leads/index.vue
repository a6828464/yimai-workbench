<template>
  <div class="leads-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElInput
          v-model="filters.name"
          placeholder="客户姓名"
          clearable
          class="!w-36"
          @change="load"
        />
        <ElInput
          v-model="filters.phone"
          placeholder="联系方式/手机号/微信"
          clearable
          class="!w-44"
          @change="load"
        />
        <ElDatePicker
          v-model="filters.dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="留资开始"
          end-placeholder="留资结束"
          range-separator="~"
          class="!w-64"
          @change="load"
        />
        <ElSelect
          v-if="showVenueFilter"
          v-model="filters.venue"
          placeholder="门店"
          clearable
          class="!w-28"
          @change="load"
        >
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElSelect
          v-model="filters.status"
          placeholder="状态"
          clearable
          class="!w-28"
          @change="load"
        >
          <ElOption v-for="s in STATUS_LIST" :key="s" :label="s" :value="s" />
        </ElSelect>
        <ElButton @click="reloadFromFirstPage">查询</ElButton>
        <ElButton @click="resetFilters">重置</ElButton>
        <div class="flex-1" />
        <ElButton type="primary" v-ripple @click="openCreate">新增留资</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left>
          <span class="text-sm text-gray-400">
            {{ scopeHint }} · 三次跟进时限从首次体验课自动计算（7/15/30天），第一节体验课取消则留白
            · 来源口径：大众点评A/美团B/抖音C/视频号D/自然到店E
          </span>
        </template>
      </ArtTableHeader>

      <!--
        表格高度
        ----------
        以前写死 max-height="520"，14 条数据只能看到 7 行，卡片底部还空着 124px
        （用户反馈的「屏幕下面还有空白」就是这个）。改成跟着可用的纵向空间走：
        `--art-full-height` 是布局层算好的「当前视口下内容区高度」（useLayoutHeight
        维护），减去本页固定占位（筛选区 + 提示行 + 分页器 + 卡片内边距）即可。
        用 calc 传给 max-height 是 EP 支持的写法（style-helper 里
        `calc(${maxHeight} - ${headerHeight}px)`，已实测生效）。
      -->
      <ElTable
        v-if="!isHandheld"
        v-loading="loading"
        :data="filteredList"
        border
        stripe
        :max-height="tableMaxHeight"
      >
        <ElTableColumn prop="leadDate" label="留资日期" width="100" sortable />
        <ElTableColumn label="姓名 / 联系方式" min-width="150">
          <template #default="{ row }">
            <div class="font-500">{{ row.name }}</div>
            <div class="text-xs text-gray-400 leading-4">
              <div>{{ row.phone || (row.phoneTail ? '尾号' + row.phoneTail : '—') }}</div>
              <div v-if="row.wechat" class="text-blue-500">微信：{{ row.wechat }}</div>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="demand" label="需求/痛点" min-width="110" show-overflow-tooltip />
        <ElTableColumn label="会籍顾问" width="95">
          <template #default="{ row }">
            <span v-if="row.serviceTeacher">{{ row.serviceTeacher }}</span>
            <ElTag v-else size="small" type="danger">待分配</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="上课老师" width="120">
          <template #default="{ row }">
            <span v-if="classTeachers(row).length">{{ classTeachers(row).join('、') }}</span>
            <span v-else class="text-gray-300">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="核销金额" width="90" align="right">
          <template #default="{ row }">
            <span
              v-if="row.redeemAmount !== null && row.redeemAmount !== undefined"
              class="font-500 text-orange-600"
              >¥{{ row.redeemAmount }}</span
            >
            <span v-else class="text-gray-300">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="成交金额" width="100" align="right">
          <template #default="{ row }">
            <span
              v-if="row.dealAmount !== null && row.dealAmount !== undefined"
              class="font-600 text-green-700"
              >¥{{ row.dealAmount.toLocaleString() }}</span
            >
            <span v-else class="text-gray-300">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="status" label="状态" width="90">
          <template #default="{ row }">
            <ElTag size="small" :type="statusType(row.status)">{{ row.status }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="备注" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.remark">{{ row.remark }}</span>
            <span v-else class="text-gray-300">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="跟进时限" width="170">
          <template #default="{ row }">
            <div v-if="firstTrialTime(row)" class="followup-cell">
              <span :class="deadlineClass(row, 7)">首跟{{ plusDays(firstTrialTime(row), 7) }}</span>
              <span :class="deadlineClass(row, 15)"
                >二跟{{ plusDays(firstTrialTime(row), 15) }}</span
              >
              <span :class="deadlineClass(row, 30)"
                >三跟{{ plusDays(firstTrialTime(row), 30) }}</span
              >
            </div>
            <div v-else class="followup-cell text-gray-300">—</div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="source" label="来源" width="100" />
        <ElTableColumn label="下单平台" width="95">
          <template #default="{ row }">
            <span v-if="row.orderPlatform">{{ row.orderPlatform }}</span>
            <span v-else class="text-gray-300">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="体验课 / 券码" min-width="170">
          <template #default="{ row }">
            <template v-if="row.trialCards?.length">
              <div v-for="(t, i) in row.trialCards" :key="i" class="text-xs leading-4">
                <span class="text-gray-500">第{{ t.session }}节</span>
                <ElTag v-if="t.noShow" size="small" type="danger" class="mx-1">已爽约</ElTag>
                <ElTag v-else-if="t.attended" size="small" type="success" class="mx-1"
                  >已上课</ElTag
                >
                <ElTag v-if="t.cancelled" size="small" type="info" class="mx-1">已取消</ElTag>
                <template v-if="t.time || t.topic || t.teacher">
                  <span class="text-gray-400"> · </span>
                  <span>{{
                    [t.time?.slice(5, 16), t.topic, t.teacher].filter(Boolean).join(' ')
                  }}</span>
                </template>
                <div class="text-gray-400">
                  <template v-if="t.couponName">{{ t.couponName }} </template>
                  <template v-if="t.voucherCode">{{ t.voucherCode }}</template>
                  <template v-if="t.total !== null && t.total !== undefined">
                    <span class="text-orange-600 font-500">{{ t.remaining ?? t.total }}</span
                    >/{{ t.total }}次
                  </template>
                </div>
              </div>
            </template>
            <template v-else>
              <div v-if="row.couponName || row.voucherCode" class="text-xs">
                <div v-if="row.couponName" class="text-gray-500">{{ row.couponName }}</div>
                <div v-if="row.voucherCode" class="text-gray-400">{{ row.voucherCode }}</div>
              </div>
              <span v-if="!row.couponName && !row.voucherCode" class="text-gray-300">—</span>
            </template>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="venue" label="门店" width="85">
          <template #default="{ row }">
            <ElTag size="small" effect="plain">{{ row.venue }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="createdBy" label="录入人" width="80" />
        <ElTableColumn label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              size="small"
              :disabled="!canManageLead(row)"
              @click="openEdit(row)"
            >
              编辑
            </ElButton>
            <ElButton link type="info" size="small" @click="openHistory(row)">变更记录</ElButton>
            <ElButton
              link
              type="danger"
              size="small"
              :disabled="!canManageLead(row)"
              @click="removeLead(row)"
            >
              删除
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表。16 列表格在手机上只能看到 3 列 -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="item in cardRows"
          :key="item.id"
          :title="item.title"
          :subtitle="item.subtitle"
          :tags="item.tags"
          :metrics="item.metrics"
          :note="item.note?.text"
          :note-label="item.note?.label"
          :note-danger="item.note?.danger"
          :actions="item.actions"
        >
          <div
            v-if="item.demand || item.trial || item.remark || item.meta"
            class="lead-card__extra"
          >
            <div v-if="item.demand" class="lead-card__line">
              <span class="lead-card__label">需求：</span>{{ item.demand }}
            </div>
            <div v-if="item.trial" class="lead-card__line">
              <span class="lead-card__label">体验课：</span>{{ item.trial }}
            </div>
            <div v-if="item.remark" class="lead-card__line">
              <span class="lead-card__label">备注：</span>{{ item.remark }}
            </div>
            <div v-if="item.meta" class="lead-card__meta">{{ item.meta }}</div>
          </div>
        </MobileCard>
        <div v-if="!loading && !cardRows.length" class="m-card-list__empty">暂无数据</div>
      </div>

      <div class="mt-4 flex justify-end">
        <ElPagination
          v-model:current-page="page.current"
          v-model:page-size="page.size"
          :page-sizes="PAGE_SIZES"
          :total="total"
          layout="total, sizes, prev, pager, next"
          @size-change="onSizeChange"
        />
      </div>
    </ElCard>

    <!-- 新增/编辑弹窗 -->
    <ElDialog
      v-model="dialog.visible"
      :title="dialog.isCreate ? '新增留资' : `编辑留资 #${dialog.form.id}`"
      width="760px"
      destroy-on-close
    >
      <ElForm :model="dialog.form" label-width="92px">
        <ElRow :gutter="12">
          <ElCol :span="12">
            <ElFormItem label="留资日期" required>
              <ElDatePicker
                v-model="dialog.form.leadDate"
                type="date"
                value-format="YYYY-MM-DD"
                class="!w-full"
              />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="姓名" required>
              <ElInput v-model="dialog.form.name" placeholder="客户姓名" />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="手机号">
              <ElInput
                v-model="dialog.form.phone"
                maxlength="11"
                placeholder="11位完整手机号"
                :validate-event="false"
                @blur="checkPhoneDuplicate"
                @input="phoneChecked = false"
              />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="微信">
              <ElInput v-model="dialog.form.wechat" placeholder="微信号" />
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="来源" required>
              <ElSelect v-model="dialog.form.source" placeholder="选择来源渠道" class="!w-full">
                <ElOption v-for="s in SOURCE_OPTIONS" :key="s" :label="s" :value="s" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="下单平台">
              <ElSelect
                v-model="dialog.form.orderPlatform"
                clearable
                placeholder="体验课下单平台"
                class="!w-full"
              >
                <ElOption v-for="p in PLATFORM_OPTIONS" :key="p" :label="p" :value="p" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="目标门店" required>
              <ElSelect v-model="dialog.form.venue" :disabled="dialogLockedVenue" class="!w-full">
                <ElOption label="绿地店" value="绿地店" />
                <ElOption label="东部店" value="东部店" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
        </ElRow>

        <div v-if="phoneChecked && phoneCheck.matches.length" class="mb-3">
          <ElAlert type="warning" :closable="false" show-icon>
            <template #title
              >该手机号已命中
              {{ phoneCheck.matches.length }} 条已有数据，请注意核对是否重复录入：</template
            >
            <ul class="mt-1 text-xs leading-5 list-disc pl-4">
              <li v-for="(m, i) in phoneCheck.matches" :key="i">
                【{{ m.kind }}】{{ m.name }} · {{ m.venue }} · {{ m.detail }}
              </li>
            </ul>
          </ElAlert>
        </div>

        <ElFormItem label="需求/痛点">
          <ElInput v-model="dialog.form.demand" placeholder="体态调整/产后修复/体式提升" />
        </ElFormItem>
        <ElRow :gutter="12">
          <ElCol :span="8">
            <ElFormItem label="状态">
              <ElSelect v-model="dialog.form.status" class="!w-full">
                <ElOption v-for="s in STATUS_LIST" :key="s" :label="s" :value="s" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol :span="8">
            <ElFormItem label="体验课类型">
              <ElSelect
                v-model="dialog.form.grade"
                clearable
                class="!w-full"
                placeholder="选择体验课类型"
              >
                <ElOption v-for="t in TRIAL_TYPES" :key="t" :label="t" :value="t" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol :span="8">
            <ElFormItem label="会籍顾问">
              <ElSelect
                v-model="dialog.form.serviceTeacher"
                filterable
                allow-create
                default-first-option
                clearable
                class="!w-full"
                placeholder="选择或输入会籍顾问"
              >
                <ElOption v-for="c in consultantOptions" :key="c" :label="c" :value="c" />
              </ElSelect>
            </ElFormItem>
          </ElCol>
        </ElRow>
        <ElRow :gutter="12">
          <ElCol :span="12">
            <ElFormItem label="成交卡项"
              ><ElInput v-model="dialog.form.dealCard" placeholder="成交时填写"
            /></ElFormItem>
          </ElCol>
          <ElCol :span="12">
            <ElFormItem label="成交金额"
              ><ElInputNumber
                v-model="dialog.form.dealAmount"
                :min="0"
                :precision="2"
                :step="1"
                controls-position="right"
                class="!w-full"
                placeholder="成交时填写"
            /></ElFormItem>
          </ElCol>
        </ElRow>

        <!-- 体验课卡片：每一节体验课单独记录（上课时间/主题/老师 + 使用的券） -->
        <ElCard shadow="never" class="!rounded-lg mb-4">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">体验课</span>
              <ElButton type="primary" link size="small" @click="addTrialCard">
                <i class="ri-add-line" /> 新增一节
              </ElButton>
            </div>
          </template>
          <div class="text-xs text-gray-400 mb-3 leading-5">
            每一节体验课填写上课时间、主题、上课老师以及核销用的券信息（下单平台 / 券名称 / 券码 /
            次数）。若第一节体验课取消，请勾选「已取消」，跟进时限将留空白；「已上课 /
            已爽约」由今日待办的体验课处理自动回写，不影响跟进时限。
          </div>
          <div v-if="dialog.form.trialCards.length" class="space-y-3">
            <div
              v-for="(card, idx) in dialog.form.trialCards"
              :key="idx"
              class="border border-dashed border-gray-300 rounded-lg p-3"
              :class="{ 'bg-red-50/40 dark:bg-red-900/10': card.cancelled }"
            >
              <div class="flex-cb mb-2">
                <span class="text-sm font-500">
                  第 {{ card.session }} 节体验课
                  <ElTag v-if="card.noShow" size="small" type="danger" class="ml-1">已爽约</ElTag>
                  <ElTag v-else-if="card.attended" size="small" type="success" class="ml-1"
                    >已上课</ElTag
                  >
                </span>
                <div class="flex items-center gap-3">
                  <ElCheckbox v-model="card.cancelled" size="small">已取消</ElCheckbox>
                  <ElButton link type="danger" size="small" @click="removeTrialCard(idx)">
                    移除
                  </ElButton>
                </div>
              </div>
              <ElRow :gutter="12">
                <ElCol :span="12">
                  <ElFormItem label="上课时间" class="!mb-2">
                    <ElDatePicker
                      v-model="card.time"
                      type="datetime"
                      format="YYYY-MM-DD HH:mm"
                      value-format="YYYY-MM-DD HH:mm"
                      placeholder="选择日期和时间"
                      clearable
                      prefix-icon="Calendar"
                      class="!w-full"
                    />
                  </ElFormItem>
                </ElCol>
                <ElCol :span="12">
                  <ElFormItem label="主题" class="!mb-2">
                    <ElInput v-model="card.topic" placeholder="如：内观流/核心床小班" />
                  </ElFormItem>
                </ElCol>
                <ElCol :span="12">
                  <ElFormItem label="上课老师" class="!mb-2">
                    <ElInput v-model="card.teacher" placeholder="本节上课老师" />
                  </ElFormItem>
                </ElCol>
                <ElCol :span="12">
                  <ElFormItem label="下单平台" class="!mb-2">
                    <ElSelect v-model="card.platform" clearable class="!w-full">
                      <ElOption v-for="p in PLATFORM_OPTIONS" :key="p" :label="p" :value="p" />
                    </ElSelect>
                  </ElFormItem>
                </ElCol>
                <ElCol :span="12">
                  <ElFormItem label="券名称" class="!mb-2">
                    <ElInput v-model="card.couponName" placeholder="如：体验课次卡" />
                  </ElFormItem>
                </ElCol>
                <ElCol :span="12">
                  <ElFormItem label="券码" class="!mb-2">
                    <ElInput v-model="card.voucherCode" placeholder="第{{ card.session }}节券码" />
                  </ElFormItem>
                </ElCol>
                <ElCol :span="6">
                  <ElFormItem label="总次数" class="!mb-0">
                    <ElInputNumber
                      v-model="card.total"
                      :min="0"
                      controls-position="right"
                      class="!w-full"
                    />
                  </ElFormItem>
                </ElCol>
                <ElCol :span="6">
                  <ElFormItem label="剩余次数" class="!mb-0">
                    <ElInputNumber
                      v-model="card.remaining"
                      :min="0"
                      controls-position="right"
                      class="!w-full"
                    />
                  </ElFormItem>
                </ElCol>
                <ElCol :span="12">
                  <ElFormItem label="核销金额" class="!mb-0">
                    <ElInputNumber
                      v-model="card.redeem"
                      :min="0"
                      :precision="2"
                      :step="1"
                      controls-position="right"
                      class="!w-full"
                      placeholder="本节券码核销金额"
                    />
                  </ElFormItem>
                </ElCol>
              </ElRow>
            </div>
          </div>
          <ElEmpty v-else description="点击「新增一节」录入第一节体验课信息" :image-size="40" />
        </ElCard>

        <ElFormItem label="备注">
          <ElInput
            v-model="dialog.form.remark"
            type="textarea"
            :rows="2"
            placeholder="沟通记录、客户关注点等"
          />
        </ElFormItem>
        <ElAlert
          v-if="!dialog.isCreate"
          title="每次保存都会记录变更日志，对方角色在「变更记录」中可见"
          type="info"
          :closable="false"
        />
      </ElForm>
      <template #footer>
        <ElButton @click="dialog.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="dialog.saving" @click="save">保存</ElButton>
      </template>
    </ElDialog>

    <!-- 变更记录抽屉 -->
    <ElDrawer v-model="history.visible" title="变更记录（双方可见）" size="420px">
      <div v-if="history.list.length">
        <ElTimeline>
          <ElTimelineItem
            v-for="h in history.list"
            :key="h.id"
            :timestamp="`${h.time} · ${h.operatorName}（${h.operatorRole}）`"
            placement="top"
          >
            <div class="text-sm font-500 mb-1">{{ h.action }}</div>
            <div class="text-xs text-gray-500 leading-5">{{ h.detail }}</div>
          </ElTimelineItem>
        </ElTimeline>
      </div>
      <ElEmpty v-else description="暂无变更记录" />
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import {
    addLead,
    canManageLead,
    checkLeadPhone,
    deleteLead,
    getLeadHistory,
    queryLeads,
    queryCustomerOptions,
    updateLead
  } from '@/api/yimai'
  import type { YimaiLead } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'
  import { toLocalDateString } from '@/utils'
  import { ElMessage, ElMessageBox } from 'element-plus'

  defineOptions({ name: 'YimaiLeads' })

  // 手持设备上用卡片列表代替宽表格（16 列在手机上只剩 3 列可见）
  const { isHandheld } = useDevice()

  const STATUS_LIST = [
    '新留资',
    '已联系',
    '已约体验',
    '已体验',
    '已成交',
    '爽约',
    '已流失'
  ] as const

  const SOURCE_OPTIONS = [
    '大众点评',
    '美团',
    '抖音',
    '抖音直播',
    '抖音私信',
    '视频号',
    '小红书',
    '电话咨询',
    '转介绍',
    '会员转介绍',
    '自然到店',
    '潜客激活'
  ]

  /** 体验课下单平台 */
  const PLATFORM_OPTIONS = ['大众点评', '美团', '抖音', '抖音直播', '小红书', '视频号', '其他']

  /** 体验课类型（替代原客户分级） */
  const TRIAL_TYPES = ['定制私教', '私教小班', '精品团课', '其他']

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  // 老师侧角色（服务老师 / 授课老师）：锁定门店、客资按人隔离
  const isTeacher = computed(
    () => roles.value.includes('R_SERVICE') || roles.value.includes('R_TEACHER')
  )
  const showVenueFilter = computed(() => !isManager.value && !isTeacher.value)
  const scopeHint = computed(() => {
    if (isManager.value) return `数据范围：本店（${userStore.getUserInfo.venue}）`
    if (isTeacher.value)
      return `我的客资 + 本店待分配池（${userStore.getUserInfo.venue ?? '未选门店'}）`
    return filters.value.venue ? `数据范围：${filters.value.venue}` : '数据范围：双店'
  })

  const loading = ref(false)
  const filters = ref({
    name: '',
    phone: '',
    venue: '',
    status: '',
    dateRange: null as [string, string] | null
  })
  /**
   * 每页条数档位
   *
   * 默认 50 的理由：用户抱怨「屏幕下面还有空白」，说明一屏能放下的行数明显多于当前的 20。
   * 实测单行 72px，1440x900 下表格可用高度约 560px（约 7.5 行）——
   * 也就是说桌面端一页 20 条本来就要滚 3 屏，屏幕上的空白并不是「条数不够」造成的，
   * 而是表格被写死 max-height:520 卡住了高度（见 tableMaxHeight）。
   * 两处一起改之后：默认 50 条 = 约 7 屏，既让桌面端把可视区填满（无空白），
   * 又不会像 200 那样在一页里塞进过多 DOM（单行 72px 的重表格，未虚拟化）。
   * 保留 20 档给「只要最近几条」的场景，最大 200 兜住全量（后端 size 上限 5000）。
   */
  const PAGE_SIZES = [20, 50, 100, 200]
  const DEFAULT_PAGE_SIZE = 50

  const page = ref({ current: 1, size: DEFAULT_PAGE_SIZE })
  const list = ref<YimaiLead[]>([])
  const total = ref(0)

  /**
   * 表格最大高度：跟随布局给出的可用内容高度，让表格把卡片填满（消除底部空白）。
   *
   * 减项 = 本页除表格外必须占用的高度（实测拆解，1440x900）：
   *   筛选区 36 + 数据范围提示行 48 + 提示行下方间距 8
   *   + 分页器 32 + 表格与分页器间距 16 + 卡片上下内边距 40
   *   = 180
   * 再加 EP 内部会扣掉的表头（style-helper 用 calc(maxHeight - headerHeight)）。
   * 用 calc 而非固定 px：窗口高度变化时表格跟着变，不会再出现固定 520 造成的空白。
   *
   * 这里刻意不去「减到刚好」：留一点余量，避免不同字体/缩放下行高变化导致
   * 分页器被挤出视口（那会变成"看不到分页"，比留白更糟）。
   */
  const tableMaxHeight = computed(() => {
    if (isHandheld.value) return undefined
    // var 兜底写 100vh：万一布局还没算出 --art-full-height，calc 会整个失效，
    // 表格就拿不到 max-height，退化成「横滑条落在页面底部、表头滚出视口」的老问题
    // （t31 修过的那个）。有兜底至少能保证表格始终有高度约束。
    return 'calc(var(--art-full-height, 100vh) - 180px)'
  })

  /** 会籍顾问选项：轻量接口（服务端去重），不再全量拉取会员 */
  const consultantOptions = ref<string[]>([])
  async function loadConsultants() {
    try {
      consultantOptions.value = (await queryCustomerOptions()).consultants ?? []
    } catch {
      /* 静默失败，允许手输 */
    }
  }

  const filteredList = computed(() => list.value)

  function emptyForm() {
    return {
      id: 0,
      leadDate: toLocalDateString(new Date()),
      name: '',
      phone: '',
      phoneTail: '',
      wechat: '',
      demand: '',
      source: '',
      orderPlatform: '',
      venue:
        isManager.value || isTeacher.value
          ? (String(userStore.getUserInfo.venue ?? '绿地店') as YimaiLead['venue'])
          : ('绿地店' as YimaiLead['venue']),
      serviceTeacher: isTeacher.value ? String(userStore.getUserInfo.staffName ?? '') : '',
      status: '新留资' as YimaiLead['status'],
      grade: '' as YimaiLead['grade'],
      dealCard: '',
      dealAmount: null as number | null,
      redeemAmount: null as number | null,
      trialCards: [] as NonNullable<YimaiLead['trialCards']>,
      remark: ''
    }
  }

  const dialog = reactive({
    visible: false,
    isCreate: true,
    saving: false,
    form: emptyForm()
  })

  const dialogLockedVenue = computed(() => isManager.value || isTeacher.value)

  /** 手机号命中检测（仅新增时提示，编辑不打扰） */
  const phoneChecked = ref(false)
  const phoneCheck = ref<{ matches: Awaited<ReturnType<typeof checkLeadPhone>>['matches'] }>({
    matches: []
  })

  async function checkPhoneDuplicate() {
    const phone = (dialog.form.phone ?? '').trim()
    if (!phone || !dialog.isCreate) return
    try {
      const res = await checkLeadPhone(phone)
      phoneChecked.value = true
      phoneCheck.value.matches = res.matches
    } catch {
      /* 静默失败 */
    }
  }

  function addTrialCard() {
    const session = dialog.form.trialCards.length + 1
    dialog.form.trialCards.push({
      session,
      time: '',
      topic: '',
      teacher: '',
      couponName: '',
      platform: dialog.form.orderPlatform || '',
      voucherCode: '',
      total: null,
      remaining: null,
      redeem: null,
      cancelled: false
    })
  }

  function removeTrialCard(idx: number) {
    dialog.form.trialCards.splice(idx, 1)
    dialog.form.trialCards.forEach((c, i) => (c.session = i + 1))
  }

  const history = reactive({
    visible: false,
    list: [] as Awaited<ReturnType<typeof getLeadHistory>>
  })

  async function load() {
    loading.value = true
    try {
      const [dateFrom, dateTo] = filters.value.dateRange ?? [undefined, undefined]
      const res = await queryLeads({
        ...filters.value,
        dateFrom,
        dateTo,
        current: page.value.current,
        size: page.value.size
      })
      list.value = res.records
      total.value = res.total
    } catch (e) {
      console.error('[leads.load]', e)
      ElMessage.error('留资列表加载失败，请稍后重试')
    } finally {
      loading.value = false
    }
  }

  function reloadFromFirstPage() {
    page.value.current = 1
    load()
  }

  /**
   * 切换「每页显示数量」。
   *
   * 为什么不复用 current-page 的 watch：改 size 也会让 EP 自己回调
   * current-change（页码被夹到新范围），两个入口都会触发 load，会出现重复请求。
   * 这里统一收口 —— 先把 current 复位到第 1 页，再只发一次请求。
   *
   * 为什么回到第 1 页而不是保留原页码：换了页大小之后「第 3 页」对应的数据区间
   * 已经完全不同（20 条的 P3 是第 41~60 条，50 条的 P3 是第 101~150 条），
   * 保留页码会让用户以为"还在原地"其实已经跳到别处；回到第 1 页语义最清晰，
   * 也是主流后台的通行做法。同时 current 从 N 变 1 会触发 watch —— 下面的
   * suppressPageWatch 标志用来吃掉这次以免重复请求。
   */
  let suppressPageWatch = false

  function onSizeChange() {
    suppressPageWatch = true
    page.value.current = 1
    // 等 watch 的 flush 走完再放开，确保这次复位不会再发一次请求
    nextTick(() => {
      suppressPageWatch = false
    })
    load()
  }

  watch(
    () => page.value.current,
    () => {
      if (suppressPageWatch) return
      load()
    }
  )

  function resetFilters() {
    filters.value = { name: '', phone: '', venue: '', status: '', dateRange: null }
    reloadFromFirstPage()
  }

  function openCreate() {
    dialog.isCreate = true
    dialog.form = emptyForm()
    phoneChecked.value = false
    phoneCheck.value.matches = []
    dialog.visible = true
  }

  function openEdit(row: YimaiLead) {
    dialog.isCreate = false
    const form = {
      ...emptyForm(),
      ...row,
      status: row.status,
      trialCards: Array.isArray(row.trialCards)
        ? row.trialCards.map((c) => ({ ...c, cancelled: Boolean(c.cancelled) }))
        : []
    } as unknown as ReturnType<typeof emptyForm>
    dialog.form = form
    phoneChecked.value = false
    phoneCheck.value.matches = []
    dialog.visible = true
  }

  async function save() {
    if (!dialog.form.name || !dialog.form.source) {
      ElMessage.warning('请至少填写姓名和来源')
      return
    }
    // 清理空的体验课卡片（未填任何内容且未勾选已取消则不提交）
    dialog.form.trialCards = dialog.form.trialCards.filter(
      (c) =>
        c.cancelled ||
        c.couponName ||
        c.voucherCode ||
        c.platform ||
        c.total ||
        c.remaining ||
        c.redeem ||
        c.time ||
        c.topic ||
        c.teacher
    )
    // 核销金额汇总各节体验课，保证经营看板/平台统计口径一致
    const redeemSum = dialog.form.trialCards.reduce((s, c) => s + (Number(c.redeem) || 0), 0)
    dialog.form.redeemAmount = redeemSum > 0 ? Math.round(redeemSum * 100) / 100 : null
    dialog.saving = true
    try {
      const teacherPayload = isTeacher.value
        ? dialog.isCreate
          ? {
              leadDate: dialog.form.leadDate,
              name: dialog.form.name,
              phone: dialog.form.phone,
              wechat: dialog.form.wechat,
              demand: dialog.form.demand,
              source: dialog.form.source,
              orderPlatform: dialog.form.orderPlatform,
              venue: dialog.form.venue,
              serviceTeacher: String(userStore.getUserInfo.staffName ?? ''),
              status: '新留资' as const,
              remark: dialog.form.remark
            }
          : {
              demand: dialog.form.demand,
              status: dialog.form.status,
              remark: dialog.form.remark,
              serviceTeacher: String(userStore.getUserInfo.staffName ?? ''),
              trialCards: dialog.form.trialCards
            }
        : dialog.form
      if (dialog.isCreate) {
        await addLead(teacherPayload as Parameters<typeof addLead>[0])
        ElMessage.success('留资已提交，对应门店店长端立即可见')
      } else {
        await updateLead(dialog.form.id, teacherPayload)
        ElMessage.success('已保存，变更已同步并写入留痕日志')
      }
      dialog.visible = false
      await load()
    } catch (e) {
      console.error('[leads.save]', e)
      ElMessage.error(e instanceof Error ? e.message : '保存失败，请稍后重试')
    } finally {
      dialog.saving = false
    }
  }

  async function openHistory(row: YimaiLead) {
    try {
      history.list = await getLeadHistory(row.id)
      history.visible = true
    } catch (e) {
      console.error('[leads.history]', e)
      ElMessage.error('变更记录加载失败，请稍后重试')
    }
  }

  /**
   * 跟进时限基准：第一次预约体验课的时间。
   * 取体验课卡片中 session 最小且已填时间的一张；若该张被标记「已取消」则返回空（跟进时限留白）。
   * 兼容历史单节体验课字段（trialTime）。
   */
  function firstTrialTime(row: YimaiLead): string {
    const cards = (row.trialCards ?? []).filter((c) => c.time)
    if (cards.length) {
      const first = [...cards].sort((a, b) => (a.session ?? 0) - (b.session ?? 0))[0]
      if (first.cancelled) return ''
      return first.time
    }
    return row.trialTime ?? ''
  }

  /** 兼容 'YYYY-MM-DD HH:mm' 与 'YYYY-MM-DD' 的日期解析（Safari 对空格格式会 Invalid Date） */
  function parseDate(date: string): Date {
    const normalized = date.includes(' ') ? date.replace(' ', 'T') : date
    return new Date(normalized)
  }

  function plusDays(date: string, days: number): string {
    if (!date) return ''
    const d = parseDate(date)
    if (Number.isNaN(d.getTime())) return '—'
    d.setDate(d.getDate() + days)
    return `${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  }

  function deadlineClass(row: YimaiLead, days: number): string {
    const base = firstTrialTime(row)
    if (!base) return ''
    if (['已成交', '已流失', '爽约'].includes(row.status)) return 'done'
    const deadline = parseDate(base)
    if (Number.isNaN(deadline.getTime())) return ''
    deadline.setDate(deadline.getDate() + days)
    return deadline.getTime() < Date.now() ? 'overdue' : ''
  }

  /** 删除留资：按编辑权限控制，二次确认后删除并刷新 */
  async function removeLead(row: YimaiLead) {
    if (!canManageLead(row)) {
      ElMessage.warning('无权限删除该留资')
      return
    }
    try {
      await ElMessageBox.confirm(
        `确认删除留资「${row.name}（${row.source}）」？删除后不可恢复，操作留痕会保留本次删除记录。`,
        '删除留资',
        {
          type: 'warning',
          confirmButtonText: '删除',
          cancelButtonText: '取消'
        }
      )
    } catch {
      return
    }
    try {
      await deleteLead(row.id)
      ElMessage.success('已删除')
      await load()
    } catch (e) {
      console.error('[leads.remove]', e)
      ElMessage.error(e instanceof Error ? e.message : '删除失败，请稍后重试')
    }
  }

  function statusType(status: string): 'danger' | 'warning' | 'info' | 'success' | 'primary' {
    const map: Record<string, 'danger' | 'warning' | 'info' | 'success' | 'primary'> = {
      新留资: 'danger',
      已联系: 'warning',
      已约体验: 'primary',
      已体验: 'primary',
      已成交: 'success',
      爽约: 'danger',
      已流失: 'info'
    }
    return map[status] ?? 'info'
  }

  /**
   * 上课老师。
   *
   * 数据实际存在**逐节体验课卡片**里（`trialCards[].teacher`）；顶层的 `trialTeacher`
   * 是旧版"单节"模型留下的字段，只兼容还没转成卡片的老数据。列表原先只读顶层字段，
   * 于是详情里逐节填了老师、列表却一直是空的。
   */
  function classTeachers(row: YimaiLead): string[] {
    const fromCards = (row.trialCards ?? [])
      .slice()
      .sort((a, b) => (a.session ?? 0) - (b.session ?? 0))
      .map((c) => (c.teacher ?? '').trim())
      .filter(Boolean)
    if (fromCards.length) return [...new Set(fromCards)]
    return row.trialTeacher ? [row.trialTeacher] : []
  }

  // ---------- 移动端卡片 ----------
  //
  // 表格 16 列，手机可见区只有 310px（内容宽 1840px），横滑也看不到完整信息，
  // 所以窄屏换卡片。这里保留决策必需的字段，明细留在桌面端。

  /** 顶部标签：状态优先，其次来源 / 门店 / 会籍顾问 */
  function leadCardTags(row: YimaiLead): MobileCardTag[] {
    const tags: MobileCardTag[] = [
      { text: row.status, type: statusType(row.status), effect: 'dark' }
    ]
    if (row.source) tags.push({ text: row.source, effect: 'plain' })
    if (row.venue) tags.push({ text: row.venue, effect: 'plain' })
    tags.push(
      row.serviceTeacher
        ? { text: row.serviceTeacher, effect: 'plain' }
        : { text: '待分配', type: 'danger', effect: 'plain' }
    )
    return tags
  }

  /** 指标：只放金额，没有就不占位 */
  function leadCardMetrics(row: YimaiLead): MobileCardMetric[] {
    const metrics: MobileCardMetric[] = []
    if (row.redeemAmount !== null && row.redeemAmount !== undefined) {
      metrics.push({ label: '核销金额', value: `¥${row.redeemAmount}` })
    }
    if (row.dealAmount !== null && row.dealAmount !== undefined) {
      metrics.push({ label: '成交金额', value: `¥${row.dealAmount.toLocaleString()}` })
    }
    return metrics
  }

  /**
   * 补充说明 = 跟进时限
   *
   * 留资页在手机上最该被提醒的就是「这个客户什么时候该跟」，
   * 所以做成卡片上最醒目的一块，过期时标红。
   */
  function leadCardNote(row: YimaiLead): { text: string; label: string; danger: boolean } | null {
    const base = firstTrialTime(row)
    if (!base) return null
    const overdue = [7, 15, 30].some((d) => deadlineClass(row, d) === 'overdue')
    return {
      label: '跟进时限',
      text: `首跟 ${plusDays(base, 7)} · 二跟 ${plusDays(base, 15)} · 三跟 ${plusDays(base, 30)}`,
      danger: overdue
    }
  }

  /** 体验课一句话摘要，替代表格里按节多行展开的「体验课 / 券码」列 */
  function leadCardTrial(row: YimaiLead): string {
    const cards = row.trialCards ?? []
    if (cards.length) {
      return cards
        .map((t) => {
          const flags = [
            t.noShow ? '已爽约' : t.attended ? '已上课' : '',
            t.cancelled ? '已取消' : ''
          ]
            .filter(Boolean)
            .join('、')
          const remain =
            t.total !== null && t.total !== undefined
              ? ` ${t.remaining ?? t.total}/${t.total}次`
              : ''
          const who = (t.teacher ?? '').trim()
          return `第${t.session}节${who ? ` ${who}` : ''}${flags ? `（${flags}）` : ''}${remain}`
        })
        .join('；')
    }
    if (row.couponName || row.voucherCode) {
      return [row.couponName, row.voucherCode].filter(Boolean).join(' ')
    }
    return ''
  }

  function leadCardActions(row: YimaiLead): MobileCardAction[] {
    const manage = canManageLead(row)
    return [
      { text: '编辑', type: 'primary', onClick: () => openEdit(row), show: manage },
      { text: '变更记录', onClick: () => openHistory(row) },
      { text: '删除', type: 'danger', onClick: () => removeLead(row), show: manage }
    ]
  }

  /** 预计算一次，避免模板里对每行重复调用多个函数 */
  const cardRows = computed(() =>
    list.value.map((row) => ({
      id: row.id,
      title: row.name,
      subtitle: `${row.phone || (row.phoneTail ? `尾号${row.phoneTail}` : '—')}${
        row.wechat ? ` · 微信：${row.wechat}` : ''
      }`,
      tags: leadCardTags(row),
      metrics: leadCardMetrics(row),
      note: leadCardNote(row),
      trial: leadCardTrial(row),
      demand: row.demand || '',
      remark: row.remark || '',
      meta: [row.orderPlatform, row.createdBy ? `录入：${row.createdBy}` : '']
        .filter(Boolean)
        .join(' · '),
      actions: leadCardActions(row)
    }))
  )

  onMounted(() => {
    load()
    loadConsultants()
  })
</script>

<style scoped lang="scss">
  .followup-cell {
    display: flex;
    flex-direction: column;
    font-size: 11px;
    line-height: 16px;
    color: var(--el-text-color-secondary);

    .overdue {
      font-weight: 500;
      color: var(--el-color-danger);
    }

    .done {
      text-decoration: line-through;
      opacity: 0.5;
    }
  }

  // 移动端卡片里的补充信息（需求 / 体验课 / 备注）
  .lead-card {
    &__extra {
      padding-top: 10px;
      margin-top: 10px;
      border-top: 1px dashed var(--art-card-border);
    }

    &__line {
      margin-top: 4px;
      font-size: 13px;
      line-height: 1.6;
      color: var(--art-gray-700);

      &:first-child {
        margin-top: 0;
      }
    }

    &__label {
      display: inline-block;
      min-width: 3.5em;
      margin-right: 4px;
      color: var(--art-gray-500);
    }

    &__meta {
      margin-top: 6px;
      font-size: 12px;
      color: var(--art-gray-500);
    }
  }
</style>
