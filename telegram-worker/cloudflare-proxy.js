const TELEGRAM_API_HOST = "api.telegram.org";
const WEBHOOK_ORIGIN = "https://booking.iversdev.ru";
const WEBHOOK_PATH = /^\/api\/v1\/telegram\/python-webhook\/[1-9]\d*$/;

export default {
  async fetch(request) {
    const url = new URL(request.url);

    if (WEBHOOK_PATH.test(url.pathname)) {
      if (request.method !== "POST") {
        return new Response("Method not allowed", { status: 405 });
      }

      const target = new URL(`${url.pathname}${url.search}`, WEBHOOK_ORIGIN);
      return fetch(new Request(target.toString(), request));
    }

    url.protocol = "https:";
    url.hostname = TELEGRAM_API_HOST;
    url.port = "";
    return fetch(new Request(url.toString(), request));
  },
};
