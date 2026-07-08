@php
    $currentStepNo = (int) ($currentStepNo ?? 0);
    $flowSteps = $flowSteps ?? [];
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:14px 0 4px;">
    <tr>
        @foreach ($flowSteps as $step)
            @php
                $stepNo = (int) ($step['step_no'] ?? 0);
                $isClosed = $currentStepNo === 999;
                $isCurrent = $stepNo === $currentStepNo;
                $isDone = $isClosed || ($stepNo !== 999 && $stepNo < $currentStepNo);
                $bg = $isCurrent ? '#fef3c7' : ($isDone ? '#dcfce7' : '#f1f5f9');
                $border = $isCurrent ? '#f59e0b' : ($isDone ? '#22c55e' : '#cbd5e1');
                $color = $isCurrent ? '#92400e' : ($isDone ? '#166534' : '#64748b');
                $mark = $isDone ? '&#10003;' : ($isCurrent ? '&#9679;' : '&nbsp;');
            @endphp
            <td width="23%" valign="top" style="padding:0 4px;">
                <div style="border:2px solid {{ $border }}; background:{{ $bg }}; color:{{ $color }}; border-radius:12px; padding:10px 8px; text-align:center; min-height:62px;">
                    <div style="font-size:15px; font-weight:700; line-height:1;">{!! $mark !!}</div>
                    <div style="font-size:12px; font-weight:700; line-height:1.35; margin-top:6px;">{{ $step['label'] ?? '-' }}</div>
                    @if ($isCurrent)
                        <div style="display:inline-block; background:#f59e0b; color:#ffffff; border-radius:999px; padding:2px 8px; font-size:10px; font-weight:700; margin-top:7px;">
                            CURRENT
                        </div>
                    @endif
                </div>
            </td>
            @if (!$loop->last)
                <td width="2%" valign="middle" style="color:#94a3b8; font-size:16px; text-align:center;">&#8250;</td>
            @endif
        @endforeach
    </tr>
</table>
