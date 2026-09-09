import App from './App.vue'
import { createApp } from 'vue'
import { initStore } from './store'                 // Store
import { initRouter } from './router'               // Router
import language from './locales'                    // 国际化
import '@styles/core/tailwind.css'                  // tailwind
import '@styles/index.scss'                         // 样式
import '@utils/sys/console.ts'                      // 控制台输出内容
import { setupGlobDirectives } from './directives'
import { setupErrorHandle } from './utils/sys/error-handle'
import { clearBusinessPiiStorage, useUserStore } from './store/modules/user'
import { useTrainingStore } from './store/modules/training'
import { registerSessionExpiredHandler } from './api/backend'
import { registerSessionLifecycleHandlers } from './utils/session-lifecycle'

document.addEventListener(
  'touchstart',
  function () {},
  { passive: false }
)

const app = createApp(App)
initStore(app)
initRouter(app)
registerSessionLifecycleHandlers({
  resetSensitiveState: () => {
    useTrainingStore().reset()
    // 切换账号/会话失效时同步清理可能含 PII 的持久化业务缓存（含谈单/训练计划按用户键）
    clearBusinessPiiStorage()
  },
  onUserChange: (userId) => useTrainingStore().loadForUser(userId)
})
registerSessionExpiredHandler(() => useUserStore().logOut())
setupGlobDirectives(app)
setupErrorHandle(app)

app.use(language)
app.mount('#app')
