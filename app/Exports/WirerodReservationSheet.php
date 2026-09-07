<?php

namespace App\Exports;

use App\Services\FormWR\ReservedStockService;
use Maatwebsite\Excel\Concerns\{FromArray, WithHeadings, WithTitle, WithCustomValueBinder, ShouldAutoSize};
use PhpOffice\PhpSpreadsheet\Cell\{Cell, DataType, DefaultValueBinder};

class WirerodReservationSheet extends DefaultValueBinder implements FromArray, WithHeadings, WithTitle, WithCustomValueBinder, ShouldAutoSize
{
    public function __construct(private array $report, private bool $orders = false) {}

    public function title(): string
    {
        return $this->orders ? 'MFG Reservations' : 'Stock by Heat';
    }

    public function headings(): array
    {
        $common = ['Company', 'Item', 'Description', 'Heat'];
        return array_merge($common, $this->orders
            ? ['MFG', 'Order Date', 'Due Date', 'Sales Order', 'Customer', 'Product', 'Product Description', 'Status', 'MFG Qty (KG.)', 'Issued (KG.)', 'Reserved Remaining (KG.)', 'Retrieved At (Bangkok)']
            : ['Received Dates', 'Balance (KG.)', 'Reserved Remaining (KG.)', 'Net (KG.)', 'Retrieved At (Bangkok)']);
    }

    public function array(): array
    {
        $rows = [];
        foreach ($this->report['items'] as $item) {
            foreach ($item['heats'] as $heat) {
                $common = [$item['company'], $item['item'], $item['description'], ReservedStockService::heatLabel($heat)];
                if ($this->orders) {
                    foreach ($heat['orders'] as $order) {
                        $rows[] = array_merge($common, [
                            $order['mfg'], $order['order_date'], $order['due_date'], $order['sales_order'],
                            $order['customer'], $order['product'], $order['product_description'], $order['status'],
                            $order['planned'], $order['issued'], $order['reserved'], $this->report['retrieved_at'],
                        ]);
                    }
                } else {
                    $rows[] = array_merge($common, [implode(', ', $heat['received_dates']), $heat['balance'],
                        $heat['reserved'], $heat['net'], $this->report['retrieved_at']]);
                }
            }
        }
        return $rows;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);
            return true;
        }
        return parent::bindValue($cell, $value);
    }
}
