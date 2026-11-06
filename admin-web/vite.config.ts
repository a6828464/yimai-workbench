import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import { fileURLToPath } from 'url'
import vueDevTools from 'vite-plugin-vue-devtools'
import viteCompression from 'vite-plugin-compression'
import Components from 'unplugin-vue-components/vite'
import AutoImport from 'unplugin-auto-import/vite'
import ElementPlus from 'unplugin-element-plus/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import tailwindcss from '@tailwindcss/vite'
import { keepyogaProxy } from './vite-plugins/keepyoga'
// import { visualizer } from 'rollup-plugin-visualizer'

export default ({ mode }: { mode: string }) => {
  const root = process.cwd()
  const env = loadEnv(mode, root)
  // 加载全部环境变量（含非 VITE_ 前缀的服务端凭据，仅存在于 Node 侧）
  const serverEnv = loadEnv(mode, root, '')
  if (serverEnv.KY_PHONE) process.env.KY_PHONE = serverEnv.KY_PHONE
  if (serverEnv.KY_PASSWORD) process.env.KY_PASSWORD = serverEnv.KY_PASSWORD
  const { VITE_VERSION, VITE_PORT, VITE_BASE_URL, VITE_API_URL, VITE_API_PROXY_URL } = env

  console.log(`🚀 API_URL = ${VITE_API_URL}`)
  console.log(`🚀 VERSION = ${VITE_VERSION}`)

  return defineConfig({
    define: {
      __APP_VERSION__: JSON.stringify(VITE_VERSION)
    },
    base: VITE_BASE_URL,
    server: {
      port: Number(VITE_PORT),
      proxy: {
        '/api': {
          target: VITE_API_PROXY_URL,
          changeOrigin: true
        }
      },
      host: true
    },
    // 路径别名
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
        '@views': resolvePath('src/views'),
        '@imgs': resolvePath('src/assets/images'),
        '@icons': resolvePath('src/assets/icons'),
        '@utils': resolvePath('src/utils'),
        '@stores': resolvePath('src/store'),
        '@styles': resolvePath('src/assets/styles')
      }
    },
    build: {
      target: 'es2015',
      outDir: 'dist',
      chunkSizeWarningLimit: 2000,
      minify: 'terser',
      terserOptions: {
        compress: {
          // 生产环境去除 console
          drop_console: true,
          // 生产环境去除 debugger
          drop_debugger: true
        }
      },
      rollupOptions: {
        output: {
          // 大体积供应商独立分包：主业务改动时浏览器可继续命中缓存，
          // 避免 element-plus/echarts 全量打进单个 1.4MB 主 chunk
          //
          // ⚠️ 这里**故意不写 `return 'vendor'` 兜底**（t12 改）：
          // 兜底会把所有没被显式归类的 node_modules 一锅塞进 vendor，而 vendor 是
          // index.html 里 modulepreload 的首屏 chunk。最典型的受害者是 crypto-js
          // （PBKDF2/HMAC，620KB 源）——它唯一的调用方是懒加载的 art-screen-lock，
          // 却因为兜底被拉到首屏（vendor 305.5KB / 106.6KB gz）。
          // 兜底去掉后 rollup 按真实引用关系切分：只被懒加载组件引用的依赖会跟着
          // 那个懒 chunk 走，不再污染首屏。真需要再拆时按包名显式加分支。
          manualChunks(id: string) {
            if (!id.includes('node_modules')) return undefined
            // ⚠️ highlight.js 已**整体摘除**（t12 改），这里不再有「动态 import 的
            // highlight.js 要放行」这种分支 —— 原来那行 `if (id.includes('highlight.js'))
            // return undefined` 是死代码：`directives/business/highlight.ts` 现在不 import
            // 任何 highlight.js，全仓唯一的提及是那段「本指令不做语法高亮」的注释。
            // 保留一行假注释比留着 bug 更贵：下一个人会照着它去找那个不存在的动态 import。
            // 若将来真要恢复语法高亮，正确做法是 highlight.js/lib/core + 按语言注册
            // （见 highlight.ts 抬头注释），届时再按需加回分支。
            if (id.includes('echarts') || id.includes('zrender')) return 'echarts'
            if (
              id.includes('element-plus') ||
              id.includes('@element-plus') ||
              id.includes('lodash-es')
            )
              return 'element-plus'
            if (
              id.includes('/vue/') ||
              id.includes('/@vue/') ||
              id.includes('vue-router') ||
              id.includes('pinia') ||
              id.includes('vue-demi') ||
              // destr / deep-pick-omit（pinia-plugin-persistedstate 依赖）：
              // 与 vue-vendor 同包，避免 vue-vendor→vendor→vue-vendor
              // 循环 chunk 导致 TDZ 报错白屏
              id.includes('destr') ||
              id.includes('deep-pick-omit')
            )
              return 'vue-vendor'
            return undefined
          }
        }
      },
      dynamicImportVarsOptions: {
        warnOnError: true,
        exclude: [],
        include: ['src/views/**/*.vue']
      }
    },
    plugins: [
      vue(),
      tailwindcss(),
      keepyogaProxy(),
      // 自动按需导入 API
      AutoImport({
        imports: ['vue', 'vue-router', 'pinia', '@vueuse/core'],
        dts: 'src/types/import/auto-imports.d.ts',
        resolvers: [ElementPlusResolver()],
        eslintrc: {
          enabled: true,
          filepath: './.auto-import.json',
          globalsPropValue: true
        }
      }),
      // 自动按需导入组件
      Components({
        dts: 'src/types/import/components.d.ts',
        resolvers: [ElementPlusResolver()]
      }),
      // 按需定制主题配置
      ElementPlus({
        useSource: true
      }),
      // 压缩
      viteCompression({
        verbose: false, // 是否在控制台输出压缩结果
        disable: false, // 是否禁用
        algorithm: 'gzip', // 压缩算法
        ext: '.gz', // 压缩后的文件名后缀
        threshold: 10240, // 只有大小大于该值的资源会被处理 10240B = 10KB
        deleteOriginFile: false // 压缩后是否删除原文件
      }),
      vueDevTools()
      // 打包分析
      // visualizer({
      //   open: true,
      //   gzipSize: true,
      //   brotliSize: true,
      //   filename: 'dist/stats.html' // 分析图生成的文件名及路径
      // }),
    ],
    // 依赖预构建：避免运行时重复请求与转换，提升首次加载速度
    optimizeDeps: {
      include: [
        'echarts/core',
        'echarts/charts',
        'echarts/components',
        'echarts/renderers',
        'xlsx',
        'xgplayer',
        'crypto-js',
        'file-saver',
        'vue-img-cutter',
        'element-plus/es',
        'element-plus/es/components/*/style/css',
        'element-plus/es/components/*/style/index'
      ]
    },
    css: {
      preprocessorOptions: {
        // sass variable and mixin
        scss: {
          additionalData: `
            @use "@styles/core/el-light.scss" as *; 
            @use "@styles/core/mixin.scss" as *;
            @use "@styles/core/breakpoint.scss" as *;
          `
        }
      },
      postcss: {
        plugins: [
          {
            postcssPlugin: 'internal:charset-removal',
            AtRule: {
              charset: (atRule) => {
                if (atRule.name === 'charset') {
                  atRule.remove()
                }
              }
            }
          }
        ]
      }
    }
  })
}

function resolvePath(paths: string) {
  return path.resolve(__dirname, paths)
}
