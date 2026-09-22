<template>
  <div class="list-page list-page--fill">
    <ElCard>
      <!-- Tab 导航 -->
      <ElTabs v-model="activeTab" class="mb-3">
        <ElTabPane v-for="t in TABS" :key="t.key" :name="t.key">
          <template #label>
            <span class="flex items-center gap-1.5">
              {{ t.label }}
              <ElTag v-if="t.key !== 'all'" size="small" :type="t.tag" effect="plain">{{
                listCounts[TAB_KEY_TO_LIST[t.key]] ?? 0
              }}</ElTag>
            </span>
          </template>
        </ElTabPane>
      </ElTabs>

      <!-- 规则设置 -->
      <div class="mb-3 flex flex-wrap items-center gap-3 text-xs text-gray-400">
        <span>{{ scopeHint }}</span>
        <ElButton v-if="isSuper" link type="primary" size="small" @click="rulesDlg = true"
          >调整标签阈值</ElButton
        >
      </div>

      <!-- 筛选：总览与其他清单页签均生效 -->
      <div class="filter-bar">
        <ElInput
          v-model="searchForm.name"
          placeholder="会员姓名"
          clearable
          class="f-lg"
          @change="reloadFromFirstPage"
        />
        <ElInput
          v-model="searchForm.phone"
          placeholder="手机号 / 尾号"
          clearable
          class="f-xl"
          @change="reloadFromFirstPage"
        />
        <ElSelect
          v-if="!isManager"
          v-model="searchForm.venue"
          placeholder="门店"
          clearable
          class="f-sm"
          @change="reloadFromFirstPage"
        >
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElSelect
          v-if="activeTab === 'all'"
          v-model="searchForm.list"
          placeholder="运营清单"
          clearable
          class="f-lg"
          @change="reloadFromFirstPage"
        >
          <ElOption v-for="k in LIST_KEYS" :key="k" :label="k" :value="k" />
        </ElSelect>
        <ElSelect
          v-model="searchForm.consultant"
          placeholder="会籍顾问"
          clearable
          class="f-lg"
          @change="reloadFromFirstPage"
        >
          <ElOption value="待分配" label="待分配" />
          <ElOption v-for="c in consultantOptions" :key="c" :label="c" :value="c" />
        </ElSelect>
        <ElSelect
          v-model="searchForm.evaluationStatus"
          placeholder="评估状态"
          clearable
          class="f-md"
          @change="reloadFromFirstPage"
        >
          <ElOption
            v-for="status in EVALUATION_STATUSES"
            :key="status"
            :label="status"
            :value="status"
          />
        </ElSelect>
        <ElButton type="primary" plain @click="reloadFromFirstPage">查询</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left
          ><span class="text-sm text-gray-400">{{ scopeHint }}</span></template
        >
      </ArtTableHeader>

      <!--
        降级说明（后端 degraded）：非空表示**有判定规则没能生效**。
        实测开发库最常见的一条是「无卡项汇总快照，剩余占比规则未生效（请先执行一次同步）」
        —— 即 renewalCountPercent / renewalExpirePercent 两个阈值在卡项快照为 NULL 时静默失效。
        用户此前抱怨「调了阈值没反应」，这条是他唯一能自查的线索，所以常驻放在列表上方，
        不藏进 tooltip。
      -->
      <ElAlert
        v-if="degradedNotices.length"
        class="mb-3"
        type="warning"
        :closable="false"
        title="部分判定规则当前未生效，下面这些人可能被漏判"
      >
        <ul class="reason-degraded-list">
          <li v-for="(m, i) in degradedNotices" :key="i">{{ m }}</li>
        </ul>
      </ElAlert>

      <ElTable
        v-if="!isHandheld"
        ref="tableRef"
        v-loading="loading"
        :data="list"
        border
        stripe
        :max-height="tableMaxHeight"
      >
        <ElTableColumn label="会员" min-width="130" fixed="left">
          <template #default="{ row }">
            <div class="font-500">{{ row.name }}</div>
            <div class="text-xs text-gray-400"
              >{{ row.phone || '尾号' + row.phoneTail
              }}{{ row.source ? ` · ${row.source}` : '' }}</div
            >
          </template>
        </ElTableColumn>

        <ElTableColumn label="门店" width="85">
          <template #default="{ row }">
            <ElTag size="small" effect="plain">{{ row.venue }}</ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn label="会籍顾问" min-width="100">
          <template #default="{ row }">
            <span v-if="row.consultant" class="text-sm">{{ row.consultant }}</span>
            <ElTag v-else size="small" type="danger" effect="plain">待分配</ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn label="生日" width="100" align="center">
          <template #default="{ row }">
            <template v-if="row.birthday">
              <div class="text-sm tabular-nums">{{ row.birthday.slice(0, 10) }}</div>
              <ElTag v-if="isBirthdayToday(row.birthday)" size="small" type="danger" effect="dark"
                >今天生日</ElTag
              >
              <div v-else-if="birthdayOffset(row.birthday)" class="text-xs text-orange-500">
                {{ birthdayOffset(row.birthday) }}天后生日
              </div>
            </template>
            <span v-else class="text-xs text-gray-300">—</span>
          </template>
        </ElTableColumn>

        <ElTableColumn label="清单归属" min-width="190">
          <template #default="{ row }">
            <div class="flex flex-wrap items-center gap-1">
              <!--
                主标签：在待续费清单里时用后端 primary（可能是「待复活」，因为
                待复活态要先唤醒再谈续费），否则回退到清单键名。
              -->
              <ElTag v-if="reasonOf(row)" size="small" effect="dark" :type="primaryType(row)">{{
                primaryLabel(row)
              }}</ElTag>
              <!-- 同时所属的其它清单：与主标签并列展示，用户能看出「他同时属于两处」 -->
              <ElTag
                v-for="l in secondaryLists(row)"
                :key="l"
                size="small"
                effect="plain"
                :type="listType(l)"
                >{{ l }}</ElTag
              >
              <!-- 没有判定理由时（不在待续费清单）退回原来的清单标签渲染 -->
              <ElTag
                v-for="l in reasonOf(row) ? [] : memberLists(row)"
                :key="l"
                size="small"
                effect="dark"
                :type="listType(l)"
                >{{ l }}</ElTag
              >

              <!--
                判定理由入口：点开看「为什么他在这个清单里」。
                用 popover 而不是 tooltip —— 理由可能有多条 + 降级说明，需要可停留、可滚动。
              -->
              <ElPopover
                v-if="reasonOf(row)"
                placement="right"
                :width="330"
                trigger="click"
                popper-class="renewal-reason-popover"
              >
                <template #reference>
                  <span class="reason-trigger" :class="{ 'is-degraded': degradedOf(row).length }">
                    <ArtSvgIcon icon="ri:question-line" />
                    <span class="reason-trigger__text">判定依据</span>
                  </span>
                </template>
                <div class="reason-body">
                  <div class="reason-body__head">
                    <span class="font-600">{{ row.name }}</span>
                    <ElTag size="small" effect="dark" :type="primaryType(row)">
                      {{ reasonOf(row)?.bucket || primaryLabel(row) }}
                    </ElTag>
                  </div>

                  <div class="reason-body__section">为什么在待续费清单</div>
                  <ul class="reason-body__list">
                    <li v-for="(w, i) in reasonOf(row)?.why ?? []" :key="i">{{ w }}</li>
                  </ul>

                  <template v-if="secondaryLists(row).length">
                    <div class="reason-body__section">同时所属清单</div>
                    <div class="flex flex-wrap gap-1">
                      <ElTag
                        v-for="l in secondaryLists(row)"
                        :key="l"
                        size="small"
                        effect="plain"
                        :type="listType(l)"
                        >{{ l }}</ElTag
                      >
                    </div>
                  </template>

                  <!-- 降级说明：与 why 分开呈现，避免用户把「规则没生效」误读成判定理由 -->
                  <template v-if="degradedOf(row).length">
                    <div class="reason-body__section reason-body__section--warn">
                      以下规则当前未生效（可能漏判）
                    </div>
                    <ul class="reason-body__list reason-body__list--warn">
                      <li v-for="(d, i) in degradedOf(row)" :key="i">{{ d }}</li>
                    </ul>
                  </template>
                </div>
              </ElPopover>

              <span v-if="!memberLists(row).length" class="text-xs text-gray-300">—</span>
            </div>
          </template>
        </ElTableColumn>

        <!-- 出勤三列：所有清单通用展示。口径＝三个连续 30 天滚动窗口的上课次数（近30天含今天） -->
        <ElTableColumn v-if="activeTab !== 'vip'" width="150" align="center">
          <template #header>
            <ElTooltip placement="top">
              <template #content>
                <div>三个连续 30 天窗口的上课次数：再前30天 / 前30天 / 近30天</div>
                <div v-if="attendanceRangeLabel" class="mt-1">
                  当前区间：{{ attendanceRangeLabel }}
                </div>
                <div class="mt-1">同一天上两节算两次</div>
              </template>
              <span class="cursor-help">
                出勤
                <span class="text-xs text-gray-400">近30天/前30天/再前30天</span>
              </span>
            </ElTooltip>
          </template>
          <template #default="{ row }">
            <span :class="declining(row) ? 'font-500 text-red-500' : ''">
              {{ row.attendM1 ?? '-' }} / {{ row.attendM2 ?? '-' }} / {{ row.attendM3 ?? '-' }}
            </span>
          </template>
        </ElTableColumn>

        <ElTableColumn
          v-if="activeTab === 'all' || activeTab === 'renewal'"
          label="剩余课时"
          width="150"
        >
          <template #default="{ row }">
            <span
              :class="
                row.remainTimes !== null && row.remainTimes < rules.renewalThreshold
                  ? 'font-600 text-red-500'
                  : ''
              "
            >
              {{ row.remainTimes ?? '—'
              }}<span v-if="row.remainTimes !== null" class="text-xs"> 节</span>
            </span>
            <div
              v-if="row.cardsList?.length"
              class="mt-0.5 text-xs leading-4 text-gray-400"
              :title="cardSummaryText(row.cardsList)"
            >
              <div v-for="card in row.cardsList.slice(0, 3)" :key="card.title" class="truncate">
                {{ card.title }} {{ card.residue ?? '?' }}{{ card.unit
                }}<template v-if="card.unactivated">（未开卡）</template>
              </div>
              <div v-if="row.cardsList.length > 3" class="text-gray-300">
                …共 {{ row.cardsList.length }} 张有效卡
              </div>
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn
          v-if="activeTab === 'all' || activeTab === 'vip'"
          label="累计购买金额"
          width="120"
          align="center"
        >
          <template #default="{ row }">{{
            row.cardPaidAmount != null ? `¥${formatMoney(row.cardPaidAmount)}` : '—'
          }}</template>
        </ElTableColumn>

        <ElTableColumn v-if="activeTab === 'renewal'" label="续费评估" width="145">
          <template #default="{ row }">
            <div v-if="row.evalScore !== null && row.evalScore !== undefined">
              <div class="flex items-center gap-1">
                <span class="text-lg font-600" :class="evaluationColor(row.evalScore)">{{
                  row.evalScore
                }}</span>
                <ElTag size="small" :type="evaluationTag(row.evalScore)" effect="plain">
                  {{ renewalLevelLabel(renewalLevel(row.evalScore)) }}
                </ElTag>
              </div>
              <div
                class="mt-1 text-xs"
                :class="evaluationExpired(row) ? 'text-red-500' : 'text-gray-400'"
              >
                {{ row.evalAt || '日期未知' }}{{ evaluationExpired(row) ? ' · 已过期' : '' }}
              </div>
              <div v-if="row.evalBy" class="text-xs text-gray-400">评估人：{{ row.evalBy }}</div>
            </div>
            <ElTag v-else size="small" type="primary" effect="plain">待评估</ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn v-if="activeTab !== 'all'" label="下一步动作" min-width="165">
          <template #default="{ row }">
            <div v-if="row.nextAction">
              <div class="text-sm font-500">{{ row.nextAction }}</div>
              <div
                class="mt-1 text-xs"
                :class="actionOverdue(row) ? 'text-red-500' : 'text-gray-400'"
              >
                {{ row.owner || row.consultant || '待分配' }} ·
                {{ row.nextActionTime || '待定时间' }}
              </div>
            </div>
            <span v-else class="text-xs text-orange-500">待明确下一步动作</span>
          </template>
        </ElTableColumn>

        <!-- 清单专属列 -->
        <template v-if="activeTab === 'renewal'">
          <ElTableColumn label="续课计划" min-width="220">
            <template #default="{ row }">
              <div v-if="row.renewalPlan?.time">
                <div
                  >{{ row.renewalPlan.intent }} · {{ row.renewalPlan.time }} ·
                  {{ row.renewalPlan.amount }}</div
                >
                <div class="text-xs text-gray-400">诉求：{{ row.renewalPlan.issue || '—' }}</div>
              </div>
              <span v-else class="text-xs text-orange-500">待教练月度预报</span>
            </template>
          </ElTableColumn>
        </template>

        <template v-if="activeTab === 'decline'">
          <ElTableColumn label="下降原因 / 解决方案" min-width="200">
            <template #default="{ row }">
              <div v-if="row.decline?.reason">
                {{ row.decline.reason
                }}<template v-if="row.decline.solution"> · {{ row.decline.solution }}</template>
              </div>
              <span v-else class="text-xs text-orange-500">待管理层确认原因</span>
            </template>
          </ElTableColumn>
        </template>

        <template v-if="activeTab === 'predrop'">
          <ElTableColumn label="停训情况" min-width="180">
            <template #default="{ row }">
              <div>{{ row.stopReason || '停课原因待回访确认' }}</div>
              <div class="text-xs text-gray-400"
                >最近到店：{{ daysAgo(row.lastVisit) }}天前{{
                  row.expectedReturn ? ` · 预期复活 ${row.expectedReturn}` : ''
                }}</div
              >
            </template>
          </ElTableColumn>
        </template>

        <template v-if="activeTab === 'revive'">
          <ElTableColumn label="复活跟进" min-width="200">
            <template #default="{ row }">
              <div>预期复活：{{ row.expectedReturn || '未确认' }}</div>
              <div
                class="text-xs"
                :class="touchOverdue(row) ? 'text-red-500 font-500' : 'text-gray-400'"
              >
                最近沟通：{{ row.lastTouch || '从未'
                }}{{ row.lastTouch ? `（${daysAgo2(row.lastTouch)}天前）` : '' }} · 需协助
                <ElTag v-if="row.needsHelp" size="small" type="danger">是</ElTag>
              </div>
            </template>
          </ElTableColumn>
        </template>

        <ElTableColumn label="操作" width="260" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" @click="openEval(row)">续费评估</ElButton>
            <ElButton link type="primary" size="small" @click="openBirthday(row)">生日</ElButton>
            <ElButton
              v-if="memberLists(row).includes('待续课')"
              link
              type="warning"
              size="small"
              @click="openRenewal(row)"
              >续课计划</ElButton
            >
            <ElButton
              v-if="memberLists(row).includes('出勤降低')"
              link
              type="info"
              size="small"
              @click="openDecline(row)"
              >下降处置</ElButton
            >
            <ElButton
              v-if="memberLists(row).includes('预流失') && !row.inRevive"
              link
              type="success"
              size="small"
              @click="toRevive(row)"
              >转待复活</ElButton
            >
            <ElButton
              v-if="memberLists(row).includes('待复活')"
              link
              type="primary"
              size="small"
              @click="openTouch(row)"
              >记录沟通</ElButton
            >
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表。宽表格在手机上只剩左右固定列，中间数据列会被挤成零宽 -->
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
          <!--
            判定理由在手机上**直接展开**，不学桌面用 popover：
            手机没有 hover，点开浮层又多一次交互，而且这些文案正是用户要核对的东西。
            理由/降级说明都是短句，直接铺开比藏起来更合适。
          -->
          <div v-if="item.reason" class="m-reason">
            <div class="m-reason__row">
              <span class="m-reason__label">判定依据</span>
              <ElTag size="small" effect="dark" :type="item.reason.primaryType">
                {{ item.reason.bucket }}
              </ElTag>
            </div>
            <ul class="m-reason__list">
              <li v-for="(w, i) in item.reason.why" :key="i">{{ w }}</li>
            </ul>
            <div v-if="item.reason.degraded.length" class="m-reason__degraded">
              <div class="m-reason__label m-reason__label--warn"
                >以下规则当前未生效（可能漏判）</div
              >
              <ul class="m-reason__list m-reason__list--warn">
                <li v-for="(d, i) in item.reason.degraded" :key="i">{{ d }}</li>
              </ul>
            </div>
          </div>
        </MobileCard>
        <div v-if="!loading && !cardRows.length" class="m-card-list__empty">暂无数据</div>
      </div>

      <div class="list-pager">
        <ElPagination
          v-model:current-page="page.current"
          v-model:page-size="page.size"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          layout="total, sizes, prev, pager, next"
          @current-change="handlePageChange"
          @size-change="handlePageChange"
        />
      </div>
    </ElCard>

    <!-- 30天续费经营评估 -->
    <ElDrawer v-model="evalDlg.visible" size="620px" title="30天续费经营评估">
      <template v-if="evalDlg.row">
        <p class="text-sm mb-3">
          评估对象：<span class="font-600">{{ evalDlg.row.name }}</span>
          <span
            v-if="evalDlg.row.evalScore !== null && evalDlg.row.evalScore !== undefined"
            class="ml-2 text-xs text-gray-400"
            >上次：{{ evalDlg.row.evalScore }}分（{{ evalDlg.row.evalAt }}）</span
          >
        </p>
        <ElAlert
          title="系统数据自动计分，人工只判断系统无法读取的服务与沟通信号"
          type="info"
          :closable="false"
          class="mb-3"
        />
        <div v-loading="evalDlg.loading" class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
          <div class="rounded-md bg-gray-50 dark:bg-gray-800 p-2 text-center">
            <div class="text-lg font-600">{{ evalContext?.attendanceCount ?? '-' }}</div>
            <div class="text-xs text-gray-400">近30天签到</div>
          </div>
          <div class="rounded-md bg-gray-50 dark:bg-gray-800 p-2 text-center">
            <div class="text-lg font-600">{{ evalContext?.remainTimes ?? '—' }}</div>
            <div class="text-xs text-gray-400">剩余课时</div>
          </div>
          <div class="rounded-md bg-gray-50 dark:bg-gray-800 p-2 text-center">
            <div class="text-sm font-600">{{ evalContext?.expireDate ?? '—' }}</div>
            <div class="text-xs text-gray-400">卡项到期</div>
          </div>
          <div class="rounded-md bg-gray-50 dark:bg-gray-800 p-2 text-center">
            <div class="text-sm font-600">
              {{
                evalContext
                  ? `${evalContext.attendM1}/${evalContext.attendM2}/${evalContext.attendM3}`
                  : '-'
              }}
            </div>
            <div class="text-xs text-gray-400">三月出勤</div>
          </div>
        </div>
        <div v-for="dim in EVAL_DIMENSIONS" :key="dim.key" class="mb-4">
          <div class="text-sm font-500 mb-1">{{ dim.title }}</div>
          <div v-if="dim.hint" class="text-xs text-gray-400 mb-1.5">{{ dim.hint }}</div>
          <ElRadioGroup v-model="evalAnswers[dim.key]" class="flex flex-col items-start">
            <ElRadio v-for="op in dim.options" :key="op.key" :value="op.key">
              {{ op.label }}（{{ op.score > 0 ? '+' : '' }}{{ op.score }}）
            </ElRadio>
          </ElRadioGroup>
        </div>
        <div class="mb-4">
          <div class="text-sm font-500 mb-1">风险减分项（可多选）</div>
          <ElCheckboxGroup v-model="evalAnswers.risks" class="flex flex-col items-start">
            <ElCheckbox v-for="risk in EVAL_RISKS" :key="risk.key" :value="risk.key">
              {{ risk.label }}（{{ risk.score }}）
            </ElCheckbox>
          </ElCheckboxGroup>
        </div>
        <ElFormItem label="评估备注">
          <ElInput
            v-model="evalRemark"
            type="textarea"
            :rows="2"
            placeholder="记录主要信号、客户顾虑和建议动作"
          />
        </ElFormItem>
        <div
          class="sticky bottom-0 bg-white dark:bg-[#1a1a1a] pt-3 pb-1 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between"
        >
          <div>
            <span
              class="text-2xl font-600"
              :class="
                evalTotal >= 70
                  ? 'text-green-600'
                  : evalTotal >= 40
                    ? 'text-orange-500'
                    : 'text-red-500'
              "
              >{{ evalTotal }}</span
            >
            <span class="text-xs text-gray-400 ml-2"
              >分 · {{ renewalLevelLabel(renewalLevel(evalTotal)) }}</span
            >
          </div>
          <div class="flex gap-2">
            <span class="text-xs self-center text-gray-400">保存后自动生成下一步任务</span>
            <ElButton type="primary" :loading="evalDlg.saving" @click="saveEval"
              >保存并生成任务</ElButton
            >
          </div>
        </div>
      </template>
    </ElDrawer>

    <!-- 续课计划登记（教练月度预报） -->
    <ElDialog
      v-model="renewalDlg.visible"
      title="续课计划登记（月度会预报口径）"
      width="520px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="续课意愿">
          <ElSelect v-model="renewalDlg.form.intent">
            <ElOption label="确认续课" value="确认续课" />
            <ElOption label="有意向待跟进" value="有意向待跟进" />
            <ElOption label="无法续课（填原因）" value="无法续课" />
          </ElSelect>
        </ElFormItem>
        <ElRow :gutter="10">
          <ElCol :span="8"
            ><ElFormItem label="预计时间"
              ><ElInput v-model="renewalDlg.form.time" placeholder="如：9月中" /></ElFormItem
          ></ElCol>
          <ElCol :span="8"
            ><ElFormItem label="预计金额"
              ><ElInput v-model="renewalDlg.form.amount" placeholder="如：¥6800" /></ElFormItem
          ></ElCol>
          <ElCol :span="8"
            ><ElFormItem label="续课课种"><ElInput v-model="renewalDlg.form.course" /></ElFormItem
          ></ElCol>
        </ElRow>
        <ElFormItem label="客户问题"
          ><ElInput
            v-model="renewalDlg.form.issue"
            type="textarea"
            :rows="2"
            placeholder="客户问题与诉求（首周只确认不解决，下次课处理）"
        /></ElFormItem>
        <ElAlert
          title="流程提醒：首周由管理层每日提醒出勤并确认意愿，问题解决后再由教练执行续课"
          type="info"
          :closable="false"
        />
      </ElForm>
      <template #footer>
        <ElButton @click="renewalDlg.visible = false">取消</ElButton>
        <ElButton type="primary" @click="saveRenewal">保存预报</ElButton>
      </template>
    </ElDialog>

    <!-- 出勤降低处置 -->
    <ElDialog v-model="declineDlg.visible" title="出勤降低处置" width="480px" destroy-on-close>
      <ElForm label-width="92px">
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
        <ElButton type="primary" @click="saveDecline">保存处置</ElButton>
      </template>
    </ElDialog>

    <!-- 待复活沟通记录 -->
    <ElDialog
      v-model="touchDlg.visible"
      title="待复活沟通记录（不低于每2周一次有效传递）"
      width="480px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="预期复活"
          ><ElDatePicker
            v-model="touchDlg.form.expectedReturn"
            type="date"
            value-format="YYYY-MM-DD"
            class="!w-full"
        /></ElFormItem>
        <ElFormItem label="需协助"
          ><ElSwitch v-model="touchDlg.form.needsHelp" /><span class="ml-2 text-xs text-gray-400"
            >预期复活1个月内的会员周会100%检查</span
          ></ElFormItem
        >
      </ElForm>
      <template #footer>
        <ElButton @click="touchDlg.visible = false">取消</ElButton>
        <ElButton type="primary" @click="saveTouch">记录本次沟通</ElButton>
      </template>
    </ElDialog>

    <!-- 生日维护 -->
    <ElDialog v-model="birthdayDlg.visible" title="会员生日维护" width="420px" destroy-on-close>
      <ElForm label-width="92px">
        <ElFormItem label="会员">
          <span class="font-500">{{ birthdayDlg.row?.name }}</span>
        </ElFormItem>
        <ElFormItem label="生日">
          <ElDatePicker
            v-model="birthdayDlg.form.birthday"
            type="date"
            value-format="YYYY-MM-DD"
            class="!w-full"
            placeholder="选择生日，用于生日关怀提醒"
          />
        </ElFormItem>
        <ElAlert
          title="保存后进入工作台「今日待办 → 生日关怀」：今天与未来 7 天内的生日会员会自动提醒"
          type="info"
          :closable="false"
        />
      </ElForm>
      <template #footer>
        <ElButton @click="birthdayDlg.visible = false">取消</ElButton>
        <ElButton type="primary" @click="saveBirthday">保存</ElButton>
      </template>
    </ElDialog>

    <!-- 阈值设置 -->
    <ElDialog v-model="rulesDlg" title="清单规则阈值（保存后实时重算）" width="520px">
      <ElForm label-width="140px">
        <ElFormItem label="次卡剩余(节)"
          ><ElInputNumber v-model="rulesForm.renewalThreshold" :min="1" :max="50" /><span
            class="ml-2 text-xs text-gray-400"
            >全部次卡合计剩余 ≤ 该值且最近月有出勤（含未开卡）</span
          ></ElFormItem
        >
        <ElFormItem label="次卡剩余占比(%)"
          ><ElInputNumber v-model="rulesForm.renewalCountPercent" :min="0" :max="100" /><span
            class="ml-2 text-xs text-gray-400"
            >合计剩余 ÷ 合计绑定(剩余+已用) ≤ 该值；0=关闭</span
          ></ElFormItem
        >
        <ElFormItem label="到期提醒(天)"
          ><ElInputNumber v-model="rulesForm.renewalExpireDays" :min="1" :max="365" /><span
            class="ml-2 text-xs text-gray-400"
            >任一有效卡剩余天数 ≤ 该值（含次卡/时间卡）</span
          ></ElFormItem
        >
        <ElFormItem label="有效期占比(%)"
          ><ElInputNumber v-model="rulesForm.renewalExpirePercent" :min="0" :max="100" /><span
            class="ml-2 text-xs text-gray-400"
            >时间卡剩余天数 ÷ 有效期天数 ≤ 该值；0=关闭</span
          ></ElFormItem
        >
        <ElAlert class="mb-3" type="info" :closable="false">
          <template #title>待续费判定：合计与逐卡两个口径同时生效，任一命中即进清单</template>
          <template #default>
            <div class="text-xs leading-5">
              <div>
                · <strong>合计口径</strong>：全部在用卡的剩余课时/有效期占比低于阈值即命中；
              </div>
              <div>
                · <strong>逐卡口径</strong>：任一单卡进入尾段（剩余课时或有效期占比低于阈值）也命中
                —— 合计还有几十节、但其中一张卡只剩几节，同样该续；
              </div>
              <div>
                · <strong>已过期但仍有余额</strong>的卡，在配置的回溯窗内同样触发提醒；
                已用完、未开卡的卡项不参与判定。
              </div>
              <div class="mt-1 text-gray-500">
                近 30 天未到店只影响「紧急 / 观察」分档，不再是进清单的门槛。
              </div>
            </div>
          </template>
        </ElAlert>
        <ElFormItem label="VIP阈值(元)"
          ><ElInputNumber
            v-model="rulesForm.vipAmountThreshold"
            :min="1000"
            :max="1000000"
            :step="1000"
          /><span class="ml-2 text-xs text-gray-400">会员卡实收金额 ≥ 该值</span></ElFormItem
        >
        <ElFormItem label="出勤下降口径">
          <ElRadioGroup v-model="rulesForm.declineMode">
            <ElRadio value="strict">连续三月递减</ElRadio>
            <ElRadio value="recent">最近两月下降</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem label="预流失(天)">
          <div class="flex items-center gap-2">
            <ElInputNumber v-model="rulesForm.predropMin" :min="1" :max="rulesForm.predropMax" />
            <span class="text-gray-400">~</span>
            <ElInputNumber v-model="rulesForm.predropMax" :min="rulesForm.predropMin" :max="180" />
          </div>
          <div class="text-xs text-gray-400 mt-1">或：上月在训、本月停训（M2&gt;0 且 M3=0）</div>
        </ElFormItem>
        <ElFormItem label="待复活(天)"
          ><ElInputNumber v-model="rulesForm.reviveDays" :min="7" :max="365" /><span
            class="ml-2 text-xs text-gray-400"
            >超过 N 天未到店且有卡项资产</span
          ></ElFormItem
        >
        <ElDivider content-position="left">新客培养 · 养成目标节数（入会90天内）</ElDivider>
        <ElFormItem label="私教目标(节)"
          ><ElInputNumber v-model="rulesForm.cultivationPrivate" :min="1" :max="100" /><span
            class="ml-2 text-xs text-gray-400"
            >新客私教已上课 ≥ 该值视为已养成</span
          ></ElFormItem
        >
        <ElFormItem label="小班目标(节)"
          ><ElInputNumber v-model="rulesForm.cultivationSmall" :min="1" :max="100" /><span
            class="ml-2 text-xs text-gray-400"
            >新客小班已上课 ≥ 该值视为已养成</span
          ></ElFormItem
        >
        <ElFormItem label="团课目标(节)"
          ><ElInputNumber v-model="rulesForm.cultivationGroup" :min="1" :max="100" /><span
            class="ml-2 text-xs text-gray-400"
            >新客团课已上课 ≥ 该值视为已养成</span
          ></ElFormItem
        >
      </ElForm>
      <template #footer>
        <ElButton @click="rulesDlg = false">取消</ElButton>
        <ElButton type="primary" @click="applyRules">保存规则</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import {
    queryCustomers,
    getMemberRules,
    setMemberRules,
    refreshMemberRules,
    updateMemberFields,
    birthdayOffset,
    isBirthdayToday,
    queryCustomerOptions,
    queryMemberListCounts,
    EVAL_DIMENSIONS,
    EVAL_RISKS,
    evalTotalScore,
    getRenewalEvaluation,
    saveRenewalEvaluation,
    renewalLevel,
    renewalLevelLabel
  } from '@/api/yimai'
  import type {
    YimaiCustomer,
    MemberListKey,
    RenewalEvaluationAnswers,
    RenewalEvaluationContext,
    RenewalReason,
    CustomerCardItem
  } from '@/api/yimai'
  import { USE_BACKEND, apiGet } from '@/api/backend'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'
  import { toLocalDateString } from '@/utils'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiMembers' })

  // 手持设备上用卡片列表代替宽表格（见下方 cardMetrics / cardNote / cardTags）
  const { isHandheld } = useDevice()

  // 表格高度自适应：减项由 hook 运行时量出，本页不写任何像素
  const { tableMaxHeight, tableRef } = useTableHeight()

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isSuper = computed(() => roles.value.includes('R_SUPER'))
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  /** 数据范围说明：随当前门店/清单/顾问筛选动态显示 */
  const scopeHint = computed(() => {
    const venue = searchForm.value.venue || userStore.getUserInfo.venue || '双店'
    const parts = [`数据范围：${venue}`]
    if (activeTab.value !== 'all') parts.push(`清单：${TAB_KEY_TO_LIST[activeTab.value]}`)
    else if (searchForm.value.list) parts.push(`清单：${searchForm.value.list}`)
    if (searchForm.value.consultant) parts.push(`顾问：${searchForm.value.consultant}`)
    return parts.join(' · ')
  })

  function formatMoney(v: number): string {
    return Number(v).toLocaleString('zh-CN')
  }

  /** 剩余课时列悬浮提示：逐卡列出名称+剩余 */
  function cardSummaryText(cards: CustomerCardItem[]): string {
    return cards
      .map((x) => `${x.title} ${x.residue ?? '?'}${x.unit}${x.unactivated ? '（未开卡）' : ''}`)
      .join('；')
  }

  const LIST_KEYS: MemberListKey[] = ['待续课', '出勤降低', 'VIP', '预流失', '待复活']
  const EVALUATION_STATUSES = ['未评估', '高机会', '重点培育', '风险修复', '已过期']
  const TAB_KEY_TO_LIST: Record<string, MemberListKey> = {
    renewal: '待续课',
    decline: '出勤降低',
    vip: 'VIP',
    predrop: '预流失',
    revive: '待复活'
  }
  const TABS: {
    key: string
    label: string
    tag?: 'danger' | 'warning' | 'success' | 'info' | 'primary'
  }[] = [
    { key: 'all', label: '总览' },
    { key: 'renewal', label: '待续课', tag: 'danger' },
    { key: 'decline', label: '出勤降低', tag: 'warning' },
    { key: 'vip', label: 'VIP', tag: 'success' },
    { key: 'predrop', label: '预流失', tag: 'primary' },
    { key: 'revive', label: '待复活', tag: 'info' }
  ]

  const loading = ref(false)
  const activeTab = ref('all')
  const searchForm = ref({
    name: '',
    phone: '',
    list: '',
    consultant: '',
    venue: '',
    evaluationStatus: ''
  })
  const page = ref({ current: 1, size: 20 })
  /** 当前页数据（服务端分页） */
  const list = ref<YimaiCustomer[]>([])
  const total = ref(0)
  /** 五清单徽标计数：服务端一次扫描后返回 */
  const listCounts = ref<Record<string, number>>({})

  /**
   * 会员 id → 所属清单集合（**后端权威口径**）。
   *
   * 改造前这里是 `computeMemberLists(row)` —— `api/yimai.ts` 里的前端镜像。
   * 后端修好新口径（去 m3 门槛、逐卡尾段、90 天过期窗）后镜像没跟上，
   * 于是会员管理页的标签与「今日待办 → 待续费」、页签徽标三处对不上。
   *
   * 现在改为向服务端取 `list=` 过滤结果的 id：口径只有后端一份，
   * 前端不再算第二遍（也就不用维护镜像）。
   */
  const memberListIds = ref<Record<number, MemberListKey[]>>({})

  /**
   * 会员 id → 待续费判定理由（**后端权威口径**，前端只消费不重算）。
   *
   * 用户最初的诉求是「设置了判定因素但筛选不够精准」——只看一个「待续费」标签
   * 无法判断系统为什么这么判。后端已算出 why/bucket/degraded/coLists/primary，
   * 这里接上就能让「精准」变成用户可自行核对的事。
   *
   * 后端只返回**在待续费清单里**的会员，其余 id 取不到值（按 undefined 处理）。
   */
  const renewalReasons = ref<Record<number, RenewalReason>>({})
  // 出勤三列的口径与日期范围由后端给出（三个连续 30 天滚动窗口），
  // 避免前端再算一遍导致口径漂移
  const attendanceMonths = ref<{
    m1: string
    m2: string
    m3: string
    m1Range?: string
    m2Range?: string
    m3Range?: string
  } | null>(null)
  const attendanceRangeLabel = computed(() => {
    const m = attendanceMonths.value
    if (!m?.m3Range) return ''

    return `再前30天 ${m.m1Range} ｜ 前30天 ${m.m2Range} ｜ 近30天 ${m.m3Range}`
  })
  const rules = ref(getMemberRules())

  watch(activeTab, (tab) => {
    if (tab !== 'all') searchForm.value.list = ''
    reloadFromFirstPage()
  })

  /** 生效清单过滤：页签优先，其次下拉清单（仅总览页签展示） */
  function effectiveListFilter(): MemberListKey | undefined {
    if (activeTab.value !== 'all') return TAB_KEY_TO_LIST[activeTab.value]
    return (searchForm.value.list || undefined) as MemberListKey | undefined
  }

  async function load(keepPage = false) {
    loading.value = true
    try {
      if (!keepPage) page.value.current = 1
      await refreshMemberRules()
      rules.value = getMemberRules()
      const res = await queryCustomers({
        name: searchForm.value.name,
        phone: searchForm.value.phone,
        list: effectiveListFilter(),
        venue: searchForm.value.venue || undefined,
        consultant: searchForm.value.consultant || undefined,
        evaluationStatus: searchForm.value.evaluationStatus || undefined,
        type: 'member',
        current: page.value.current,
        size: page.value.size
      })
      list.value = res.records ?? []
      attendanceMonths.value = res.attendanceMonths ?? attendanceMonths.value
      // 判定理由随列表返回（只含待续费清单里的会员）。
      // 这里**整体替换**而不是合并：翻页/切页签后旧页的理由必须失效，
      // 否则会把上一页的解释挂到当前页的人身上。
      renewalReasons.value = res.renewalReasons ?? {}
      total.value = res.total ?? list.value.length
      if (!keepPage) {
        loadListCounts()
        loadMemberListIds()
      }
    } catch (e) {
      console.error('[members.load]', e)
      ElMessage.error('会员列表加载失败，请稍后重试')
    } finally {
      loading.value = false
    }
  }

  async function loadListCounts() {
    try {
      // 必须带 `type: 'member'`：本页页签列表走 `/customers?type=member`
      // （排除 layer=P5 的未成交客资）。徽标不带 type 就会按「全部客户」算，
      // 于是出现「徽标 4、点进去 3 行」——徽标数与行数必须同源（t16/t17）。
      listCounts.value = await queryMemberListCounts('member')
    } catch {
      /* 徽标计数失败不阻塞列表，保留上次值 */
    }
  }

  /**
   * 某清单的会员 id 集合（**直接取后端权威口径**，不在前端重算）。
   *
   * 为什么不在 `api/yimai.ts` 里加这个函数：那是「数据层」，而这里只服务于本页
   * 的标签渲染，是页面自己的取数逻辑；放页面里也让「本页标签口径 = 后端清单口径」
   * 这件事在一处看得见。`api/yimai.ts` 的 `computeMemberLists()` 是 mock 分支用的
   * 前端镜像（仍含 m3>0 门槛、无逐卡尾段、无 90 天过期窗），**本页不再调用它**。
   *
   * 只取 id（后端 size 上限 5000，超一页继续拉），不带卡片明细。
   */
  async function fetchMemberListIds(list: MemberListKey): Promise<number[]> {
    if (!USE_BACKEND) return []
    const PAGE = 5000
    const ids: number[] = []
    for (let current = 1; current <= 20; current += 1) {
      const d = await apiGet<{ records: { id: number }[] }>('/customers', {
        list,
        current,
        size: PAGE
      })
      const page = d.records ?? []
      ids.push(...page.map((r) => r.id))
      if (page.length < PAGE) break
    }

    return ids
  }

  /**
   * 拉取五清单的会员 id 归属（后端口径），供表格标签与卡片 tags 使用。
   *
   * 五个清单并行请求，任一失败只影响那一个清单的标签，不阻塞列表。
   */
  async function loadMemberListIds() {
    try {
      const results = await Promise.all(
        LIST_KEYS.map(async (key) => {
          try {
            return [key, await fetchMemberListIds(key)] as const
          } catch {
            return [key, [] as number[]] as const
          }
        })
      )
      const map: Record<number, MemberListKey[]> = {}
      for (const [key, ids] of results) {
        for (const id of ids) {
          if (!map[id]) map[id] = []
          map[id].push(key)
        }
      }
      memberListIds.value = map
    } catch {
      /* 归属失败不阻塞列表：标签退化为空，不会显示错的口径 */
    }
  }

  function reloadFromFirstPage() {
    load(false)
  }

  function handlePageChange() {
    load(true)
  }

  /** 会籍顾问下拉选项：轻量接口返回去重名单 */
  const consultantOptions = ref<string[]>([])
  async function loadConsultants() {
    try {
      consultantOptions.value = (await queryCustomerOptions()).consultants ?? []
    } catch {
      /* 静默失败，允许手输 */
    }
  }

  /**
   * 该会员所属的清单（**读后端归属，不在前端重算**）。
   *
   * 顺序按 LIST_KEYS 固定，保证同一会员每次渲染标签顺序一致。
   */
  function memberLists(row: YimaiCustomer): MemberListKey[] {
    return memberListIds.value[row.id] ?? []
  }

  // ---------- 待续费判定理由（只消费后端字段） ----------

  /** 该会员的待续费判定理由；不在待续费清单里时为 undefined */
  function reasonOf(row: YimaiCustomer): RenewalReason | undefined {
    return renewalReasons.value[row.id]
  }

  /**
   * 主标签文案。
   *
   * 后端 `primary` 的语义是「哪个标签放主位」：
   * - `待复活` —— 该会员处于待复活态，先唤醒再谈续费（D3：不隐藏、只分主次）
   * - `待续费·紧急` / `待续费·观察` —— 按紧急度
   *
   * 注意 `primary` 只在**待续费清单**的明细里给出，所以这里的兜底是「待续课」
   * （页签/清单键名），而不是 `primary` 本身。
   */
  function primaryLabel(row: YimaiCustomer): string {
    return reasonOf(row)?.primary || '待续课'
  }

  /** 主标签的语义色：待复活走 info，紧急走 danger，观察走 warning */
  function primaryType(row: YimaiCustomer): 'danger' | 'warning' | 'info' {
    const p = primaryLabel(row)
    if (p === '待复活') return 'info'
    if (p.includes('紧急')) return 'danger'
    if (p.includes('观察')) return 'warning'
    return 'danger'
  }

  /**
   * 除主标签外，该会员同时所属的其它清单。
   *
   * 真实数据里待续费与待复活重叠率 100%（诊断 C5），只渲染一个标签会让用户
   * 看不出「他同时属于两处」。D3 决策是不隐藏人，两个都显示、由用户判断主次。
   *
   * 两个坑（都是实测踩出来的）：
   * 1. `coLists` 可能**包含 primary 本身**（例：primary=「待复活」、coLists=["待复活"]），
   *    直接 v-for 会把同一个标签渲染两遍。这里按文案去重。
   * 2. `coLists` 是「除待续课外」的其它清单（后端构造时 `if ($key === '待续课') continue`），
   *    而 primary 若是「待复活」，页面上就只剩待复活一个标签，「同时属于两处」又看不见了。
   *    但此人**必然在待续费清单里**（否则后端不会返回 renewalReasons），
   *    所以主标签不是待续费档位时，补一个「待续课」把它所属的另一处显式说出来。
   */
  function secondaryLists(row: YimaiCustomer): string[] {
    const r = reasonOf(row)
    if (!r) return []
    const primary = primaryLabel(row)
    const out = (r.coLists ?? []).filter((l) => l !== primary)
    // 主标签不是待续费档位（即让给了待复活）时，补上待续课 —— 用页签同一套词汇
    if (!primary.includes('待续费')) out.push('待续课')
    return [...new Set(out)]
  }

  /** 降级说明：非空即表示有规则没能生效（如卡项快照为 NULL 时占比阈值静默失效） */
  function degradedOf(row: YimaiCustomer): string[] {
    return reasonOf(row)?.degraded ?? []
  }

  /**
   * 当前页出现过的降级说明（去重）。
   *
   * 后端是同一条降级会挂在每个受影响的人身上（例如快照为 NULL 时整页都会带
   * 「无卡项汇总快照…」）。逐行展示会变成刷屏，所以聚合成一条页面级提示；
   * 单行 popover 里仍保留各自的 degraded，便于逐个核对。
   */
  const degradedNotices = computed(() => {
    const set = new Set<string>()
    for (const row of list.value) {
      for (const d of degradedOf(row)) set.add(d)
    }
    return [...set]
  })

  function declining(row: YimaiCustomer): boolean {
    return memberLists(row).includes('出勤降低')
  }

  function listType(l: string): 'danger' | 'warning' | 'success' | 'info' | 'primary' {
    if (l === '待续课') return 'danger'
    if (l === '出勤降低') return 'warning'
    if (l === 'VIP') return 'success'
    if (l === '预流失') return 'primary'
    return 'info'
  }

  function daysAgo(date: string | null): number {
    if (!date) return 9999
    return Math.floor((Date.now() - new Date(date).getTime()) / 86400000)
  }

  function daysAgo2(date: string): number {
    return Math.floor((Date.now() - new Date(date).getTime()) / 86400000)
  }

  function touchOverdue(row: YimaiCustomer): boolean {
    if (!row.lastTouch) return true
    return daysAgo2(row.lastTouch) > 14
  }

  function actionOverdue(row: YimaiCustomer): boolean {
    if (!row.nextActionTime) return false
    return new Date(row.nextActionTime.replace(' ', 'T')).getTime() < Date.now()
  }

  function evaluationExpired(row: YimaiCustomer): boolean {
    return Boolean(row.evalAt) && daysAgo2(row.evalAt!) > 30
  }

  function evaluationColor(score: number): string {
    return score >= 70 ? 'text-green-600' : score >= 40 ? 'text-orange-500' : 'text-red-500'
  }

  function evaluationTag(score: number): 'success' | 'warning' | 'danger' {
    return score >= 70 ? 'success' : score >= 40 ? 'warning' : 'danger'
  }

  // ---------- 移动端卡片 ----------
  //
  // 卡片不是「把表格横过来」：每个 tab 只保留该清单最该看的信息（指标 ≤3 个 + 一条补充说明），
  // 其余明细留在桌面端。表格在手机上只剩左右固定列，中间数据列会被挤成零宽，所以窄屏必须换形态。

  /** 顶部标签：门店 + 顾问（空则标红「待分配」）+ 清单归属 */
  function cardTags(row: YimaiCustomer): MobileCardTag[] {
    const tags: MobileCardTag[] = [{ text: row.venue, effect: 'plain' }]

    if (row.consultant) {
      tags.push({ text: row.consultant, effect: 'plain' })
    } else {
      tags.push({ text: '待分配', type: 'danger', effect: 'plain' })
    }

    // 清单标签：与桌面表格同一套主次规则 —— 主标签用后端 primary，
    // 其余 coLists 以 plain 次要标签并列，手机上同样能看出「他同时属于两处」。
    if (reasonOf(row)) {
      tags.push({ text: primaryLabel(row), type: primaryType(row), effect: 'dark' })
      for (const l of secondaryLists(row)) {
        tags.push({ text: l, type: listType(l), effect: 'plain' })
      }
    } else {
      for (const l of memberLists(row)) {
        tags.push({ text: l, type: listType(l), effect: 'dark' })
      }
    }

    return tags
  }

  /** 关键指标：按 tab 取 1–3 个 */
  function cardMetrics(row: YimaiCustomer): MobileCardMetric[] {
    const metrics: MobileCardMetric[] = []

    if (activeTab.value !== 'vip') {
      metrics.push({
        label: '出勤（近30天）',
        value: row.attendM3 ?? 0,
        unit: '次',
        danger: declining(row)
      })
    }

    if (activeTab.value === 'all' || activeTab.value === 'renewal') {
      const remain = row.remainTimes
      metrics.push({
        label: '剩余课时',
        value: remain ?? '—',
        unit: remain == null ? '' : '节',
        danger: remain != null && remain < rules.value.renewalThreshold
      })
    }

    if (activeTab.value === 'renewal') {
      metrics.push({
        label: '续费评估',
        value:
          row.evalScore != null
            ? `${row.evalScore} · ${renewalLevelLabel(renewalLevel(row.evalScore))}`
            : '待评估',
        danger: row.evalScore != null && row.evalScore < 40
      })
    }

    if (activeTab.value === 'all' || activeTab.value === 'vip') {
      metrics.push({
        label: '累计购买',
        value: row.cardPaidAmount != null ? `¥${formatMoney(row.cardPaidAmount)}` : '—'
      })
    }

    return metrics
  }

  /** 每个 tab 专属的补充说明，等价于表格里该 tab 才出现的那一列 */
  function cardNote(row: YimaiCustomer): { text: string; label: string; danger: boolean } | null {
    if (activeTab.value === 'renewal') {
      const p = row.renewalPlan
      if (!p?.time) return { text: '待教练月度预报', label: '续课计划', danger: true }
      return {
        text: `${p.intent} · ${p.time} · ${p.amount}（诉求：${p.issue || '—'}）`,
        label: '续课计划',
        danger: false
      }
    }

    if (activeTab.value === 'decline') {
      if (!row.decline?.reason) return { text: '待管理层确认原因', label: '下降原因', danger: true }
      return {
        text: `${row.decline.reason}${row.decline.solution ? ` · ${row.decline.solution}` : ''}`,
        label: '下降原因',
        danger: false
      }
    }

    if (activeTab.value === 'predrop') {
      const days = daysAgo(row.lastVisit)
      return {
        text: `${row.stopReason || '停课原因待回访确认'} · 最近到店：${
          days === 9999 ? '无记录' : `${days}天前`
        }${row.expectedReturn ? ` · 预期复活 ${row.expectedReturn}` : ''}`,
        label: '停训情况',
        danger: false
      }
    }

    if (activeTab.value === 'revive') {
      return {
        text: `预期复活：${row.expectedReturn || '未确认'} · 最近沟通：${
          row.lastTouch ? `${row.lastTouch}（${daysAgo2(row.lastTouch)}天前）` : '从未'
        }${row.needsHelp ? ' · 需协助' : ''}`,
        label: '复活跟进',
        danger: touchOverdue(row)
      }
    }

    // 总览 / 待续课 / VIP：展示下一步动作
    if (row.nextAction) {
      return {
        text: `${row.owner || row.consultant || '待分配'} · ${row.nextActionTime || '待定时间'}`,
        label: row.nextAction,
        danger: actionOverdue(row)
      }
    }

    return activeTab.value !== 'all'
      ? { text: '待明确下一步动作', label: '下一步', danger: true }
      : null
  }

  /**
   * 卡片操作
   *
   * 组件只平铺前 2 个、其余收进「更多」，所以把与当前清单最相关的动作排在前面，
   * 保证它在手机上直接可见，不用展开菜单。
   */
  function cardActions(row: YimaiCustomer): MobileCardAction[] {
    const lists = memberLists(row)
    const actions: MobileCardAction[] = []

    if (lists.includes('待续课')) {
      actions.push({ text: '续课计划', type: 'warning', onClick: () => openRenewal(row) })
    }
    if (lists.includes('出勤降低')) {
      actions.push({ text: '下降处置', type: 'info', onClick: () => openDecline(row) })
    }
    if (lists.includes('预流失') && !row.inRevive) {
      actions.push({ text: '转待复活', type: 'success', onClick: () => toRevive(row) })
    }
    if (lists.includes('待复活')) {
      actions.push({ text: '记录沟通', type: 'primary', onClick: () => openTouch(row) })
    }

    actions.push({ text: '续费评估', type: 'primary', onClick: () => openEval(row) })
    actions.push({ text: '生日', onClick: () => openBirthday(row) })

    return actions
  }

  /** 预计算一次，避免模板里对每行重复调用四个函数 */
  const cardRows = computed(() =>
    list.value.map((row) => {
      const reason = reasonOf(row)
      return {
        id: row.id,
        title: row.name,
        subtitle: `${row.phone || `尾号${row.phoneTail}`}${row.source ? ` · ${row.source}` : ''}`,
        tags: cardTags(row),
        metrics: cardMetrics(row),
        note: cardNote(row),
        actions: cardActions(row),
        // 判定理由：手机端直接展开渲染（见模板里的 m-reason 区块）。
        // 这里预先算好 bucket 文案与语义色，避免模板里再调函数。
        reason: reason
          ? {
              why: reason.why ?? [],
              bucket: reason.bucket || primaryLabel(row),
              primaryType: primaryType(row),
              degraded: reason.degraded ?? []
            }
          : null
      }
    })
  )

  // ---------- 续课计划 ----------
  const renewalDlg = reactive({
    visible: false,
    row: null as YimaiCustomer | null,
    form: { intent: '确认续课', time: '', amount: '', course: '', issue: '' }
  })

  function openRenewal(row: YimaiCustomer) {
    renewalDlg.row = row
    renewalDlg.form = {
      intent: row.renewalPlan?.intent ?? '确认续课',
      time: row.renewalPlan?.time ?? '',
      amount: row.renewalPlan?.amount ?? '',
      course: row.renewalPlan?.course ?? '',
      issue: row.renewalPlan?.issue ?? ''
    }
    renewalDlg.visible = true
  }

  async function saveRenewal() {
    if (!renewalDlg.row) return
    try {
      await updateMemberFields(
        renewalDlg.row.id,
        { renewalPlan: { ...renewalDlg.form } },
        '续课预报'
      )
      renewalDlg.visible = false
      ElMessage.success('已保存，进入首周「先确认不销售」流程')
    } catch (e) {
      console.error('[members.saveRenewal]', e)
      ElMessage.error('保存失败，请稍后重试')
    }
  }

  // ---------- 出勤降低处置 ----------
  const declineDlg = reactive({
    visible: false,
    row: null as YimaiCustomer | null,
    form: { reason: '训练意愿降低', solution: '' }
  })

  function openDecline(row: YimaiCustomer) {
    declineDlg.row = row
    declineDlg.form = {
      reason: row.decline?.reason ?? '训练意愿降低',
      solution: row.decline?.solution ?? ''
    }
    declineDlg.visible = true
  }

  async function saveDecline() {
    if (!declineDlg.row) return
    try {
      await updateMemberFields(declineDlg.row.id, { decline: { ...declineDlg.form } }, '下降处置')
      declineDlg.visible = false
      ElMessage.success('已保存')
    } catch (e) {
      console.error('[members.saveDecline]', e)
      ElMessage.error('保存失败，请稍后重试')
    }
  }

  // ---------- 生日维护 ----------
  const birthdayDlg = reactive({
    visible: false,
    row: null as YimaiCustomer | null,
    form: { birthday: '' }
  })

  function openBirthday(row: YimaiCustomer) {
    birthdayDlg.row = row
    birthdayDlg.form.birthday = row.birthday?.slice(0, 10) ?? ''
    birthdayDlg.visible = true
  }

  async function saveBirthday() {
    if (!birthdayDlg.row) return
    try {
      await updateMemberFields(
        birthdayDlg.row.id,
        { birthday: birthdayDlg.form.birthday || null },
        '生日维护'
      )
      birthdayDlg.row.birthday = birthdayDlg.form.birthday || null
      birthdayDlg.visible = false
      ElMessage.success('已保存，工作台「今日待办 → 生日关怀」将自动提醒')
    } catch (e) {
      console.error('[members.saveBirthday]', e)
      ElMessage.error('保存失败，请稍后重试')
    }
  }

  // ---------- 待复活沟通 ----------
  const touchDlg = reactive({
    visible: false,
    row: null as YimaiCustomer | null,
    form: { expectedReturn: '', needsHelp: false }
  })

  function openTouch(row: YimaiCustomer) {
    touchDlg.row = row
    touchDlg.form = { expectedReturn: row.expectedReturn ?? '', needsHelp: row.needsHelp ?? false }
    touchDlg.visible = true
  }

  async function saveTouch() {
    if (!touchDlg.row) return
    const today = toLocalDateString(new Date())
    try {
      await updateMemberFields(
        touchDlg.row.id,
        {
          expectedReturn: touchDlg.form.expectedReturn,
          needsHelp: touchDlg.form.needsHelp,
          lastTouch: today
        },
        '复活跟进'
      )
      touchDlg.visible = false
      ElMessage.success('已记录，14天后需再次有效触达')
    } catch (e) {
      console.error('[members.saveTouch]', e)
      ElMessage.error('保存失败，请稍后重试')
    }
  }

  // ---------- 预流失转待复活 ----------
  async function toRevive(row: YimaiCustomer) {
    try {
      await updateMemberFields(
        row.id,
        { inRevive: true, lastTouch: toLocalDateString(new Date()) },
        '转待复活'
      )
      ElMessage.success(`${row.name} 已转入待复活清单`)
      load(false)
    } catch (e) {
      console.error('[members.toRevive]', e)
      ElMessage.error('操作失败，请稍后重试')
    }
  }

  // ---------- 30天评估 ----------
  const evalDlg = reactive<{
    visible: boolean
    row: YimaiCustomer | null
    loading: boolean
    saving: boolean
  }>({
    visible: false,
    row: null,
    loading: false,
    saving: false
  })
  const evalAnswers = reactive<RenewalEvaluationAnswers>({
    goal: 'none',
    feedback: 'none',
    wechat: 'no_response',
    intent: 'none',
    service: 'normal',
    risks: []
  })
  const evalContext = ref<RenewalEvaluationContext | null>(null)
  const evalRemark = ref('')

  async function openEval(row: YimaiCustomer) {
    evalDlg.row = row
    evalDlg.visible = true
    evalDlg.loading = true
    try {
      evalContext.value = await getRenewalEvaluation(row.id)
      const latest = evalContext.value.latest
      Object.assign(
        evalAnswers,
        latest?.answers ?? {
          goal: 'none',
          feedback: 'none',
          wechat: 'no_response',
          intent: 'none',
          service: 'normal',
          risks: []
        }
      )
      evalAnswers.attendanceCount = evalContext.value.attendanceCount
      evalAnswers.cardWindow = evalContext.value.cardWindow
      evalRemark.value = latest?.remark ?? ''
    } catch (error) {
      ElMessage.error(String((error as { message?: string }).message ?? error).slice(0, 120))
    } finally {
      evalDlg.loading = false
    }
  }

  const evalTotal = computed(() => evalTotalScore(evalAnswers))

  async function saveEval() {
    if (!evalDlg.row) return
    evalDlg.saving = true
    try {
      const result = await saveRenewalEvaluation(evalDlg.row.id, {
        answers: { ...evalAnswers, risks: [...evalAnswers.risks] },
        remark: evalRemark.value
      })
      evalDlg.visible = false
      ElMessage.success(
        `评估已保存，已生成任务「${result.task?.title ?? ''}」，负责人：${result.task?.owner ?? ''}`
      )
      await load(false)
    } catch (e) {
      console.error('[members.saveEval]', e)
      ElMessage.error('评估保存失败，请稍后重试')
    } finally {
      evalDlg.saving = false
    }
  }

  // ---------- 阈值 ----------
  const rulesDlg = ref(false)
  const rulesForm = reactive({ ...getMemberRules() })

  watch(rulesDlg, (v) => {
    if (v) Object.assign(rulesForm, getMemberRules())
  })

  async function applyRules() {
    try {
      await setMemberRules({ ...rulesForm })
      rules.value = getMemberRules()
      rulesDlg.value = false
      load(false)
      ElMessage.success('规则已更新，清单实时重算')
    } catch (e) {
      console.error('[members.applyRules]', e)
      ElMessage.error('规则保存失败，请稍后重试')
    }
  }

  onMounted(() => {
    loadConsultants()
    load(false)
  })
</script>

<style lang="scss" scoped>
  /*
    待续费判定理由的呈现样式。

    「判定依据」入口刻意做成小号文字链接而不是图标按钮：
    它是解释性入口、不是主操作，做成按钮会和右侧「续费评估 / 续课计划」抢注意力。
    但命中降级时变橙色并加粗 —— 那时它是用户排查问题的唯一入口，必须被看见。
  */
  .reason-trigger {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    font-size: 12px;
    line-height: 1;
    color: var(--art-gray-600, #909399);
    cursor: pointer;
    border-bottom: 1px dashed currentcolor;

    &:hover {
      color: var(--el-color-primary);
    }

    &.is-degraded {
      font-weight: 600;
      color: var(--el-color-warning);
    }

    &__text {
      white-space: nowrap;
    }
  }

  .reason-body {
    font-size: 13px;
    line-height: 1.6;

    &__head {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 8px;
    }

    &__section {
      margin: 8px 0 4px;
      font-size: 12px;
      color: var(--art-gray-500, #a8abb2);

      &--warn {
        color: var(--el-color-warning);
      }
    }

    &__list {
      margin: 0;
      padding-left: 18px;

      li {
        list-style: disc;
      }

      &--warn li::marker {
        color: var(--el-color-warning);
      }
    }
  }

  .reason-degraded-list {
    margin: 0;
    padding-left: 18px;

    li {
      list-style: disc;
    }
  }

  /* 手机卡片内的判定理由区块 */
  .m-reason {
    margin-top: 10px;
    padding-top: 10px;
    font-size: 13px;
    line-height: 1.6;
    border-top: 1px dashed var(--el-border-color-lighter);

    &__row {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 4px;
    }

    &__label {
      font-size: 12px;
      color: var(--art-gray-500, #a8abb2);

      &--warn {
        color: var(--el-color-warning);
      }
    }

    &__list {
      margin: 0;
      padding-left: 18px;

      li {
        list-style: disc;
      }

      &--warn li::marker {
        color: var(--el-color-warning);
      }
    }

    &__degraded {
      margin-top: 8px;
    }
  }
</style>
