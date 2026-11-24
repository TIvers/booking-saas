# ADR-0002: Защита от двойного бронирования на уровне БД

<!--
ОПИСАНИЕ ФАЙЛА: Architecture Decision Record.
Решение: EXCLUDE USING gist (btree_gist) по staff_id и tstzrange(starts_at, ends_at).
Почему не только lockForUpdate(), как ловится SQLSTATE 23P01 и превращается в SlotAlreadyTakenException (HTTP 409),
как проверяется конкурентным тестом.
-->
