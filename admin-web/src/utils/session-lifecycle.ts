type UserChangeHandler = (userId: string) => void

let resetSensitiveState: (() => void) | undefined
let userChangeHandler: UserChangeHandler | undefined

export function registerSessionLifecycleHandlers(handlers: {
  resetSensitiveState: () => void
  onUserChange: UserChangeHandler
}) {
  resetSensitiveState = handlers.resetSensitiveState
  userChangeHandler = handlers.onUserChange
}

export function notifySessionReset() {
  resetSensitiveState?.()
}

export function notifyUserChange(userId: string) {
  userChangeHandler?.(userId)
}
