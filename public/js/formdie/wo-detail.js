(function () {
    "use strict";

    const out = document.getElementById("wdContent");

    function esc(s) {
        if (s === null || s === undefined) return "";
        return String(s).replace(
            /[&<>"']/g,
            (c) =>
                ({
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                })[c],
        );
    }
    function num(v, d = 0) {
        if (v == null || v === "") return "-";
        return Number(v).toLocaleString("en-US", {
            minimumFractionDigits: d,
            maximumFractionDigits: d || 3,
        });
    }
    function dateOnly(s) {
        return s ? String(s).substring(0, 10) : "";
    }
    function dateTimeShort(s) {
        if (!s) return "";
        const text = String(s).replace("T", " ");
        return text.substring(0, 16);
    }
    function receiveWindowText(step) {
        const start = dateTimeShort(step.receive_start);
        const end = dateTimeShort(step.receive_end);
        if (!start && !end) return "-";
        if (!end || start === end) return start || end;
        return `${start} -> ${end}`;
    }

    function setEmpty(msg) {
        out.innerHTML = `<div class="de-empty"><div class="icon"><i class="fa fa-circle-info"></i></div><div class="text">${esc(msg)}</div></div>`;
    }
    function updateUrl(wo) {
        const next = new URLSearchParams();
        if (wo) next.set("wo", wo);
        window.history.replaceState(
            null,
            "",
            window.location.pathname + (wo ? "?" + next.toString() : ""),
        );
    }
    function setLoading() {
        out.innerHTML = `
            <div class="de-card"><div class="de-card-body">
                <div class="de-skel lg" style="width:40%;"></div>
                <div class="de-skel" style="width:60%;"></div>
                <div class="row g-2 mt-2">
                    <div class="col"><div class="de-skel"></div></div>
                    <div class="col"><div class="de-skel"></div></div>
                    <div class="col"><div class="de-skel"></div></div>
                    <div class="col"><div class="de-skel"></div></div>
                </div>
            </div></div>
            <div class="row g-3 mb-3">
                ${[0, 1, 2, 3, 4].map(() => '<div class="col"><div class="de-kpi"><div class="de-skel" style="width:60%;"></div><div class="de-skel lg"></div></div></div>').join("")}
            </div>
        `;
    }

    function checkSpec(val, oper, upper, lower) {
        if (val == null || oper == null) return null;
        const u = Number(upper);
        const l = Number(lower);
        const v = Number(val);
        switch (oper) {
            case "LE":
                return v <= u;
            case "GE":
                return v >= l;
            case "GT":
                return v > l;
            case "BTW":
                return v >= l && v <= u;
            case "NL":
                return null;
            default:
                return null;
        }
    }

    function renderHeader(d) {
        const h = d.header || {};
        const dashUrl =
            window.WD_DASHBOARD +
            "?mode=workorder&wo=" +
            encodeURIComponent(d.wo || "");
        return `
            <div class="de-card">
                <div class="de-card-header">
                    <span><i class="fa fa-file-lines me-2 text-primary"></i>${esc(d.wo)} <span class="text-muted small">${esc(h.fcat || "")} ${esc(h.fsize || "")}</span></span>
                    <span class="small text-muted">Sales Order: ${esc(h.ordnumber || "-")} · Brand: ${esc(h.brand || "-")} · Cust ID: ${esc(h.customer_id || "-")}</span>
                </div>
                <div class="de-card-body">
                    <div class="mb-2" style="display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                        <a class="btn btn-sm btn-outline-primary" href="${dashUrl}">
                            <i class="fa fa-chart-line me-1"></i>แดชบอร์ด
                        </a>
                        <a class="btn btn-sm btn-outline-secondary" href="${window.WD_MASTER}">
                            <i class="fa fa-database me-1"></i>ทะเบียน DIE
                        </a>
                    </div>
                    <div class="row g-2">
                        <div class="col"><div class="de-sub-card"><div class="title">วันที่เปิด</div><div>${dateOnly(h.dateopen)}</div></div></div>
                        <div class="col"><div class="de-sub-card"><div class="title">วันที่แผน</div><div>${dateOnly(h.plandate)}</div></div></div>
                        <div class="col"><div class="de-sub-card"><div class="title">วันที่ต้องการ</div><div>${dateOnly(h.reqdate)}</div></div></div>
                        <div class="col"><div class="de-sub-card"><div class="title">ยอดแผน (kg)</div><div>${num(h.plan_qty)}</div></div></div>
                        <div class="col"><div class="de-sub-card"><div class="title">ลำดับความสำคัญ</div><div>${esc(h.priority ?? "-")}</div></div></div>
                        <div class="col"><div class="de-sub-card"><div class="title">อนุมัติ</div><div>${h.approved ? '<i class="fa fa-check-circle text-success"></i>' : '<i class="fa fa-circle-xmark text-danger"></i>'}</div></div></div>
                    </div>
                    ${h.notes ? `<div class="alert alert-warning mt-2 mb-0 small" style="white-space:pre-wrap;"><i class="fa fa-note-sticky me-2"></i>${esc(h.notes)}</div>` : ""}
                </div>
            </div>
        `;
    }

    function renderKpi(d) {
        const s = d.summary || {};
        const yieldKpi =
            s.yield_pct >= 95
                ? "kpi-green"
                : s.yield_pct >= 85
                  ? "kpi-amber"
                  : "kpi-red";
        const statusMap = {
            closed: { kpi: "kpi-green", label: "ปิดงานแล้ว" },
            done: { kpi: "kpi-green", label: "ผลิตเสร็จ " },
            in_progress: { kpi: "", label: "กำลังผลิต" },
            pending: { kpi: "kpi-amber", label: "รอเริ่ม" },
        };
        const st = statusMap[s.wo_status] || statusMap.pending;
        const varTxt =
            s.last_variance_pct != null
                ? `<div class="sub">Last step ${s.last_variance_pct}% off</div>`
                : "";
        return `
            <div class="row g-3 mb-3">
                <div class="col"><div class="de-kpi ${st.kpi}"><div class="label">Status</div><div class="value" style="font-size:1.1rem;">${esc(st.label)}</div>${varTxt}</div></div>
                <div class="col"><div class="de-kpi"><div class="label">แผน (kg)</div><div class="value">${num(s.plan_qty)}</div></div></div>
                <div class="col"><div class="de-kpi kpi-green"><div class="label">FG (kg)</div><div class="value">${num(s.fg_total)}</div></div></div>
                <div class="col"><div class="de-kpi kpi-red"><div class="label">NCR (kg)</div><div class="value">${num(s.ncr_total)}</div></div></div>
                <div class="col"><div class="de-kpi ${yieldKpi}"><div class="label">ยีลด์ %</div><div class="value">${s.yield_pct ?? 0}%</div></div></div>
            </div>
        `;
    }

    function renderBom(d) {
        const bom = d.bom || [];
        if (!bom.length) return "";
        const rows = bom
            .map(
                (b) => `
            <tr>
                <td><b>${esc(b.partnumber || b.parts_id)}</b>${b.part_description ? `<div class="small text-muted">${esc(b.part_description)}</div>` : ""}</td>
                <td>${esc(b.fgrade)}</td>
                <td>${esc(b.fsize)}</td>
                <td>${esc(b.fheatno)}</td>
                <td>${esc(b.fcoilno)}</td>
                <td>${esc(b.fsupplier)}</td>
                <td class="num">${num(b.qty)}</td>
            </tr>`,
            )
            .join("");
        return `
            <div class="de-card">
                <div class="de-card-header"><span><i class="fa fa-box me-2 text-primary"></i>BOM (วัตถุดิบที่วางแผน)</span></div>
                <table class="de-table">
                    <thead><tr><th>พาร์ท</th><th>เกรด</th><th>ขนาด</th><th>Heat No</th><th>Coil No</th><th>ซัพพลายเออร์</th><th class="num">จำนวน</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    function renderMatUsage(d) {
        const m = d.mat_usage || [];
        if (!m.length) return "";
        const rows = m
            .map(
                (r) => `
            <tr>
                <td><b>${esc(r.partnumber || r.parts_id)}</b>${r.part_description ? `<div class="small text-muted">${esc(r.part_description)}</div>` : ""}</td>
                <td class="num">${num(r.qty)}</td>
                <td>${esc(r.unit || "")}</td>
                <td>${esc(r.docnumber)}</td>
                <td>${esc(r.requestedby)}</td>
                <td>${esc(r.requeststamp)}</td>
                <td>${esc(r.warehousenumber)}</td>
            </tr>`,
            )
            .join("");
        return `
            <div class="de-card">
                <div class="de-card-header"><span><i class="fa fa-truck me-2 text-success"></i>Material Usage (วัตถุดิบที่เบิกใช้จริง)</span></div>
                <table class="de-table">
                    <thead><tr><th>พาร์ท</th><th class="num">จำนวน</th><th>หน่วย</th><th>Doc#</th><th>ผู้เบิก</th><th>เวลา</th><th>คลัง</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    function renderRoutingQuickActions(d) {
        const steps = d.steps || [];
        if (!steps.length) return "";
        const done = steps.filter((s) => s.step_status === "done").length;
        const inProgress = steps.filter(
            (s) => s.step_status === "in_progress",
        ).length;
        const pending = steps.filter((s) => s.step_status === "pending").length;
        const buttons = steps
            .map(
                (step) => `
            <button type="button" class="wd-route-btn ${esc(step.step_status)}" data-step="${esc(step.workseq)}"
                    title="${esc(step.workcenter || "")} - ${esc(step.workcenter_desc || "")}">
                ${esc(step.workseq)} · ${esc(step.workcenter || "")}
            </button>
        `,
            )
            .join("");
        const claim = d.customer_claim || {};
        const claimClass =
            claim.status === "CLAIM"
                ? "claim"
                : claim.status === "ERROR"
                  ? "pending"
                  : "done";
        const claimButton = `
            <button type="button" class="wd-route-btn ${claimClass}" data-claim="1"
                    title="สถานะ Customer Claim หลัง PACK">
                Customer Claim · ${esc(claim.label || "ยังไม่พบ Claim")}
            </button>
        `;

        return `
            <div class="de-card">
                <div class="de-card-header">
                    <span><i class="fa fa-route me-2 text-primary"></i>รูตติ้ง (${steps.length} ขั้นตอน)</span>
                    <span class="small text-muted">เสร็จ ${done} · กำลังทำ ${inProgress} · รอ ${pending}</span>
                </div>
                <div class="de-card-body">
                    <div class="wd-route-actions">${buttons}${claimButton}</div>
                </div>
            </div>
        `;
    }

    function renderCustomerClaim(d) {
        const claim = d.customer_claim || {};
        const rows = claim.rows || [];
        const statusClass =
            claim.status === "CLAIM"
                ? "danger"
                : claim.status === "ERROR"
                  ? "warn"
                  : "ok";
        const tableRows = rows
            .map(
                (row) => `
            <tr>
                <td>${esc(dateOnly(row.transdate))}</td>
                <td><b>${esc(row.returnnumber || "-")}</b></td>
                <td>${esc(row.invnumber || "-")}</td>
                <td>${esc(row.customer_name || "-")}<div class="small text-muted">${esc(row.customernumber || "")}</div></td>
                <td>${esc(row.item_description || "-")}</td>
                <td class="num">${num(Math.abs(Number(row.qty || 0)), 2)}</td>
                <td class="num">${num(row.sellprice, 2)}</td>
                <td class="num">${num(row.amount, 2)}</td>
                <td>${esc(dateOnly(row.returnpaiddate)) || "-"}</td>
                <td style="min-width:260px; white-space:normal;">${esc(row.notes || "-")}</td>
            </tr>
        `,
            )
            .join("");

        return `
            <div class="de-card wd-step-target" id="wd-customer-claim">
                <div class="de-card-header">
                    <span><i class="fa fa-box-open me-2 text-danger"></i>หลัง PACK · Customer Claim</span>
                    <span class="de-chip ${statusClass}">${esc(claim.label || "ยังไม่พบ Claim")}</span>
                </div>
                ${
                    claim.status === "ERROR"
                        ? `
                    <div class="de-card-body text-danger">ตรวจสอบข้อมูล Claim ไม่สำเร็จ</div>
                `
                        : rows.length
                          ? `
                    <div class="de-card-body tight">
                        <div class="small text-muted mb-2">
                            Sales Order: <b>${esc(claim.sales_order || "-")}</b>
                            · ${Number(claim.claim_count || 0).toLocaleString()} Claim
                            · Qty ${num(claim.claim_qty, 2)}
                            · Amount ${num(claim.claim_amount, 2)}
                        </div>
                    </div>
                    <div style="overflow:auto;">
                        <table class="de-table" style="min-width:1150px;">
                            <thead><tr><th>วันที่</th><th>Claim No.</th><th>Invoice</th><th>Customer</th><th>Item</th><th class="num">Qty</th><th class="num">Sell Price</th><th class="num">Amount</th><th>Paid Date</th><th>รายละเอียด</th></tr></thead>
                            <tbody>${tableRows}</tbody>
                        </table>
                    </div>
                `
                          : `
                    <div class="de-card-body text-muted">
                        ยังไม่พบ Customer Claim ของ Sales Order <b>${esc(claim.sales_order || "-")}</b>
                    </div>
                `
                }
            </div>
        `;
    }

    function machineSettingForBlock(machines, blockNo) {
        const key = String(blockNo);
        for (let i = (machines || []).length - 1; i >= 0; i--) {
            const m = machines[i];
            const powder = m.powder?.[key] || "";
            const oil = m.oil?.[key] || "";
            const outSize = m.osize_actual?.[key];
            if (powder || oil || outSize != null) {
                return {
                    powder,
                    oil,
                    outSize,
                    speed: m.speed,
                    resin: m.resin_perc,
                    temp: m.temp,
                };
            }
        }
        return null;
    }

    function renderBlockMachineSetting(setting) {
        if (!setting) return "";
        const parts = [];
        if (setting.powder) parts.push(`ผงรีด ${esc(setting.powder)}`);
        if (setting.oil) parts.push(`น้ำมัน ${esc(setting.oil)}`);
        if (setting.outSize != null)
            parts.push(`ขนาดออก ${num(setting.outSize, 3)} mm`);
        const title = [
            setting.speed != null ? `สปีด ${num(setting.speed, 2)}` : "",
            setting.resin != null ? `Resin ${num(setting.resin, 1)}%` : "",
            setting.temp != null ? `อุณหภูมิ ${num(setting.temp, 0)}` : "",
        ]
            .filter(Boolean)
            .join(" / ");
        return `<div class="small text-muted mt-1" title="${esc(title || "ค่าตั้งเครื่องของ block นี้")}">
            <i class="fa fa-gear me-1"></i>${parts.join(" / ")}
        </div>`;
    }

    function renderBlocks(blocks, isDrawing, machines = []) {
        if (!isDrawing) return ""; // ไม่ใช่แผนกรีด — ซ่อน block grid
        if (!blocks.length)
            return '<div style="color:#94a3b8; font-size:12px;">ยังไม่ระบุ block ใน step นี้</div>';
        return (
            `<div class="wd-blocks">` +
            blocks
                .map((b) => {
                    const machineSetting = machineSettingForBlock(
                        machines,
                        b.block_no,
                    );
                    const dies = (b.dies || [])
                        .map(
                            (d) => `
                <span class="die-chip" title="${esc(d.die_description)} (ordered ${esc(d.ordered_size ?? d.size_in)} / present ${esc(d.present_diameter ?? d.size_out)})${d.transdate ? " · " + esc(dateOnly(d.transdate)) : ""}${d.inferred_block ? " · inferred block" : ""}"
                      onclick="window.location='${window.WD_MASTER}?tab=master&q=${encodeURIComponent(d.equipnumber)}'">${esc(d.equipnumber)}</span>
            `,
                        )
                        .join("");
                    return `
                <div class="wd-block">
                    <div class="no">Block ${b.block_no}</div>
                    <div class="size">${b.size_plan ? num(b.size_plan, 3) + " mm" : "-"}</div>
                    <div class="mat">${esc(b.material || "-")}</div>
                    ${renderBlockMachineSetting(machineSetting)}
                    ${dies ? `<div class="dies">${dies}</div>` : ""}
                </div>
            `;
                })
                .join("") +
            `</div>`
        );
    }

    function renderMachineTests(machines, seq) {
        if (!machines.length) return "";
        const COLLAPSE_LIMIT = 5;
        const collapsible = machines.length > COLLAPSE_LIMIT;
        const rows = machines
            .map((m, idx) => {
                const powders = Object.values(m.powder)
                    .filter(Boolean)
                    .slice(0, 11)
                    .map((p) => `<span style="color:#7c3aed;">${esc(p)}</span>`)
                    .join(" / ");
                const oils = Object.values(m.oil)
                    .filter(Boolean)
                    .slice(0, 11)
                    .map((o) => `<span style="color:#ea580c;">${esc(o)}</span>`)
                    .join(" / ");
                const sizes = Object.values(m.osize_actual)
                    .filter((v) => v != null)
                    .slice(0, 11)
                    .map((s) => num(s, 3))
                    .join(" → ");
                const extraCls =
                    collapsible && idx >= COLLAPSE_LIMIT ? " wd-row-extra" : "";
                return `
                <tr class="${extraCls.trim()}">
                    <td>${esc(dateOnly(m.transdate))}</td>
                    <td><b>${esc(m.machine_number || "-")}</b>${m.machine_desc ? `<div class="small text-muted">${esc(m.machine_desc)}</div>` : ""}</td>
                    <td class="num">${num(m.speed, 2)}</td>
                    <td class="num">${num(m.resin_perc, 1)}</td>
                    <td class="num">${num(m.temp, 0)}</td>
                    <td>${powders || "-"}</td>
                    <td>${oils || "-"}</td>
                    <td>${sizes || "-"}</td>
                </tr>
            `;
            })
            .join("");
        const toggle = collapsible
            ? `
            <button type="button" class="wd-q-toggle" data-qseq="m${esc(seq)}">
                <i class="fa fa-chevron-down me-1"></i><span>ดูทั้งหมด (${machines.length} แถว)</span>
            </button>
        `
            : "";
        return `
            <div class="wd-q-table mt-3${collapsible ? " wd-q-collapsed" : ""}" data-qwrap="m${esc(seq)}" data-qcount="${machines.length}" data-qunit="แถว">
                <div class="text-muted small fw-bold mb-1"><i class="fa fa-gear me-1"></i>ค่าตั้งเครื่อง${collapsible ? ` <span class="fw-normal">· แสดง ${COLLAPSE_LIMIT}/${machines.length}</span>` : ""}</div>
                <div style="overflow:auto;">
                    <table class="de-table" style="min-width:900px;">
                        <thead><tr><th>วันที่</th><th>Machine</th><th class="num">สปีด</th><th class="num">Resin %</th><th class="num">อุณหภูมิ</th><th>ผงรีดต่อ block</th><th>น้ำมันต่อ block</th><th>ขนาดออก (mm)</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                ${toggle}
            </div>
        `;
    }

    // รายชื่อเครื่องใน station — แสดงเป็น chip อ่านง่าย (เลขเครื่องตัวหนา + คำอธิบายตัวเล็ก) ย่อถ้าเกิน 8
    function renderStationMachines(step) {
        const list = (step.station_machines || []).filter(
            (m) => m && (m.number || m.label || m.description),
        );
        if (!list.length) return "";
        const LIMIT = 8;
        const collapsible = list.length > LIMIT;
        const chips = list
            .map((m, idx) => {
                const no = esc(m.number || m.label || "");
                const desc = m.number ? esc(m.description || "") : "";
                const extraCls =
                    collapsible && idx >= LIMIT ? " wd-row-extra" : "";
                return `<span class="wd-machine-chip${extraCls}" title="${esc(m.label || m.description || "")}"><b>${no}</b>${desc ? `<small>${desc}</small>` : ""}</span>`;
            })
            .join("");
        const toggle = collapsible
            ? `
            <button type="button" class="wd-q-toggle" data-qseq="sm${esc(step.workseq)}">
                <i class="fa fa-chevron-down me-1"></i><span>ดูทั้งหมด (${list.length} เครื่อง)</span>
            </button>
        `
            : "";
        return `
            <div class="wd-q-table mb-2${collapsible ? " wd-q-collapsed" : ""}" data-qwrap="sm${esc(step.workseq)}" data-qcount="${list.length}" data-qunit="เครื่อง">
                <div class="text-muted small fw-bold mb-1"><i class="fa fa-industry me-1"></i>เครื่องใน station (${list.length})</div>
                <div class="wd-machine-list">${chips}</div>
                ${toggle}
            </div>
        `;
    }

    function renderQualityTests(tests, spec, seq) {
        if (!tests.length) return "";
        const qvLabels = spec?.qv || {};
        const labelKeys = Object.keys(qvLabels)
            .map(Number)
            .sort((a, b) => a - b);
        if (!labelKeys.length) return "";

        const COLLAPSE_LIMIT = 5;
        const collapsible = tests.length > COLLAPSE_LIMIT;

        const headerCells = labelKeys
            .map((i) => {
                const lab = qvLabels[i];
                const specTxt =
                    lab.oper === "NL"
                        ? ""
                        : ` (${esc(lab.oper)} ${esc(lab.lower ?? "")}/${esc(lab.upper ?? "")})`;
                const title =
                    lab.oper === "NL"
                        ? "ไม่มีเกณฑ์ limit จึงแสดงค่าโดยไม่ลงสีผ่าน/ไม่ผ่าน"
                        : `พื้นหลังสีเขียวคือค่านี้ผ่าน spec: ${lab.oper || ""} ${lab.lower ?? ""}/${lab.upper ?? ""}`;
                return `<th title="${esc(title)}">${esc(lab.text)}<br><span class="text-muted small fw-normal text-lowercase">${specTxt}</span></th>`;
            })
            .join("");

        const rows = tests
            .map((t, idx) => {
                const cells = labelKeys
                    .map((i) => {
                        const v = t.qv[i];
                        const lab = qvLabels[i];
                        const ok = checkSpec(v, lab.oper, lab.upper, lab.lower);
                        const cls =
                            ok === true ? "pass" : ok === false ? "fail" : "";
                        const title =
                            ok === true
                                ? "ค่านี้ผ่าน spec ของช่องนี้"
                                : ok === false
                                  ? "ค่านี้ไม่ผ่าน spec ของช่องนี้"
                                  : "ไม่มี spec หรือไม่มีค่าให้เทียบ";
                        return `<td class="num ${cls}" title="${esc(title)}">${v != null ? num(v, 3) : "-"}</td>`;
                    })
                    .join("");
                const approveTitle = t.approved
                    ? `QC ตรวจแล้ว${t.qc_name ? " โดย " + t.qc_name : ""}${t.qctime ? " เวลา " + dateTimeShort(t.qctime) : ""}`
                    : "ยังไม่มีผู้อนุมัติ QC (qc_id/qctime) สีเขียวของแต่ละช่องหมายถึงค่านั้นผ่าน spec เท่านั้น";
                const extraCls =
                    collapsible && idx >= COLLAPSE_LIMIT ? " wd-row-extra" : "";
                return `
                <tr class="${extraCls.trim()}">
                    <td>${esc(dateOnly(t.transdate))}</td>
                    <td>${esc(t.docnumber)}</td>
                    <td><span class="de-chip ${t.approved ? "ok" : "danger"}" title="${esc(approveTitle)}">${t.approved ? "✓" : "✗"}</span></td>
                    ${cells}
                </tr>
            `;
            })
            .join("");

        const toggle = collapsible
            ? `
            <button type="button" class="wd-q-toggle" data-qseq="q${esc(seq)}">
                <i class="fa fa-chevron-down me-1"></i><span>ดูทั้งหมด (${tests.length} แถว)</span>
            </button>
        `
            : "";

        return `
            <div class="wd-q-table mt-3${collapsible ? " wd-q-collapsed" : ""}" data-qwrap="q${esc(seq)}" data-qcount="${tests.length}" data-qunit="แถว">
                <div class="text-muted small fw-bold mb-1">
                    <i class="fa fa-flask me-1"></i>ผลทดสอบคุณภาพ (ค่าที่ QC รับเข้า)${collapsible ? ` <span class="fw-normal">· แสดง ${COLLAPSE_LIMIT}/${tests.length}</span>` : ""}
                </div>
                <div style="overflow:auto;">
                    <table class="de-table" style="min-width:600px;">
                        <thead><tr><th>วันที่</th><th>Doc#</th><th title="สถานะ approve ของใบ QC ทั้งใบ แยกจากสีเขียว/แดงของค่ารายช่อง">QC Approve</th>${headerCells}</tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                ${toggle}
            </div>
        `;
    }

    function stepStatusChip(s) {
        const map = {
            done: { cls: "ok", txt: "เสร็จ" },
            in_progress: { cls: "info", txt: "กำลังผลิต" },
            pending: { cls: "muted", txt: "รอเริ่ม" },
        };
        const m = map[s] || map.pending;
        return `<span class="de-chip ${m.cls}" title="รับได้ตั้งแต่ 98% ของยอด step ก่อนหน้า (step แรกเทียบยอด WO ที่เปิด); เกินถือว่าเสร็จ">${m.txt}</span>`;
    }

    function renderStep(step) {
        const machineCount = (step.station_machines || []).filter(
            (m) => m && (m.number || m.label || m.description),
        ).length;
        const machineBadge = machineCount
            ? `<span class="de-chip ok ms-1" title="ดูรายชื่อเครื่องด้านล่าง"><i class="fa fa-industry me-1"></i>${machineCount} เครื่อง</span>`
            : `<span class="de-chip muted ms-1">ยังไม่พบ Machine</span>`;
        const drawingBadge = step.is_drawing
            ? `<span class="de-chip info ms-1">รีด</span>`
            : "";
        return `
            <div class="de-card wd-step-target" id="wd-step-${esc(step.workseq)}">
                <div class="de-card-header wd-step-head" style="flex-wrap:wrap;">
                    <span>
                        <strong>Workseq ${step.workseq}</strong> · ${esc(step.workcenter)} - ${esc(step.workcenter_desc)}${drawingBadge}${machineBadge}
                    </span>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        ${stepStatusChip(step.step_status)}
                        <span class="mini prog">${step.progress_pct}%</span>
                        <span class="mini fg">FG: ${num(step.fg_qty)} kg</span>
                        ${step.ncr_qty > 0 ? `<span class="mini ncr">NCR: ${num(step.ncr_qty)} kg</span>` : ""}
                    </div>
                </div>
                <div class="de-card-body">
                    ${step.step_desc ? `<div class="text-muted small mb-2" style="white-space:pre-wrap;">${esc(step.step_desc)}</div>` : ""}
                    <div class="small mb-2">
                        <strong>ขนาดแผน:</strong> ${esc(step.msize_plan ?? "-")} mm (เข้า: ${esc(step.msize_in_plan ?? "-")} mm)
                        · <strong>รับแล้ว:</strong> ${num(step.received)} kg (ต่างจาก${esc(step.baseline_label || "ยอดก่อนหน้า")} ${step.variance_pct}%${step.baseline_qty != null ? ` · เทียบ ${num(step.baseline_qty)} kg` : ""})
                        · <strong>ช่วงเวลารับ:</strong> ${esc(receiveWindowText(step))}
                        ${step.notes ? ` · <em>${esc(step.notes)}</em>` : ""}
                    </div>
                    ${renderStationMachines(step)}
                    ${renderBlocks(step.blocks, step.is_drawing, step.machine_tests)}
                    ${renderMachineTests(step.machine_tests, step.workseq)}
                    ${renderQualityTests(step.quality_tests, step.spec, step.workseq)}
                </div>
            </div>
        `;
    }

    async function load() {
        const input = document.getElementById("wdWo");
        let wo = input.value.trim().toUpperCase();
        if (!wo) {
            setEmpty("กรุณาระบุ WO");
            return;
        }
        input.value = wo; // normalize displayed value
        updateUrl(wo);
        setLoading();
        try {
            const res = await fetch(
                window.WD_API + "?wo=" + encodeURIComponent(wo),
            );
            const data = await res.json();
            if (data.error) {
                setEmpty("Error: " + data.error);
                return;
            }

            let html =
                renderHeader(data) +
                renderKpi(data) +
                renderBom(data) +
                renderMatUsage(data) +
                renderRoutingQuickActions(data);
            html +=
                '<div class="de-card-header" style="background:transparent; border:none; padding:8px 0;"><span><i class="fa fa-industry me-2 text-primary"></i>ขั้นตอนการผลิต</span></div>';
            html += (data.steps || []).map(renderStep).join("");
            html += renderCustomerClaim(data);

            out.innerHTML = html;
            bindRouteButtons();
        } catch (e) {
            setEmpty("Error: " + e.message);
        }
    }

    function bindRouteButtons() {
        out.querySelectorAll(".wd-route-btn[data-step]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const target = document.getElementById(
                    "wd-step-" + btn.dataset.step,
                );
                if (!target) return;
                target.scrollIntoView({ behavior: "smooth", block: "start" });
                target.style.boxShadow = "0 0 0 3px rgba(37,99,235,.18)";
                setTimeout(() => {
                    target.style.boxShadow = "";
                }, 900);
            });
        });
        out.querySelectorAll(".wd-q-toggle").forEach((btn) => {
            btn.addEventListener("click", () => {
                const seq = btn.dataset.qseq;
                const wrap = out.querySelector(
                    '.wd-q-table[data-qwrap="' + seq + '"]',
                );
                if (!wrap) return;
                const collapsed = wrap.classList.toggle("wd-q-collapsed");
                const icon = btn.querySelector("i");
                const label = btn.querySelector("span");
                const unit = wrap.dataset.qunit || "แถว";
                if (collapsed) {
                    icon.className = "fa fa-chevron-down me-1";
                    label.textContent =
                        "ดูทั้งหมด (" + wrap.dataset.qcount + " " + unit + ")";
                } else {
                    icon.className = "fa fa-chevron-up me-1";
                    label.textContent = "ย่อ";
                }
            });
        });
        const claimBtn = out.querySelector(".wd-route-btn[data-claim]");
        if (claimBtn) {
            claimBtn.addEventListener("click", () => {
                const target = document.getElementById("wd-customer-claim");
                if (!target) return;
                target.scrollIntoView({ behavior: "smooth", block: "start" });
                target.style.boxShadow = "0 0 0 3px rgba(220,38,38,.16)";
                setTimeout(() => {
                    target.style.boxShadow = "";
                }, 900);
            });
        }
    }

    document.getElementById("wdSearch").addEventListener("click", load);
    document.getElementById("wdReset").addEventListener("click", () => {
        document.getElementById("wdWo").value = "";
        updateUrl("");
        setEmpty("ใส่หมายเลข WO ด้านบนแล้วกดค้นหา");
    });
    document.getElementById("wdWo").addEventListener("keydown", (e) => {
        if (e.key === "Enter") load();
    });

    // Autocomplete WO#
    if (window.DieAutocomplete && window.WD_SUGGEST_WO) {
        DieAutocomplete.attach(document.getElementById("wdWo"), {
            url: window.WD_SUGGEST_WO,
            renderItem: (row) => {
                const tags = `${row.brand ? `<small>${row.brand}</small>` : ""}${row.fcat ? `<small>${row.fcat}</small>` : ""}${row.fsize ? `<small>${row.fsize}mm</small>` : ""}`;
                const status = row.open
                    ? '<span class="de-chip info">open</span>'
                    : '<span class="de-chip muted">closed</span>';
                return `<div class="main">${row.value}</div><div class="sub">${status}${tags}</div>`;
            },
            onSelect: (row) => {
                document.getElementById("wdWo").value = row.value;
                load();
            },
        });
    }

    if (document.getElementById("wdWo").value.trim()) load();
})();
