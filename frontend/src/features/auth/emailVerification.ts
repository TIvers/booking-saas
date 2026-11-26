export type EmailVerificationChannel = 'code' | 'link'

export function preferredEmailVerificationChannel(): EmailVerificationChannel {
  const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
    || window.Telegram?.WebApp?.initData !== undefined

  return isMobile ? 'code' : 'link'
}
