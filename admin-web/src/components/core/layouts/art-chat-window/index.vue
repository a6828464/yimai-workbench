<!-- 系统聊天窗口 -->
<template>
  <div>
    <ElDrawer v-model="isDrawerVisible" :size="isMobile ? '100%' : '480px'" :with-header="false">
      <div class="mb-5 flex-cb">
        <div>
          <span class="text-base font-medium">Art Bot</span>
          <div class="mt-1.5 flex-c gap-1">
            <div
              class="h-2 w-2 rounded-full"
              :class="isOnline ? 'bg-success/100' : 'bg-danger/100'"
            ></div>
            <span class="text-xs text-g-600">{{ isOnline ? '在线' : '离线' }}</span>
          </div>
        </div>
        <div>
          <ElIcon class="c-p" :size="20" @click="closeChat">
            <Close />
          </ElIcon>
        </div>
      </div>
      <div class="flex h-[calc(100%-70px)] flex-col">
        <!-- 聊天消息区域 -->
        <div
          class="flex-1 overflow-y-auto border-t-d px-4 py-7.5 [&::-webkit-scrollbar]:!w-1"
          ref="messageContainer"
        >
          <template v-for="(message, index) in messages" :key="index">
            <div
              :class="[
                'mb-7.5 flex w-full items-start gap-2',
                message.isMe ? 'flex-row-reverse' : 'flex-row'
              ]"
            >
              <ElAvatar :size="32" :src="message.avatar" class="shrink-0" />
              <div
                :class="['flex max-w-[70%] flex-col', message.isMe ? 'items-end' : 'items-start']"
              >
                <div
                  :class="[
                    'mb-1 flex gap-2 text-xs',
                    message.isMe ? 'flex-row-reverse' : 'flex-row'
                  ]"
                >
                  <span class="font-medium">{{ message.sender }}</span>
                  <span class="text-g-600">{{ message.time }}</span>
                </div>
                <div
                  :class="[
                    'rounded-md px-3.5 py-2.5 text-sm leading-[1.4] text-g-900',
                    message.isMe ? 'message-right bg-theme/15' : 'message-left bg-g-300/50'
                  ]"
                  >{{ message.content }}</div
                >
              </div>
            </div>
          </template>
        </div>

        <!-- 聊天输入区域 -->
        <div class="px-4 pt-4">
          <ElInput
            v-model="messageText"
            type="textarea"
            :rows="3"
            placeholder="输入消息"
            resize="none"
            @keyup.enter.prevent="sendMessage"
          >
            <template #append>
              <div class="flex gap-2 py-2">
                <ElButton :icon="Paperclip" circle plain />
                <ElButton :icon="Picture" circle plain />
                <ElButton type="primary" @click="sendMessage" v-ripple>发送</ElButton>
              </div>
            </template>
          </ElInput>
          <div class="mt-3 flex-cb">
            <div class="flex-c">
              <ArtSvgIcon icon="ri:image-line" class="mr-5 c-p text-g-600 text-lg" />
              <ArtSvgIcon icon="ri:emotion-happy-line" class="mr-5 c-p text-g-600 text-lg" />
            </div>
            <ElButton type="primary" @click="sendMessage" v-ripple class="min-w-20">发送</ElButton>
          </div>
        </div>
      </div>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { Picture, Paperclip, Close } from '@element-plus/icons-vue'
  import { mittBus } from '@/utils/sys'
  import { useAiConfigStore } from '@/store/modules/ai-config'
  import meAvatar from '@/assets/images/avatar/avatar5.webp'
  import aiAvatar from '@/assets/images/avatar/avatar10.webp'

  defineOptions({ name: 'ArtChatWindow' })

  // 类型定义
  interface ChatMessage {
    id: number
    sender: string
    content: string
    time: string
    isMe: boolean
    avatar: string
  }

  // 常量定义
  const MOBILE_BREAKPOINT = 640
  const SCROLL_DELAY = 100
  const BOT_NAME = '一麦AI助手'
  const USER_NAME = '我'

  // 响应式布局
  const { width } = useWindowSize()
  const isMobile = computed(() => width.value < MOBILE_BREAKPOINT)

  // 组件状态
  const isDrawerVisible = ref(false)
  const isOnline = ref(true)

  // 消息相关状态
  const messageText = ref('')
  const messageId = ref(10)
  const messageContainer = ref<HTMLElement | null>(null)

  // 工具函数
  const formatCurrentTime = (): string => {
    return new Date().toLocaleTimeString([], {
      hour: '2-digit',
      minute: '2-digit'
    })
  }

  const messages = ref<ChatMessage[]>([{
    id: 0,
    sender: BOT_NAME,
    content: '你好，我是一麦AI助手。有什么门店经营、会员服务、文案创作的问题都可以问我（由大模型驱动）。',
    time: formatCurrentTime(),
    isMe: false,
    avatar: aiAvatar
  }])

  const scrollToBottom = (): void => {
    nextTick(() => {
      setTimeout(() => {
        if (messageContainer.value) {
          messageContainer.value.scrollTop = messageContainer.value.scrollHeight
        }
      }, SCROLL_DELAY)
    })
  }

  // 消息处理方法
  const sendMessage = async (): Promise<void> => {
    const text = messageText.value.trim()
    if (!text) return

    const newMessage: ChatMessage = {
      id: messageId.value++,
      sender: USER_NAME,
      content: text,
      time: formatCurrentTime(),
      isMe: true,
      avatar: meAvatar
    }

    messages.value.push(newMessage)
    messageText.value = ''
    scrollToBottom()

    // 调用大模型回答
    const aiStore = useAiConfigStore()
    const { USE_BACKEND, apiPost } = await import('@/api/backend')
    if (!aiStore.isReady()) {
      messages.value.push({
        id: messageId.value++,
        sender: BOT_NAME,
        content: '尚未接入大模型。请找超管在「模型配置」中填写服务商信息并启用后，我才能回答。',
        time: formatCurrentTime(),
        isMe: false,
        avatar: aiAvatar
      })
      scrollToBottom()
      return
    }

    // 简易"思考中"占位
    const thinking: ChatMessage = {
      id: messageId.value++,
      sender: BOT_NAME,
      content: '· · ·',
      time: formatCurrentTime(),
      isMe: false,
      avatar: aiAvatar
    }
    messages.value.push(thinking)
    scrollToBottom()

    try {
      const c = aiStore.config
      let reply = ''
      if (USE_BACKEND) {
        const d = await apiPost<{ content?: string; code?: number; message?: string }>('/ai/chat', {
          baseUrl: c.baseUrl,
          apiKey: c.apiKey,
          model: c.model,
          messages: [
            { role: 'system', content: '你是一麦瑜伽普拉提馆的内部AI助手，回答简洁、专业、口语化。' },
            { role: 'user', content: text }
          ],
          temperature: c.temperature
        })
        if (d && d.code !== undefined && d.code !== 0) throw new Error(d.message || 'AI_ERROR')
        reply = d?.content ?? ''
      } else {
        const resp = await fetch(`${c.baseUrl.replace(/\/$/, '')}/chat/completions`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${c.apiKey}` },
          body: JSON.stringify({
            model: c.model,
            messages: [
              { role: 'system', content: '你是一麦瑜伽普拉提馆的内部AI助手，回答简洁、专业、口语化。' },
              { role: 'user', content: text }
            ],
            temperature: c.temperature
          })
        })
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`)
        reply = (await resp.json())?.choices?.[0]?.message?.content ?? ''
      }
      thinking.content = reply || '（模型未返回内容，请重试）'
    } catch (e) {
      thinking.content = `回答失败：${String(e).slice(0, 160)}`
    } finally {
      scrollToBottom()
    }
  }

  // 聊天窗口控制方法
  const openChat = (): void => {
    isDrawerVisible.value = true
    scrollToBottom()
  }

  const closeChat = (): void => {
    isDrawerVisible.value = false
  }

  // 生命周期
  onMounted(() => {
    scrollToBottom()
    mittBus.on('openChat', openChat)
  })

  onUnmounted(() => {
    mittBus.off('openChat', openChat)
  })
</script>
