<?php

/**
 * ОПИСАНИЕ ФАЙЛА: миграция bookings. Поля с timestamptz, индексы (tenant_id, staff_id, starts_at) и (status, expires_at).
 * Расширение btree_gist и EXCLUDE-констрейнт bookings_no_overlap с условием WHERE по активным статусам.
 */
