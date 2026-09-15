<template>
  <ElDialog
    v-model="visible"
    :title="`体验课课后分析 · ${form.studentName || '新学员'}`"
    width="860px"
    top="4vh"
    destroy-on-close
    class="review-wizard"
    @closed="onClosed"
  >
    <!-- 手机上三步的标题+说明会挤成两行，改成一行进度计数 -->
    <ElSteps v-if="!isMobile" :active="step" simple class="mb-4">
      <ElStep title="课后观察" :description="stepHint(0)" />
      <ElStep title="训练方向" :description="stepHint(1)" />
      <ElStep title="当场交付" :description="stepHint(2)" />
    </ElSteps>
    <div v-else class="wizard-progress">
      <span class="wizard-progress__idx">第 {{ step + 1 }}/3 步</span>
      <span class="wizard-progress__name">{{ STEP_NAMES[step] }}</span>
      <span class="wizard-progress__hint">{{ stepHint(step) }}</span>
    </div>

    <!-- ① 课后观察 -->
    <div v-show="step === 0" class="wizard-step-body max-h-[62vh] overflow-y-auto pr-1">
      <ElAlert type="info" :closable="false" class="mb-3">
        当场填完约 3–4 分钟：先勾"快筛"（看一眼就能判断的），有把握的再展开详细项。
        讲观察不讲诊断，讲方向不承诺疗效。
      </ElAlert>

      <ElForm :label-width="isMobile ? undefined : '88px'" :label-position="formLabelPosition">
        <ElRow :gutter="12">
          <ElCol :xs="24" :sm="12">
            <ElFormItem label="学员姓名">
              <ElInput v-model="form.studentName" placeholder="学员姓名" />
            </ElFormItem>
          </ElCol>
          <ElCol :xs="24" :sm="12">
            <ElFormItem label="手机号">
              <ElInput
                v-model="form.studentPhone"
                placeholder="用于关联会员与客资"
                maxlength="11"
              />
            </ElFormItem>
          </ElCol>
          <ElCol :xs="24" :sm="12">
            <ElFormItem label="场景">
              <ElSelect v-model="form.scene" class="!w-full">
                <ElOption
                  v-for="(label, key) in catalog?.scenes ?? {}"
                  :key="key"
                  :label="label"
                  :value="key"
                />
              </ElSelect>
            </ElFormItem>
          </ElCol>
          <ElCol :xs="24" :sm="12">
            <ElFormItem label="上课时间">
              <ElDatePicker
                v-model="form.classAt"
                type="datetime"
                class="!w-full"
                value-format="YYYY-MM-DD HH:mm:ss"
                placeholder="选择上课时间"
              />
            </ElFormItem>
          </ElCol>
          <ElCol :span="24">
            <ElFormItem label="学员目标">
              <ElInput
                v-model="form.goalText"
                placeholder="学员自己说的目标，例如：想瘦一点 / 产后肚子收不回去 / 肩颈酸"
              />
            </ElFormItem>
          </ElCol>
          <ElCol :span="24">
            <ElFormItem label="学员类型">
              <ElSelect
                v-model="form.studentType"
                clearable
                placeholder="留空则由目标与观察项自动判断"
                class="!w-full"
              >
                <ElOption
                  v-for="t in catalog?.types ?? []"
                  :key="t.key"
                  :label="`${t.label}（${t.alias}）`"
                  :value="t.key"
                />
              </ElSelect>
            </ElFormItem>
          </ElCol>
        </ElRow>
      </ElForm>

      <!-- 红线：硬拦截 -->
      <!-- 体测报告：贴链接自动带出观察项 -->
      <ElCard shadow="never" class="mb-3 bodytest-card">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-500 text-[13px]">体测报告（门店智能魔镜，可选）</span>
            <ElTag v-if="bodyTest" size="small" type="success" effect="dark">
              已解析 · 异常 {{ bodyTest.abnormal.length }} 项 · 体态
              {{ bodyTest.posture.length }} 项
            </ElTag>
          </div>
        </template>
        <div class="flex flex-wrap gap-2">
          <ElInput
            v-model="bodyTestUrl"
            placeholder="粘贴体测报告链接，自动带出观察项（https://bodytest.ruleye.com/#/report-new/…）"
            class="!w-auto flex-1 min-w-[280px]"
            clearable
          />
          <ElButton :loading="parsingBodyTest" :disabled="!bodyTestUrl" @click="doParseBodyTest">
            解析报告
          </ElButton>
          <ElButton v-if="bodyTest" text type="danger" @click="clearBodyTest">清除</ElButton>
        </div>
        <div v-if="bodyTestUrl && !bodyTest" class="mt-1 text-xs text-gray-400">
          链接来自门店体测设备生成的报告，有效期以设备侧为准
        </div>

        <template v-if="bodyTest">
          <!-- 基础档案 -->
          <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
            <span>测于 {{ bodyTest.profile.testedAt }}</span>
            <span
              >体态评分 <b>{{ bodyTest.profile.score }}</b></span
            >
            <span>BMI {{ bodyTest.profile.bmi }}</span>
            <span>体脂率 {{ bodyTest.profile.bodyFatRate }}%</span>
            <span>
              年龄 {{ bodyTest.profile.age }} / 身高 {{ bodyTest.profile.height }}cm / 体重
              {{ bodyTest.profile.weight }}kg
            </span>
            <span v-if="bodyTest.directions.length" class="text-gray-500">
              自选方向：{{ bodyTest.directions.join('、') }}
            </span>
          </div>

          <!-- 异常项：值 + 标准区间 + 风险 + 建议 -->
          <div v-if="bodyTest.abnormal.length" class="mt-3">
            <div class="mb-1 text-xs text-gray-500">偏离标准的指标</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <div v-for="it in bodyTest.abnormal" :key="it.key" class="bt-item">
                <div class="flex items-baseline justify-between">
                  <span class="font-500 text-[13px]">{{ it.name }}</span>
                  <span class="text-[13px]">
                    <b class="text-danger">{{ it.value }}{{ it.unit }}</b>
                    <span v-if="it.normalRange" class="ml-1 text-xs text-gray-400">
                      标准 {{ it.normalRange[0] }}~{{ it.normalRange[1] }}
                    </span>
                  </span>
                </div>
                <ElTag size="small" type="danger" effect="plain" class="mt-1">{{
                  it.bandLabel
                }}</ElTag>
                <div v-if="it.risk" class="mt-1 text-xs leading-5 text-gray-500">{{ it.risk }}</div>
              </div>
            </div>
          </div>

          <!-- 体态 -->
          <div v-if="bodyTest.posture.length" class="mt-3">
            <div class="mb-1 text-xs text-gray-500">体态评估发现</div>
            <div class="flex flex-wrap gap-1.5">
              <ElTooltip
                v-for="p in bodyTest.posture"
                :key="p.key"
                :content="p.risk"
                placement="top"
                :show-after="200"
              >
                <ElTag size="small" type="warning" effect="plain" class="cursor-help">
                  {{ postureLabel(p.key) }}
                </ElTag>
              </ElTooltip>
            </div>
          </div>

          <!-- 自动带出的观察项 -->
          <ElAlert type="success" :closable="false" class="mt-3" show-icon>
            已按体测自动勾选
            <b>{{ bodyTest.observations.length }}</b> 个观察项（下方快筛/详细项中高亮），
            你可以按今天的实际课堂表现增删。
          </ElAlert>
          <ElAlert
            v-if="bodyTest.redFlags.length"
            type="error"
            :closable="false"
            class="mt-2"
            show-icon
          >
            体测健康问卷提示需先做专业评估（{{ bodyTest.redFlags.length }}
            项），已同步到下方红线排查。
          </ElAlert>
        </template>
      </ElCard>
      <ElCard shadow="never" class="mb-3 red-flag-card">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-500 text-[13px]">红线排查（命中则不排训练，只给专业评估建议）</span>
            <ElTag v-if="form.redFlags.length" type="danger" effect="dark" size="small">
              命中 {{ form.redFlags.length }} 项
            </ElTag>
          </div>
        </template>
        <ElCheckboxGroup v-model="form.redFlags" class="w-full">
          <div v-for="f in catalog?.redFlags ?? []" :key="f.key" class="w-full py-1">
            <ElCheckbox :value="f.key">
              <span class="text-[13px]">{{ f.label }}</span>
            </ElCheckbox>
            <span class="ml-2 text-xs text-gray-400">{{ f.hint }}</span>
          </div>
        </ElCheckboxGroup>
        <ElAlert v-if="form.redFlags.length" type="error" :closable="false" class="mt-2" show-icon>
          命中红线后不会生成对客训练方案，改为「建议进一步专业评估」的沟通卡，
          该记录也不会进入成交跟进池。老师只记录主诉与课堂表现，不做诊断、不解释影像。
        </ElAlert>
      </ElCard>

      <!-- 快筛 -->
      <ElCard shadow="never" class="mb-3">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-500 text-[13px]">快筛观察项（看一眼就能判断）</span>
            <span class="text-xs text-gray-400">已选 {{ selectedKeys.length }} 项</span>
          </div>
        </template>
        <div v-for="g in fastGroups" :key="g.group" class="mb-3 last:mb-0">
          <div class="mb-1 text-xs text-gray-500">{{ g.label }}</div>
          <div class="flex flex-wrap gap-2">
            <div
              v-for="it in g.items"
              :key="it.key"
              class="obs-chip"
              :class="{ active: !!levelOf(it.key) }"
              @click="onChipClick(it)"
            >
              <span class="text-[13px]">{{ it.label }}</span>
              <ElRadioGroup
                v-if="levelOf(it.key) && !isMobile"
                :model-value="levelOf(it.key)"
                size="small"
                class="ml-1"
                @click.stop
                @change="(v: string | number | boolean | undefined) => setLevel(it.key, String(v))"
              >
                <ElRadioButton v-for="(lv, k) in catalog?.levels ?? {}" :key="k" :value="k">
                  {{ lv }}
                </ElRadioButton>
              </ElRadioGroup>
            </div>
          </div>
        </div>

        <ElCollapse class="mt-2">
          <ElCollapseItem name="detail" title="展开详细观察项（可选，已成交会员建档时更完整）">
            <div v-for="g in detailGroups" :key="g.group" class="mb-3 last:mb-0">
              <div class="mb-1 text-xs text-gray-500">{{ g.label }}</div>
              <div class="flex flex-wrap gap-2">
                <div
                  v-for="it in g.items"
                  :key="it.key"
                  class="obs-chip"
                  :class="{ active: !!levelOf(it.key) }"
                  @click="onChipClick(it)"
                >
                  <span class="text-[13px]">{{ it.label }}</span>
                  <ElRadioGroup
                    v-if="levelOf(it.key) && !isMobile"
                    :model-value="levelOf(it.key)"
                    size="small"
                    class="ml-1"
                    @click.stop
                    @change="
                      (v: string | number | boolean | undefined) => setLevel(it.key, String(v))
                    "
                  >
                    <ElRadioButton v-for="(lv, k) in catalog?.levels ?? {}" :key="k" :value="k">
                      {{ lv }}
                    </ElRadioButton>
                  </ElRadioGroup>
                </div>
              </div>
            </div>
          </ElCollapseItem>
        </ElCollapse>
      </ElCard>

      <!-- 学员主观反馈 -->
      <ElCard shadow="never">
        <template #header
          ><span class="font-500 text-[13px]">学员主观反馈（下课当场问）</span></template
        >
        <ElForm :label-width="isMobile ? undefined : '88px'" :label-position="formLabelPosition">
          <ElFormItem label="身体感觉">
            <ElInput
              v-model="form.feedback.bodyFeel"
              placeholder="哪里轻松了 / 哪里发酸 / 哪里还紧"
            />
          </ElFormItem>
          <ElFormItem label="喜欢与否">
            <ElInput v-model="form.feedback.like" placeholder="喜欢的方向 / 不喜欢的部分" />
          </ElFormItem>
          <ElFormItem label="学员顾虑">
            <ElInput
              v-model="form.feedback.concern"
              placeholder="怕坚持不了 / 觉得贵 / 怕练壮 / 没时间"
            />
          </ElFormItem>
        </ElForm>
      </ElCard>
    </div>

    <!-- ② 训练方向（生成 + 微调） -->
    <div v-show="step === 1" class="wizard-step-body max-h-[62vh] overflow-y-auto pr-1">
      <ElAlert v-if="result?.red_flag" type="error" :closable="false" class="mb-3" show-icon>
        <template #title>命中红线，本次不生成对客训练方案</template>
        <div class="mt-1 text-[13px] leading-6">
          <div v-for="(c, i) in result.cautions" :key="i">{{ c }}</div>
        </div>
      </ElAlert>

      <template v-else-if="result">
        <div class="mb-3 flex flex-wrap items-center gap-2">
          <ElTag type="success" effect="dark" size="small">
            学员类型：{{ result.student_type || '未判断' }}
          </ElTag>
          <ElTag size="small" effect="plain">{{ result.plan?.frequency.text }}</ElTag>
          <ElTag
            v-for="c in result.plan?.courses ?? []"
            :key="c"
            size="small"
            effect="plain"
            type="info"
          >
            {{ c }}
          </ElTag>
          <div class="flex-1" />
          <ElButton size="small" :loading="generating" @click="generate">重新生成</ElButton>
        </div>

        <ElCard shadow="never" class="mb-3">
          <template #header>
            <span class="font-500 text-[13px]"
              >三阶段训练方向（已按本次观察项个体化，可直接改）</span
            >
          </template>
          <div v-for="ph in editablePhases" :key="ph.key" class="mb-3 last:mb-0">
            <div class="flex items-baseline justify-between flex-wrap gap-1">
              <span class="font-500 text-[13px]">{{ ph.name }}</span>
              <span class="text-xs text-gray-400">{{ ph.duration }} · {{ ph.durationTimes }}</span>
            </div>
            <ElInput v-model="ph.goal" type="textarea" :autosize="{ minRows: 2 }" class="mt-1" />
            <div v-if="ph.focus.length" class="mt-1.5">
              <div class="text-xs text-gray-500 mb-1">本次针对性重点</div>
              <div class="flex flex-wrap gap-1.5">
                <ElTag
                  v-for="(f, fi) in ph.focus"
                  :key="fi"
                  size="small"
                  type="warning"
                  effect="plain"
                >
                  {{ f }}
                </ElTag>
              </div>
            </div>
            <div v-if="ph.courses.length" class="mt-1 text-xs text-gray-500">
              建议课程：{{ ph.courses.join(' / ') }}
            </div>
          </div>
        </ElCard>

        <ElCard shadow="never" class="mb-3">
          <template #header
            ><span class="font-500 text-[13px]">对客要说的话（提词卡 · 照着讲）</span></template
          >
          <div class="script-item">
            <span class="script-idx">①</span>
            <div class="flex-1">
              <div class="text-xs text-gray-500 mb-1">今天练了什么</div>
              <ElInput v-model="script.whatPracticed" type="textarea" :autosize="{ minRows: 2 }" />
            </div>
          </div>
          <div class="script-item">
            <span class="script-idx">②</span>
            <div class="flex-1">
              <div class="text-xs text-gray-500 mb-1"
                >你今天最明显的进步（最有杀伤力的一句，改到具体）</div
              >
              <ElInput v-model="script.progress" type="textarea" :autosize="{ minRows: 2 }" />
            </div>
          </div>
          <div class="script-item">
            <span class="script-idx">③</span>
            <div class="flex-1">
              <div class="text-xs text-gray-500 mb-1">下节课我们要推进什么</div>
              <ElInput v-model="script.nextClass" type="textarea" :autosize="{ minRows: 2 }" />
            </div>
          </div>
          <div class="script-item">
            <span class="script-idx">✓</span>
            <div class="flex-1">
              <div class="text-xs text-gray-500 mb-1">一句回家提醒</div>
              <ElInput v-model="script.reminder" />
            </div>
          </div>
        </ElCard>

        <ElCard shadow="never">
          <template #header
            ><span class="font-500 text-[13px]">顾问衔接（只填方向，不写价格）</span></template
          >
          <ElForm :label-width="isMobile ? undefined : '110px'" :label-position="formLabelPosition">
            <ElFormItem label="建议卡项方向">
              <ElSelect v-model="handoff.cardDirection" class="!w-full" clearable>
                <ElOption
                  v-for="c in catalog?.cardDirections ?? []"
                  :key="c"
                  :label="c"
                  :value="c"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem label="建议每周次数">
              <ElInput v-model="handoff.weeklyTimes" />
            </ElFormItem>
            <ElFormItem label="顾问要同步的重点">
              <ElInput v-model="handoff.focus" type="textarea" :autosize="{ minRows: 2 }" />
            </ElFormItem>
            <ElFormItem label="需要顾问跟进">
              <ElSwitch v-model="handoff.needConsultant" />
            </ElFormItem>
          </ElForm>
        </ElCard>
      </template>

      <ElEmpty v-else description="请先在第一步勾选观察项" :image-size="80" />
    </div>

    <!-- ③ 当场交付 -->
    <div v-show="step === 2" class="wizard-step-body max-h-[62vh] overflow-y-auto pr-1">
      <template v-if="savedId">
        <ElResult
          v-if="result?.red_flag"
          icon="warning"
          title="已记录，建议进一步专业评估"
          sub-title="本次不产出对客训练方案，也不会进入成交跟进池"
        >
          <template #extra>
            <ElButton type="primary" @click="$router.push('/yimai/post-class')">返回列表</ElButton>
          </template>
        </ElResult>
        <template v-else>
          <ElAlert type="success" :closable="false" class="mb-3" show-icon>
            训练方向已确认。把下面这段话讲给学员听，然后让他扫码带走。
          </ElAlert>

          <ElCard shadow="never" class="mb-3">
            <template #header><span class="font-500 text-[13px]">照读提词卡</span></template>
            <div class="prompt-line">① {{ script.whatPracticed }}</div>
            <div class="prompt-line highlight">② {{ script.progress }}</div>
            <div class="prompt-line">③ {{ script.nextClass }}</div>
            <div class="prompt-line muted">✓ {{ script.reminder }}</div>
          </ElCard>

          <ElCard shadow="never" class="mb-3">
            <template #header>
              <div class="flex items-center justify-between">
                <span class="font-500 text-[13px]">学员带走的东西（对客页）</span>
                <div class="flex gap-2">
                  <ElButton size="small" @click="copyLink">复制链接</ElButton>
                  <ElButton size="small" type="primary" @click="openShare">打开对客页</ElButton>
                </div>
              </div>
            </template>
            <div class="text-xs text-gray-500 break-all">{{ shareUrl }}</div>
          </ElCard>

          <ElCard shadow="never">
            <template #header><span class="font-500 text-[13px]">成交衔接三件事</span></template>
            <ol class="checklist">
              <li>当场约下一次课时间，不要留「你想好了找我」</li>
              <li>把「建议卡项方向」抄给顾问 / 前台 —— 老师不报价</li>
              <li
                >当天晚上发一条课后反馈（按四步结构：练了什么 / 一个具体进步 / 一个回家提醒 /
                下节课推进什么）</li
              >
            </ol>
          </ElCard>
        </template>
      </template>

      <ElEmpty v-else description="请先完成第二步的训练方向确认" :image-size="80" />
    </div>

    <template #footer>
      <div class="flex items-center justify-between">
        <div>
          <ElButton v-if="step > 0" @click="step -= 1">上一步</ElButton>
        </div>
        <div class="flex gap-2">
          <ElButton @click="visible = false">取消</ElButton>
          <ElButton
            v-if="step === 0"
            type="primary"
            :loading="generating"
            :disabled="!form.studentName || !selectedKeys.length"
            @click="nextFromObserve"
          >
            生成训练方向
          </ElButton>
          <ElButton v-else-if="step === 1" type="primary" :loading="saving" @click="saveAndConfirm">
            保存并确认
          </ElButton>
          <ElButton v-else type="primary" @click="visible = false">完成</ElButton>
        </div>
      </div>
    </template>
  </ElDialog>

  <!-- 手机端：观察项等级选择（轻/中/重） -->
  <ElDrawer
    v-model="levelSheet.visible"
    direction="btt"
    size="auto"
    :with-header="false"
    class="wizard-level-sheet"
  >
    <div class="level-sheet">
      <div class="level-sheet__title">{{ levelSheet.label }}</div>
      <button
        v-for="(lv, k) in catalog?.levels ?? {}"
        :key="k"
        type="button"
        class="level-sheet__item"
        :class="{ 'is-active': levelOf(levelSheet.key) === k }"
        @click="pickLevel(String(k))"
      >
        {{ lv }}
      </button>
      <button
        v-if="levelOf(levelSheet.key)"
        type="button"
        class="level-sheet__item level-sheet__item--danger"
        @click="clearLevel()"
      >
        取消选择该项
      </button>
      <button
        type="button"
        class="level-sheet__item level-sheet__item--plain"
        @click="levelSheet.visible = false"
      >
        关闭
      </button>
    </div>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useDevice } from '@/hooks/core/useDevice'
  import {
    confirmPostClassReview,
    createPostClassReview,
    getPostClassCatalog,
    previewPostClassPlan,
    updatePostClassReview
  } from '@/api/yimai'
  import { parseBodyTestReport } from '@/api/yimai'
  import type {
    BodyTestAnalysis,
    PostClassCatalog,
    PostClassCatalogGroup,
    PostClassCandidate,
    PostClassPlanResult
  } from '@/api/yimai'

  defineOptions({ name: 'PostClassReviewWizard' })

  const props = defineProps<{ modelValue: boolean; candidate?: PostClassCandidate | null }>()
  const emit = defineEmits<{
    (e: 'update:modelValue', v: boolean): void
    (e: 'saved'): void
  }>()

  const visible = computed({
    get: () => props.modelValue,
    set: (v: boolean) => emit('update:modelValue', v)
  })

  // ---------- 移动端适配 ----------
  //
  // 老师是下了课当场站在场边用手机填的，所以手机端的所有呈现都按「单手 + 站着」来定：
  // 标签置顶、去掉嵌套滚动、等级选择改成 44px 的底部面板。

  const { isMobile } = useDevice()

  /** 手机上表单标签置顶：88px 的左侧标签会占掉可用宽度的近四分之一 */
  const formLabelPosition = computed(() => (isMobile.value ? 'top' : 'left'))

  const STEP_NAMES = ['课后观察', '训练方向', '当场交付']

  /** 手机端的等级选择面板 */
  const levelSheet = reactive({ visible: false, key: '', label: '' })

  const catalog = ref<PostClassCatalog | null>(null)
  const step = ref(0)
  const generating = ref(false)
  const saving = ref(false)
  const result = ref<PostClassPlanResult | null>(null)
  const savedId = ref<number | null>(null)
  const bodyTestUrl = ref('')
  const bodyTest = ref<BodyTestAnalysis | null>(null)
  const parsingBodyTest = ref(false)

  const form = reactive({
    scene: 'trial',
    studentName: '',
    studentPhone: '',
    classAt: '' as string | null,
    courseName: '',
    venue: '',
    studentType: '',
    goalText: '',
    observations: [] as { key: string; level: string }[],
    redFlags: [] as string[],
    feedback: { bodyFeel: '', like: '', concern: '' },
    issues: [] as { text: string; basis: string }[],
    leadId: null as number | null,
    customerId: null as number | null,
    bookingId: null as number | null
  })

  const script = reactive({ whatPracticed: '', progress: '', nextClass: '', reminder: '' })
  const handoff = reactive({
    cardDirection: '',
    weeklyTimes: '',
    focus: '',
    needConsultant: true,
    blocked: false
  })
  const editablePhases = ref<
    {
      key: string
      name: string
      duration: string
      durationTimes: string
      goal: string
      focus: string[]
      courses: string[]
    }[]
  >([])

  const selectedKeys = computed(() => form.observations.map((o) => o.key))
  const fastGroups = computed<PostClassCatalogGroup[]>(() =>
    (catalog.value?.groups ?? [])
      .map((g) => ({ ...g, items: g.items.filter((i) => i.fast) }))
      .filter((g) => g.items.length > 0)
  )
  const detailGroups = computed<PostClassCatalogGroup[]>(() =>
    (catalog.value?.groups ?? [])
      .map((g) => ({ ...g, items: g.items.filter((i) => !i.fast) }))
      .filter((g) => g.items.length > 0)
  )

  // 应用用 hash 路由：分享路径必须写在 # 后面，否则会被当成未匹配路径跳到登录页
  // （训练计划的分享链接也是同样写法：`${origin}${pathname}#/s/plan/${code}`）
  const shareUrl = computed(() =>
    savedId.value && result.value && !result.value.red_flag && shareCode.value
      ? `${window.location.origin}${window.location.pathname}#/s/post-class/${shareCode.value}`
      : ''
  )
  const shareCode = ref('')

  function stepHint(i: number): string {
    if (i === 0)
      return selectedKeys.value.length ? `已选 ${selectedKeys.value.length} 项` : '勾选观察项'
    if (i === 1)
      return result.value ? (result.value.red_flag ? '命中红线' : '微调后确认') : '生成方向'
    return savedId.value ? '已生成分享' : '待确认'
  }

  /** 体态项 key → 中文名（报告没给 flag_name，这里补一份展示用的） */
  const POSTURE_LABELS: Record<string, string> = {
    shoulder_slope: '双肩不等高',
    spine_lateral: '脊柱侧弯倾向',
    pelvis_rolling: '骨盆旋转',
    x_leg: '膝盖内扣',
    o_leg: 'O 型腿',
    highlow_pelvis: '骨盆高低',
    longshort_leg: '长短腿',
    lupper_limbs: '上肢紧张',
    truncal_bones: '躯干骨位',
    lower_limbs: '下肢力线',
    spine_restriction: '脊柱侧曲受限',
    mascular_tension: '肩颈紧张·呼吸浅表',
    trunk_muscle: '躯干肌张力不平衡',
    muscle_trunk_rigid: '胸椎活动受限',
    vertebra_flexible: '脊柱弹性不足',
    hip_flexion: '髋屈受限',
    dorsal_abdominal: '腰腹核心弱',
    leg_power: '腿臀力量不足'
  }
  function postureLabel(key: string): string {
    return POSTURE_LABELS[key] ?? key
  }

  /**
   * 解析体测报告：把体态与体成分映射出的观察项并进已选观察项，
   * 报告里的健康提示并进红线。老师再按课堂表现增删即可。
   */
  async function doParseBodyTest() {
    if (!bodyTestUrl.value.trim()) return
    parsingBodyTest.value = true
    try {
      const r = await parseBodyTestReport(bodyTestUrl.value.trim())
      bodyTest.value = r
      if (!form.studentName && r.profile.nickName) form.studentName = r.profile.nickName
      mergeObservations(r.observations)
      const flags = r.redFlags.filter((f) => !form.redFlags.includes(f))
      form.redFlags.push(...flags)
      ElMessage.success(
        `已解析：带出 ${r.observations.length} 个观察项${flags.length ? `，${flags.length} 项健康提示已并入红线` : ''}`
      )
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 140))
    } finally {
      parsingBodyTest.value = false
    }
  }

  /** 合并观察项：已存在的不覆盖老师已调过的等级 */
  function mergeObservations(keys: string[]) {
    keys.forEach((k) => {
      if (!form.observations.some((o) => o.key === k)) {
        form.observations.push({ key: k, level: '中' })
      }
    })
  }

  function clearBodyTest() {
    bodyTestUrl.value = ''
    bodyTest.value = null
  }

  function levelOf(key: string): string {
    return form.observations.find((o) => o.key === key)?.level ?? ''
  }

  function toggleObservation(key: string) {
    const idx = form.observations.findIndex((o) => o.key === key)
    if (idx >= 0) {
      form.observations.splice(idx, 1)
    } else {
      form.observations.push({ key, level: '中' })
    }
  }

  /**
   * 点击观察项标签
   *
   * 桌面端：标签内直接展开 轻/中/重，点一下即选好。
   * 手机端：展开后的三个按钮只有 24px 高（低于 44px 触控标准），所以改为弹底部面板；
   * 「取消选择」也一并放进面板 —— 否则选中之后没有地方可以取消。
   */
  function onChipClick(it: { key: string; label: string }) {
    if (!isMobile.value) {
      toggleObservation(it.key)
      return
    }
    if (!levelOf(it.key)) toggleObservation(it.key)
    levelSheet.key = it.key
    levelSheet.label = it.label
    levelSheet.visible = true
  }

  function pickLevel(level: string) {
    setLevel(levelSheet.key, level)
    levelSheet.visible = false
  }

  function clearLevel() {
    toggleObservation(levelSheet.key)
    levelSheet.visible = false
  }

  function setLevel(key: string, level: string) {
    const item = form.observations.find((o) => o.key === key)
    if (item) item.level = level
  }

  async function loadCatalog() {
    if (catalog.value) return
    try {
      catalog.value = await getPostClassCatalog()
    } catch (e) {
      ElMessage.error(
        String((e as { message?: string }).message ?? e).slice(0, 120) || '规则库加载失败'
      )
    }
  }

  function resetFromCandidate(c?: PostClassCandidate | null) {
    step.value = 0
    result.value = null
    savedId.value = null
    shareCode.value = ''
    form.scene = c?.scene === 'private' ? 'private' : 'trial'
    form.studentName = c?.studentName ?? ''
    form.studentPhone = c?.phone ?? ''
    form.classAt = c?.classAt ?? null
    form.courseName = c?.courseName ?? ''
    form.venue = c?.venue ?? ''
    form.studentType = ''
    form.goalText = ''
    form.observations = []
    form.redFlags = []
    bodyTestUrl.value = ''
    bodyTest.value = null
    form.feedback = { bodyFeel: '', like: '', concern: '' }
    form.issues = []
    form.leadId = c?.leadId ?? null
    form.customerId = c?.customerId ?? null
    form.bookingId = c?.bookingId ?? null
    editablePhases.value = []
    Object.assign(script, { whatPracticed: '', progress: '', nextClass: '', reminder: '' })
    Object.assign(handoff, {
      cardDirection: '',
      weeklyTimes: '',
      focus: '',
      needConsultant: true,
      blocked: false
    })
  }

  watch(
    () => props.modelValue,
    async (open) => {
      if (!open) return
      await loadCatalog()
      resetFromCandidate(props.candidate ?? null)
    }
  )

  function payload() {
    return {
      ...form,
      classAt: form.classAt ?? '',
      observations: form.observations,
      redFlags: form.redFlags,
      leadId: form.leadId,
      customerId: form.customerId,
      bookingId: form.bookingId,
      phases: editablePhases.value.map((p) => ({ key: p.key, goal: p.goal })),
      bodyTestReportId: bodyTest.value?.reportId ?? null,
      script: { ...script },
      handoff: { ...handoff, cardDirection: handoff.cardDirection }
    }
  }

  async function generate() {
    generating.value = true
    try {
      const r = await previewPostClassPlan({
        studentType: form.studentType,
        observations: form.observations,
        redFlags: form.redFlags,
        goalText: form.goalText
      })
      result.value = r
      Object.assign(script, r.script)
      Object.assign(handoff, r.handoff)
      editablePhases.value = (r.plan?.phases ?? []).map((p) => ({
        key: p.key,
        name: p.name,
        duration: p.duration,
        durationTimes: p.durationTimes,
        goal: p.goal,
        focus: p.focus,
        courses: p.courses
      }))
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      generating.value = false
    }
  }

  async function nextFromObserve() {
    await generate()
    step.value = 1
  }

  async function saveAndConfirm() {
    if (!form.studentName) return ElMessage.warning('请填写学员姓名')
    if (result.value?.red_flag) {
      try {
        await ElMessageBox.confirm(
          '本次命中红线：不会生成对客训练方案，改为「建议进一步专业评估」的沟通卡，该记录也不进入成交跟进池。确认保存？',
          '红线确认',
          { type: 'warning', confirmButtonText: '确认保存' }
        )
      } catch {
        return
      }
    }
    saving.value = true
    try {
      const body = payload()
      // 老师微调过的阶段文案要覆盖回生成结果
      const id = savedId.value
      if (id) {
        await updatePostClassReview(id, body)
      } else {
        const created = await createPostClassReview(body)
        savedId.value = created.id
      }
      const conf = await confirmPostClassReview(savedId.value as number)
      shareCode.value = conf.shareCode
      ElMessage.success(result.value?.red_flag ? '已保存' : '已确认，可让学员扫码带走')
      step.value = 2
      emit('saved')
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      saving.value = false
    }
  }

  function copyLink() {
    navigator.clipboard
      ?.writeText(shareUrl.value)
      .then(() => ElMessage.success('链接已复制'))
      .catch(() => ElMessage.warning('复制失败，请手动选择链接'))
  }

  function openShare() {
    window.open(shareUrl.value, '_blank')
  }

  function onClosed() {
    emit('update:modelValue', false)
  }
</script>

<style scoped lang="scss">
  // 手机端进度条：替代三步 ElSteps（三步的标题+说明在窄屏会挤成两行）
  .wizard-progress {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 8px;
    align-items: baseline;
    padding-bottom: 12px;
    margin-bottom: 12px;
    border-bottom: 1px solid var(--el-border-color-lighter);

    &__idx {
      padding: 2px 8px;
      font-size: 12px;
      color: var(--el-color-primary);
      background: var(--el-color-primary-light-9);
      border-radius: 10px;
    }

    &__name {
      font-size: 15px;
      font-weight: 600;
    }

    &__hint {
      font-size: 12px;
      color: var(--el-text-color-secondary);
    }
  }

  @include mobile-only {
    // 去掉步骤内的嵌套滚动：手机上它只有 523px 高（内容 1819px），
    // 且键盘弹出时会和它抢空间。改为让弹窗 body 整体滚动。
    .wizard-step-body {
      max-height: none;
      overflow: visible;
    }

    // 标签内的等级按钮在手机上已换成底部面板，标签只需要能点中
    .obs-chip {
      min-height: $touch-target-min;
      padding: 0 14px;
    }
  }

  // 底部等级选择面板
  .level-sheet {
    padding: 4px 0 calc(4px + env(safe-area-inset-bottom, 0px));

    &__title {
      padding: 0 4px 10px;
      font-size: 14px;
      font-weight: 600;
      color: var(--art-gray-900);
    }

    &__item {
      display: block;
      width: 100%;
      min-height: $touch-target-min;
      margin-bottom: 8px;
      font-size: 16px;
      color: var(--art-gray-800);
      background: var(--el-fill-color-light);
      border: 1px solid transparent;
      border-radius: 10px;

      &.is-active {
        font-weight: 600;
        color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
        border-color: var(--el-color-primary);
      }

      &--danger {
        color: var(--el-color-danger);
      }

      &--plain {
        color: var(--art-gray-600);
        background: transparent;
        border-color: var(--el-border-color);
      }
    }
  }

  .obs-chip {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border: 1px solid var(--el-border-color);
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.15s;
    background: var(--el-fill-color-blank);

    &:hover {
      border-color: var(--el-color-primary);
    }

    &.active {
      border-color: var(--el-color-primary);
      background: var(--el-color-primary-light-9);
    }
  }

  .bodytest-card {
    border-color: var(--el-color-success-light-5);
  }

  .bt-item {
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--el-fill-color-light);
  }

  .red-flag-card {
    border-color: var(--el-color-danger-light-5);
  }

  .script-item {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 8px 0;
    border-bottom: 1px dashed var(--el-border-color-lighter);

    &:last-child {
      border-bottom: none;
    }
  }

  .script-idx {
    flex: none;
    width: 22px;
    height: 22px;
    line-height: 22px;
    text-align: center;
    border-radius: 50%;
    background: var(--el-color-primary-light-9);
    color: var(--el-color-primary);
    font-size: 12px;
  }

  .prompt-line {
    font-size: 15px;
    line-height: 1.9;
    padding: 6px 0;

    &.highlight {
      font-size: 16px;
      font-weight: 600;
      color: var(--el-color-primary);
    }

    &.muted {
      color: var(--el-text-color-secondary);
      font-size: 14px;
    }
  }

  .checklist {
    margin: 0;
    padding-left: 18px;
    font-size: 13px;
    line-height: 2;
    color: var(--el-text-color-regular);
  }
</style>
