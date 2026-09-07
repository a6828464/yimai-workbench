<template>
  <div>
    <!-- 账号定位与人设（保存到账号，多设备共用） -->
    <ElCard shadow="never" class="mb-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">账号定位与人设</span>
          <div class="flex-c gap-3">
            <span class="text-xs text-gray-400">品牌固定「一麦」· 城市固定「宁波」</span>
            <ElButton
              size="small"
              type="primary"
              plain
              :loading="savingProfile"
              @click="saveProfile"
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
        <ElCol :xs="24" :md="6">
          <ElFormItem label="IP定位">
            <ElRadioGroup v-model="profile.ipType">
              <ElRadioButton value="个人IP">个人IP</ElRadioButton>
              <ElRadioButton value="门店IP">门店IP</ElRadioButton>
            </ElRadioGroup>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5"
          ><ElFormItem label="账号名"
            ><ElInput v-model="profile.accountName" placeholder="非必填" /></ElFormItem
        ></ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem label="身份角色">
            <ElSelect v-model="profile.role">
              <ElOption v-for="r in PERSONA_ROLES" :key="r" :label="r" :value="r" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="8" :md="3"
          ><ElFormItem label="同城属性"><ElSwitch v-model="profile.localFocus" /></ElFormItem
        ></ElCol>
        <ElCol :xs="24" :lg="12">
          <ElFormItem label="专业">
            <ElSelect
              v-model="specialties"
              multiple
              filterable
              allow-create
              default-first-option
              collapse-tags
              :max-collapse-tags="6"
              class="w-full"
              placeholder="与个人中心·会员管理共用，可多选、可自定义"
            >
              <ElOption v-for="s in SPECIALTY_OPTIONS" :key="s" :label="s" :value="s" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="16">
          <ElFormItem label="客户画像">
            <ElSelect
              v-model="profile.audiences"
              multiple
              filterable
              allow-create
              default-first-option
              :multiple-limit="6"
              collapse-tags
              class="w-full"
              placeholder="最多选6项（可自定义添加）"
            >
              <ElOption v-for="a in AUDIENCE_OPTIONS" :key="a" :label="a" :value="a" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="9"
          ><ElFormItem label="表达风格"
            ><ElSelect v-model="profile.style"
              ><ElOption
                v-for="v in XHS_STYLES"
                :key="v"
                :label="v"
                :value="v" /></ElSelect></ElFormItem
        ></ElCol>
        <ElCol :xs="24" :md="10"
          ><ElFormItem label="转化方向"
            ><ElSelect v-model="profile.conversion"
              ><ElOption
                v-for="v in XHS_CONVERSIONS"
                :key="v"
                :label="v"
                :value="v" /></ElSelect></ElFormItem
        ></ElCol>
      </ElRow>
    </ElCard>

    <!-- 本次笔记 -->
    <ElCard shadow="never" class="mb-4">
      <template #header><span class="font-500">本次笔记</span></template>
      <ElRow :gutter="12">
        <ElCol :xs="24" :md="6"
          ><ElFormItem label="主题分类"
            ><ElSelect v-model="category" @change="pickedTopic = ''"
              ><ElOption
                v-for="(v, k) in XHS_CATEGORIES"
                :key="k"
                :label="k"
                :value="k" /></ElSelect></ElFormItem
        ></ElCol>
        <ElCol :xs="24" :md="10">
          <ElFormItem label="选题模板">
            <ElSelect
              v-model="pickedTopic"
              filterable
              allow-create
              class="w-full"
              placeholder="选模板或输入自定义选题"
            >
              <ElOption v-for="t in topicOptions" :key="t" :label="t" :value="t" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="8"
          ><ElFormItem label="补充要点"
            ><ElInput v-model="points" placeholder="想覆盖的信息点、活动信息（选填）" /></ElFormItem
        ></ElCol>
      </ElRow>
      <div class="flex gap-2">
        <ElButton type="primary" :loading="generating" @click="generate">生成笔记</ElButton>
        <ElButton @click="openHistory">历史生成记录</ElButton>
        <ElButton @click="favVisible = true">笔记库（{{ favorites.length }}）</ElButton>
      </div>
    </ElCard>

    <!-- 生成结果：小红书笔记卡片 -->
    <ElCard v-if="hasResult || warning || generating" shadow="never" class="mb-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">生成结果</span>
          <div class="flex-c gap-3">
            <ElButton
              v-if="hasResult"
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
          <div class="text-xs text-gray-400 mb-1">标题</div>
          <ElInput v-model="title" class="mb-3" />
          <div class="text-xs text-gray-400 mb-1">正文</div>
          <ElInput v-model="content" type="textarea" :rows="9" class="mb-3" />
          <div class="text-xs text-gray-400 mb-1">话题标签（逗号分隔，可编辑）</div>
          <ElInput v-model="tagsText" class="mb-3" />
          <div class="text-xs text-gray-400 mb-1">首评建议（发布后置顶评论）</div>
          <ElInput v-model="reply" type="textarea" :rows="3" resize="none" />
        </template>
        <!-- 笔记样式预览 -->
        <template v-else>
          <div
            class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden bg-white dark:bg-[#0d0d0d]"
          >
            <div class="px-4 pt-5 pb-3 bg-gradient-to-r from-[#ff2442]/10 to-[#ff7a45]/10">
              <h3 class="text-[17px] font-700 leading-snug text-g-900">{{ title }}</h3>
              <div class="mt-1.5 text-xs text-g-500">一麦瑜伽 · 小红书笔记</div>
            </div>
            <p
              class="px-4 py-3 text-[14.5px] leading-[1.85] text-g-700 whitespace-pre-wrap break-all"
              >{{ content }}</p
            >
            <div v-if="tags.length" class="px-4 pb-4 flex flex-wrap gap-1.5">
              <span
                v-for="tag in tags.slice(0, 10)"
                :key="tag"
                class="px-2 py-0.5 rounded-full text-xs bg-[#ff2442]/10 text-[#ff2442]"
              >
                {{ tag }}
              </span>
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
          <ElButton type="primary" plain @click="copyAll">复制整篇</ElButton>
          <ElButton plain @click="saveFavorite">收藏到笔记库</ElButton>
          <ElButton text @click="generate()">重新生成</ElButton>
        </div>
      </div>
    </ElCard>

    <!-- 笔记库（收藏） -->
    <ElDrawer v-model="favVisible" title="小红书笔记库" size="460px">
      <div v-if="favorites.length">
        <div
          v-for="f in favorites"
          :key="f.id"
          class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700"
        >
          <div class="flex-cb mb-1">
            <span class="text-sm font-500 truncate">{{ f.title }}</span>
            <ElIcon
              class="cursor-pointer shrink-0 ml-2"
              color="#f56c6c"
              @click="removeFavorite(f.id)"
              ><Delete
            /></ElIcon>
          </div>
          <div class="text-xs text-gray-400 mb-1">{{ f.createdAt }}</div>
          <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-3">{{ f.content }}</div>
          <div class="mt-2 flex gap-2">
            <ElButton link type="primary" size="small" @click="loadFavorite(f)">载入编辑</ElButton>
            <ElButton
              link
              size="small"
              @click="copyText(`${f.title}\n\n${f.content}\n\n${f.tags.join(' ')}`)"
              >复制</ElButton
            >
          </div>
        </div>
      </div>
      <ElEmpty v-else description="收藏的笔记会出现在这里" />
    </ElDrawer>

    <!-- 历史生成记录（按账号云端保存，最近300条） -->
    <ElDrawer v-model="historyVisible" title="历史生成笔记" size="460px">
      <div v-loading="historyLoading">
        <div v-if="history.length">
          <div
            v-for="h in history"
            :key="h.id"
            class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700"
          >
            <div class="flex-cb mb-1">
              <span class="text-xs font-500 truncate">{{ h.title }}</span>
              <ElIcon
                class="cursor-pointer shrink-0 ml-2"
                color="#f56c6c"
                @click="removeHistory(h.id)"
                ><Delete
              /></ElIcon>
            </div>
            <div class="text-xs text-gray-400 mb-1">{{ h.createdAt }}</div>
            <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-3">{{ h.content }}</div>
            <div v-if="h.reply" class="mt-1.5 text-xs text-g-500 leading-5 line-clamp-2"
              >💬 {{ h.reply }}</div
            >
            <div class="mt-2 flex gap-2">
              <ElButton link type="primary" size="small" @click="loadHistory(h)">载入编辑</ElButton>
              <ElButton link size="small" @click="copyText(h.content)">复制</ElButton>
              <ElButton v-if="h.reply" link size="small" @click="copyText(h.reply)"
                >复制首评</ElButton
              >
            </div>
          </div>
        </div>
        <ElEmpty v-else-if="!historyLoading" description="每次生成的笔记都会自动记录在这里" />
      </div>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import {
    XHS_CATEGORIES,
    XHS_STYLES,
    XHS_CONVERSIONS,
    PERSONA_ROLES,
    SPECIALTY_OPTIONS,
    AUDIENCE_OPTIONS,
    BRAND_NAME,
    BRAND_CITY,
    generateXhsNote
  } from '@/api/ai'
  import type { XhsProfile } from '@/api/ai'
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

  defineOptions({ name: 'XhsTool' })

  const aiStore = useAiConfigStore()
  const { removeFavorite } = aiStore
  const favorites = computed(() =>
    aiStore.marketing.favorites.filter((f) => f.platform === '小红书')
  )

  const my = ref<MyProfile>(defaultMyProfile())

  /** 小红书人设编辑区 */
  const profile = ref<XhsPersonaEditor>({
    ipType: '个人IP',
    accountName: '',
    role: '全职老师',
    audiences: [],
    style: '真实接地气',
    conversion: '评论区留言',
    localFocus: true
  })
  /** 与个人中心共享的专业 */
  const specialties = ref<string[]>([])

  type XhsPersonaEditor = Omit<XhsProfile, 'gender' | 'age' | 'brand' | 'city' | 'strengths'>

  onMounted(async () => {
    my.value = await fetchMyProfile()
    const { ipType, accountName, role, audiences, style, conversion, localFocus } = my.value.xhs
    profile.value = {
      ipType,
      accountName,
      role,
      audiences: [...audiences],
      style,
      conversion,
      localFocus
    }
    specialties.value = [...my.value.specialties]
  })

  const savingProfile = ref(false)
  async function saveProfile() {
    savingProfile.value = true
    try {
      await saveMyProfile({
        specialties: specialties.value,
        xhs: { ...profile.value }
      })
      my.value = await fetchMyProfile(true)
      ElMessage.success('人设已保存到账号，换设备登录也不会丢')
    } catch (e) {
      ElMessage.error(`保存失败：${String(e).slice(0, 60)}`)
    } finally {
      savingProfile.value = false
    }
  }

  const category = ref<string>('干货教程')
  const topicOptions = computed(() => [...(XHS_CATEGORIES[category.value] ?? []), '自定义'])
  const pickedTopic = ref('')
  const points = ref('')

  const generating = ref(false)
  const title = ref('')
  const content = ref('')
  const tagsText = ref('')
  const reply = ref('')

  const tags = computed(() => tagsText.value.split(/[\s,，]+/).filter(Boolean))

  const editMode = ref(false)
  const source = ref<'llm' | 'fallback'>('fallback')
  const warning = ref('')
  const favVisible = ref(false)

  const historyVisible = ref(false)
  const historyLoading = ref(false)
  const history = ref<MarketingHistoryItem[]>([])

  const hasResult = computed(() => title.value || content.value)

  async function openHistory() {
    historyVisible.value = true
    historyLoading.value = true
    try {
      history.value = await fetchMarketingHistory('小红书')
    } finally {
      historyLoading.value = false
    }
  }

  async function removeHistory(id: number) {
    await removeMarketingHistory(id)
    history.value = history.value.filter((h) => h.id !== id)
  }

  function loadHistory(h: MarketingHistoryItem) {
    // 历史整文格式：标题 \n\n 正文 \n\n #标签串
    const lines = h.content.split('\n')
    title.value = lines.shift() ?? ''
    let tagLine = ''
    for (let i = lines.length - 1; i >= 0; i--) {
      if (lines[i].trim().startsWith('#')) {
        tagLine = lines.splice(i, 1)[0].trim()
        break
      }
      if (lines[i].trim()) break
    }
    content.value = lines.join('\n').trim()
    tagsText.value = tagLine
    reply.value = h.reply || ''
    source.value = h.source === 'llm' ? 'llm' : 'fallback'
    historyVisible.value = false
    warning.value = ''
    editMode.value = false
  }

  async function generate() {
    if (!pickedTopic.value.trim() && !points.value.trim()) {
      ElMessage.warning('请选择/填写选题或补充要点')
      return
    }
    generating.value = true
    warning.value = ''
    try {
      const res = await generateXhsNote({
        profile: {
          ...profile.value,
          gender: my.value.gender,
          age: my.value.age,
          brand: BRAND_NAME,
          city: BRAND_CITY,
          strengths: specialties.value
        },
        category: category.value,
        topic: pickedTopic.value === '自定义' ? '' : pickedTopic.value,
        points: points.value
      })
      title.value = res.title
      content.value = res.content
      tagsText.value = res.tags.join(' ')
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
    const parts = [[title.value, '', content.value, '', tagsText.value].join('\n')]
    if (reply.value) parts.push(`——评论区首条回复——\n${reply.value}`)
    copyText(parts.join('\n\n'))
  }

  function saveFavorite() {
    if (!title.value && !content.value) return
    aiStore.addFavorite({
      platform: '小红书',
      title: title.value || content.value.slice(0, 20),
      content: content.value,
      tags: tagsText.value.split(/[\s,，]+/).filter(Boolean)
    })
    ElMessage.success('已收藏到笔记库')
  }

  function loadFavorite(f: { title: string; content: string; tags: string[] }) {
    title.value = f.title
    content.value = f.content
    tagsText.value = f.tags.join(' ')
    source.value = 'fallback'
    favVisible.value = false
  }
</script>
