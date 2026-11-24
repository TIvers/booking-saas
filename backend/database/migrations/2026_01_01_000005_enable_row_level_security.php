<?php

/**
 * ОПИСАНИЕ ФАЙЛА: включает Row Level Security на tenant-таблицах и создаёт политики
 * USING (tenant_id = current_setting('app.tenant_id')::bigint).
 */
