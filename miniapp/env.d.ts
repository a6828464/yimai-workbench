/// <reference types="vite/client" />
/// <reference types="@dcloudio/types" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<{}, {}, any>
  export default component
}

interface ImportMetaEnv {
  /** Laravel API 基址（生产：https://oa.nbyimai.com/api） */
  readonly VITE_API_BASE?: string
  /** 微信授权登录开关：'true' 才显示入口（后端 wx-login 上线且服务器已配好密钥后开启） */
  readonly VITE_WX_LOGIN_ENABLED?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
