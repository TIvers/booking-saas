<?php

/**
 * ОПИСАНИЕ ФАЙЛА: создание записи-резерва.
 * В транзакции: проверка, что слот входит в сгенерированные, вставка pending-записи с expires_at.
 * Нарушение exclusion constraint (SQLSTATE 23P01) превращается в SlotAlreadyTakenException.
 * После успеха диспатчит событие BookingCreated.
 */
