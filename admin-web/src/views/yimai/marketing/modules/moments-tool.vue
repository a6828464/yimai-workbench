<template>
  <div>
    <!-- 个人发圈人设（保存到账号，多设备共用） -->
    <ElCard shadow="never" class="mb-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">个人发圈人设</span>
          <div class="flex-c gap-3">
            <span class="text-xs text-gray-400">品牌固定「一麦」· 城市固定「宁波」</span>
            <ElButton
              size="small"
              type="primary"
              plain
              :loading="savingPersona"
              @click="savePersona"
              >保存人设</ElButton
            >
          </div>
        </div>
      </template>
      <div class="flex items-center flex-wrap gap-x-4 gap-y-1 mb-1 text-xs text-g-500">
        <span
          >姓名/性别/年龄取自个人中心：<b class="text-g-800">{{ my.name || '未设置' }}</b
          >{{ my.gender ? ` · ${my.gender}` : '' }}{{ my.age ? ` · ${my.age}岁` : '' }}</span
        >
        <ElButton link type="primary" size="small" @click="$router.push('/yimai/profile')"
          >去个人中心修改</ElButton
        >
      </div>
      <ElRow :gutter="12">
        <ElCol :xs="12" :sm="8" :lg="5">
          <ElFormItem label="身份角色">
            <ElSelect v-model="persona.role">
              <ElOption v-for="r in PERSONA_ROLES" :key="r" :label="r" :value="r" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :sm="8" :lg="4">
          <ElFormItem label="从业年限"
            ><ElInput v-model="persona.years" placeholder="如：5年"
          /></ElFormItem>
        </ElCol>
        <ElCol :xs="24" :lg="12">
          <ElFormItem label="专业">
            <ElSelect
              v-model="persona.specialties"
              multiple
              filterable
              allow-create
              default-first-option
              collapse-tags
              :max-collapse-tags="6"
              class="w-full"
              placeholder="普拉提/瑜伽/康复理疗…可多选、可自定义"
            >
              <ElOption v-for="s in SPECIALTY_OPTIONS" :key="s" :label="s" :value="s" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :span="24">
          <ElFormItem label="客户画像">
            <ElSelect
              v-model="persona.audiences"
              multiple
              filterable
              allow-create
              default-first-option
              collapse-tags
              :max-collapse-tags="6"
              class="w-full"
              placeholder="选择你的典型客户画像（可多选、可自定义添加）"
            >
              <ElOption v-for="a in AUDIENCE_OPTIONS" :key="a" :label="a" :value="a" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
      </ElRow>
    </ElCard>

    <!-- 本条设置 -->
    <ElCard shadow="never" class="mb-4">
      <template #header><span class="font-500">本条朋友圈设置</span></template>
      <ElRow :gutter="12">
        <ElCol :xs="24" :md="6"
          ><ElFormItem label="灵感分类"
            ><ElSelect v-model="category" @change="topic = ''"
              ><ElOption
                v-for="(v, k) in MOMENTS_CATEGORIES"
                :key="k"
                :label="k"
                :value="k" /></ElSelect></ElFormItem
        ></ElCol>
        <ElCol :xs="24" :md="8"
          ><ElFormItem label="主题模板"
            ><ElSelect v-model="topic" filterable allow-create
              ><ElOption
                v-for="t in topics"
                :key="t"
                :label="t"
                :value="t" /></ElSelect></ElFormItem
        ></ElCol>
        <ElCol :xs="12" :md="5"
          ><ElFormItem label="语气"
            ><ElSelect v-model="tone"
              ><ElOption
                v-for="v in MOMENT_TONES"
                :key="v"
                :label="v"
                :value="v" /></ElSelect></ElFormItem
        ></ElCol>
        <ElCol :xs="12" :md="5"
          ><ElFormItem label="目标"
            ><ElSelect v-model="goal"
              ><ElOption
                v-for="v in MOMENT_GOALS"
                :key="v"
                :label="v"
                :value="v" /></ElSelect></ElFormItem
        ></ElCol>
        <ElCol :xs="12" :md="5"
          ><ElFormItem label="表达方式"
            ><ElSelect v-model="expression"
              ><ElOption
                v-for="v in MOMENT_EXPRESSIONS"
                :key="v"
                :label="v"
                :value="v" /></ElSelect></ElFormItem
        ></ElCol>
        <ElCol :xs="12" :md="6"
          ><ElFormItem label="内容长度"
            ><ElSelect v-model="lengthLabel"
              ><ElOption
                v-for="v in MOMENT_LENGTHS"
                :key="v.label"
                :label="v.label"
                :value="v.label" /></ElSelect></ElFormItem
        ></ElCol>
        <ElCol :xs="12" :md="4"
          ><ElFormItem label="多分行"><ElSwitch v-model="multiLine" /></ElFormItem
        ></ElCol>
        <ElCol :xs="12" :md="9"
          ><ElFormItem label="表情符号"
            ><ElSelect v-model="emojiLevel"
              ><ElOption
                v-for="v in EMOJI_LEVELS"
                :key="v"
                :label="v"
                :value="v" /></ElSelect></ElFormItem
        ></ElCol>
      </ElRow>
      <ElFormItem label="我想说">
        <ElInput
          v-model="userInput"
          type="textarea"
          :rows="3"
          placeholder="我想说什么 / 我想表达什么（越具体，生成越贴近）"
        />
      </ElFormItem>
      <div class="flex gap-2">
        <ElButton type="primary" :loading="generating" @click="generate">生成朋友圈</ElButton>
        <ElButton @click="openHistory">历史生成记录</ElButton>
        <ElButton @click="favVisible = true">朋友圈库（{{ favorites.length }}）</ElButton>
      </div>
    </ElCard>

    <!-- 生成结果：朋友圈预览卡片 -->
    <ElCard v-if="result || warning || generating" shadow="never" class="mb-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">生成结果</span>
          <div class="flex-c gap-3">
            <ElButton
              v-if="result"
              link
              size="small"
              :type="editMode ? 'primary' : 'info'"
              @click="editMode = !editMode"
            >
              {{ editMode ? '预览' : '编辑' }}
            </ElButton>
            <ElTag size="small" :type="source === 'llm' ? 'success' : 'warning'">{{
              source === 'llm' ? '大模型生成' : '本地模板草稿'
            }}</ElTag>
          </div>
        </div>
      </template>
      <ElAlert
        v-if="warning"
        :title="warning"
        type="warning"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <div v-loading="generating">
        <!-- 编辑模式 -->
        <template v-if="editMode">
          <ElInput v-model="result" type="textarea" :rows="8" resize="none" class="mb-3" />
          <div class="text-xs text-gray-400 mb-1">首条回复建议（发布后自己评论）</div>
          <ElInput v-model="reply" type="textarea" :rows="3" resize="none" />
        </template>
        <!-- 朋友圈样式预览 -->
        <template v-else>
          <div
            class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-[#0d0d0d]"
          >
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg overflow-hidden shrink-0 bg-theme/10 flex-c">
                <img
                  v-if="my.avatar"
                  :src="my.avatar"
                  class="w-full h-full object-cover"
                  alt="avatar"
                />
                <i v-else class="ri-user-smile-line text-xl text-theme" />
              </div>
              <div>
                <div class="text-sm font-600 text-g-900">{{ my.name || persona.role || '我' }}</div>
                <div class="text-xs text-g-500 mt-0.5">刚刚 · 来自一麦</div>
              </div>
            </div>
            <p class="mt-3 text-[15px] leading-[1.8] text-g-800 whitespace-pre-wrap break-all">{{
              result
            }}</p>
            <div class="mt-3 flex items-center gap-4 text-xs text-g-500">
              <span class="flex-c gap-1"><i class="ri-heart-line" /> 赞</span>
              <span class="flex-c gap-1"><i class="ri-chat-1-line" /> 评论</span>
            </div>
          </div>
          <div
            v-if="reply"
            class="mt-3 rounded-lg border border-dashed border-gray-300 dark:border-gray-600 p-3"
          >
            <div class="flex-cb mb-1">
              <span class="text-xs font-500 text-g-600"
                ><i class="ri-chat-3-line mr-1" />评论区首条回复建议</span
              >
              <ElButton link type="primary" size="small" @click="copyText(reply)"
                >复制回复</ElButton
              >
            </div>
            <p class="text-sm leading-6 text-g-700 whitespace-pre-wrap">{{ reply }}</p>
          </div>
        </template>
        <div class="mt-3 flex gap-2">
          <ElButton type="primary" plain @click="copyAll">复制全文</ElButton>
          <ElButton plain @click="saveFavorite">收藏到朋友圈库</ElButton>
          <ElButton text @click="generate()">重新生成</ElButton>
        </div>
      </div>
    </ElCard>

    <!-- 朋友圈库（收藏） -->
    <ElDrawer v-model="favVisible" title="朋友圈库" size="440px">
      <div v-if="favorites.length">
        <div
          v-for="f in favorites"
          :key="f.id"
          class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700"
        >
          <div class="flex-cb mb-1">
            <span class="text-xs text-gray-400">{{ f.createdAt }} · {{ f.title }}</span>
            <ElIcon class="cursor-pointer" color="#f56c6c" @click="removeFavorite(f.id)"
              ><Delete
            /></ElIcon>
          </div>
          <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-4">{{ f.content }}</div>
          <div class="mt-2 flex gap-2">
            <ElButton link type="primary" size="small" @click="loadFavorite(f)">载入编辑</ElButton>
            <ElButton link size="small" @click="copyText(f.content)">复制</ElButton>
          </div>
        </div>
      </div>
      <ElEmpty v-else description="收藏的文案会出现在这里，可反复复用" />
    </ElDrawer>

    <!-- 历史生成记录（按账号云端保存，最近300条） -->
    <ElDrawer v-model="historyVisible" title="历史生成朋友圈" size="460px">
      <div v-loading="historyLoading">
        <div v-if="history.length">
          <div
            v-for="h in history"
            :key="h.id"
            class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700"
          >
            <div class="flex-cb mb-1">
              <span class="text-xs text-gray-400">{{ h.createdAt }} · {{ h.title }}</span>
              <ElIcon
                class="cursor-pointer shrink-0 ml-2"
                color="#f56c6c"
                @click="removeHistory(h.id)"
                ><Delete
              /></ElIcon>
            </div>
            <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-4">{{ h.content }}</div>
            <div v-if="h.reply" class="mt-1.5 text-xs text-g-500 leading-5 line-clamp-2"
              >💬 {{ h.reply }}</div
            >
            <div class="mt-2 flex gap-2">
              <ElButton link type="primary" size="small" @click="loadHistory(h)">载入编辑</ElButton>
              <ElButton link size="small" @click="copyText(h.content)">复制文案</ElButton>
              <ElButton v-if="h.reply" link size="small" @click="copyText(h.reply)"
                >复制首评</ElButton
              >
            </div>
          </div>
        </div>
        <ElEmpty v-else-if="!historyLoading" description="每次生成的文案都会自动记录在这里" />
      </div>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import {
    MOMENTS_CATEGORIES,
    MOMENT_TONES,
    MOMENT_GOALS,
    MOMENT_EXPRESSIONS,
    MOMENT_LENGTHS,
    EMOJI_LEVELS,
    PERSONA_ROLES,
    SPECIALTY_OPTIONS,
    AUDIENCE_OPTIONS,
    BRAND_NAME,
    BRAND_CITY,
    generateMomentsCopy
  } from '@/api/ai'
  import type { MarketingHistoryItem, MyProfile } from '@/api/my'
  import {
    defaultMyProfile,
    fetchMyProfile,
    saveMyProfile,
    fetchMarketingHistory,
    removeMarketingHistory
  } from '@/api/my'
  import { useAiConfigStore } from '@/store/modules/ai-config'
  import { Delete } from '@element-plus/icons-vue'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'MomentsTool' })

  const aiStore = useAiConfigStore()
  const { removeFavorite } = aiStore
  const favorites = computed(() =>
    aiStore.marketing.favorites.filter((f) => f.platform === '朋友圈')
  )

  /** 个人中心资料（姓名/性别/年龄等自动带入人设） */
  const my = ref<MyProfile>(defaultMyProfile())

  /** 人设编辑区（保存在账号 profile 上） */
  const persona = ref({
    role: '全职老师',
    years: '',
    specialties: [] as string[],
    audiences: [] as string[]
  })

  onMounted(async () => {
    my.value = await fetchMyProfile()
    persona.value = {
      role: my.value.persona.role,
      years: my.value.years,
      specialties: [...my.value.specialties],
      audiences: [...my.value.persona.audiences]
    }
  })

  const savingPersona = ref(false)
  async function savePersona() {
    savingPersona.value = true
    try {
      await saveMyProfile({
        years: persona.value.years,
        specialties: persona.value.specialties,
        persona: { role: persona.value.role, audiences: persona.value.audiences }
      })
      my.value = await fetchMyProfile(true)
      ElMessage.success('人设已保存到账号，换设备登录也不会丢')
    } catch (e) {
      ElMessage.error(`保存失败：${String(e).slice(0, 60)}`)
    } finally {
      savingPersona.value = false
    }
  }

  const category = ref<string>('课堂日常')
  const topics = computed(() => [...(MOMENTS_CATEGORIES[category.value] ?? []), '自定义'])
  const topic = ref('')
  const tone = ref(MOMENT_TONES[0])
  const goal = ref(MOMENT_GOALS[0])
  const expression = ref(MOMENT_EXPRESSIONS[0])
  const lengthLabel = ref(MOMENT_LENGTHS[1].label)
  const multiLine = ref(true)
  const emojiLevel = ref(EMOJI_LEVELS[0])
  const userInput = ref('')

  const generating = ref(false)
  const result = ref('')
  const reply = ref('')
  const source = ref<'llm' | 'fallback'>('fallback')
  const editMode = ref(false)
  const warning = ref('')
  const favVisible = ref(false)

  const historyVisible = ref(false)
  const historyLoading = ref(false)
  const history = ref<MarketingHistoryItem[]>([])

  async function openHistory() {
    historyVisible.value = true
    historyLoading.value = true
    try {
      history.value = await fetchMarketingHistory('朋友圈')
    } finally {
      historyLoading.value = false
    }
  }

  async function removeHistory(id: number) {
    await removeMarketingHistory(id)
    history.value = history.value.filter((h) => h.id !== id)
  }

  function loadHistory(h: MarketingHistoryItem) {
    result.value = h.content
    reply.value = h.reply || ''
    source.value = h.source === 'llm' ? 'llm' : 'fallback'
    historyVisible.value = false
    warning.value = ''
    editMode.value = false
  }

  function buildPersona() {
    return {
      name: my.value.name,
      gender: my.value.gender,
      age: my.value.age,
      role: persona.value.role,
      years: persona.value.years,
      specialties: persona.value.specialties,
      brand: BRAND_NAME,
      city: BRAND_CITY,
      audiences: persona.value.audiences
    }
  }

  async function generate() {
    if (!userInput.value.trim() && !topic.value.trim()) {
      ElMessage.warning('请先选择主题或在输入框写下想表达的内容')
      return
    }
    generating.value = true
    warning.value = ''
    try {
      const res = await generateMomentsCopy({
        persona: buildPersona(),
        category: category.value,
        topic: topic.value === '自定义' ? '' : topic.value,
        tone: tone.value,
        goal: goal.value,
        expression: expression.value,
        lengthLabel: lengthLabel.value,
        multiLine: multiLine.value,
        emojiLevel: emojiLevel.value,
        userInput: userInput.value
      })
      result.value = res.content
      reply.value = res.reply
      source.value = res.source
      warning.value = res.warning ?? ''
      editMode.value = false
    } finally {
      generating.value = false
    }
  }

  async function copyText(text: string) {
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success('已复制')
    } catch {
      ElMessage.warning('复制失败，请手动选择复制')
    }
  }

  function copyAll() {
    copyText(reply.value ? `${result.value}\n\n——评论区首条回复——\n${reply.value}` : result.value)
  }

  function saveFavorite() {
    if (!result.value.trim()) return
    aiStore.addFavorite({
      platform: '朋友圈',
      title: topic.value || userInput.value.slice(0, 16) || '自由创作',
      content: result.value,
      tags: []
    })
    ElMessage.success('已收藏到朋友圈库')
  }

  function loadFavorite(f: { content: string }) {
    result.value = f.content
    source.value = 'fallback'
    favVisible.value = false
  }
</script>
