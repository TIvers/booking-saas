import asyncio
import unittest
from unittest.mock import AsyncMock, patch

import httpx

import app as worker


class TelegramWebhookTests(unittest.TestCase):
    def setUp(self):
        self.config = {
            "api_base": "https://tg-proxy.test",
            "bot_token": "123456789:test-token",
            "tenant_name": "Studio Oliva",
            "company_description": "Beauty studio",
            "contact_url": "https://studio.example",
            "mini_app_url": "https://booking.example/t/studio-oliva?tg_bot=tenant",
            "marketing_subscribed": False,
        }
        self.patches = [
            patch.object(worker, "load_bot_config", new_callable=AsyncMock, return_value=self.config),
            patch.object(worker, "forward_subscription_update", new_callable=AsyncMock),
            patch.object(worker, "update_marketing_subscription", new_callable=AsyncMock),
            patch.object(worker, "telegram_call", new_callable=AsyncMock),
        ]
        self.load_config, self.forward_subscription, self.update_marketing, self.telegram_call = [
            mocked.start() for mocked in self.patches
        ]
        self.addCleanup(self.stop_patches)

    def stop_patches(self):
        for mocked in self.patches:
            mocked.stop()

    def headers(self):
        return {"X-Telegram-Bot-Api-Secret-Token": "webhook-secret"}

    def post(self, path, headers, json):
        async def send():
            transport = httpx.ASGITransport(app=worker.app)
            async with httpx.AsyncClient(transport=transport, base_url="http://test") as client:
                return await client.post(path, headers=headers, json=json)

        return asyncio.run(send())

    def post_raw(self, path, headers, content):
        async def send():
            transport = httpx.ASGITransport(app=worker.app)
            async with httpx.AsyncClient(transport=transport, base_url="http://test") as client:
                return await client.post(path, headers=headers, content=content)

        return asyncio.run(send())

    def test_start_displays_company_details_and_mini_app_button(self):
        response = self.post(
            "/api/v1/telegram/python-webhook/7",
            self.headers(),
            {
                "message": {
                    "chat": {"id": 1234, "type": "private"},
                    "text": "/start",
                },
            },
        )

        self.assertEqual(response.status_code, 204)
        self.load_config.assert_awaited_once_with(7, "webhook-secret", 1234)
        payload = self.telegram_call.await_args.args[2]
        self.assertIn("Beauty studio", payload["text"])
        self.assertEqual(
            payload["reply_markup"]["inline_keyboard"][0][0]["web_app"]["url"],
            self.config["mini_app_url"],
        )

    def test_subscription_start_is_forwarded_to_laravel(self):
        raw_token = "A" * 48
        update = {
            "message": {
                "chat": {"id": 1234, "type": "private"},
                "text": f"/start {raw_token}",
            },
        }

        response = self.post(
            "/api/v1/telegram/python-webhook/7",
            self.headers(),
            update,
        )

        self.assertEqual(response.status_code, 204)
        self.forward_subscription.assert_awaited_once_with(7, "webhook-secret", update)
        self.telegram_call.assert_not_awaited()

    def test_marketing_subscription_updates_laravel_for_a_private_chat(self):
        response = self.post(
            "/api/v1/telegram/python-webhook/7",
            self.headers(),
            {
                "callback_query": {
                    "id": "callback-1",
                    "from": {"id": 1234},
                    "data": "marketing:subscribe",
                    "message": {
                        "chat": {"id": 1234, "type": "private"},
                    },
                },
            },
        )

        self.assertEqual(response.status_code, 204)
        self.update_marketing.assert_awaited_once_with(7, "webhook-secret", 1234, "subscribe")

    def test_group_marketing_callbacks_are_ignored(self):
        response = self.post(
            "/api/v1/telegram/python-webhook/7",
            self.headers(),
            {
                "callback_query": {
                    "id": "callback-2",
                    "from": {"id": 1234},
                    "data": "marketing:subscribe",
                    "message": {
                        "chat": {"id": -1001234, "type": "supergroup"},
                    },
                },
            },
        )

        self.assertEqual(response.status_code, 204)
        self.load_config.assert_not_awaited()
        self.update_marketing.assert_not_awaited()

    def test_malformed_json_is_ignored(self):
        response = self.post_raw(
            "/api/v1/telegram/python-webhook/7",
            self.headers(),
            b"{",
        )

        self.assertEqual(response.status_code, 204)
        self.load_config.assert_not_awaited()


if __name__ == "__main__":
    unittest.main()
