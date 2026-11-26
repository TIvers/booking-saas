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

interface TelegramLoginResult {
  id_token?: string
  error?: string
}

interface Window {
  Telegram?: {
    WebApp?: TelegramWebApp
    Login?: {
      auth: (
        options: {
          client_id: string
          nonce: string
          scope: string[]
          lang: string
        },
        callback: (result: TelegramLoginResult) => void,
      ) => void
    }
  }
}
