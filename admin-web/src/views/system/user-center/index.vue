<!-- 个人中心（一麦·瑜伽普拉提版） -->
<template>
  <div class="w-full h-full p-0 bg-transparent border-none shadow-none">
    <div class="relative flex-b mt-2.5 max-md:block max-md:mt-1">
      <!-- 左侧资料卡 -->
      <div class="w-112 mr-5 max-md:w-full max-md:mr-0">
        <div class="art-card-sm relative p-9 pb-6 overflow-hidden text-center">
          <div
            class="absolute top-0 left-0 w-full h-44 object-cover"
            style="background: linear-gradient(135deg, #2f7d5d, #1d5c43)"
          ></div>
          <img
            class="relative z-10 w-20 h-20 mt-28 mx-auto object-cover border-2 border-white rounded-full bg-white"
            src="@imgs/yimai-logo.png"
          />
          <h2 class="mt-4 text-xl font-600">{{ userInfo.userName }}</h2>
          <p class="mt-1.5 text-sm text-g-600">{{ roleLabel }}</p>

          <div class="w-75 mx-auto mt-6 text-left">
            <div class="mt-2.5">
              <ArtSvgIcon icon="ri:store-2-line" class="text-g-700" />
              <span class="ml-2 text-sm">门店范围：{{ venueLabel }}</span>
            </div>
            <div class="mt-2.5">
              <ArtSvgIcon icon="ri:mail-line" class="text-g-700" />
              <span class="ml-2 text-sm">{{ userInfo.email }}</span>
            </div>
            <div class="mt-2.5">
              <ArtSvgIcon icon="ri:leaf-line" class="text-g-700" />
              <span class="ml-2 text-sm">一麦瑜伽 · 让每一次练习都算数</span>
            </div>
          </div>

          <div class="mt-8">
            <h3 class="text-sm font-medium">我的标签</h3>
            <div class="flex flex-wrap justify-center mt-3.5">
              <div
                v-for="item in roleTags"
                :key="item"
                class="py-1 px-2 mr-2 mb-2 text-xs border border-g-300 rounded text-g-600"
              >
                {{ item }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 右侧设置区 -->
      <div class="flex-1 overflow-hidden max-md:w-full max-md:mt-3.5">
        <!-- AI 创作人设 -->
        <div class="art-card-sm">
          <h1 class="p-4 text-xl font-normal border-b border-g-300">AI 创作人设</h1>
          <p class="px-5 pt-3 text-xs text-g-500 leading-5">
            此处统一设置你的职业人设，营销工具（朋友圈 / 小红书）生成文案时会自动带入，全店生效。
          </p>
          <ElForm :model="persona" class="box-border p-5" label-width="86px" label-position="top">
            <ElRow>
              <ElFormItem label="微信昵称" class="w-[calc(50%-10px)]">
                <ElInput v-model="persona.name" placeholder="选填" />
              </ElFormItem>
              <ElFormItem label="性别" class="ml-5 w-[calc(50%-10px)]">
                <ElSelect v-model="persona.gender">
                  <ElOption label="女" value="女" />
                  <ElOption label="男" value="男" />
                </ElSelect>
              </ElFormItem>
            </ElRow>
            <ElRow>
              <ElFormItem label="身份角色" class="w-[calc(50%-10px)]">
                <ElSelect v-model="persona.role">
                  <ElOption v-for="r in PERSONA_ROLES" :key="r" :label="r" :value="r" />
                </ElSelect>
              </ElFormItem>
              <ElFormItem label="从业年限" class="ml-5 w-[calc(50%-10px)]">
                <ElInput v-model="persona.years" placeholder="如：5年" />
              </ElFormItem>
            </ElRow>
            <ElRow>
              <ElFormItem label="职位" class="w-[calc(50%-10px)]">
                <ElInput v-model="persona.position" placeholder="如：普拉提主教练" />
              </ElFormItem>
              <ElFormItem label="品牌 / 城市" class="ml-5 w-[calc(50%-10px)]">
                <div class="flex gap-2 w-full">
                  <ElInput v-model="persona.brand" placeholder="品牌（选填）" />
                  <ElInput v-model="persona.city" placeholder="城市" />
                </div>
              </ElFormItem>
            </ElRow>
            <ElFormItem label="主要客群">
              <ElSelect v-model="persona.audiences" multiple :max-collapse-tags="6" class="w-full">
                <ElOption v-for="a in AUDIENCE_OPTIONS" :key="a" :label="a" :value="a" />
              </ElSelect>
            </ElFormItem>
            <div class="flex-c justify-end">
              <ElButton type="primary" class="w-22.5" @click="savePersona">保存人设</ElButton>
            </div>
          </ElForm>
        </div>

        <!-- 基本信息 -->
        <div class="art-card-sm my-5">
          <h1 class="p-4 text-xl font-normal border-b border-g-300">账号信息</h1>
          <ElForm :model="form" class="box-border p-5" label-width="86px" label-position="top">
            <ElRow>
              <ElFormItem label="登录名" class="w-[calc(50%-10px)]">
                <ElInput :model-value="userInfo.userName" disabled />
              </ElFormItem>
              <ElFormItem label="角色" class="ml-5 w-[calc(50%-10px)]">
                <ElInput :model-value="roleLabel" disabled />
              </ElFormItem>
            </ElRow>
            <ElRow>
              <ElFormItem label="邮箱" class="w-[calc(50%-10px)]">
                <ElInput :model-value="userInfo.email" disabled />
              </ElFormItem>
              <ElFormItem label="门店范围" class="ml-5 w-[calc(50%-10px)]">
                <ElInput :model-value="venueLabel" disabled />
              </ElFormItem>
            </ElRow>
            <ElFormItem label="个人介绍" prop="des" class="h-32">
              <ElInput
                type="textarea"
                :rows="4"
                v-model="form.des"
                placeholder="如：深耕瑜伽普拉提教学多年，专注体态改善与产后恢复的会员陪伴。"
              />
            </ElFormItem>
            <div class="flex-c justify-end">
              <ElButton type="primary" class="w-22.5" @click="saveProfile">保存</ElButton>
            </div>
          </ElForm>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'
  import { useStorage } from '@vueuse/core'
  import { PERSONA_ROLES, AUDIENCE_OPTIONS } from '@/api/ai'
  import type { MomentsPersona } from '@/api/ai'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'UserCenter' })

  const userStore = useUserStore()
  const userInfo = computed(() => userStore.getUserInfo)

  const ROLE_LABEL: Record<string, string> = {
    R_SUPER: '超管',
    R_MANAGER: '店长',
    R_TEACHER: '瑜伽/普拉提老师',
    R_MEDIA: '新媒体'
  }
  const roleLabel = computed(() => ROLE_LABEL[userInfo.value.roles?.[0] ?? ''] ?? '员工')
  const venueLabel = computed(() => {
    const v = userInfo.value.venues ?? []
    if (!v.length) return '双店'
    return v.join('、')
  })
  const roleTags = computed(() => {
    switch (userInfo.value.roles?.[0]) {
      case 'R_SUPER':
        return ['经营统筹', '会员维系', 'AI 工具', '品质把控']
      case 'R_MANAGER':
        return ['门店运营', '会员续费', '团队带教', '体验成交']
      case 'R_TEACHER':
        return ['瑜伽教学', '普拉提', '体态纠正', '呼吸与核心']
      case 'R_MEDIA':
        return ['内容创作', '私域运营', 'AI 写作', '留资增长']
      default:
        return ['一麦人']
    }
  })

  const persona = useStorage<MomentsPersona>('yimai-moments-persona', {
    name: '',
    gender: '女',
    age: '',
    role: '瑜伽普拉提教练',
    years: '',
    position: '',
    brand: '',
    city: '',
    audiences: ['上班族', '想改善体态的人']
  })

  const form = reactive({
    des: localStorage.getItem('yimai-profile-des') || '深耕瑜伽普拉提领域，专注体态改善与会员长期陪伴。'
  })

  function savePersona() {
    persona.value = { ...persona.value }
    ElMessage.success('人设已保存，营销工具生成时将自动带入')
  }

  function saveProfile() {
    localStorage.setItem('yimai-profile-des', form.des)
    ElMessage.success('已保存')
  }
</script>