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
        <ElButton @click="load">查询</ElButton>
        <ElButton @click="resetFilters">重置</ElButton>
        <div class="flex-1" />
        <ElButton type="primary" v-ripple @click="openCreate">新增留资</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left>
          <span class="text-sm text-gray-400">
            {{ scopeHint }} · 三次跟进时限自动计算（7/15/30天），超期红色提醒 ·
            来源口径：大众点评A/美团B/抖音C/视频号D/自然到店E
          </span>
        </template>
      </ArtTableHeader>

      <ElTable v-loading="loading" :data="pagedList" border stripe>
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
        <ElTableColumn label="会籍顾问" width="95">
          <template #default="{ row }">
            <span v-if="row.serviceTeacher">{{ row.serviceTeacher }}</span>
            <ElTag v-else size="small" type="danger">待分配</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="上课老师" width="95">
          <template #default="{ row }">
            <span v-if="row.trialTeacher">{{ row.trialTeacher }}</span>
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
        <ElTableColumn label="跟进时限" width="170">
          <template #default="{ row }">
            <div class="followup-cell">
              <span :class="deadlineClass(row, 7)">首跟{{ plusDays(row.leadDate, 7) }}</span>
              <span :class="deadlineClass(row, 15)">二跟{{ plusDays(row.leadDate, 15) }}</span>
              <span :class="deadlineClass(row, 30)">三跟{{ plusDays(row.leadDate, 30) }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="createdBy" label="录入人" width="80" />
        <ElTableColumn label="操作" width="150" fixed="right">
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
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="mt-4 flex justify-end">
        <ElPagination
          v-model:current-page="page.current"
          :page-size="page.size"
          :total="filteredList.length"
          layout="total, prev, pager, next"
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
            次数）。
          </div>
          <div v-if="dialog.form.trialCards.length" class="space-y-3">
            <div
              v-for="(card, idx) in dialog.form.trialCards"
              :key="idx"
              class="border border-dashed border-gray-300 rounded-lg p-3"
            >
              <div class="flex-cb mb-2">
                <span class="text-sm font-500">第 {{ card.session }} 节体验课</span>
                <ElButton
                  link
                  type="danger"
                  size="small"
                  :disabled="idx === 0 && dialog.form.trialCards.length === 1"
                  @click="removeTrialCard(idx)"
                >
                  移除
                </ElButton>
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
    getLeadHistory,
    queryLeads,
    queryCustomers,
    updateLead
  } from '@/api/yimai'
  import type { YimaiLead } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiLeads' })

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
  const isTeacher = computed(() => roles.value.includes('R_TEACHER'))
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
  const page = ref({ current: 1, size: 20 })
  const list = ref<YimaiLead[]>([])

  /** 会籍顾问选项：从在册会员的会籍顾问去重得到 */
  const consultantOptions = ref<string[]>([])
  async function loadConsultants() {
    try {
      const res = await queryCustomers({ type: 'member', current: 1, size: 5000 })
      const set = new Set<string>()
      for (const c of res.records ?? []) {
        const name = (c.consultant ?? '').trim()
        if (name) set.add(name)
      }
      consultantOptions.value = [...set].sort((a, b) => a.localeCompare(b, 'zh'))
    } catch {
      /* 静默失败，允许手输 */
    }
  }

  const filteredList = computed(() => list.value)
  const pagedList = computed(() =>
    filteredList.value.slice(
      (page.value.current - 1) * page.value.size,
      page.value.current * page.value.size
    )
  )

  function emptyForm() {
    return {
      id: 0,
      leadDate: new Date().toISOString().slice(0, 10),
      name: '',
      phone: '',
      phoneTail: '',
      wechat: '',
      demand: '',
      source: '',
      orderPlatform: '',
      venue: isManager.value || isTeacher.value
        ? (String(userStore.getUserInfo.venue ?? '绿地店') as YimaiLead['venue'])
        : ('绿地店' as YimaiLead['venue']),
      serviceTeacher: isTeacher.value ? String(userStore.getUserInfo.userName ?? '') : '',
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
      redeem: null
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
      page.value.current = 1
      const [dateFrom, dateTo] = filters.value.dateRange ?? [undefined, undefined]
      list.value = (
        await queryLeads({
          ...filters.value,
          dateFrom,
          dateTo,
          current: 1,
          size: 5000
        })
      ).records
    } catch (e) {
      console.error('[leads.load]', e)
      ElMessage.error('留资列表加载失败，请稍后重试')
    } finally {
      loading.value = false
    }
  }

  function resetFilters() {
    filters.value = { name: '', phone: '', venue: '', status: '', dateRange: null }
    load()
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
      trialCards: Array.isArray(row.trialCards) ? [...row.trialCards] : []
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
    // 清理空的体验课卡片（未填任何内容不提交）
    dialog.form.trialCards = dialog.form.trialCards.filter(
      (c) =>
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
    dialog.form.redeemAmount = redeemSum > 0 ? redeemSum : null
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
              serviceTeacher: String(userStore.getUserInfo.userName ?? ''),
              status: '新留资' as const,
              remark: dialog.form.remark
            }
          : {
              demand: dialog.form.demand,
              status: dialog.form.status,
              remark: dialog.form.remark,
              serviceTeacher: String(userStore.getUserInfo.userName ?? ''),
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

  function plusDays(date: string, days: number): string {
    if (!date) return '—'
    const d = new Date(date)
    d.setDate(d.getDate() + days)
    return `${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  }

  function deadlineClass(row: YimaiLead, days: number): string {
    if (!row.leadDate) return ''
    if (['已成交', '已流失', '爽约'].includes(row.status)) return 'done'
    const deadline = new Date(row.leadDate)
    deadline.setDate(deadline.getDate() + days)
    return deadline.getTime() < Date.now() ? 'overdue' : ''
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
</style>
