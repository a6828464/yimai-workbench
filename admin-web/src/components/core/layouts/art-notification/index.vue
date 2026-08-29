<template>
  <div
    v-show="visible"
    class="art-notification-panel art-card-sm !shadow-xl"
    :style="{ transform: show ? 'scaleY(1)' : 'scaleY(0.9)', opacity: show ? 1 : 0 }"
    @click.stop
  >
    <div class="flex-cb px-3.5 mt-3.5">
      <div>
        <span class="text-base font-medium text-g-800">通知中心</span>
        <span v-if="refreshedAt" class="ml-2 text-xs text-g-500">更新 {{ refreshedAt }}</span>
      </div>
      <div class="flex gap-2 text-xs">
        <span class="px-1.5 py-1 c-p rounded hover:bg-g-200" @click="load">刷新</span>
        <span class="px-1.5 py-1 c-p rounded hover:bg-g-200" @click="readAll">全部已读</span>
      </div>
    </div>

    <ul class="box-border flex items-end w-full h-12.5 px-3.5 border-b-d">
      <li
        v-for="(tab, index) in tabs"
        :key="tab.key"
        class="h-12 leading-12 mr-5 text-[13px] text-g-700 c-p"
        :class="{ 'bar-active': active === index }"
        @click="active = index"
      >
        {{ tab.label }} ({{ tab.items.length }})
      </li>
    </ul>

    <div class="h-[calc(100%-95px)]">
      <div v-loading="loading" class="h-[calc(100%-60px)] overflow-y-auto scrollbar-thin">
        <ul>
          <li
            v-for="item in currentItems"
            :key="item.key"
            class="box-border flex-c px-3.5 py-3.5 c-p hover:bg-g-200/60"
            :class="{ 'opacity-60': item.read }"
            @click="openItem(item)"
          >
            <div class="size-9 rounded-lg flex-cc" :class="levelClass(item.level)">
              <ArtSvgIcon :icon="levelIcon(item.category)" class="text-lg !bg-transparent" />
            </div>
            <div class="w-[calc(100%-45px)] ml-3.5">
              <div class="flex items-start gap-2">
                <h4 class="flex-1 text-sm font-normal leading-5.5 text-g-900">{{ item.title }}</h4>
                <span v-if="!item.read" class="mt-1 size-1.5 rounded-full bg-danger"></span>
              </div>
              <p class="mt-1 text-xs text-g-500">{{ item.detail }}</p>
            </div>
          </li>
        </ul>
        <div v-if="!loading && !currentItems.length" class="relative top-25 text-g-500 text-center">
          <ArtSvgIcon icon="system-uicons:inbox" class="text-5xl" />
          <p class="mt-3.5 text-xs">暂无{{ tabs[active].label }}</p>
        </div>
      </div>
      <div class="px-3.5">
        <ElButton class="w-full mt-3" @click="viewAll">查看全部</ElButton>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { getNotifications, readAllNotifications, readNotification } from '@/api/yimai'
  import type { BusinessNotification } from '@/api/yimai'
  import { useRouter } from 'vue-router'

  defineOptions({ name: 'ArtNotification' })

  const props = defineProps<{ value: boolean }>()
  const emit = defineEmits<{
    'update:value': [value: boolean]
    'unread-change': [value: number]
  }>()
  const router = useRouter()
  const show = ref(false)
  const visible = ref(false)
  const loading = ref(false)
  const active = ref(0)
  const items = ref<BusinessNotification[]>([])
  const refreshedAt = ref('')

  const tabs = computed(() => [
    {
      key: 'notice',
      label: '通知',
      items: items.value.filter((item) => item.category === 'notice')
    },
    {
      key: 'message',
      label: '消息',
      items: items.value.filter((item) => item.category === 'message')
    },
    { key: 'todo', label: '待办', items: items.value.filter((item) => item.category === 'todo') }
  ])
  const currentItems = computed(() => tabs.value[active.value]?.items ?? [])

  async function load() {
    loading.value = true
    try {
      const data = await getNotifications()
      items.value = data.items
      refreshedAt.value = data.refreshedAt
      emit('unread-change', data.unreadCount)
    } finally {
      loading.value = false
    }
  }

  async function openItem(item: BusinessNotification) {
    if (!item.read) await readNotification(item.key)
    emit('update:value', false)
    await router.push(item.path)
    await load()
  }

  async function readAll() {
    await readAllNotifications()
    items.value = items.value.map((item) => ({ ...item, read: true }))
    emit('unread-change', 0)
  }

  function viewAll() {
    const path =
      active.value === 2 ? '/yimai/tasks' : active.value === 1 ? '/yimai/today' : '/yimai/members'
    emit('update:value', false)
    router.push(path)
  }

  function levelIcon(category: BusinessNotification['category']): string {
    return category === 'todo'
      ? 'ri:task-line'
      : category === 'message'
        ? 'ri:message-3-line'
        : 'ri:notification-3-line'
  }

  function levelClass(level: BusinessNotification['level']): string {
    return level === 'high'
      ? 'bg-danger/12 text-danger'
      : level === 'warning'
        ? 'bg-warning/12 text-warning'
        : 'bg-theme/12 text-theme'
  }

  watch(
    () => props.value,
    (open) => {
      if (open) {
        visible.value = true
        load()
        setTimeout(() => (show.value = true), 5)
      } else {
        show.value = false
        setTimeout(() => (visible.value = false), 350)
      }
    },
    { immediate: true }
  )

  onMounted(load)
</script>

<style scoped>
  @reference '@styles/core/tailwind.css';

  .art-notification-panel {
    @apply absolute top-14.5 right-5 w-90 h-125 overflow-hidden transition-all duration-300 origin-top will-change-[top,left] max-[640px]:top-[65px] max-[640px]:right-0 max-[640px]:w-full max-[640px]:h-[80vh];
  }

  .bar-active {
    @apply relative text-theme;
  }

  .bar-active::after {
    position: absolute;
    right: 0;
    bottom: 0;
    left: 0;
    height: 2px;
    content: '';
    background-color: var(--main-color);
  }
</style>
