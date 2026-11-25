interface TelegramWebApp {
  initData: string
  initDataUnsafe: {
    start_param?: string
    user?: {
      id: number
      first_name: string
      last_name?: string
      username?: string
    }
  }
  ready(): void
  expand(): void
  openTelegramLink(url: string): void
}

interface Window {
  Telegram?: {
    WebApp: TelegramWebApp
  }
}
