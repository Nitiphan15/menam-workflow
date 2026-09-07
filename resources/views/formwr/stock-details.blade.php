@foreach ($r['stock_details'] as $stock)
    <div class="fw-semibold mb-2">{{ $stock['company'] }} · {{ $stock['item'] }} · {{ $stock['description'] }}</div>
    <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered bg-white mb-0">
            <thead><tr>
                <th>Heat / รายละเอียด MFG</th><th>วันที่รับเข้า</th>
                <th class="text-end">คงเหลือ (KG.)</th><th class="text-end">จองคงเหลือ (KG.)</th><th class="text-end">สุทธิ (KG.)</th>
            </tr></thead>
            <tbody>
                @foreach ($stock['heats'] as $heat)
                    <tr>
                        <td>
                            @if ($heat['orders'])
                                <a href="#wr-mfg-{{ $rowIndex }}-{{ $loop->parent->index }}-{{ $loop->index }}" class="text-primary">
                                    {{ \App\Services\FormWR\ReservedStockService::heatLabel($heat) }} ({{ count($heat['orders']) }} MFG)
                                </a>
                            @else
                                {{ \App\Services\FormWR\ReservedStockService::heatLabel($heat) }}
                            @endif
                        </td>
                        <td>{{ implode(', ', $heat['received_dates']) ?: '-' }}</td>
                        <td class="text-end">{{ number_format($heat['balance'], 2) }}</td>
                        <td class="text-end">{{ number_format($heat['reserved'], 2) }}</td>
                        <td class="text-end {{ $heat['net'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($heat['net'], 2) }}</td>
                    </tr>
                    @if ($heat['orders'])
                        <tr><td colspan="5">
                            <details id="wr-mfg-{{ $rowIndex }}-{{ $loop->parent->index }}-{{ $loop->index }}" class="wr-mfg-details">
                                <summary class="text-primary">ใบสั่งผลิต Heat {{ \App\Services\FormWR\ReservedStockService::heatLabel($heat) }}</summary>
                                <div class="table-responsive mt-2">
                                    <table class="table table-sm mb-0 small">
                                        <thead><tr><th>MFG</th><th>วันที่เปิด / กำหนด</th><th>คำสั่งขาย</th><th>ลูกค้า</th><th>สินค้า</th><th>สถานะ</th><th class="text-end">จำนวน MFG</th><th class="text-end">เบิกแล้ว</th><th class="text-end">จองคงเหลือ</th></tr></thead>
                                        <tbody>@foreach ($heat['orders'] as $order)
                                            <tr><td class="text-nowrap">{{ $order['mfg'] }}</td><td class="text-nowrap">{{ $order['order_date'] }}<br>{{ $order['due_date'] }}</td>
                                                <td>{{ $order['sales_order'] }}</td><td>{{ $order['customer'] }}</td><td>{{ $order['product'] }}<br>{{ $order['product_description'] }}</td><td class="text-nowrap">{{ $order['status'] }}</td>
                                                <td class="text-end">{{ number_format($order['planned'], 2) }}</td><td class="text-end">{{ number_format($order['issued'], 2) }}</td><td class="text-end fw-semibold">{{ number_format($order['reserved'], 2) }}</td>
                                            </tr>
                                        @endforeach</tbody>
                                    </table>
                                </div>
                            </details>
                        </td></tr>
                    @endif
                @endforeach
            </tbody>
            <tfoot class="fw-semibold"><tr><td colspan="2">รวม {{ $stock['company'] }}</td><td class="text-end">{{ number_format($stock['balance'], 2) }}</td><td class="text-end">{{ number_format($stock['reserved'], 2) }}</td><td class="text-end {{ $stock['net'] < 0 ? 'text-danger' : '' }}">{{ number_format($stock['net'], 2) }}</td></tr></tfoot>
        </table>
    </div>
@endforeach
