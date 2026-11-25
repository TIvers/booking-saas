import os
import re
from json import JSONDecodeError

import httpx
from fastapi import FastAPI, Header, HTTPException, Request, Response

BACKEND_URL = os.environ.get("TELEGRAM_BACKEND_URL", "http://backend-nginx").rstrip("/")
START_TOKEN = re.compile(r"^/start(?:@\w+)?\s+([A-Za-z0-9_-]{32,64})$")
STOP_COMMAND = re.compile(r"^/stop(?:@\w+)?$")
START_COMMAND = re.compile(r"^/start(?:@\w+)?(?:\s+.*)?$")
ABOUT_COMMAND = re.compile(r"^/about(?:@\w+)?$")

app = FastAPI()


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/api/v1/telegram/python-webhook/{tenant_id}")
@app.post("/api/v1/telegram/webhook/{tenant_id}")
async def webhook(
    tenant_id: int,
    request: Request,
    telegram_secret: str | None = Header(
        default=None,
        alias="X-Telegram-Bot-Api-Secret-Token",
    ),
) -> Response:
    if not telegram_secret:
        raise HTTPException(status_code=403, detail="Invalid webhook secret.")

    try:
        update = await request.json()
    except JSONDecodeError:
        return Response(status_code=204)

    if not isinstance(update, dict):
        return Response(status_code=204)

    message = update.get("message")
    chat_id = private_chat_id(message)
    if chat_id is not None:
        text = message.get("text")
        if not isinstance(text, str):
            return Response(status_code=204)

        is_subscription_start = START_TOKEN.fullmatch(text) is not None
        is_stop = STOP_COMMAND.fullmatch(text) is not None
        is_start = START_COMMAND.fullmatch(text) is not None
        is_about = ABOUT_COMMAND.fullmatch(text) is not None
        if not (is_subscription_start or is_stop or is_start or is_about):
            return Response(status_code=204)

        config = await load_bot_config(tenant_id, telegram_secret, chat_id)

        if is_subscription_start:
            await forward_subscription_update(tenant_id, telegram_secret, update)
        elif is_stop:
            await forward_subscription_update(tenant_id, telegram_secret, update)
        elif is_start or is_about:
            await send_company_info(config, chat_id)

        return Response(status_code=204)

    callback = update.get("callback_query")
    if not isinstance(callback, dict):
        return Response(status_code=204)
    callback_message = callback.get("message")
    callback_chat_id = private_chat_id(callback_message)
    if callback_chat_id is None:
        return Response(status_code=204)

    callback_data = callback.get("data")
    if not isinstance(callback_data, str) or callback_data not in {
        "about",
        "marketing:subscribe",
        "marketing:unsubscribe",
    }:
        return Response(status_code=204)

    config = await load_bot_config(tenant_id, telegram_secret, callback_chat_id)
    callback_id = callback.get("id")
    sender = callback.get("from")
    sender_id = sender.get("id") if isinstance(sender, dict) else None

    if callback_id:
        await telegram_call(config, "answerCallbackQuery", {"callback_query_id": callback_id})

    if callback_data == "about":
        await telegram_call(config, "sendMessage", {
            "chat_id": callback_chat_id,
            "text": company_info_text(config),
        })
    elif callback_data in {"marketing:subscribe", "marketing:unsubscribe"}:
        if sender_id != callback_chat_id:
            return Response(status_code=204)

        action = "subscribe" if callback_data.endswith(":subscribe") else "unsubscribe"
        await update_marketing_subscription(
            tenant_id,
            telegram_secret,
            callback_chat_id,
            action,
        )
        config["marketing_subscribed"] = action == "subscribe"
        await telegram_call(config, "sendMessage", {
            "chat_id": callback_chat_id,
            "text": (
                "Вы подписались на новости организации."
                if action == "subscribe"
                else "Вы отписались от новостей организации."
            ),
            "reply_markup": welcome_keyboard(config),
        })

    return Response(status_code=204)


def private_chat_id(message: object) -> int | None:
    if not isinstance(message, dict):
        return None

    chat = message.get("chat")
    if not isinstance(chat, dict) or chat.get("type") != "private":
        return None

    chat_id = chat.get("id")
    return chat_id if isinstance(chat_id, int) and not isinstance(chat_id, bool) and chat_id > 0 else None


async def load_bot_config(tenant_id: int, secret: str, chat_id: int) -> dict:
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            response = await client.get(
                f"{BACKEND_URL}/api/v1/telegram/worker/config/{tenant_id}",
                params={"chat_id": chat_id},
                headers={
                    "X-Tenant": str(tenant_id),
                    "X-Telegram-Bot-Api-Secret-Token": secret,
                },
            )
            response.raise_for_status()
            return response.json()["data"]
    except httpx.HTTPStatusError as error:
        raise HTTPException(
            status_code=error.response.status_code,
            detail="Bot configuration is unavailable.",
        ) from None
    except (httpx.HTTPError, KeyError, ValueError):
        raise HTTPException(status_code=503, detail="Bot configuration is unavailable.") from None


async def forward_subscription_update(tenant_id: int, secret: str, update: dict) -> None:
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            response = await client.post(
                f"{BACKEND_URL}/api/v1/telegram/internal-webhook/{tenant_id}",
                json=update,
                headers={
                    "X-Tenant": str(tenant_id),
                    "X-Telegram-Bot-Api-Secret-Token": secret,
                },
            )
            response.raise_for_status()
    except httpx.HTTPError:
        raise HTTPException(status_code=502, detail="Unable to process notification subscription.") from None


async def update_marketing_subscription(
    tenant_id: int,
    secret: str,
    chat_id: int,
    action: str,
) -> None:
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            response = await client.post(
                f"{BACKEND_URL}/api/v1/telegram/worker/marketing/{tenant_id}",
                json={"chat_id": chat_id, "action": action},
                headers={
                    "X-Tenant": str(tenant_id),
                    "X-Telegram-Bot-Api-Secret-Token": secret,
                },
            )
            response.raise_for_status()
    except httpx.HTTPError:
        raise HTTPException(status_code=502, detail="Unable to update marketing subscription.") from None


def company_info_text(config: dict) -> str:
    parts = [config["tenant_name"]]
    description = config.get("company_description")
    if description:
        parts.append(description)
    contact_url = config.get("contact_url")
    if contact_url:
        parts.append(f"Контакты: {contact_url}")
    return "\n\n".join(parts)


def welcome_keyboard(config: dict) -> dict:
    marketing_text = (
        "Отписаться от новостей"
        if config.get("marketing_subscribed")
        else "Подписаться на новости"
    )
    marketing_action = (
        "marketing:unsubscribe"
        if config.get("marketing_subscribed")
        else "marketing:subscribe"
    )
    return {
        "inline_keyboard": [
            [{
                "text": "Открыть онлайн-запись",
                "web_app": {"url": config["mini_app_url"]},
            }],
            [{"text": "О компании", "callback_data": "about"}],
            [{"text": marketing_text, "callback_data": marketing_action}],
        ],
    }


async def send_company_info(config: dict, chat_id: int) -> None:
    await telegram_call(config, "sendMessage", {
        "chat_id": chat_id,
        "text": company_info_text(config),
        "reply_markup": welcome_keyboard(config),
    })


async def telegram_call(config: dict, method: str, payload: dict) -> dict:
    api_base = str(config.get("api_base", "")).rstrip("/")
    token = config.get("bot_token")
    if not api_base or not isinstance(token, str) or not token:
        raise HTTPException(status_code=503, detail="Telegram API is not configured.")

    try:
        async with httpx.AsyncClient(timeout=10) as client:
            response = await client.post(
                f"{api_base}/bot{token}/{method}",
                json=payload,
            )
            response.raise_for_status()
            result = response.json()
    except (httpx.HTTPError, ValueError):
        raise HTTPException(status_code=502, detail="Telegram API request failed.") from None

    if not isinstance(result, dict) or not result.get("ok"):
        raise HTTPException(status_code=502, detail="Telegram API rejected the request.")

    return result
