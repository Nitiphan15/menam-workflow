(function inquiryTogglePanels() {
    function bindToggle(buttonId, targetId) {
        const btn = document.getElementById(buttonId);
        const target = document.getElementById(targetId);
        if (!btn || !target) return;

        function setExpanded() {
            btn.setAttribute("aria-expanded", target.classList.contains("show") ? "true" : "false");
        }

        btn.addEventListener("click", () => {
            if (typeof bootstrap !== "undefined" && bootstrap.Collapse) {
                bootstrap.Collapse.getOrCreateInstance(target, { toggle: false }).toggle();
                return;
            }

            target.classList.toggle("show");
            setExpanded();
        });

        target.addEventListener("shown.bs.collapse", setExpanded);
        target.addEventListener("hidden.bs.collapse", setExpanded);
        setExpanded();
    }

    bindToggle("btnToggleKpi", "kpiCollapse");
    bindToggle("btnToggleFilter", "filterCollapse");
})();

(function inquiryExcelTable() {
    const table = document.getElementById("inqTable");
    const tbody = document.getElementById("inqTbody");
    if (!table || !tbody || !table.tHead || !table.tBodies.length) return;

    const headerRow = table.tHead.querySelector(".dp-inquiry-header-row");
    if (!headerRow || table.tHead.querySelector(".dp-inquiry-filter-row")) return;

    const dataRows = () => Array.from(tbody.querySelectorAll("tr.data-row"));
    const groupRows = () => Array.from(tbody.querySelectorAll("tr.group-row"));
    const originalGroupRows = groupRows();
    const numericColumns = new Set([0, 6, 7, 14]);
    const filterableColumns = new Set(
        Array.from(headerRow.cells)
            .map((_, idx) => idx)
            .filter((idx) => ![0, 16, 17].includes(idx)),
    );
    const state = {
        sortCol: null,
        sortDir: 1,
    };

    function normalizeText(value) {
        return String(value || "")
            .toLowerCase()
            .replace(/\s+/g, " ")
            .trim();
    }

    function parseNum(value) {
        return (
            parseFloat(
                String(value || "")
                    .replace(/,/g, "")
                    .replace(/[^\d.-]/g, ""),
            ) || 0
        );
    }

    function cellText(row, col) {
        const cell = row.cells[col];
        return cell ? cell.innerText.trim() : "";
    }

    function sortValue(row, col) {
        if (col === 1) return row.dataset.ship || "";
        if (col === 3) return row.dataset.customer || "";
        if (col === 4) return row.dataset.part || "";
        if (col === 6) return parseNum(row.dataset.qty || cellText(row, col));
        if (col === 10) return row.dataset.so || "";
        if (col === 14) return parseNum(row.dataset.rev || cellText(row, col));
        return numericColumns.has(col) ? parseNum(cellText(row, col)) : normalizeText(cellText(row, col));
    }

    function compareFilter(cellValue, filterValue, isNumeric) {
        const filter = String(filterValue || "").trim();
        if (!filter) return true;

        if (isNumeric) {
            const cellNumber = parseNum(cellValue);
            const match = filter.match(/^(>=|<=|>|<|=)?\s*(-?\d+(?:\.\d+)?)$/);
            if (match) {
                const op = match[1] || ">=";
                const n = parseFloat(match[2]);
                if (op === ">=") return cellNumber >= n;
                if (op === "<=") return cellNumber <= n;
                if (op === ">") return cellNumber > n;
                if (op === "<") return cellNumber < n;
                if (op === "=") return Math.abs(cellNumber - n) < 0.0001;
            }
        }

        return normalizeText(cellValue).includes(normalizeText(filter));
    }

    function activeFilters() {
        return Array.from(table.querySelectorAll(".dp-col-filter"))
            .map((input) => {
                const col = parseInt(input.dataset.filterCol || "0", 10);
                return {
                    col,
                    value: input.value,
                    isNumeric: numericColumns.has(col),
                };
            })
            .filter((item) => String(item.value || "").trim() !== "");
    }

    function setKpi(visibleRows) {
        const rows = visibleRows || dataRows().filter((row) => row.style.display !== "none");
        const total = dataRows().length;
        const so = rows.filter((row) => String(row.dataset.mode || "").toUpperCase() !== "ACID").length;
        const acid = rows.filter((row) => String(row.dataset.mode || "").toUpperCase() === "ACID").length;
        const sellByLine = rows.filter((row) => String(row.dataset.sbl || "") === "1").length;

        const visibleEl = document.getElementById("kpi_visible");
        const totalEl = document.getElementById("kpi_total");
        const soEl = document.getElementById("kpi_so");
        const acidEl = document.getElementById("kpi_acid");
        const sblEl = document.getElementById("kpi_sbl");
        const countEl = document.getElementById("inqCount");

        if (visibleEl) visibleEl.textContent = rows.length.toLocaleString();
        if (totalEl) totalEl.textContent = total.toLocaleString();
        if (soEl) soEl.textContent = so.toLocaleString();
        if (acidEl) acidEl.textContent = acid.toLocaleString();
        if (sblEl) sblEl.textContent = sellByLine.toLocaleString();
        if (countEl) countEl.textContent = `${rows.length.toLocaleString()} / ${total.toLocaleString()} visible`;
    }

    function updateGroupRows() {
        groupRows().forEach((groupRow) => {
            const group = groupRow.dataset.group || "";
            const hasVisible = dataRows().some(
                (row) => row.dataset.group === group && row.style.display !== "none",
            );
            groupRow.style.display = hasVisible ? "" : "none";
        });
    }

    function applyFilters() {
        const filters = activeFilters();
        const visibleRows = [];

        dataRows().forEach((row) => {
            const ok = filters.every((filter) =>
                compareFilter(cellText(row, filter.col), filter.value, filter.isNumeric),
            );
            row.style.display = ok ? "" : "none";
            if (ok) visibleRows.push(row);
        });

        updateGroupRows();
        setKpi(visibleRows);
    }

    function applySort(col) {
        const dir = state.sortCol === col ? state.sortDir * -1 : 1;
        state.sortCol = col;
        state.sortDir = dir;

        Array.from(headerRow.cells).forEach((th) => {
            const ind = th.querySelector(".sort-ind");
            if (ind) ind.textContent = "";
        });

        const indicator = headerRow.cells[col]?.querySelector(".sort-ind");
        if (indicator) indicator.textContent = dir === 1 ? "▲" : "▼";

        const sorted = dataRows().sort((a, b) => {
            const av = sortValue(a, col);
            const bv = sortValue(b, col);
            if (numericColumns.has(col)) return (Number(av) - Number(bv)) * dir;
            return String(av).localeCompare(String(bv), undefined, { numeric: true }) * dir;
        });

        if (originalGroupRows.length) {
            const fragment = document.createDocumentFragment();
            const usedRows = new Set();

            originalGroupRows.forEach((groupRow) => {
                const group = groupRow.dataset.group || "";
                const rows = sorted.filter((row) => row.dataset.group === group);

                fragment.appendChild(groupRow);
                rows.forEach((row) => {
                    usedRows.add(row);
                    fragment.appendChild(row);
                });
            });

            sorted
                .filter((row) => !usedRows.has(row))
                .forEach((row) => fragment.appendChild(row));

            tbody.appendChild(fragment);
        } else {
            sorted.forEach((row) => tbody.appendChild(row));
        }

        applyFilters();
    }

    Array.from(headerRow.cells).forEach((th, idx) => {
        th.classList.add("sortable");
        const indicator = document.createElement("span");
        indicator.className = "sort-ind";
        th.appendChild(indicator);
        th.addEventListener("click", () => applySort(idx));
    });

    const filterRow = document.createElement("tr");
    filterRow.className = "dp-inquiry-filter-row";

    Array.from(headerRow.cells).forEach((th, idx) => {
        const filterTh = document.createElement("th");
        filterTh.className = th.className
            .replace(/\bsortable\b/g, "")
            .replace(/\bsticky-col\b/g, "")
            .replace(/\bsticky-[1-4]\b/g, "")
            .trim();

        if (filterableColumns.has(idx)) {
            const input = document.createElement("input");
            input.type = "text";
            input.className = "form-control form-control-sm dp-col-filter";
            input.dataset.filterCol = String(idx);
            input.placeholder = numericColumns.has(idx) ? ">= or text" : "Filter";
            input.addEventListener("input", applyFilters);
            input.addEventListener("click", (event) => event.stopPropagation());
            filterTh.appendChild(input);
        }

        filterRow.appendChild(filterTh);
    });

    table.tHead.appendChild(filterRow);

    applyFilters();
})();

(function truckModal() {
    const byId = (id) => document.getElementById(id);
    const CONFIG = window.DP_INQUIRY || {};
    const ROUTES = CONFIG.routes || {};
    const DOC_MAP = CONFIG.docMap || {};

    const modalEl = byId("truckModal");
    const form = byId("truckAssignForm");
    const submitBtn = byId("btnTruckAssignSubmit");
    if (!modalEl || !form) return;
    if (typeof bootstrap === "undefined" || !bootstrap.Modal) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    const tmReturnUrl = byId("tmReturnUrl");
    const tmOrdId = byId("tmOrdId");
    const tmSoText = byId("tmSoText");
    const tmShipDateText = byId("tmShipDateText");
    const tmShipToText = byId("tmShipToText");
    const tmSoHidden = byId("tmSoHidden");
    const tmShipDateHidden = byId("tmShipDateHidden");
    const tmTruckId = byId("tmTruckId");

    const tmLineTbody = byId("tmLineTbody");
    const tmTotalQty = byId("tmTotalQty");
    const tmSelectedWeight = byId("tmSelectedWeight");
    const tmTruckRemainAfter = byId("tmTruckRemainAfter");

    const tmTruckTableBody = byId("tmTruckTableBody");
    const tmTruckSearch = byId("tmTruckSearch");
    const tmReplaceMode = byId("tmReplaceMode");

    const tmManualMode = byId("tmManualMode");
    const tmManualPlate = byId("tmManualPlate");
    const tmManualDriver = byId("tmManualDriver");
    const tmManualPhone = byId("tmManualPhone");
    const tmManualMaxLoad = byId("tmManualMaxLoad");
    const tmManualLength = byId("tmManualLength");
    const tmManualRemark = byId("tmManualRemark");

    const tmManualPlateHidden = byId("tmManualPlateHidden");
    const tmManualDriverHidden = byId("tmManualDriverHidden");
    const tmManualPhoneHidden = byId("tmManualPhoneHidden");
    const tmManualMaxLoadHidden = byId("tmManualMaxLoadHidden");
    const tmManualLengthHidden = byId("tmManualLengthHidden");
    const tmManualRemarkHidden = byId("tmManualRemarkHidden");

    const tmTruckPickedInfo = byId("tmTruckPickedInfo");
    const tmPickedPlate = byId("tmPickedPlate");
    const tmPickedJobs = byId("tmPickedJobs");

    const tmCheckAll = byId("tmCheckAll");
    const tmUncheckAll = byId("tmUncheckAll");

    const tmDriverStaffId = byId("tmDriverStaffId");
    const tmHelper1StaffId = byId("tmHelper1StaffId");
    const tmHelper2StaffId = byId("tmHelper2StaffId");
    const tmHelper3StaffId = byId("tmHelper3StaffId");

    const state = {
        so: "",
        shipDate: "",
        shipto: "",
        ordId: "",
        lines: [],
        currentTruckId: "",
        isSubmitting: false,
    };

    function parseJsonSafe(text, fallback) {
        try {
            return JSON.parse(text);
        } catch (_) {
            return fallback;
        }
    }

    function esc(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function numberFormat(value, digits = 3) {
        const n = Number(value || 0);
        return n.toLocaleString(undefined, {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        });
    }

    function clearTruckSelection() {
        if (tmTruckId) tmTruckId.value = "";
        document.querySelectorAll(".tmTruckRadio").forEach((radio) => {
            radio.checked = false;
        });
    }

    function clearManualFields(opts = {}) {
        const keepPlate = !!opts.keepPlate;

        if (!keepPlate && tmManualPlate) tmManualPlate.value = "";
        if (tmManualDriver) tmManualDriver.value = "";
        if (tmManualPhone) tmManualPhone.value = "";
        if (tmManualMaxLoad) tmManualMaxLoad.value = "";
        if (tmManualLength) tmManualLength.value = "";
        if (tmManualRemark) tmManualRemark.value = "";
    }

    function clearManualHiddenFields() {
        if (tmManualPlateHidden) tmManualPlateHidden.value = "";
        if (tmManualDriverHidden) tmManualDriverHidden.value = "";
        if (tmManualPhoneHidden) tmManualPhoneHidden.value = "";
        if (tmManualMaxLoadHidden) tmManualMaxLoadHidden.value = "";
        if (tmManualLengthHidden) tmManualLengthHidden.value = "";
        if (tmManualRemarkHidden) tmManualRemarkHidden.value = "";
    }

    function fillManualHiddenFromRadio(radio) {
        if (tmManualPlateHidden) {
            tmManualPlateHidden.value =
                radio.dataset.manualPlate || radio.dataset.plate || "";
        }
        if (tmManualDriverHidden) {
            tmManualDriverHidden.value = radio.dataset.driverName || "";
        }
        if (tmManualPhoneHidden) {
            tmManualPhoneHidden.value = radio.dataset.driverPhone || "";
        }
        if (tmManualMaxLoadHidden) {
            tmManualMaxLoadHidden.value = radio.dataset.max || "";
        }
        if (tmManualLengthHidden) {
            tmManualLengthHidden.value = radio.dataset.carLength || "";
        }
        if (tmManualRemarkHidden) {
            tmManualRemarkHidden.value = radio.dataset.remark || "";
        }
    }

    function clearStaffSelection() {
        if (tmDriverStaffId) tmDriverStaffId.value = "";
        if (tmHelper1StaffId) tmHelper1StaffId.value = "";
        if (tmHelper2StaffId) tmHelper2StaffId.value = "";
        if (tmHelper3StaffId) tmHelper3StaffId.value = "";
    }

    function simplifyHelperInputs() {
        const helper2Wrap = tmHelper2StaffId
            ? tmHelper2StaffId.closest(".col-md-12")
            : null;
        const helper3Wrap = tmHelper3StaffId
            ? tmHelper3StaffId.closest(".col-md-12")
            : null;

        if (tmHelper2StaffId) {
            tmHelper2StaffId.value = "";
            tmHelper2StaffId.disabled = true;
            tmHelper2StaffId.name = "";
        }

        if (tmHelper3StaffId) {
            tmHelper3StaffId.value = "";
            tmHelper3StaffId.disabled = true;
            tmHelper3StaffId.name = "";
        }

        if (helper2Wrap) helper2Wrap.classList.add("d-none");
        if (helper3Wrap) helper3Wrap.classList.add("d-none");

        if (!tmHelper1StaffId) return;

        const helper1Wrap = tmHelper1StaffId.closest(".col-md-12");
        const helper1Label = helper1Wrap
            ? helper1Wrap.querySelector("label")
            : null;
        const helper1Placeholder = tmHelper1StaffId.querySelector("option[value='']");

        if (helper1Label) helper1Label.textContent = "เด็กรถ";
        if (helper1Placeholder) {
            helper1Placeholder.textContent = "-- เลือกเด็กรถ --";
        }
    }

    function keepOnlyTopManualModeOption() {
        const manualModeInputs = Array.from(
            document.querySelectorAll('#truckModal input[id="tmManualMode"]'),
        );

        manualModeInputs.slice(1).forEach((input) => {
            const wrap = input.closest(".form-check");
            if (wrap) {
                wrap.classList.add("d-none");
            } else {
                input.classList.add("d-none");
            }
        });
    }

    async function loadTruckStaffOptions() {
        if (!ROUTES.truckStaffOptions) return;

        try {
            const res = await fetch(ROUTES.truckStaffOptions, {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            });

            if (!res.ok) return;
            const data = await res.json();
            fillStaffSelectOptions(data || {});
            simplifyHelperInputs();
            keepOnlyTopManualModeOption();
        } catch (_) {}
    }

    function fillOneStaffSelect(selectEl, items, placeholder) {
        if (!selectEl) return;

        const rows = Array.isArray(items) ? items : [];
        selectEl.innerHTML = [
            `<option value="">${placeholder}</option>`,
            ...rows.map((x) => {
                const name = x.emp_code
                    ? `${x.emp_code} - ${x.name || "-"}`
                    : x.name || "-";
                return `<option value="${esc(x.id)}">${esc(name)}</option>`;
            }),
        ].join("");
    }

    function fillStaffSelectOptions(data) {
        fillOneStaffSelect(
            tmDriverStaffId,
            data.drivers || [],
            "-- เลือกคนขับ --",
        );
        fillOneStaffSelect(
            tmHelper1StaffId,
            data.helpers || [],
            "-- เลือกเด็กรถ 1 --",
        );
        fillOneStaffSelect(
            tmHelper2StaffId,
            data.helpers || [],
            "-- เลือกเด็กรถ 2 --",
        );
        fillOneStaffSelect(
            tmHelper3StaffId,
            data.helpers || [],
            "-- เลือกเด็กรถ 3 --",
        );
    }

    async function loadTruckStaffDefaults(params = {}) {
        if (!ROUTES.truckStaffDefaults) return;

        const q = new URLSearchParams();
        if (params.truck_id) q.set("truck_id", params.truck_id);
        if (params.manual_plate_no)
            q.set("manual_plate_no", params.manual_plate_no);
        if (state.shipDate) q.set("ship_posted_at", state.shipDate);

        try {
            const url = `${ROUTES.truckStaffDefaults}?${q.toString()}`;
            const res = await fetch(url, {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            });

            if (!res.ok) return;
            const data = await res.json();
            applyTruckStaffDefaults(data || {});
            if (tmHelper2StaffId) tmHelper2StaffId.value = "";
            if (tmHelper3StaffId) tmHelper3StaffId.value = "";
        } catch (_) {}
    }

    function applyTruckStaffDefaults(data) {
        const driver = data.driver || null;
        const helpers = Array.isArray(data.helpers) ? data.helpers : [];

        if (tmDriverStaffId) {
            tmDriverStaffId.value =
                driver && driver.id ? String(driver.id) : "";
        }
        if (tmHelper1StaffId) {
            tmHelper1StaffId.value =
                helpers[0] && helpers[0].id ? String(helpers[0].id) : "";
        }
        if (tmHelper2StaffId) {
            tmHelper2StaffId.value =
                helpers[1] && helpers[1].id ? String(helpers[1].id) : "";
        }
        if (tmHelper3StaffId) {
            tmHelper3StaffId.value =
                helpers[2] && helpers[2].id ? String(helpers[2].id) : "";
        }
    }

    function setTruckAssignAction(ordId) {
        const base = ROUTES.truckAssign || "";
        if (form) {
            form.action = String(base).replace("__ID__", String(ordId || 0));
        }
    }

    function selectedOrdIds() {
        if (!modalEl) return [];
        return Array.from(modalEl.querySelectorAll(".tmOrdCheckbox:checked"))
            .map((el) => String(el.value || "").trim())
            .filter(Boolean);
    }

    function selectedTotalQty() {
        if (!modalEl) return 0;
        return Array.from(
            modalEl.querySelectorAll(".tmOrdCheckbox:checked"),
        ).reduce((sum, checkbox) => {
            const tr = checkbox.closest("tr");
            if (!tr) return sum;
            const qty = Number(tr.dataset.qtyRemaining || 0);
            return sum + qty;
        }, 0);
    }

    function updateTruckRemainPreview() {
        const selectedWeight = selectedTotalQty();

        if (tmSelectedWeight) {
            tmSelectedWeight.textContent = numberFormat(selectedWeight);
        }

        if (!tmTruckRemainAfter || !modalEl) return;

        const selectedTruck = modalEl.querySelector(".tmTruckRadio:checked");

        if (!selectedTruck) {
            tmTruckRemainAfter.textContent = "-";
            tmTruckRemainAfter.classList.remove("text-danger", "text-success");
            return;
        }

        const currentRemain = Number(selectedTruck.dataset.remaining || 0);
        const remainAfter = currentRemain - selectedWeight;

        tmTruckRemainAfter.textContent = `${numberFormat(remainAfter)} KG`;
        tmTruckRemainAfter.classList.toggle("text-danger", remainAfter < 0);
        tmTruckRemainAfter.classList.toggle("text-success", remainAfter >= 0);
    }

    function renderLineTable(lines) {
        if (!tmLineTbody || !tmTotalQty) return;

        const safeLines = Array.isArray(lines) ? lines : [];
        let total = 0;

        if (!safeLines.length) {
            tmLineTbody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted">ไม่มีรายการ</td></tr>';
            tmTotalQty.textContent = numberFormat(0);

            if (tmSelectedWeight) {
                tmSelectedWeight.textContent = numberFormat(0);
            }

            updateTruckRemainPreview();
            return;
        }

        const html = safeLines
            .map((line) => {
                const qtyAssigned = Number(line.qty_assigned || 0);
                const qtyRemaining = Number(line.qty_remaining || 0);

                const lineText =
                    Number(line.sell_by_line || 0) === 1
                        ? String(line.line_text || "ระบุเส้น").trim()
                        : "-";

                const shipto = String(line.shipto || "-").trim() || "-";

                total += qtyRemaining;

                return `
                    <tr data-qty-remaining="${qtyRemaining}">
                        <td class="text-center">
                            <input
                                type="checkbox"
                                class="form-check-input tmOrdCheckbox"
                                name="ord_ids[]"
                                value="${esc(line.ord_id || "")}"
                                checked
                            >
                        </td>
                        <td>${esc(line.mfg_no || "-")}</td>
                        <td>${esc(line.part || "-")}</td>
                        <td>${esc(line.desc || "-")}</td>
                        <td>${esc(lineText)}</td>
                        <td>${esc(shipto)}</td>
                        <td class="text-end">${numberFormat(qtyAssigned)}</td>
                        <td class="text-end">${numberFormat(qtyRemaining)}</td>
                    </tr>
                `;
            })
            .join("");

        tmLineTbody.innerHTML = html;
        tmTotalQty.textContent = numberFormat(total);

        if (tmSelectedWeight) {
            tmSelectedWeight.textContent = numberFormat(total);
        }

        updateTruckRemainPreview();
    }

    function filterTruckRows(keyword) {
        const q = String(keyword || "")
            .trim()
            .toLowerCase();
        document.querySelectorAll(".tmTruckRow").forEach((row) => {
            const hay = String(row.dataset.search || "").toLowerCase();
            row.style.display = q === "" || hay.includes(q) ? "" : "none";
        });
    }

    function initTruckTooltips() {
        document
            .querySelectorAll('#truckModal [data-bs-toggle="tooltip"]')
            .forEach((el) => {
                const old = bootstrap.Tooltip.getInstance(el);
                if (old) old.dispose();
                new bootstrap.Tooltip(el);
            });
    }

    function renderTruckRows(trucks) {
        if (!tmTruckTableBody) return;

        const rows = Array.isArray(trucks) ? trucks : [];

        tmTruckTableBody.innerHTML = rows
            .map((t) => {
                const isManualTemp =
                    String(t.truck_pick_type || "") === "MANUAL_TEMP";
                const remaining = Number(t.remaining_capacity || 0);
                const current = Number(t.current_load || 0);
                const max = Number(t.max_load || 0);
                const rowKey = String(t.row_key || "");
                const truckId = t.truck_id ?? "";
                const manualPlate = t.manual_plate_no ?? "";

                const jobSummary = Array.isArray(t.job_summary)
                    ? t.job_summary
                    : [];
                const jobSummaryText = jobSummary
                    .map((x) => {
                        const customer = x.customer_name || "-";
                        const jobType = x.job_type || "-";
                        const weight = numberFormat(
                            Number(x.assigned_weight || 0),
                        );
                        return `${customer} | ${jobType} (${weight} KG)`;
                    })
                    .join(" || ");

                const soSummaryText = t.so_summary_text || "";
                const mfgSummaryText = t.mfg_summary_text || "";

                const tooltipLines = [];
                if (jobSummary.length) {
                    jobSummary.forEach((x) => {
                        tooltipLines.push(
                            `${x.so_number || soSummaryText || "-"} || ${x.mfg_no || mfgSummaryText || "-"} || ${numberFormat(Number(x.assigned_weight || 0))} KG || ${x.address || "-"}`,
                        );
                    });
                } else {
                    tooltipLines.push("ยังไม่มีรายการ");
                }
                const remainTooltip = tooltipLines.join("\n");

                const search =
                    `${t.plate_no || ""} ${t.driver_name || ""} ${t.remark || ""} ${t.source_label || ""} ${jobSummaryText} ${soSummaryText} ${mfgSummaryText}`.toLowerCase();

                const jobHtml = jobSummary.length
                    ? jobSummary
                          .map(
                              (x) => `
                        <div class="mb-1">
                            <span class="fw-semibold">${esc(x.customer_name || "-")}</span>
                            <span class="text-muted">| ${esc(x.job_type || "-")}</span>
                            <span class="text-primary">(${numberFormat(Number(x.assigned_weight || 0))} KG)</span>
                        </div>
                    `,
                          )
                          .join("")
                    : `<span class="text-muted">ยังไม่มีรายการ</span>`;

                return `
                    <tr class="tmTruckRow"
                        data-row-key="${esc(rowKey)}"
                        data-truck-id="${esc(truckId)}"
                        data-manual-plate="${esc(manualPlate)}"
                        data-job-summary="${esc(jobSummaryText)}"
                        data-search="${esc(search)}">
                        <td class="text-center">
                            <input type="radio"
                                class="form-check-input tmTruckRadio"
                                name="truck_pick_mode"
                                value="${isManualTemp ? "MANUAL_TEMP" : "MASTER"}"
                                data-row-key="${esc(rowKey)}"
                                data-truck-id="${esc(truckId)}"
                                data-manual-plate="${esc(manualPlate)}"
                                data-plate="${esc(t.plate_no || "")}"
                                data-driver-name="${esc(t.driver_name || "")}"
                                data-driver-phone="${esc(t.driver_phone || "")}"
                                data-max="${esc(max)}"
                                data-current="${esc(current)}"
                                data-remaining="${esc(remaining)}"
                                data-car-length="${esc(t.car_length ?? "")}"
                                data-remark="${esc(t.remark || "")}"
                                data-job-summary="${esc(jobSummaryText)}"
                                ${remaining <= 0 ? "disabled" : ""}>
                        </td>

                        <td>
                            <div class="fw-semibold">
                                ${esc(t.plate_no || "-")}
                                ${
                                    isManualTemp
                                        ? '<span class="badge bg-info-subtle text-info-emphasis border ms-1">รถนอกวันนี้</span>'
                                        : '<span class="badge bg-light text-dark border ms-1">ในระบบ</span>'
                                }
                            </div>
                            <div class="small text-muted">${esc(t.driver_name || "-")}</div>
                            <div class="small text-muted">${esc(t.driver_phone || "-")}</div>
                        </td>

                        <td class="text-end">${numberFormat(max)}</td>
                        <td class="text-end">${numberFormat(current)}</td>
                        <td
                            class="text-end ${remaining < 0 ? "text-danger fw-bold" : "fw-semibold"}"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top"
                            data-bs-html="false"
                            data-bs-custom-class="truck-remain-tooltip"
                            title="${esc(remainTooltip)}"
                        >
                            ${numberFormat(remaining)}
                        </td>
                        <td class="text-end">${t.car_length ? numberFormat(t.car_length, 0) : "-"}</td>
                        <td class="small">${jobHtml}</td>
                        <td class="small">
                            ${soSummaryText ? `<div><span class="fw-semibold">SO:</span> ${esc(soSummaryText)}</div>` : ""}
                            ${mfgSummaryText ? `<div><span class="fw-semibold">MFG:</span> ${esc(mfgSummaryText)}</div>` : ""}
                            <div><span class="fw-semibold">Remark:</span> ${esc(t.remark || "-")}</div>
                        </td>
                    </tr>
                `;
            })
            .join("");

        if (tmTruckSearch) {
            filterTruckRows(tmTruckSearch.value || "");
        }

        initTruckTooltips();
    }

    async function loadTruckCapacity(shipDate) {
        if (!ROUTES.truckCapacity || !shipDate) return [];

        try {
            const url = `${ROUTES.truckCapacity}?ship_posted_at=${encodeURIComponent(shipDate)}`;
            const res = await fetch(url, {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            });

            if (!res.ok) return [];

            const trucks = await res.json();
            if (!Array.isArray(trucks)) return [];

            renderTruckRows(trucks);
            updateTruckRemainPreview();
            return trucks;
        } catch (_) {
            return [];
        }
    }

    function openTruckModalFromButton(btn) {
        const ordId = btn.dataset.ordId || "";
        const so = btn.dataset.so || "-";
        const shipDate = btn.dataset.shipDate || "";
        const shipto = btn.dataset.shipto || "-";
        const currentTruckId = btn.dataset.currentTruckId || "";
        const currentTruckSource = String(
            btn.dataset.currentTruckSource || "",
        ).toUpperCase();
        const currentManualPlate = btn.dataset.currentManualPlate || "";
        const lines = parseJsonSafe(btn.dataset.lines || "[]", []);

        state.ordId = ordId;
        state.so = so;
        state.shipDate = shipDate;
        state.shipto = shipto;
        state.lines = Array.isArray(lines) ? lines : [];
        state.currentTruckId = currentTruckId;

        if (tmSoText) tmSoText.textContent = so || "-";
        if (tmShipDateText) tmShipDateText.textContent = shipDate || "-";
        if (tmShipToText) tmShipToText.textContent = shipto || "-";

        if (tmOrdId) tmOrdId.value = ordId;
        if (tmSoHidden) tmSoHidden.value = so;
        if (tmShipDateHidden) tmShipDateHidden.value = shipDate;
        if (tmReturnUrl) tmReturnUrl.value = window.location.href;

        clearStaffSelection();
        loadTruckStaffOptions();

        setTruckAssignAction(ordId);
        renderLineTable(state.lines);
        clearTruckSelection();
        clearManualFields();
        clearManualHiddenFields();

        if (tmReplaceMode) {
            tmReplaceMode.checked = false;
        }

        if (tmTruckSearch) {
            tmTruckSearch.value = "";
            filterTruckRows("");
        }

        if (modal) {
            modal.show();
        }

        if (shipDate) {
            loadTruckCapacity(shipDate).then(() => {
                if (currentTruckSource === "MASTER" && currentTruckId) {
                    document
                        .querySelectorAll(".tmTruckRadio")
                        .forEach((radio) => {
                            const hit =
                                String(radio.dataset.truckId || "") ===
                                String(currentTruckId || "");
                            radio.checked = hit;

                            if (hit) {
                                if (tmTruckId)
                                    tmTruckId.value = String(
                                        currentTruckId || "",
                                    );
                                clearManualHiddenFields();
                                loadTruckStaffDefaults({
                                    truck_id: currentTruckId,
                                });
                            }
                        });
                } else if (
                    currentTruckSource === "MANUAL" &&
                    currentManualPlate
                ) {
                    document
                        .querySelectorAll(".tmTruckRadio")
                        .forEach((radio) => {
                            const hit =
                                String(
                                    radio.dataset.manualPlate || "",
                                ).trim() ===
                                String(currentManualPlate || "").trim();
                            radio.checked = hit;

                            if (hit) {
                                if (tmTruckId) tmTruckId.value = "";
                                fillManualHiddenFromRadio(radio);
                                loadTruckStaffDefaults({
                                    manual_plate_no:
                                        radio.dataset.manualPlate ||
                                        radio.dataset.plate ||
                                        "",
                                });
                            }
                        });
                }

                updateTruckRemainPreview();
            });
        } else {
            updateTruckRemainPreview();
        }
    }

    function validateBeforeSubmit() {
        const ordIds = selectedOrdIds();

        if (!ordIds.length) {
            alert("กรุณาเลือก MFG อย่างน้อย 1 รายการ");
            return false;
        }

        const checked = modalEl.querySelector(
            'input[name="truck_pick_mode"]:checked',
        );
        const mode = checked ? String(checked.value || "") : "";

        if (mode === "MASTER") {
            if (!tmTruckId || !tmTruckId.value) {
                alert("กรุณาเลือกรถในระบบ");
                return false;
            }
            clearManualHiddenFields();
        } else if (mode === "MANUAL_TEMP") {
            const plate = String(tmManualPlateHidden?.value || "").trim();
            if (!plate) {
                alert("ไม่พบข้อมูลรถนอกที่เลือก");
                return false;
            }
        } else if (mode === "MANUAL") {
            const plate = String(tmManualPlate?.value || "").trim();
            if (!plate) {
                alert("กรุณากรอกทะเบียนรถนอก");
                return false;
            }

            if (tmManualPlateHidden) {
                tmManualPlateHidden.value = tmManualPlate.value || "";
            }
            if (tmManualDriverHidden) {
                tmManualDriverHidden.value = tmManualDriver.value || "";
            }
            if (tmManualPhoneHidden) {
                tmManualPhoneHidden.value = tmManualPhone.value || "";
            }
            if (tmManualMaxLoadHidden) {
                tmManualMaxLoadHidden.value = tmManualMaxLoad.value || "";
            }
            if (tmManualLengthHidden) {
                tmManualLengthHidden.value = tmManualLength.value || "";
            }
            if (tmManualRemarkHidden) {
                tmManualRemarkHidden.value = tmManualRemark.value || "";
            }
        } else {
            alert("กรุณาเลือกประเภทรถ");
            return false;
        }

        return true;
    }

    document.addEventListener("click", (e) => {
        if (!(e.target instanceof Element)) return;

        const btn = e.target.closest(".jsOpenTruckModal");
        if (btn) {
            openTruckModalFromButton(btn);
            return;
        }
    });

    modalEl.addEventListener("change", (e) => {
        if (!(e.target instanceof Element)) return;
        const target = e.target;

        if (target.classList.contains("tmOrdCheckbox")) {
            updateTruckRemainPreview();
            return;
        }

        if (target.classList.contains("tmTruckRadio")) {
            const mode = String(target.value || "");
            const truckId = target.dataset.truckId || "";

            if (mode === "MASTER") {
                if (tmTruckId) tmTruckId.value = truckId;
                clearManualHiddenFields();
            } else if (mode === "MANUAL_TEMP") {
                if (tmTruckId) tmTruckId.value = "";
                fillManualHiddenFromRadio(target);
            }

            if (tmManualMode) {
                tmManualMode.checked = false;
            }

            if (tmTruckPickedInfo && tmPickedPlate && tmPickedJobs) {
                tmPickedPlate.textContent = target.dataset.plate || "-";
                tmPickedJobs.textContent =
                    target.dataset.jobSummary || "ยังไม่มีรายการ";
                tmTruckPickedInfo.classList.remove("d-none");
            }

            if (mode === "MASTER") {
                loadTruckStaffDefaults({
                    truck_id: truckId,
                });
            } else if (mode === "MANUAL_TEMP") {
                loadTruckStaffDefaults({
                    manual_plate_no:
                        target.dataset.manualPlate ||
                        target.dataset.plate ||
                        "",
                });
            }

            updateTruckRemainPreview();
            return;
        }

        if (target.id === "tmManualMode") {
            if (target.checked) {
                clearTruckSelection();
                clearManualHiddenFields();
                clearStaffSelection();
                updateTruckRemainPreview();
            }
        }
    });

    modalEl.addEventListener("input", (e) => {
        if (!(e.target instanceof Element)) return;
        const target = e.target;

        if (
            target === tmManualPlate ||
            target === tmManualDriver ||
            target === tmManualPhone ||
            target === tmManualMaxLoad ||
            target === tmManualLength ||
            target === tmManualRemark
        ) {
            if (tmManualMode) {
                tmManualMode.checked = true;
            }
            clearTruckSelection();
            clearManualHiddenFields();
            return;
        }

        if (target === tmTruckSearch) {
            filterTruckRows(target.value || "");
        }
    });

    form.addEventListener("submit", (e) => {
        if (state.isSubmitting) {
            e.preventDefault();
            return;
        }

        if (!validateBeforeSubmit()) {
            e.preventDefault();
            return;
        }

        state.isSubmitting = true;

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = "กำลังบันทึก...";
        }
    });

    modalEl.addEventListener("hidden.bs.modal", () => {
        state.isSubmitting = false;

        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = "บันทึก";
        }

        clearTruckSelection();
        clearManualHiddenFields();
        clearStaffSelection();
    });

    if (tmCheckAll) {
        tmCheckAll.addEventListener("click", () => {
            modalEl.querySelectorAll(".tmOrdCheckbox").forEach((el) => {
                el.checked = true;
            });
            updateTruckRemainPreview();
        });
    }

    if (tmUncheckAll) {
        tmUncheckAll.addEventListener("click", () => {
            modalEl.querySelectorAll(".tmOrdCheckbox").forEach((el) => {
                el.checked = false;
            });
            updateTruckRemainPreview();
        });
    }

    simplifyHelperInputs();
    keepOnlyTopManualModeOption();
})();

(function voidModal() {
    const byId = (id) => document.getElementById(id);
    const CONFIG = window.DP_INQUIRY || {};
    const ROUTES = CONFIG.routes || {};

    const modalEl = byId("voidModal");
    const form = byId("voidForm");
    const infoEl = byId("voidInfo");
    const remarkEl = byId("remarkVoid");

    if (!modalEl || !form) return;
    if (typeof bootstrap === "undefined" || !bootstrap.Modal) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    function setVoidAction(ordId) {
        const base = ROUTES.void || "";
        form.action = String(base).replace("__ID__", String(ordId || 0));
    }

    function buildInfo(btn) {
        const so = String(btn.dataset.so || "").trim();
        const part = String(btn.dataset.part || "").trim();
        const ship = String(btn.dataset.ship || "").trim();

        return [so || "-", part || "-", ship || "-"].join(" | ");
    }

    document.addEventListener("click", (e) => {
        if (!(e.target instanceof Element)) return;

        const btn = e.target.closest(".jsVoidBtn");
        if (!btn || btn.hasAttribute("disabled")) return;

        setVoidAction(btn.dataset.ordId || "");

        if (infoEl) {
            infoEl.textContent = buildInfo(btn);
        }

        if (remarkEl) {
            remarkEl.value = "";
        }

        modal.show();
    });

    modalEl.addEventListener("hidden.bs.modal", () => {
        if (infoEl) {
            infoEl.textContent = "-";
        }
        if (remarkEl) {
            remarkEl.value = "";
        }
    });
})();

(function historyModal() {
    const byId = (id) => document.getElementById(id);
    const CONFIG = window.DP_INQUIRY || {};
    const ROUTES = CONFIG.routes || {};

    const modalEl = byId("historyModal");
    const metaEl = byId("historyMeta");
    const topViewEl = byId("histViewTop");
    const dbViewEl = byId("histViewDb");
    const tbodyEl = byId("historyTbody");
    const colTitleEl = byId("histColTitle");
    const dbTheadEl = byId("histDbThead");
    const dbTbodyEl = byId("histDbTbody");
    const currentBtn = byId("btnHistCurrent");
    const stepBtn = byId("btnHistStep");
    const dbBtn = byId("btnHistDb");

    if (!modalEl || !tbodyEl) return;

    const state = {
        ordId: "",
        mode: "current",
        payload: null,
        dbRows: null,
        loadingDb: false,
    };

    function esc(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function historyUrl(ordId) {
        return String(ROUTES.history || "").replace("__ID__", encodeURIComponent(String(ordId || "")));
    }

    function historyDbUrl(ordId) {
        return String(ROUTES.historyDb || "").replace("__ID__", encodeURIComponent(String(ordId || "")));
    }

    function setButtons(mode) {
        state.mode = mode;

        if (currentBtn) {
            currentBtn.classList.toggle("btn-primary", mode === "current");
            currentBtn.classList.toggle("btn-outline-primary", mode !== "current");
        }
        if (stepBtn) {
            stepBtn.classList.toggle("btn-secondary", mode === "step");
            stepBtn.classList.toggle("btn-outline-secondary", mode !== "step");
        }
        if (dbBtn) {
            dbBtn.classList.toggle("btn-dark", mode === "db");
            dbBtn.classList.toggle("btn-outline-dark", mode !== "db");
        }

        if (topViewEl) topViewEl.classList.toggle("d-none", mode === "db");
        if (dbViewEl) dbViewEl.classList.toggle("d-none", mode !== "db");
    }

    function setLoading(text) {
        if (metaEl) metaEl.textContent = text || "Loading...";
        tbodyEl.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">Loading...</td></tr>`;
    }

    function renderError(message) {
        if (metaEl) metaEl.textContent = `ord_id: ${state.ordId || "-"}`;
        tbodyEl.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-3">${esc(message || "Unable to load history")}</td></tr>`;
    }

    function formatPeriod(row) {
        const start = row.sys_start || row.SysStartTime || "-";
        const end = row.sys_end || row.SysEndTime || "-";
        return `${esc(start)}<br><span class="text-muted">${esc(end)}</span>`;
    }

    function actorName(row) {
        return row.revise_by_name || row.revise_user_name || row.created_by_name || row.revise_by || "-";
    }

    function renderDiffs(diffs) {
        if (!Array.isArray(diffs) || diffs.length === 0) {
            return '<span class="text-muted">No field changes</span>';
        }

        return `<div class="vstack gap-1">${diffs.map((d) => `
            <div class="border rounded p-2 bg-light">
                <div class="fw-semibold">${esc(d.label || d.field || "-")}</div>
                <div class="small">
                    <span class="text-danger">${esc(d.from || "-")}</span>
                    <span class="text-muted mx-1">-&gt;</span>
                    <span class="text-success">${esc(d.to || "-")}</span>
                </div>
            </div>
        `).join("")}</div>`;
    }

    function renderTop() {
        const payload = state.payload || {};
        const versions = Array.isArray(payload.versions) ? payload.versions : [];
        const diffKey = state.mode === "step" ? "diff_step" : "diff_current";

        if (metaEl) {
            metaEl.textContent = `ord_id: ${payload.ord_id || state.ordId || "-"} | current rev: ${payload.current_revision ?? "-"}`;
        }
        if (colTitleEl) {
            colTitleEl.textContent = state.mode === "step"
                ? "Changed Fields (step by step)"
                : "Changed Fields (compare with current)";
        }

        if (versions.length === 0) {
            tbodyEl.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No history versions found</td></tr>';
            return;
        }

        tbodyEl.innerHTML = versions.map((row) => `
            <tr>
                <td class="text-center">${esc(row.revision_number ?? "-")}</td>
                <td>${formatPeriod(row)}</td>
                <td>${esc(actorName(row))}</td>
                <td>${renderDiffs(row[diffKey])}</td>
            </tr>
        `).join("");
    }

    function renderDbRows(rows) {
        const list = Array.isArray(rows) ? rows : [];

        if (!dbTheadEl || !dbTbodyEl) return;

        if (list.length === 0) {
            dbTheadEl.innerHTML = "";
            dbTbodyEl.innerHTML = '<tr><td class="text-center text-muted py-3">No database history rows found</td></tr>';
            return;
        }

        const columns = Object.keys(list[0]);
        dbTheadEl.innerHTML = columns.map((c) => `<th>${esc(c)}</th>`).join("");
        dbTbodyEl.innerHTML = list.map((row) => `
            <tr>${columns.map((c) => `<td>${esc(row[c] ?? "")}</td>`).join("")}</tr>
        `).join("");
    }

    async function loadHistory(ordId) {
        state.ordId = ordId;
        state.payload = null;
        state.dbRows = null;
        setButtons("current");
        setLoading(`ord_id: ${ordId || "-"}`);

        try {
            const res = await fetch(historyUrl(ordId), {
                headers: { Accept: "application/json" },
                credentials: "same-origin",
            });
            const data = await res.json();

            if (!res.ok || data.ok === false) {
                renderError(data.message || `HTTP ${res.status}`);
                return;
            }

            state.payload = data;
            renderTop();
        } catch (err) {
            renderError(err && err.message ? err.message : "Unable to load history");
        }
    }

    async function loadDbHistory() {
        if (state.dbRows) {
            renderDbRows(state.dbRows);
            return;
        }
        if (state.loadingDb) return;

        state.loadingDb = true;
        if (dbTheadEl) dbTheadEl.innerHTML = "";
        if (dbTbodyEl) {
            dbTbodyEl.innerHTML = '<tr><td class="text-center text-muted py-3">Loading...</td></tr>';
        }

        try {
            const res = await fetch(historyDbUrl(state.ordId), {
                headers: { Accept: "application/json" },
                credentials: "same-origin",
            });
            const data = await res.json();

            if (!res.ok) {
                renderDbRows([]);
                return;
            }

            state.dbRows = Array.isArray(data.rows) ? data.rows : [];
            renderDbRows(state.dbRows);
        } catch (err) {
            if (dbTbodyEl) {
                dbTbodyEl.innerHTML = `<tr><td class="text-center text-danger py-3">${esc(err && err.message ? err.message : "Unable to load database history")}</td></tr>`;
            }
        } finally {
            state.loadingDb = false;
        }
    }

    document.addEventListener("click", (e) => {
        if (!(e.target instanceof Element)) return;

        const btn = e.target.closest(".jsHistoryBtn");
        if (!btn) return;

        loadHistory(btn.dataset.ordId || "");
    });

    if (currentBtn) {
        currentBtn.addEventListener("click", () => {
            setButtons("current");
            renderTop();
        });
    }

    if (stepBtn) {
        stepBtn.addEventListener("click", () => {
            setButtons("step");
            renderTop();
        });
    }

    if (dbBtn) {
        dbBtn.addEventListener("click", () => {
            setButtons("db");
            loadDbHistory();
        });
    }
})();
