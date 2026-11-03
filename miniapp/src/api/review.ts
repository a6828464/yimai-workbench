import { http } from '@/utils/http'

/** GET /post-class-reviews：课后分析列表 */
export function fetchReviews(params?: Record<string, unknown>) {
  return http.get<Record<string, unknown>>('/post-class-reviews', params)
}

/** GET /post-class-reviews/pending-count：tab 角标 */
export function fetchReviewPendingCount() {
  return http.get<{ count?: number } | number>('/post-class-reviews/pending-count', undefined, {
    silent: true
  })
}

/** GET /training-plans：我的训练计划 */
export function fetchTrainingPlans(params?: Record<string, unknown>) {
  return http.get<unknown[]>('/training-plans', params)
}
