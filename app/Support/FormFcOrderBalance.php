<?php

namespace App\Support;

final class FormFcOrderBalance
{
    public static function calculate(
        float $onhand,
        float $fg,
        float $totalPo,
        float $wip,
        float $safetyForecast,
        float $forecast,
        float $salesOrder
    ): array {
        $supply = $onhand + $fg + $totalPo + $wip;
        $forecastAndSalesOrder = $forecast + $salesOrder;
        $demand = $safetyForecast + $forecastAndSalesOrder;
        $balance = $supply - $demand;

        return [
            'supply' => round($supply, 2),
            'forecast_and_sales_order' => round($forecastAndSalesOrder, 2),
            'demand' => round($demand, 2),
            'balance' => round($balance, 2),
            'shortage' => round(max(-$balance, 0), 2),
        ];
    }
}
