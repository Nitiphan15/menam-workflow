<?php

use Illuminate\Support\Collection;
use Illuminate\Pagination\AbstractPaginator;

if (! function_exists('normalize_rows')) {
    /**
     * Normalize any result (Collection/array/stdClass/paginator/null)
     * into a plain array of rows. Empty -> [].
     *
     * @param mixed $result
     * @return array
     */
    function normalize_rows($result): array
    {
        // 0) ว่างทุกรูปแบบ -> []
        if (empty($result)) {
            return [];
        }

        // 1) Laravel Collection -> array ของ items
        if ($result instanceof Collection) {
            return $result->all();
        }

        // 2) Laravel Paginator (LengthAware/simple)
        if ($result instanceof AbstractPaginator) {
            return $result->items();
        }

        // 3) array
        if (is_array($result)) {
            // { data: [...] }
            if (isset($result['data']) && is_array($result['data'])) {
                return $result['data'];
            }

            // PHP < 8.1 ไม่มี array_is_list() -> เขียนเองเล็กน้อย
            $isList = function (array $arr): bool {
                $i = 0;
                foreach (array_keys($arr) as $k) {
                    if ($k !== $i++) return false;
                }
                return true;
            };

            // ถ้าเป็น list (หลายแถว) ก็ส่งกลับเลย, ถ้าเป็น associative -> หุ้มเป็น 1 แถว
            return $isList($result) ? $result : [$result];
        }

        // 4) stdClass / scalar -> หุ้มเป็น 1 แถว
        return [$result];
    }
}

if (! function_exists('normalize_collect')) {
    /**
     * เหมือน normalize_rows แต่คืนเป็น Illuminate\Support\Collection
     * และกรองค่า null ออก (กัน sum/loop พัง)
     *
     * @param mixed $result
     * @return \Illuminate\Support\Collection
     */
    function normalize_collect($result): Collection
    {
        return collect(normalize_rows($result))
            ->filter(fn($row) => !is_null($row))
            ->values();
    }
}
