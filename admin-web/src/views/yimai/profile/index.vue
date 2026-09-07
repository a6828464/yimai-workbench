<template>
  <div class="p-4">
    <ElRow :gutter="12">
      <!-- 左：账号总览 -->
      <ElCol :xs="24" :md="8" class="mb-3">
        <ElCard shadow="never">
          <div class="flex flex-col items-center py-2">
            <div class="relative">
              <div
                class="w-24 h-24 rounded-full overflow-hidden bg-theme/10 flex-c border-2 border-white dark:border-gray-700 shadow"
              >
                <img
                  v-if="form.avatar"
                  :src="form.avatar"
                  class="w-full h-full object-cover"
                  alt="头像"
                />
                <i v-else class="ri-user-smile-line text-5xl text-theme" />
              </div>
              <span
                class="absolute -bottom-1 -right-1 w-7 h-7 rounded-full bg-theme text-white flex-c cursor-pointer shadow"
                title="更换头像"
                @click="fileInput?.click()"
              >
                <i class="ri-camera-line" />
              </span>
              <input
                ref="fileInput"
                type="file"
                accept="image/*"
                class="hidden"
                @change="pickAvatar"
              />
            </div>
            <div class="mt-3 text-base font-600 text-g-900">{{ form.name || '未设置姓名' }}</div>
            <div class="mt-1 flex items-center gap-2">
              <ElTag size="small" type="primary">{{ roleLabel }}</ElTag>
              <ElTag v-for="v in form.venues" :key="v" size="small" type="success">{{ v }}</ElTag>
            </div>
            <div class="mt-2 text-xs text-g-500">{{ form.email }}</div>
            <div class="mt-0.5 text-xs text-g-500">头像将同步显示在右上角账号菜单与朋友圈预览</div>
          </div>
          <ElDivider />
          <div class="text-xs text-g-500 leading-6">
            <p
              ><i
                class="ri-information-line mr-1 text-theme"
              />姓名由超管在「人员管理」统一维护，如需改名请联系管理员。</p
            >
            <p
              ><i
                class="ri-links-line mr-1 text-theme"
              />这里的性别、年龄、从业年限与「专业」会被营销工具（发圈人设/小红书）自动取用。</p
            >
          </div>
        </ElCard>
      </ElCol>

      <!-- 右：个人信息 + 账号安全 -->
      <ElCol :xs="24" :md="16">
        <ElCard shadow="never" class="mb-3">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">我的信息</span>
              <ElButton size="small" type="primary" :loading="saving" @click="save">保存</ElButton>
            </div>
          </template>
          <ElForm label-width="90px" label-position="left">
            <ElRow :gutter="12">
              <ElCol :xs="12"
                ><ElFormItem label="姓名"><ElInput :model-value="form.name" disabled /></ElFormItem
              ></ElCol>
              <ElCol :xs="12">
                <ElFormItem label="手机号">
                  <ElInput v-model="form.phone" maxlength="20" placeholder="用于客户联系，选填" />
                </ElFormItem>
              </ElCol>
              <ElCol :xs="8">
                <ElFormItem label="性别">
                  <ElSelect v-model="form.gender"
                    ><ElOption label="女" value="女" /><ElOption label="男" value="男"
                  /></ElSelect>
                </ElFormItem>
              </ElCol>
              <ElCol :xs="8"
                ><ElFormItem label="年龄"
                  ><ElInput v-model="form.age" maxlength="3" placeholder="如：28" /></ElFormItem
              ></ElCol>
              <ElCol :xs="8"
                ><ElFormItem label="从业年限"
                  ><ElInput v-model="form.years" maxlength="10" placeholder="如：5年" /></ElFormItem
              ></ElCol>
              <ElCol :span="24">
                <ElFormItem label="专业">
                  <ElSelect
                    v-model="form.specialties"
                    multiple
                    filterable
                    allow-create
                    default-first-option
                    collapse-tags
                    class="w-full"
                    placeholder="普拉提/瑜伽/康复理疗…可多选、可自定义"
                  >
                    <ElOption v-for="s in SPECIALTY_OPTIONS" :key="s" :label="s" :value="s" />
                  </ElSelect>
                </ElFormItem>
              </ElCol>
            </ElRow>
          </ElForm>
        </ElCard>

        <ElCard shadow="never">
          <template #header><span class="font-500">账号安全</span></template>
          <div class="flex items-center flex-wrap gap-3">
            <div class="text-sm text-g-600">登录密码：******</div>
            <ElButton size="small" plain @click="pwdVisible = true">修改密码</ElButton>
          </div>
          <p class="mt-2 text-xs text-g-400">修改密码后，除当前设备外其他设备将自动下线。</p>
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 修改密码 -->
    <ElDialog v-model="pwdVisible" title="修改密码" width="380px" append-to-body>
      <ElForm label-width="80px" label-position="top">
        <ElFormItem label="当前密码"
          ><ElInput v-model="pwd.old" type="password" show-password
        /></ElFormItem>
        <ElFormItem label="新密码"
          ><ElInput v-model="pwd.next" type="password" show-password placeholder="至少 8 位"
        /></ElFormItem>
        <ElFormItem label="确认新密码"
          ><ElInput v-model="pwd.confirm" type="password" show-password @keyup.enter="submitPwd"
        /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="pwdVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="pwdSaving" @click="submitPwd">确认修改</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import type { MyProfile } from '@/api/my'
  import { defaultMyProfile, fetchMyProfile, saveMyProfile, changeMyPassword } from '@/api/my'
  import { SPECIALTY_OPTIONS } from '@/api/ai'
  import { useUserStore } from '@/store/modules/user'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiProfile' })

  const userStore = useUserStore()
  const form = ref<MyProfile>(defaultMyProfile())
  const saving = ref(false)
  const fileInput = ref<HTMLInputElement>()

  const ROLE_LABELS: Record<string, string> = {
    R_SUPER: '超管',
    R_MANAGER: '店长',
    R_TEACHER: '老师',
    R_MEDIA: '新媒体'
  }
  const roleLabel = computed(() => ROLE_LABELS[form.value.role] ?? form.value.role ?? '—')

  onMounted(async () => {
    const p = await fetchMyProfile(true)
    // 姓名/邮箱以后端账号信息为准（兼容旧会话缓存）
    const info = userStore.getUserInfo
    form.value = { ...p, name: info.userName || p.name, email: info.email || p.email }
  })

  /** 选择头像 → canvas 压缩为 128px jpeg data URI（不落盘，需点保存） */
  async function pickAvatar(e: Event) {
    const input = e.target as HTMLInputElement
    const file = input.files?.[0]
    input.value = ''
    if (!file) return
    if (!file.type.startsWith('image/')) {
      ElMessage.warning('请选择图片文件')
      return
    }
    try {
      form.value.avatar = await compressAvatar(file)
      ElMessage.success('头像已就绪，点击「保存」生效')
    } catch {
      ElMessage.error('图片处理失败，请换一张图片')
    }
  }

  function compressAvatar(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file)
      const img = new Image()
      img.onload = () => {
        const size = 128
        const canvas = document.createElement('canvas')
        canvas.width = canvas.height = size
        const ctx = canvas.getContext('2d')
        if (!ctx) {
          URL.revokeObjectURL(url)
          reject(new Error('canvas unsupported'))
          return
        }
        ctx.fillStyle = '#ffffff'
        ctx.fillRect(0, 0, size, size)
        const ratio = Math.min(size / img.width, size / img.height)
        const w = img.width * ratio
        const h = img.height * ratio
        ctx.drawImage(img, (size - w) / 2, (size - h) / 2, w, h)
        URL.revokeObjectURL(url)
        resolve(canvas.toDataURL('image/jpeg', 0.82))
      }
      img.onerror = () => {
        URL.revokeObjectURL(url)
        reject(new Error('image load fail'))
      }
      img.src = url
    })
  }

  async function save() {
    saving.value = true
    try {
      await saveMyProfile({
        phone: form.value.phone,
        avatar: form.value.avatar,
        gender: form.value.gender,
        age: form.value.age,
        years: form.value.years,
        specialties: form.value.specialties
      })
      // 联动顶栏/账号菜单头像（刷新 /me 的本地副本）
      userStore.setUserInfo({
        ...userStore.getUserInfo,
        avatar: form.value.avatar || undefined
      } as Api.Auth.UserInfo)
      ElMessage.success('已保存')
    } catch (e) {
      ElMessage.error(`保存失败：${String(e).slice(0, 60)}`)
    } finally {
      saving.value = false
    }
  }

  // ---------- 修改密码 ----------
  const pwdVisible = ref(false)
  const pwdSaving = ref(false)
  const pwd = reactive({ old: '', next: '', confirm: '' })

  async function submitPwd() {
    if (!pwd.old) return ElMessage.warning('请输入当前密码')
    if (pwd.next.length < 8) return ElMessage.warning('新密码至少 8 位')
    if (pwd.next !== pwd.confirm) return ElMessage.warning('两次输入的新密码不一致')
    pwdSaving.value = true
    try {
      await changeMyPassword(pwd.old, pwd.next)
      pwdVisible.value = false
      pwd.old = pwd.next = pwd.confirm = ''
      ElMessage.success('密码已修改，其他设备需重新登录')
    } catch (e) {
      ElMessage.error(
        String(
          (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? e
        ).slice(0, 60)
      )
    } finally {
      pwdSaving.value = false
    }
  }
</script>
