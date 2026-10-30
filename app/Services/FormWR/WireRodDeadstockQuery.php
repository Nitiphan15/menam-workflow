<?php

namespace App\Services\FormWR;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WireRodDeadstockQuery
{
    private string $connection;

    public function __construct()
    {
        $this->connection = config('database.workflow_connection', 'sqlsrv_menam');
    }

    public function current(array $filters = []): Builder
    {
        $q = DB::connection($this->connection)->table('wr_ds_current_items as c')
            ->join('wr_ds_item_lifecycles as lifecycle', 'lifecycle.id', '=', 'c.lifecycle_id')
            ->where('lifecycle.is_current', 1)
            ->select('c.*');

        foreach (['site_code' => 'site', 'material_group' => 'material_group', 'stock_status' => 'stock_status', 'effective_division_code' => 'division', 'trace_status' => 'trace_status', 'display_unit' => 'unit'] as $column => $input) {
            if (filled($filters[$input] ?? null)) $q->where("c.{$column}", $filters[$input]);
        }
        if (filled($filters['item'] ?? null)) {
            $term = '%'.mb_strtoupper(trim((string) $filters['item'])).'%';
            $q->where(fn ($sub) => $sub->whereRaw('UPPER(c.part_number) LIKE ?', [$term])
                ->orWhereRaw('UPPER(c.part_description) LIKE ?', [$term]));
        }
        if (filled($filters['part_number_exact'] ?? null)) $q->where('c.part_number', $filters['part_number_exact']);
        if (filled($filters['serial'] ?? null)) $q->whereRaw('UPPER(c.serial_number) LIKE ?', ['%'.mb_strtoupper(trim((string) $filters['serial'])).'%']);
        if (filled($filters['customer'] ?? null)) {
            $term = '%'.mb_strtoupper(trim((string) $filters['customer'])).'%';
            $q->where(function ($customerQuery) use ($term) {
                $customerQuery->whereRaw('UPPER(c.customer_json) LIKE ?', [$term])
                    ->orWhereExists(fn ($extra) => $extra->selectRaw('1')->from('wr_ds_customer_additions as customer_filter')
                        ->whereColumn('customer_filter.lifecycle_id', 'c.lifecycle_id')->where('customer_filter.is_active', 1)
                        ->whereRaw('UPPER(customer_filter.customer_name) LIKE ?', [$term]));
            });
        }
        if (filled($filters['action_status'] ?? null)) {
            $status = $filters['action_status'];
            $matchingReview = fn ($sub) => $sub->selectRaw('1')->from('wr_ds_item_reviews as review_filter')
                ->whereColumn('review_filter.lifecycle_id', 'c.lifecycle_id')->where('review_filter.review_status', $status);
            if ($status === 'open') {
                $q->where(fn ($sub) => $sub->whereExists($matchingReview)->orWhereNotExists(
                    fn ($review) => $review->selectRaw('1')->from('wr_ds_item_reviews as any_review')->whereColumn('any_review.lifecycle_id', 'c.lifecycle_id')
                ));
            } else {
                $q->whereExists($matchingReview);
            }
        }
        if (filled($filters['follow_up'] ?? null)) {
            $today = now('Asia/Bangkok')->toDateString();
            $nextThreeDays = now('Asia/Bangkok')->addDays(3)->toDateString();
            $followUp = $filters['follow_up'];
            $q->whereExists(function ($review) use ($followUp, $today, $nextThreeDays) {
                $review->selectRaw('1')->from('wr_ds_item_reviews as follow_up_filter')
                    ->whereColumn('follow_up_filter.lifecycle_id', 'c.lifecycle_id')
                    ->whereNotNull('follow_up_filter.next_follow_up_date');
                match ($followUp) {
                    'overdue' => $review->whereDate('follow_up_filter.next_follow_up_date', '<', $today),
                    'today' => $review->whereDate('follow_up_filter.next_follow_up_date', '=', $today),
                    'next_3_days' => $review->whereDate('follow_up_filter.next_follow_up_date', '>', $today)
                        ->whereDate('follow_up_filter.next_follow_up_date', '<=', $nextThreeDays),
                    default => $review->whereRaw('1 = 0'),
                };
            });
        }
        if (filled($filters['purchase_from'] ?? null)) $q->whereDate('c.purchase_date', '>=', $filters['purchase_from']);
        if (filled($filters['purchase_to'] ?? null)) $q->whereDate('c.purchase_date', '<=', $filters['purchase_to']);
        if (filter_var($filters['became_dead'] ?? false, FILTER_VALIDATE_BOOLEAN)) $q->where('c.became_dead_stock', 1);

        return $q;
    }
}
