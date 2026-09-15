<!-- 登录页面 -->
<template>
  <div class="flex w-full h-screen">
    <LoginLeftView />

    <div class="relative flex-1">
      <AuthTopBar />

      <div class="auth-right-wrap">
        <div class="form">
          <div class="flex items-center gap-3 mb-8">
            <img src="@imgs/yimai-logo.png" alt="一麦" class="w-12 h-12 object-contain" />
            <div>
              <h3 class="title !mt-0">{{ AppConfig.systemInfo.name }}</h3>
              <p class="sub-title">双店内部经营与服务执行平台</p>
            </div>
          </div>

          <ElForm ref="formRef" :model="formData" :rules="rules" @keyup.enter="handleSubmit">
            <ElFormItem prop="username">
              <ElInput
                class="custom-height"
                placeholder="请输入用户名"
                v-model.trim="formData.username"
                size="large"
              />
            </ElFormItem>
            <ElFormItem prop="password">
              <ElInput
                class="custom-height"
                placeholder="请输入密码"
                v-model="formData.password"
                type="password"
                autocomplete="off"
                show-password
                size="large"
              />
            </ElFormItem>

            <div style="margin-top: 24px">
              <ElButton
                class="w-full custom-height"
                type="primary"
                @click="handleSubmit"
                :loading="loading"
                v-ripple
              >
                登 录
              </ElButton>
            </div>

            <div class="mt-6 text-xs leading-5 text-gray-400">
              支持角色：超管 / 店长 / 老师 / 新媒体 · 登录后自动进入对应工作台
            </div>
          </ElForm>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import AppConfig from '@/config'
  import { useUserStore } from '@/store/modules/user'
  import { HttpError } from '@/utils/http/error'
  import { fetchGetUserInfo, fetchLogin } from '@/api/auth'
  import { ElMessage, ElNotification, type FormInstance, type FormRules } from 'element-plus'

  defineOptions({ name: 'Login' })

  // 滑动验证已移除：原先必须拖动滑块才放行，对内部系统没有价值且挡住登录

  const userStore = useUserStore()
  const router = useRouter()
  const route = useRoute()

  const formRef = ref<FormInstance>()

  const formData = reactive({
    username: '',
    password: ''
  })

  const rules = computed<FormRules>(() => ({
    username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
    password: [{ required: true, message: '请输入密码', trigger: 'blur' }]
  }))

  const loading = ref(false)

  /** 登录后按角色与门店权限决定落地页 */
  function resolveLanding(roles: string[], venues: string[], venue: string | null): string {
    if (
      (roles.includes('R_SERVICE') || roles.includes('R_TEACHER')) &&
      venues.length > 1 &&
      !venue
    ) {
      return '/yimai/store-select'
    }
    return '/yimai/today'
  }

  const handleSubmit = async () => {
    if (!formRef.value) return

    try {
      const valid = await formRef.value.validate()
      if (!valid) return

      loading.value = true

      const { username, password } = formData
      const { token, refreshToken } = await fetchLogin({ userName: username, password })

      if (!token) {
        throw new Error('Login failed - no token received')
      }

      userStore.setToken(token, refreshToken)
      userStore.setLoginStatus(true)

      const info = await fetchGetUserInfo()
      userStore.setUserInfo(info)
      userStore.checkAndClearWorktabs()

      showLoginSuccessNotice(info.userName)

      const redirect = route.query.redirect as string
      router.push(
        redirect || resolveLanding(info.roles ?? [], info.venues ?? [], info.venue ?? null)
      )
    } catch (error) {
      if (error instanceof HttpError) {
        ElMessage.error(error.message)
      } else {
        ElMessage.error('登录失败，请稍后重试')
        console.error('[Login] Unexpected error:', error)
      }
    } finally {
      loading.value = false
    }
  }

  // 登录成功提示
  const showLoginSuccessNotice = (name: string) => {
    setTimeout(() => {
      ElNotification({
        title: '登录成功',
        type: 'success',
        duration: 2500,
        zIndex: 10000,
        message: `${name}，欢迎回来！`
      })
    }, 800)
  }
</script>

<style scoped>
  @import './style.css';
</style>
