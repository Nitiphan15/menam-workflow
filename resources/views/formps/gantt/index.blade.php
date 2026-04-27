@extends('layouts.layout')
@section('title', 'TEst')
@section('page-title', 'Test')

@section('content')
    <div class="container-fluid">
        <h3 class="mb-3">PS – Plan Schedule Gantt (จาก Workorder เดิม)</h3>

        <form id="ps-filter-form" class="row g-2 mb-3">
            <div class="col-md-3">
                <label class="form-label small text-secondary">SKU</label>
                <input type="text" name="sku" class="form-control" placeholder="เช่น WIRE-XXX">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-secondary">Site</label>
                <select name="site" class="form-select">
                    <option value="ALL">ทุกไซต์</option>
                    <option value="MENAM WIRE">MENAM WIRE</option>
                    <option value="MENAM PLUS">MENAM PLUS</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-secondary">วันที่เปิดตั้งแต่</label>
                <input type="date" name="date_from" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-secondary">ถึง</label>
                <input type="date" name="date_to" class="form-control">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="button" id="btn-ps-load" class="btn btn-primary w-100">
                    โหลด Gantt
                </button>
            </div>
        </form>

        <div class="mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="zoom-out">-</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="zoom-in">+</button>
        </div>


        <div class="card">
            <div class="card-body">
                <div id="ps-gantt"></div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        /* ให้เต็ม card */
        #ps-gantt {
            width: 100%;
            height: 600px;
        }

        .task-station .gantt_task_line {
            background-color: #90caf9;
            border-color: #64b5f6;
        }

        .task-station .gantt_task_progress {
            background-color: #1e88e5;
            opacity: 0.95;
        }
    </style>
@endpush

@push('scripts')
    <script src="https://cdn.dhtmlx.com/gantt/edge/dhtmlxgantt.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ganttElem = document.getElementById('ps-gantt');

            // ถ้าอยาก read-only ก็เปิดบรรทัดนี้
            // gantt.config.readonly = true;

            gantt.config.date_format = "%Y-%m-%d %H:%i";
            gantt.config.progress_step = 0.01; // progress ละเอียด 1%
            gantt.config.round_dnd_dates = false;
            gantt.config.scroll_on_zoom = true;

            // ---------- Zoom levels ----------
            gantt.ext.zoom.init({
                levels: [{ // 1) ละเอียด: วัน + ชั่วโมง
                        name: "hours",
                        scale_height: 60,
                        min_column_width: 30,
                        scales: [{
                                unit: "day",
                                step: 1,
                                format: "%d %M"
                            },
                            {
                                unit: "hour",
                                step: 4,
                                format: "%H:%i"
                            }
                        ]
                    },
                    { // 2) ปกติ: วันอย่างเดียว
                        name: "days",
                        scale_height: 50,
                        min_column_width: 40,
                        scales: [{
                            unit: "day",
                            step: 1,
                            format: "%d %M"
                        }]
                    },
                    { // 3) หยาบ: สัปดาห์
                        name: "weeks",
                        scale_height: 50,
                        min_column_width: 50,
                        scales: [{
                                unit: "week",
                                step: 1,
                                format: "Week %W"
                            },
                            {
                                unit: "day",
                                step: 1,
                                format: "%d %M"
                            }
                        ]
                    }
                ]
            });

            // เริ่มต้นที่มุมมองรายวัน
            gantt.ext.zoom.setLevel("days");

            // ❌ ไม่ต้องใช้ config.subscales แล้ว เพราะใช้ scales ใน zoom แทน
            // gantt.config.scale_unit = "day";
            // gantt.config.subscales = [...]

            // ---------- Columns ----------
            gantt.config.columns = [{
                    name: "text",
                    label: "WO / Station",
                    tree: true,
                    width: "*"
                },
                {
                    name: "start_date",
                    label: "Start",
                    align: "center",
                    width: 110
                },
                {
                    name: "end_date",
                    label: "End",
                    align: "center",
                    width: 110
                }
            ];

            // label บนแท่ง
            gantt.templates.task_text = function(start, end, task) {
                if (task.type === "project") return "WO " + task.text;
                return task.text;
            };

            // class สำหรับแยกสี WO / station
            gantt.templates.task_class = function(start, end, task) {
                if (task.type === "project") return "task-wo";
                return "task-station";
            };

            gantt.init(ganttElem);

            // ---------- ปุ่มซูม ----------
            document.getElementById('zoom-in').addEventListener('click', function() {
                gantt.ext.zoom.zoomIn();
            });
            document.getElementById('zoom-out').addEventListener('click', function() {
                gantt.ext.zoom.zoomOut();
            });

            gantt.ext.zoom.attachEvent("onAfterZoom", function(level, config) {
                console.log("Zoom level:", level);
            });

            // ซูมด้วย scroll wheel
            ganttElem.addEventListener("wheel", function(e) {
                e.preventDefault(); // กันไม่ให้ scroll ธรรมดา
                if (e.deltaY < 0) gantt.ext.zoom.zoomIn();
                else gantt.ext.zoom.zoomOut();
            });

            // ---------- Load data ----------
            async function loadPsGantt() {
                const form = document.getElementById('ps-filter-form');
                const params = new URLSearchParams(new FormData(form));

                const res = await fetch('{{ route('ps.gantt.data') }}?' + params.toString());
                if (!res.ok) {
                    const text = await res.text();
                    console.error('Server error', res.status, text);
                    alert('โหลดข้อมูลไม่สำเร็จ (HTTP ' + res.status + ')');
                    return;
                }

                const payload = await res.json(); // {data:[...], links:[...]}
                console.log('gantt payload', payload);

                gantt.clearAll();
                gantt.parse(payload);
            }

            document.getElementById('btn-ps-load')
                .addEventListener('click', loadPsGantt);

            // ถ้าอยาก auto load ตอนเปิดหน้า:
            // loadPsGantt();

        });
    </script>
@endpush
