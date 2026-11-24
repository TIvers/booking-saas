<?php

/**
 * ОПИСАНИЕ ФАЙЛА: запись клиента. Поля: starts_at, ends_at (UTC, с учётом буфера), status (enum),
 * expires_at (конец резерва), cancelled_at. Связи: staff, service, customer, payment.
 * Scope active(): статусы pending и confirmed.
 */
