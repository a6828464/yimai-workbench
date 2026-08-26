<template>
  <div>
    <!-- 账号定位与人设 -->
    <ElCard shadow="never" class="mb-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">账号定位与人设</span>
          <span class="text-xs text-gray-400">保存后长期生效，生成时自动带入</span>
        </div>
      </template>
      <ElRow :gutter="12">
        <ElCol :xs="24" :md="6">
          <ElFormItem label="IP定位">
            <ElRadioGroup v-model="profile.ipType">
              <ElRadioButton value="个人IP">个人IP</ElRadioButton>
              <ElRadioButton value="门店IP">门店IP</ElRadioButton>
            </ElRadioGroup>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5"><ElFormItem label="账号名"><ElInput v-model="profile.accountName" placeholder="非必填" /></ElFormItem></ElCol>
        <ElCol :xs="12" :md="4"><ElFormItem label="身份角色"><ElInput v-model="profile.role" /></ElFormItem></ElCol>
        <ElCol :xs="8" :md="3"><ElFormItem label="性别"><ElSelect v-model="profile.gender"><ElOption label="女" value="女" /><ElOption label="男" value="男" /></ElSelect></ElFormItem></ElCol>
        <ElCol :xs="8" :md="3"><ElFormItem label="年龄"><ElInput v-model="profile.age" /></ElFormItem></ElCol>
        <ElCol :xs="8" :md="3"><ElFormItem label="同城属性"><ElSwitch v-model="profile.localFocus" /></ElFormItem></ElCol>
        <ElCol :xs="12" :md="4"><ElFormItem label="品牌名"><ElInput v-model="profile.brand" placeholder="非必填" /></ElFormItem></ElCol>
        <ElCol :xs="12" :md="4"><ElFormItem label="城市"><ElInput v-model="profile.city" placeholder="非必填" /></ElFormItem></ElCol>
        <ElCol :xs="24" :md="16">
          <ElFormItem label="客户画像">
            <ElSelect v-model="profile.audiences" multiple :multiple-limit="4" collapse-tags class="w-full" placeholder="最多选4项">
              <ElOption v-for="a in AUDIENCE_OPTIONS" :key="a" :label="a" :value="a" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :span="24">
          <ElFormItem label="擅长方向">
            <ElSelect v-model="profile.strengths" multiple collapse-tags :max-collapse-tags="8" class="w-full">
              <ElOption v-for="s in XHS_STRENGTHS" :key="s" :label="s" :value="s" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="9"><ElFormItem label="表达风格"><ElSelect v-model="profile.style"><ElOption v-for="v in XHS_STYLES" :key="v" :label="v" :value="v" /></ElSelect></ElFormItem></ElCol>
        <ElCol :xs="24" :md="10"><ElFormItem label="转化方向"><ElSelect v-model="profile.conversion"><ElOption v-for="v in XHS_CONVERSIONS" :key="v" :label="v" :value="v" /></ElSelect></ElFormItem></ElCol>
      </ElRow>
    </ElCard>

    <!-- 本次选题 -->
    <ElCard shadow="never" class="mb-4">
      <template #header><span class="font-500">本次笔记</span></template>
      <ElRow :gutter="12">
        <ElCol :xs="24" :md="10"><ElFormItem label="选题"><ElInput v-model="topic" placeholder="如：产后妈妈第一次上普拉提 / 体态评估流程" /></ElFormItem></ElCol>
        <ElCol :xs="24" :md="14"><ElFormItem label="补充要点"><ElInput v-model="points" placeholder="想覆盖的信息点、活动信息（选填）" /></ElFormItem></ElCol>
      </ElRow>
      <div class="flex gap-2">
        <ElButton type="primary" :loading="generating" @click="generate">生成笔记</ElButton>
        <ElButton @click="favVisible = true">笔记库（{{ favorites.length }}）</ElButton>
      </div>
    </ElCard>

    <!-- 生成结果：标题/正文/标签 三段式 -->
    <ElCard v-if="hasResult || warning || generating" shadow="never" class="mb-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">生成结果</span>
          <ElTag size="small" :type="source === 'llm' ? 'success' : 'warning'">{{ source === 'llm' ? '大模型生成' : '本地模板草稿' }}</ElTag>
        </div>
      </template>
      <ElAlert v-if="warning" :title="warning" type="warning" show-icon :closable="false" class="mb-3" />
      <div v-loading="generating">
        <div class="text-xs text-gray-400 mb-1">标题</div>
        <ElInput v-model="title" class="mb-3" />
        <div class="text-xs text-gray-400 mb-1">正文</div>
        <ElInput v-model="content" type="textarea" :rows="9" class="mb-3" />
        <div class="text-xs text-gray-400 mb-1">话题标签（逗号分隔，可编辑）</div>
        <ElInput v-model="tagsText" />
        <div class="mt-3 flex gap-2">
          <ElButton type="primary" plain @click="copyAll">复制整篇</ElButton>
          <ElButton plain @click="saveFavorite">收藏到笔记库</ElButton>
          <ElButton text @click="generate()">重新生成</ElButton>
        </div>
      </div>
    </ElCard>

    <!-- 笔记库 -->
    <ElDrawer v-model="favVisible" title="小红书笔记库" size="460px">
      <div v-if="favorites.length">
        <div v-for="f in favorites" :key="f.id" class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700">
          <div class="flex-cb mb-1">
            <span class="text-sm font-500 truncate">{{ f.title }}</span>
            <ElIcon class="cursor-pointer shrink-0 ml-2" color="#f56c6c" @click="removeFavorite(f.id)"><Delete /></ElIcon>
          </div>
          <div class="text-xs text-gray-400 mb-1">{{ f.createdAt }}</div>
          <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-3">{{ f.content }}</div>
          <div class="mt-2 flex gap-2">
            <ElButton link type="primary" size="small" @click="loadFavorite(f)">载入编辑</ElButton>
            <ElButton link size="small" @click="copyText(`${f.title}\n\n${f.content}\n\n${f.tags.join(' ')}`)">复制</ElButton>
          </div>
        </div>
      </div>
      <ElEmpty v-else description="收藏的笔记会出现在这里" />
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import {
    XHS_STRENGTHS,
    XHS_STYLES,
    XHS_CONVERSIONS,
    AUDIENCE_OPTIONS,
    generateXhsNote
  } from '@/api/ai'
  import type { XhsProfile } from '@/api/ai'
  import { useAiConfigStore } from '@/store/modules/ai-config'
  import { Delete } from '@element-plus/icons-vue'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'XhsTool' })

  const aiStore = useAiConfigStore()
  const { removeFavorite } = aiStore
  const favorites = computed(() => aiStore.marketing.favorites.filter((f) => f.platform === '小红书'))

  const profile = useStorage<XhsProfile>('yimai-xhs-profile', {
    ipType: '个人IP',
    accountName: '',
    role: '瑜伽普拉提教练',
    gender: '女',
    age: '',
    audiences: ['零基础女性', '久坐肩颈人群'],
    brand: '',
    city: '',
    localFocus: true,
    strengths: ['体态改善', '核心训练'],
    style: '真实接地气',
    conversion: '评论区留言'
  })

  const topic = ref('')
  const points = ref('')
  const generating = ref(false)
  const title = ref('')
  const content = ref('')
  const tagsText = ref('')
  const source = ref<'llm' | 'fallback'>('fallback')
  const warning = ref('')
  const favVisible = ref(false)

  const hasResult = computed(() => title.value || content.value)

  async function generate() {
    if (!topic.value.trim() && !points.value.trim()) {
      ElMessage.warning('请填写选题或补充要点')
      return
    }
    generating.value = true
    warning.value = ''
    try {
      const res = await generateXhsNote({
        profile: profile.value,
        topic: topic.value,
        points: points.value
      })
      title.value = res.title
      content.value = res.content
      tagsText.value = res.tags.join(' ')
      source.value = res.source
      warning.value = res.warning ?? ''
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
    copyText([title.value, '', content.value, '', tagsText.value].join('\n'))
  }

  function saveFavorite() {
    if (!title.value && !content.value) return
    aiStore.addFavorite({
      platform: '小红书',
      title: title.value || content.value.slice(0, 20),
      content: content.value,
      tags: tagsText.value.split(/\s+/).filter(Boolean)
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
