<template>
  <div class="flex-c h-full min-h-100vh">
    <div class="text-center">
      <img src="@imgs/yimai-logo.png" alt="一麦" class="w-14 h-14 object-contain mx-auto mb-4" />
      <h2 class="text-xl font-600 mb-2">选择要进入的门店</h2>
      <p class="text-sm text-gray-400 mb-8">你拥有双店权限，进入后可随时在工作台切换</p>

      <div class="flex gap-6 justify-center flex-wrap">
        <div
          v-for="v in venues"
          :key="v"
          class="store-card"
          @click="enter(v)"
        >
          <ElIcon :size="34" style="color: var(--main-color)">
            <OfficeBuilding />
          </ElIcon>
          <div class="mt-3 text-base font-500">{{ v }}</div>
          <div class="mt-1 text-xs text-gray-400">{{ v === '绿地店' ? '总店' : '分店' }} · 点击进入工作台</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'
  import { OfficeBuilding } from '@element-plus/icons-vue'

  defineOptions({ name: 'YimaiStoreSelect' })

  const userStore = useUserStore()
  const venues = computed(() => {
    const list = userStore.getUserInfo.venues ?? []
    return list.length ? list : ['绿地店', '东部店']
  })

  function enter(venue: string) {
    const prev = userStore.getUserInfo
    userStore.setUserInfo({
      buttons: prev.buttons ?? [],
      roles: prev.roles ?? [],
      userId: prev.userId ?? 0,
      userName: prev.userName ?? '',
      email: prev.email ?? '',
      avatar: prev.avatar,
      venue,
      venues: prev.venues ?? []
    })
    userStore.checkAndClearWorktabs()
    const redirect = useRoute().query.redirect as string
    useRouter().push(redirect || '/yimai/today')
  }
</script>

<style scoped lang="scss">
  .store-card {
    width: 220px;
    padding: 32px 20px;
    cursor: pointer;
    text-align: center;
    border: 1px solid var(--art-border-color, #e8e8e8);
    border-radius: 14px;
    background: var(--default-box-color);
    transition:
      transform 0.18s,
      box-shadow 0.18s,
      border-color 0.18s;

    &:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 28px rgb(0 0 0 / 8%);
      border-color: var(--main-color);
    }
  }
</style>
