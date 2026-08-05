(function inquiryTogglePanels() {
    function bindToggle(buttonId, targetId) {
        const btn = document.getElementById(buttonId);
        const target = document.getElementById(targetId);
        if (!btn || !target) return;

        function setExpanded() {
            btn.setAttribute(
                "aria-expanded",
                target.classList.contains("show") ? "true" : "false",
            );
        }

        btn.addEventListener("click", () => {
            if (typeof bootstrap !== "undefined" && bootstrap.Collapse) {
                bootstrap.Collapse.getOrCreateInstance(target, {
                    toggle: false,
                }).toggle();
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

    // Autocomplete: custom dropdown (style ตาม dp.index)
    (function initAutocomplete() {
        // Source: ดึงจาก server (distinct ทั้ง DB) ก่อน, fallback เป็น rows ในหน้า
        const fullSources = window.DP_AC_SOURCES || {};
        const rows = document.querySelectorAll("#inqTbody tr.data-row");
        const fallback = {
            so: new Set(),
            customer: new Set(),
            shipto: new Set(),
            divsales: new Set(),
        };

        rows.forEach((row) => {
            const so = (row.dataset.so || "").trim();
            const customer = (row.dataset.customer || "").trim();
            const shipto = (row.dataset.shipto || "").trim();
            const group = (row.dataset.group || "").trim();
            if (so) fallback.so.add(so);
            if (customer) fallback.customer.add(customer);
            if (shipto) fallback.shipto.add(shipto);
            if (group) fallback.divsales.add(group);
        });

        const sourceArrays = {};
        ["so", "customer", "shipto", "divsales"].forEach((key) => {
            const fromServer = Array.isArray(fullSources[key])
                ? fullSources[key]
                : [];
            const merged = new Set([...(fromServer || []), ...fallback[key]]);
            sourceArrays[key] = Array.from(merged)
                .map((v) => String(v || "").trim())
                .filter((v) => v !== "")
                .sort((a, b) => a.localeCompare(b, "th"));
        });

        // ปุ่ม clear (X)
        document.querySelectorAll(".dp-ac-clear").forEach((btn) => {
            const targetId = btn.dataset.acClear;
            const input = document.getElementById(targetId);
            if (!input) return;
            const wrap = btn.closest(".dp-ac-wrap");
            const sync = () =>
                wrap?.classList.toggle("has-value", input.value.trim() !== "");
            sync();
            input.addEventListener("input", sync);
            input.addEventListener("change", sync);
            btn.addEventListener("click", (e) => {
                e.preventDefault();
                input.value = "";
                sync();
                input.focus();
                input.dispatchEvent(new Event("input"));
            });
        });

        const escapeHtml = (s) =>
            String(s).replace(
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

        const escapeRegex = (s) =>
            String(s).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

        const highlight = (text, term) => {
            if (!term) return escapeHtml(text);
            const re = new RegExp("(" + escapeRegex(term) + ")", "ig");
            return escapeHtml(text).replace(re, "<mark>$1</mark>");
        };

        document.querySelectorAll(".dp-ac-input").forEach((input) => {
            const sourceKey = input.dataset.acSource || "";
            const list = sourceArrays[sourceKey] || [];
            const dd = document.querySelector(
                `.dp-suggest[data-ac-for="${input.id}"]`,
            );
            if (!dd) return;

            let activeIdx = -1;

            function render(term) {
                const q = (term || "").trim().toLowerCase();
                const matched = q
                    ? list
                          .filter((v) => v.toLowerCase().includes(q))
                          .slice(0, 30)
                    : list.slice(0, 30);

                if (!matched.length) {
                    dd.innerHTML = `<div class="dp-suggest-empty">ไม่พบรายการ</div>`;
                } else {
                    dd.innerHTML = matched
                        .map(
                            (v, i) => `
                            <button type="button" class="dp-suggest-item" data-val="${escapeHtml(v)}" data-idx="${i}">
                                <div class="dp-suggest-title">${highlight(v, term)}</div>
                            </button>`,
                        )
                        .join("");
                }
                dd.classList.remove("d-none");
                activeIdx = -1;
            }

            function hide() {
                dd.classList.add("d-none");
                activeIdx = -1;
            }

            function setActive(idx) {
                const items = dd.querySelectorAll(".dp-suggest-item");
                items.forEach((el) => el.classList.remove("is-active"));
                if (idx >= 0 && idx < items.length) {
                    items[idx].classList.add("is-active");
                    items[idx].scrollIntoView({ block: "nearest" });
                    activeIdx = idx;
                }
            }

            input.addEventListener("focus", () => render(input.value));
            input.addEventListener("input", () => render(input.value));

            input.addEventListener("keydown", (e) => {
                const items = dd.querySelectorAll(".dp-suggest-item");
                if (!items.length || dd.classList.contains("d-none")) return;
                if (e.key === "ArrowDown") {
                    e.preventDefault();
                    setActive(Math.min(activeIdx + 1, items.length - 1));
                } else if (e.key === "ArrowUp") {
                    e.preventDefault();
                    setActive(Math.max(activeIdx - 1, 0));
                } else if (e.key === "Enter" && activeIdx >= 0) {
                    e.preventDefault();
                    input.value = items[activeIdx].dataset.val || "";
                    hide();
                } else if (e.key === "Escape") {
                    hide();
                }
            });

            dd.addEventListener("mousedown", (e) => {
                const btn = e.target.closest(".dp-suggest-item");
                if (!btn) return;
                e.preventDefault();
                input.value = btn.dataset.val || "";
                hide();
                input.focus();
            });

            document.addEventListener("click", (e) => {
                if (!input.contains(e.target) && !dd.contains(e.target)) hide();
            });
        });
    })();

    // Export ทั้งหมด — โหลด Excel แล้วเปิด PDF tab ใหม่
    const btnExportBoth = document.getElementById("btnExportBoth");
    if (btnExportBoth) {
        btnExportBoth.addEventListener("click", () => {
            const excelUrl = btnExportBoth.dataset.excelUrl || "";
            const pdfUrl = btnExportBoth.dataset.pdfUrl || "";
            if (excelUrl) {
                window.location.href = excelUrl;
            }
            if (pdfUrl) {
                setTimeout(
                    () => window.open(pdfUrl, "_blank", "noopener"),
                    600,
                );
            }
        });
    }
})();

(function inquiryExcelTable() {
    const table = document.getElementById("inqTable");
    const tbody = document.getElementById("inqTbody");
    if (!table || !tbody || !table.tHead || !table.tBodies.length) return;

    const headerRow = table.tHead.querySelector(".dp-inquiry-header-row");
    if (!headerRow || table.tHead.querySelector(".dp-inquiry-filter-row"))
        return;

    const hasBulkTruckColumn = !!document.getElementById("bulkTruckCheckAll");
    const hasBulkPostponeColumn = !!document.getElementById(
        "bulkPostponeCheckAll",
    );
    const columnKeys = [
        ...(hasBulkTruckColumn ? ["bulk_truck"] : []),
        ...(hasBulkPostponeColumn ? ["bulk_select"] : []),
        "no",
        "ship_date",
        "truck",
        "customer",
        "part_desc",
        "mfg",
        "qty",
        "stock_fg",
        "sell_by_line",
        "shipto",
        "so",
        "docs",
        "more",
        "contact",
        "revision",
        "status",
        "action",
        "history",
    ];
    const dataRows = () => Array.from(tbody.querySelectorAll("tr.data-row"));
    const groupRows = () => Array.from(tbody.querySelectorAll("tr.group-row"));
    const originalGroupRows = groupRows();
    const numericColumns = new Set(["no", "qty", "stock_fg", "revision"]);
    const systemColumns = [
        "bulk_truck",
        "bulk_select",
        "no",
        "action",
        "history",
    ];
    const filterableColumns = new Set(
        columnKeys.filter((key) => !systemColumns.includes(key)),
    );
    const protectedColumns = new Set(["bulk_truck", "bulk_select", "no"]);
    const hiddenStorageKey = "dp.inquiry.hiddenColumns.v3";
    const orderStorageKey = "dp.inquiry.columnOrder.v3";
    const defaultOrder = columnKeys.slice();
    let hiddenSet = new Set();
    let columnOrder = defaultOrder.slice();
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

    function readStoredArray(key) {
        try {
            const value = JSON.parse(localStorage.getItem(key) || "[]");
            return Array.isArray(value) ? value : [];
        } catch (_) {
            return [];
        }
    }

    function writeStoredArray(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (_) {
            // The current page still updates when browser storage is unavailable.
        }
    }

    function normalizeOrder(order) {
        const seen = new Set();
        const valid = [];
        const fixedLeading = columnKeys.filter((key) =>
            ["bulk_truck", "bulk_select", "no"].includes(key),
        );
        fixedLeading.forEach((key) => {
            seen.add(key);
            valid.push(key);
        });

        order.forEach((key) => {
            if (columnKeys.includes(key) && !seen.has(key)) {
                seen.add(key);
                valid.push(key);
            }
        });
        defaultOrder.forEach((key) => {
            if (!seen.has(key)) valid.push(key);
        });
        return valid;
    }

    function loadColumnPrefs() {
        hiddenSet = new Set(
            readStoredArray(hiddenStorageKey).filter(
                (key) => columnKeys.includes(key) && !protectedColumns.has(key),
            ),
        );
        columnOrder = normalizeOrder(readStoredArray(orderStorageKey));
    }

    function assignColumnKeys() {
        Array.from(headerRow.cells).forEach((cell, idx) => {
            if (columnKeys[idx]) cell.dataset.colKey = columnKeys[idx];
        });

        dataRows().forEach((row) => {
            Array.from(row.cells).forEach((cell, idx) => {
                if (columnKeys[idx]) cell.dataset.colKey = columnKeys[idx];
            });
        });
    }

    function cellByKey(row, key) {
        return row.querySelector(`[data-col-key="${key}"]`);
    }

    function cellText(row, col) {
        const cell = cellByKey(row, col);
        return cell ? cell.innerText.trim() : "";
    }

    function sortValue(row, col) {
        if (col === "ship_date") return row.dataset.ship || "";
        if (col === "customer") return row.dataset.customer || "";
        if (col === "part_desc") return row.dataset.part || "";
        if (col === "qty")
            return parseNum(row.dataset.qty || cellText(row, col));
        if (col === "so") return row.dataset.so || "";
        if (col === "revision")
            return parseNum(row.dataset.rev || cellText(row, col));
        return numericColumns.has(col)
            ? parseNum(cellText(row, col))
            : normalizeText(cellText(row, col));
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
                const col =
                    input.dataset.filterKey || input.dataset.filterCol || "";
                return {
                    col,
                    value: input.value,
                    isNumeric: numericColumns.has(col),
                };
            })
            .filter((item) => String(item.value || "").trim() !== "");
    }

    function setKpi(visibleRows) {
        const rows =
            visibleRows ||
            dataRows().filter((row) => row.style.display !== "none");
        const total = dataRows().length;

        const num = (v) => {
            const n = parseFloat(v);
            return Number.isFinite(n) ? n : 0;
        };
        const fmtInt = (n) => Math.round(n).toLocaleString();

        let totalKg = 0;
        let remainKg = 0;
        let noTruck = 0;
        let revised = 0;
        const postponedKeys = new Set();

        rows.forEach((row) => {
            const qty = num(row.dataset.qty);
            const remaining = num(row.dataset.remaining);
            const hasTruck = String(row.dataset.hasTruck || "0") === "1";
            const rev = parseInt(row.dataset.rev || "0", 10) || 0;
            const status = String(row.dataset.status || "").toUpperCase();
            const pcStatus = String(row.dataset.pcStatus || "").toUpperCase();
            const so = String(row.dataset.so || "").trim();
            const mfg = String(row.dataset.mfg || "").trim();

            totalKg += qty;
            remainKg += remaining;

            // นับ line ที่ planner ขอเลื่อน — dedupe ตาม MFG (ไม่นับ revision/รอบเดิม)
            // ถ้าไม่มี MFG ใช้ SO แทน, สุดท้ายเข้า DOM id เพื่อไม่ให้สูญ
            if (status === "POSTPONED" || pcStatus === "POSTPONE") {
                const key =
                    mfg ||
                    so ||
                    (row.dataset.part || "") + "|" + (row.dataset.ship || "");
                postponedKeys.add(key);
            }

            if (
                !hasTruck &&
                ![
                    "CLOSED",
                    "VOID",
                    "VOIDED",
                    "CANCEL",
                    "CANCELED",
                    "CANCELLED",
                    "POSTPONED",
                ].includes(status)
            ) {
                noTruck += 1;
            }

            if (rev > 0) revised += 1;
        });

        const postponed = postponedKeys.size;

        const set = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        set("kpi_visible", rows.length.toLocaleString());
        set("kpi_total", total.toLocaleString());
        set("kpi_total_kg", fmtInt(totalKg));
        set("kpi_remain_kg", fmtInt(remainKg));
        set("kpi_postponed", postponed.toLocaleString());
        set("kpi_no_truck", noTruck.toLocaleString());
        set("kpi_rev", revised.toLocaleString());
    }

    function updateGroupRows() {
        groupRows().forEach((groupRow) => {
            const group = groupRow.dataset.group || "";
            const hasVisible = dataRows().some(
                (row) =>
                    row.dataset.group === group && row.style.display !== "none",
            );
            groupRow.style.display = hasVisible ? "" : "none";
        });
    }

    function applyFilters() {
        const filters = activeFilters();
        const visibleRows = [];

        dataRows().forEach((row) => {
            const ok = filters.every((filter) =>
                compareFilter(
                    cellText(row, filter.col),
                    filter.value,
                    filter.isNumeric,
                ),
            );
            row.style.display = ok ? "" : "none";
            if (ok) visibleRows.push(row);
        });

        updateGroupRows();
        setKpi(visibleRows);
        document.dispatchEvent(new CustomEvent("dp:inquiry-filtered"));
    }

    function visibleColumnCount() {
        return columnOrder.filter((key) => !hiddenSet.has(key)).length || 1;
    }

    function updateStickyColumns() {
        // ปิดการตรึงคอลัมน์ซ้าย-ขวา (มีฟีเจอร์สลับ/ซ่อนคอลัมน์ ทำให้ตำแหน่งเพี้ยน)
        // ล้าง inline left ที่อาจค้างจากเวอร์ชันก่อน
        table.querySelectorAll(".sticky-col[data-col-key]").forEach((cell) => {
            cell.style.left = "";
        });
    }

    function applyColumnVisibility() {
        columnKeys.forEach((key) => {
            table
                .querySelectorAll(`[data-col-key="${key}"]`)
                .forEach((cell) => {
                    cell.style.display = hiddenSet.has(key) ? "none" : "";
                });
        });

        groupRows().forEach((row) => {
            const cell = row.cells[0];
            if (cell) cell.colSpan = visibleColumnCount();
        });

        const hiddenCount = document.getElementById("inqHiddenColumnCount");
        if (hiddenCount) {
            hiddenCount.textContent = hiddenSet.size;
            hiddenCount.classList.toggle("bg-danger", hiddenSet.size > 0);
            hiddenCount.classList.toggle("bg-secondary", hiddenSet.size === 0);
        }

        document
            .querySelectorAll("#inqColumnToggleList input[data-col-key]")
            .forEach((input) => {
                input.checked = !hiddenSet.has(input.dataset.colKey || "");
            });

        updateStickyColumns();
    }

    function orderedCells(row) {
        const byKey = new Map();
        Array.from(row.children).forEach((cell) => {
            if (cell.dataset.colKey) byKey.set(cell.dataset.colKey, cell);
        });
        return columnOrder.map((key) => byKey.get(key)).filter(Boolean);
    }

    function applyColumnOrder() {
        orderedCells(headerRow).forEach((cell) => headerRow.appendChild(cell));
        const filterRow = table.tHead.querySelector(".dp-inquiry-filter-row");
        if (filterRow)
            orderedCells(filterRow).forEach((cell) =>
                filterRow.appendChild(cell),
            );
        dataRows().forEach((row) =>
            orderedCells(row).forEach((cell) => row.appendChild(cell)),
        );
        applyColumnVisibility();
    }

    function headerLabel(key) {
        if (key === "bulk_select") return "เลือกย้ายแผน";
        const th = headerRow.querySelector(`[data-col-key="${key}"]`);
        if (!th) return key;
        const clone = th.cloneNode(true);
        clone
            .querySelectorAll("button, .sort-ind")
            .forEach((el) => el.remove());
        return clone.textContent.trim().replace(/\s+/g, " ") || key;
    }

    function saveHiddenColumns() {
        writeStoredArray(hiddenStorageKey, Array.from(hiddenSet));
    }

    function saveColumnOrder() {
        writeStoredArray(orderStorageKey, columnOrder);
    }

    function moveColumnTo(sourceKey, targetKey) {
        if (!sourceKey || !targetKey || sourceKey === targetKey) return;
        const from = columnOrder.indexOf(sourceKey);
        const to = columnOrder.indexOf(targetKey);
        if (from < 0 || to < 0) return;
        if (protectedColumns.has(sourceKey) || protectedColumns.has(targetKey))
            return;

        const next = columnOrder.slice();
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        columnOrder = next;
        saveColumnOrder();
        applyColumnOrder();
        renderColumnMenu();
    }

    function renderColumnMenu() {
        const list = document.getElementById("inqColumnToggleList");
        if (!list) return;

        list.innerHTML = "";
        let draggedKey = "";
        columnOrder.forEach((key, idx) => {
            const item = document.createElement("div");
            item.className = "inq-column-item";
            item.classList.toggle("is-hidden", hiddenSet.has(key));
            item.draggable = !protectedColumns.has(key);
            item.dataset.colKey = key;
            const label = headerLabel(key);
            item.innerHTML = `
                <span class="inq-column-drag" title="Drag to move"><i class="fas fa-grip-vertical"></i></span>
                <button type="button" class="inq-column-eye ${hiddenSet.has(key) ? "is-hidden" : ""}" data-col-key="${key}" title="${hiddenSet.has(key) ? "Show column" : "Hide column"}" ${protectedColumns.has(key) ? "disabled" : ""}>
                    <i class="fas ${hiddenSet.has(key) ? "fa-eye-slash" : "fa-eye"}"></i>
                </button>
                <span title="${label}">${label}</span>
                <button type="button" class="inq-column-move" data-move="-1" data-col-key="${key}" ${idx === 0 || protectedColumns.has(key) ? "disabled" : ""}>
                    <i class="fas fa-arrow-up"></i>
                </button>
                <button type="button" class="inq-column-move" data-move="1" data-col-key="${key}" ${idx === columnOrder.length - 1 || protectedColumns.has(key) ? "disabled" : ""}>
                    <i class="fas fa-arrow-down"></i>
                </button>
            `;

            item.addEventListener("dragstart", (event) => {
                if (protectedColumns.has(key)) {
                    event.preventDefault();
                    return;
                }
                draggedKey = key;
                item.classList.add("is-dragging");
                event.dataTransfer.effectAllowed = "move";
                event.dataTransfer.setData("text/plain", key);
            });
            item.addEventListener("dragover", (event) => {
                event.preventDefault();
                event.dataTransfer.dropEffect = "move";
            });
            item.addEventListener("drop", (event) => {
                event.preventDefault();
                const sourceKey =
                    event.dataTransfer.getData("text/plain") || draggedKey;
                moveColumnTo(sourceKey, key);
            });
            item.addEventListener("dragend", () => {
                draggedKey = "";
                item.classList.remove("is-dragging");
            });

            const eyeBtn = item.querySelector(".inq-column-eye");
            eyeBtn.addEventListener("click", (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (hiddenSet.has(key)) {
                    hiddenSet.delete(key);
                } else if (!protectedColumns.has(key)) {
                    hiddenSet.add(key);
                }
                saveHiddenColumns();
                applyColumnVisibility();
                renderColumnMenu();
            });

            item.querySelectorAll("button[data-move]").forEach((btn) => {
                btn.addEventListener("click", (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const current = columnOrder.indexOf(key);
                    const next = current + Number(btn.dataset.move || 0);
                    if (current < 0 || next < 0 || next >= columnOrder.length)
                        return;
                    if (
                        protectedColumns.has(key) ||
                        protectedColumns.has(columnOrder[next])
                    )
                        return;
                    [columnOrder[current], columnOrder[next]] = [
                        columnOrder[next],
                        columnOrder[current],
                    ];
                    saveColumnOrder();
                    applyColumnOrder();
                    renderColumnMenu();
                });
            });

            list.appendChild(item);
        });
    }

    function hideColumn(key) {
        if (protectedColumns.has(key)) return;
        hiddenSet.add(key);
        saveHiddenColumns();
        applyColumnVisibility();
        renderColumnMenu();
    }

    function applySort(col) {
        if (col === "bulk_select" || !col) return;
        const dir = state.sortCol === col ? state.sortDir * -1 : 1;
        state.sortCol = col;
        state.sortDir = dir;

        Array.from(headerRow.cells).forEach((th) => {
            const ind = th.querySelector(".sort-ind");
            if (ind) ind.textContent = "";
        });

        const indicator = headerRow.querySelector(
            `[data-col-key="${col}"] .sort-ind`,
        );
        if (indicator) indicator.textContent = dir === 1 ? "▲" : "▼";

        const sorted = dataRows().sort((a, b) => {
            const av = sortValue(a, col);
            const bv = sortValue(b, col);
            if (numericColumns.has(col)) return (Number(av) - Number(bv)) * dir;
            return (
                String(av).localeCompare(String(bv), undefined, {
                    numeric: true,
                }) * dir
            );
        });

        if (originalGroupRows.length) {
            const fragment = document.createDocumentFragment();
            const usedRows = new Set();

            originalGroupRows.forEach((groupRow) => {
                const group = groupRow.dataset.group || "";
                const rows = sorted.filter(
                    (row) => row.dataset.group === group,
                );

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

    loadColumnPrefs();
    assignColumnKeys();
    applyColumnOrder();

    Array.from(headerRow.cells).forEach((th) => {
        const key = th.dataset.colKey || "";
        if (key !== "bulk_select") {
            th.classList.add("sortable");
            const indicator = document.createElement("span");
            indicator.className = "sort-ind";
            th.appendChild(indicator);
            th.addEventListener("click", () => applySort(key));
        }

        if (key && !protectedColumns.has(key)) {
            const hideBtn = document.createElement("button");
            hideBtn.type = "button";
            hideBtn.className = "inq-hide-col-btn";
            hideBtn.title = "Hide column";
            hideBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
            hideBtn.addEventListener("click", (event) => {
                event.preventDefault();
                event.stopPropagation();
                hideColumn(key);
            });
            th.appendChild(hideBtn);
        }
    });

    const filterRow = document.createElement("tr");
    filterRow.className = "dp-inquiry-filter-row";

    Array.from(headerRow.cells).forEach((th) => {
        const key = th.dataset.colKey || "";
        const filterTh = document.createElement("th");
        filterTh.dataset.colKey = key;
        filterTh.className = th.className.replace(/\bsortable\b/g, "").trim();

        if (filterableColumns.has(key)) {
            const input = document.createElement("input");
            input.type = "text";
            input.className = "form-control form-control-sm dp-col-filter";
            input.dataset.filterKey = key;
            input.placeholder = numericColumns.has(key)
                ? ">= or text"
                : "Filter";
            input.addEventListener("input", applyFilters);
            input.addEventListener("click", (event) => event.stopPropagation());
            filterTh.appendChild(input);
        }

        filterRow.appendChild(filterTh);
    });

    table.tHead.appendChild(filterRow);

    applyColumnOrder();
    renderColumnMenu();
    applyColumnVisibility();
    window.addEventListener("resize", updateStickyColumns);

    const showAllBtn = document.getElementById("inqShowAllColumnsBtn");
    if (showAllBtn) {
        showAllBtn.addEventListener("click", () => {
            hiddenSet.clear();
            saveHiddenColumns();
            applyColumnVisibility();
            renderColumnMenu();
        });
    }

    const resetBtn = document.getElementById("inqResetColumnsBtn");
    if (resetBtn) {
        resetBtn.addEventListener("click", () => {
            hiddenSet.clear();
            columnOrder = defaultOrder.slice();
            saveHiddenColumns();
            saveColumnOrder();
            applyColumnOrder();
            renderColumnMenu();
        });
    }

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
    const tmTripNo = byId("tmTripNo");
    const tmTripMinus = byId("tmTripMinus");
    const tmTripPlus = byId("tmTripPlus");
    const tmTripControl = byId("tmTripControl");
    const tmDispatchTruckMode = byId("tmDispatchTruckMode");
    const tmDispatchSpecialMode = byId("tmDispatchSpecialMode");
    const tmLineSelectPanel = byId("tmLineSelectPanel");
    const tmTruckPickPanel = byId("tmTruckPickPanel");
    const tmSpecialDispatchPanel = byId("tmSpecialDispatchPanel");
    const tmSpecialDispatchType = byId("tmSpecialDispatchType");
    const tmSpecialDispatchRemark = byId("tmSpecialDispatchRemark");
    const tmFooterHelp = byId("tmFooterHelp");

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
    const bulkTruckCheckAll = byId("bulkTruckCheckAll");
    const bulkTruckChecks = Array.from(
        document.querySelectorAll(".jsBulkTruckCheck"),
    );
    const bulkTruckOpenBtn = byId("bulkTruckOpenBtn");
    const bulkTruckClearBtn = byId("bulkTruckClearBtn");
    const bulkTruckSameCustomerBtn = byId("bulkTruckSameCustomerBtn");
    const bulkTruckUnassignOpenBtn = byId("bulkTruckUnassignOpenBtn");
    const bulkTruckCount = byId("bulkTruckCount");
    const bulkTruckCustomer = byId("bulkTruckCustomer");

    const tmDriverStaffId = byId("tmDriverStaffId");
    const tmHelper1StaffId = byId("tmHelper1StaffId");
    const tmHelper2StaffId = byId("tmHelper2StaffId");
    const tmHelper3StaffId = byId("tmHelper3StaffId");
    const tmHelper4StaffId = byId("tmHelper4StaffId");
    const tmHelper5StaffId = byId("tmHelper5StaffId");
    const tmHelperStaffIds = [
        tmHelper1StaffId,
        tmHelper2StaffId,
        tmHelper3StaffId,
        tmHelper4StaffId,
        tmHelper5StaffId,
    ].filter(Boolean);

    const state = {
        so: "",
        shipDate: "",
        shipto: "",
        ordId: "",
        lines: [],
        currentTruckId: "",
        currentSpecialType: "",
        currentStatus: "",
        isSubmitting: false,
    };
    let staffOptionsPromise = null;

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

    function warn(message, title = "ตรวจสอบข้อมูล") {
        const scrollY = window.scrollY || 0;
        const restoreScroll = () => {
            if (scrollY > 0) {
                window.scrollTo({ top: scrollY, left: 0, behavior: "auto" });
            }
        };

        if (window.Swal && typeof window.Swal.fire === "function") {
            window.Swal.fire({
                icon: "warning",
                title,
                text: message,
                confirmButtonText: "ตกลง",
                heightAuto: false,
                returnFocus: false,
                scrollbarPadding: false,
                didOpen: restoreScroll,
                didClose: restoreScroll,
            });
            return;
        }

        alert(message);
        restoreScroll();
    }

    function numberFormat(value, digits = 3) {
        const n = Number(value || 0);
        return n.toLocaleString(undefined, {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        });
    }

    function weightFormat(kg) {
        const value = Number(kg || 0);
        return String(Math.round(value));
    }

    function pieceFormat(value) {
        return `${numberFormat(value, 0)} ชิ้น`;
    }

    function isPieceLine(line) {
        return (
            Number(line?.sell_by_line || 0) === 1 &&
            Number(line?.line_qty || 0) > 0 &&
            Number(line?.qty_total || 0) === 0
        );
    }

    function loadDisplayFormat(weightKg, pieceQty = 0) {
        const weight = Number(weightKg || 0);
        const pieces = Number(pieceQty || 0);
        if (weight > 0 && pieces > 0) {
            return `${weightFormat(weight)} + ${pieceFormat(pieces)}`;
        }
        if (pieces > 0) return pieceFormat(pieces);
        return weightFormat(weight);
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
        tmHelperStaffIds.forEach((selectEl) => {
            selectEl.value = "";
        });
    }

    function simplifyHelperInputs() {
        tmHelperStaffIds.forEach((selectEl, index) => {
            const slot = index + 1;
            const wrap = selectEl.closest(".tm-staff-field");
            const label = wrap ? wrap.querySelector("label") : null;
            const placeholder = selectEl.querySelector("option[value='']");

            selectEl.disabled = false;
            selectEl.name = `helper${slot}_staff_id`;
            if (wrap) wrap.classList.remove("d-none");
            if (label) label.textContent = `เด็กรถ ${slot}`;
            if (placeholder)
                placeholder.textContent = `-- เลือกเด็กรถ ${slot} --`;
        });
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
        if (staffOptionsPromise) return staffOptionsPromise;

        staffOptionsPromise = (async () => {
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
        })().catch(() => {
            staffOptionsPromise = null;
        });

        return staffOptionsPromise;
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
        [
            [tmHelper4StaffId, "-- เลือกเด็กรถ 4 --"],
            [tmHelper5StaffId, "-- เลือกเด็กรถ 5 --"],
        ].forEach(([selectEl, placeholder]) => {
            fillOneStaffSelect(selectEl, data.helpers || [], placeholder);
        });
    }

    async function loadTruckStaffDefaults(params = {}) {
        if (!ROUTES.truckStaffDefaults) return;
        await loadTruckStaffOptions();

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
        } catch (_) {}
    }

    function applyTruckStaffDefaults(data) {
        const driver = data.driver || null;
        const helpers = Array.isArray(data.helpers) ? data.helpers : [];

        if (tmDriverStaffId) {
            ensureStaffOption(tmDriverStaffId, driver);
            tmDriverStaffId.value =
                driver && driver.id ? String(driver.id) : "";
        }
        tmHelperStaffIds.forEach((selectEl, index) => {
            const helper = helpers[index] || null;
            ensureStaffOption(selectEl, helper);
            selectEl.value = helper && helper.id ? String(helper.id) : "";
        });
    }

    function ensureStaffOption(selectEl, staff) {
        if (!selectEl || !staff || !staff.id) return;
        const id = String(staff.id);
        if (Array.from(selectEl.options).some((option) => option.value === id))
            return;

        const option = document.createElement("option");
        option.value = id;
        option.textContent = staff.name || `Staff #${id}`;
        selectEl.appendChild(option);
    }

    function setTruckAssignAction(ordId) {
        const base = ROUTES.truckAssign || "";
        if (form) {
            form.action = String(base).replace("__ID__", String(ordId || 0));
        }
    }

    function setSpecialDispatchAction(ordId) {
        const base = ROUTES.specialDispatch || "";
        if (form) {
            form.action = String(base).replace("__ID__", String(ordId || 0));
        }
    }

    function isSpecialDispatchMode() {
        return !!tmDispatchSpecialMode?.checked;
    }

    function setDispatchMode(mode) {
        const specialMode = String(mode || "").toUpperCase() === "SPECIAL";

        if (tmDispatchTruckMode) tmDispatchTruckMode.checked = !specialMode;
        if (tmDispatchSpecialMode) tmDispatchSpecialMode.checked = specialMode;

        [tmLineSelectPanel, tmTruckPickPanel, tmTripControl].forEach((el) => {
            if (el) el.classList.toggle("d-none", specialMode);
        });

        if (tmSpecialDispatchPanel) {
            tmSpecialDispatchPanel.classList.toggle("d-none", !specialMode);
        }

        if (tmSpecialDispatchType)
            tmSpecialDispatchType.disabled = !specialMode;
        if (tmSpecialDispatchRemark)
            tmSpecialDispatchRemark.disabled = !specialMode;

        if (tmFooterHelp) {
            tmFooterHelp.textContent = specialMode
                ? "ช่องทางพิเศษจะไม่กิน capacity รถ และจะติดตามแยกใน dashboard งานพิเศษ"
                : "ระบบจะบันทึกตามน้ำหนักที่รถยังรับได้จริง และถ้าเต็มก่อนจะบันทึกเฉพาะบางส่วน";
        }

        if (submitBtn) {
            submitBtn.classList.toggle("btn-primary", !specialMode);
            submitBtn.classList.toggle("btn-warning", specialMode);
            submitBtn.textContent = specialMode
                ? "บันทึกช่องทางพิเศษ"
                : "บันทึก";
        }

        if (specialMode) {
            setSpecialDispatchAction(state.ordId);
            clearTruckSelection();
            clearManualHiddenFields();
            if (tmManualMode) tmManualMode.checked = false;
            return;
        }

        setTruckAssignAction(state.ordId);
        updateTruckRemainPreview();
    }

    function stepTrip(delta) {
        if (!tmTripNo) return;
        const current = Math.max(1, parseInt(tmTripNo.value || "1", 10) || 1);
        const next = Math.min(99, Math.max(1, current + delta));
        tmTripNo.value = String(next);
        tmTripNo.dispatchEvent(new Event("input", { bubbles: true }));
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
            const manualKgInput = tr.querySelector(".tmAssignWeightKg");
            const manualKg = Number(manualKgInput?.value || 0);
            const qty =
                manualKg > 0 ? manualKg : Number(tr.dataset.qtyRemaining || 0);
            return sum + qty;
        }, 0);
    }

    function selectedPieceQty() {
        if (!modalEl) return 0;
        return Array.from(
            modalEl.querySelectorAll(".tmOrdCheckbox:checked"),
        ).reduce((sum, checkbox) => {
            const tr = checkbox.closest("tr");
            if (!tr || tr.dataset.pieceLine !== "1") return sum;
            const manualKgInput = tr.querySelector(".tmAssignWeightKg");
            if (Number(manualKgInput?.value || 0) > 0) return sum;
            return sum + Number(tr.dataset.lineQty || 0);
        }, 0);
    }

    function updateTruckRemainPreview() {
        const selectedWeight = selectedTotalQty();
        const selectedPieces = selectedPieceQty();

        if (tmSelectedWeight) {
            tmSelectedWeight.textContent = loadDisplayFormat(
                selectedWeight,
                selectedPieces,
            );
        }

        if (!tmTruckRemainAfter || !modalEl) return;

        const selectedTruck = modalEl.querySelector(".tmTruckRadio:checked");

        if (!selectedTruck) {
            tmTruckRemainAfter.textContent = "-";
            tmTruckRemainAfter.classList.remove("text-danger", "text-success");
            return;
        }

        const capacityUnlimited =
            String(selectedTruck.dataset.capacityUnlimited || "") === "1";
        if (capacityUnlimited) {
            tmTruckRemainAfter.textContent = "ตามน้ำหนักที่กรอก";
            tmTruckRemainAfter.classList.remove("text-danger");
            tmTruckRemainAfter.classList.add("text-success");
            return;
        }

        const currentRemain = Number(selectedTruck.dataset.remaining || 0);
        const remainAfter = currentRemain - selectedWeight;

        tmTruckRemainAfter.textContent =
            selectedWeight > 0
                ? weightFormat(remainAfter)
                : weightFormat(currentRemain);
        tmTruckRemainAfter.classList.toggle("text-danger", remainAfter < 0);
        tmTruckRemainAfter.classList.toggle("text-success", remainAfter >= 0);
    }

    function renderLineTable(lines) {
        if (!tmLineTbody || !tmTotalQty) return;

        const safeLines = Array.isArray(lines) ? lines : [];
        let total = 0;
        let totalPieces = 0;

        if (!safeLines.length) {
            tmLineTbody.innerHTML =
                '<tr><td colspan="9" class="text-center text-muted">ไม่มีรายการ</td></tr>';
            tmTotalQty.textContent = weightFormat(0);

            if (tmSelectedWeight) {
                tmSelectedWeight.textContent = weightFormat(0);
            }

            updateTruckRemainPreview();
            return;
        }

        const html = safeLines
            .map((line) => {
                const qtyAssigned = Number(line.qty_assigned || 0);
                const qtyRemaining = Number(line.qty_remaining || 0);
                const pieceLine = isPieceLine(line);
                const lineQty = Number(line.line_qty || 0);

                const lineText =
                    Number(line.sell_by_line || 0) === 1
                        ? String(line.line_text || "ระบุเส้น").trim()
                        : "-";

                const shipto = String(line.shipto || "-").trim() || "-";

                total += qtyRemaining;
                if (pieceLine) totalPieces += lineQty;

                return `
                    <tr data-qty-remaining="${qtyRemaining}" data-piece-line="${pieceLine ? "1" : "0"}" data-line-qty="${lineQty}">
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
                        <td class="text-end">${pieceLine && qtyAssigned <= 0 ? "-" : weightFormat(qtyAssigned)}</td>
                        <td class="text-end">${pieceLine && qtyRemaining <= 0 ? pieceFormat(lineQty) : weightFormat(qtyRemaining)}</td>
                        <td>
                            <input type="number"
                                class="form-control form-control-sm text-end tmAssignWeightKg"
                                name="assign_weight_kg[${esc(line.ord_id || "")}]"
                                min="0"
                                step="1"
                                value="${weightFormat(qtyRemaining)}"
                                placeholder="ใส่เอง">
                        </td>
                    </tr>
                `;
            })
            .join("");

        tmLineTbody.innerHTML = html;
        tmTotalQty.textContent = loadDisplayFormat(total, totalPieces);

        if (tmSelectedWeight) {
            tmSelectedWeight.textContent = weightFormat(total);
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
                const capacityUnlimited = !!t.capacity_unlimited || max <= 0;
                const rowKey = String(t.row_key || "");
                const truckId = t.truck_id ?? "";
                const manualPlate = t.manual_plate_no ?? "";
                const maxText = capacityUnlimited
                    ? "ไม่ระบุ"
                    : weightFormat(max);
                const remainingText = capacityUnlimited
                    ? "ตามน้ำหนักที่กรอก"
                    : weightFormat(remaining);

                const jobSummary = Array.isArray(t.job_summary)
                    ? t.job_summary
                    : [];
                const jobSummaryText = jobSummary
                    .map((x) => {
                        const customer = x.customer_name || "-";
                        const jobType = x.job_type || "-";
                        const weight = weightFormat(
                            Number(x.assigned_weight || 0),
                        );
                        return `${customer} | ${jobType} (${weight})`;
                    })
                    .join(" || ");

                const soSummaryText = t.so_summary_text || "";
                const mfgSummaryText = t.mfg_summary_text || "";

                const tooltipLines = [];
                if (jobSummary.length) {
                    jobSummary.forEach((x) => {
                        tooltipLines.push(
                            `${x.so_number || soSummaryText || "-"} || ${x.mfg_no || mfgSummaryText || "-"} || ${weightFormat(Number(x.assigned_weight || 0))} || ${x.address || "-"}`,
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
                            <span class="text-primary">(${weightFormat(Number(x.assigned_weight || 0))})</span>
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
                                data-capacity-unlimited="${capacityUnlimited ? "1" : "0"}"
                                data-car-length="${esc(t.car_length ?? "")}"
                                data-remark="${esc(t.remark || "")}"
                                data-job-summary="${esc(jobSummaryText)}"
                                ${!capacityUnlimited && remaining <= 0 ? "disabled" : ""}>
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

                        <td class="text-end">${maxText}</td>
                        <td class="text-end">${weightFormat(current)}</td>
                        <td
                            class="text-end ${!capacityUnlimited && remaining < 0 ? "text-danger fw-bold" : "fw-semibold"}"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top"
                            data-bs-html="false"
                            data-bs-custom-class="truck-remain-tooltip"
                            title="${esc(remainTooltip)}"
                        >
                            ${remainingText}
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
            const tripNo = Math.max(
                1,
                parseInt(tmTripNo?.value || "1", 10) || 1,
            );
            const url = `${ROUTES.truckCapacity}?ship_posted_at=${encodeURIComponent(shipDate)}&trip_no=${encodeURIComponent(tripNo)}`;
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
        const currentSpecialType = String(
            btn.dataset.currentSpecialType || "",
        ).trim();
        const currentStatus = String(btn.dataset.status || "")
            .trim()
            .toUpperCase();
        const lines = parseJsonSafe(btn.dataset.lines || "[]", []);

        state.ordId = ordId;
        state.so = so;
        state.shipDate = shipDate;
        state.shipto = shipto;
        state.lines = Array.isArray(lines) ? lines : [];
        state.currentTruckId = currentTruckId;
        state.currentSpecialType = currentSpecialType;
        state.currentStatus = currentStatus;

        if (tmSoText) tmSoText.textContent = so || "-";
        if (tmShipDateText) tmShipDateText.textContent = shipDate || "-";
        if (tmShipToText) tmShipToText.textContent = shipto || "-";

        if (tmOrdId) tmOrdId.value = ordId;
        if (tmSoHidden) tmSoHidden.value = so;
        if (tmShipDateHidden) tmShipDateHidden.value = shipDate;
        if (tmReturnUrl) tmReturnUrl.value = window.location.href;
        if (tmTripNo) tmTripNo.value = "1";
        if (tmSpecialDispatchType) {
            tmSpecialDispatchType.value =
                currentSpecialType || "CONTAINER_LOAD";
        }
        if (tmSpecialDispatchRemark) {
            tmSpecialDispatchRemark.value = "";
        }

        clearStaffSelection();
        loadTruckStaffOptions();

        const lockedToSpecial = currentStatus === "POSTPONED";
        if (tmDispatchTruckMode) tmDispatchTruckMode.disabled = lockedToSpecial;
        if (lockedToSpecial || currentStatus === "SPECIAL") {
            setSpecialDispatchAction(ordId);
            setDispatchMode("SPECIAL");
        } else {
            setTruckAssignAction(ordId);
            setDispatchMode("TRUCK");
        }
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

    function bulkTruckKey(input) {
        const dataset = input?.dataset || {};
        const customerId = String(dataset.customerId || "").trim();
        const customer = String(dataset.customer || "").trim();
        const shipDate = String(dataset.shipDate || "").trim();

        return {
            customerKey: customerId || customer,
            customer,
            shipDate,
        };
    }

    function visibleBulkTruckChecks() {
        return bulkTruckChecks.filter((input) => {
            if (input.disabled) return false;
            const row = input.closest("tr");
            return !row || row.style.display !== "none";
        });
    }

    function selectedBulkTruckChecks() {
        return bulkTruckChecks.filter(
            (input) => input.checked && !input.disabled,
        );
    }

    function selectedBulkTruckUnassignChecks() {
        return selectedBulkTruckChecks().filter(
            (input) => String(input.dataset.hasTruck || "0") === "1",
        );
    }

    function bulkTruckMatchesShipDate(input, seed) {
        const key = bulkTruckKey(input);
        return key.shipDate !== "" && key.shipDate === seed.shipDate;
    }

    function updateBulkTruckState() {
        const selected = selectedBulkTruckChecks();
        const visible = visibleBulkTruckChecks();

        if (bulkTruckCount)
            bulkTruckCount.textContent = String(selected.length);
        if (bulkTruckOpenBtn) bulkTruckOpenBtn.disabled = selected.length === 0;
        if (bulkTruckClearBtn)
            bulkTruckClearBtn.disabled = selected.length === 0;
        if (bulkTruckSameCustomerBtn)
            bulkTruckSameCustomerBtn.disabled = visible.length === 0;
        if (bulkTruckUnassignOpenBtn) {
            bulkTruckUnassignOpenBtn.disabled =
                selectedBulkTruckUnassignChecks().length === 0;
        }

        if (bulkTruckCustomer) {
            if (!selected.length) {
                bulkTruckCustomer.textContent = "ยังไม่ได้เลือกรายการ";
            } else {
                const first = bulkTruckKey(selected[0]);
                const customers = new Set(
                    selected
                        .map((input) => bulkTruckKey(input).customer)
                        .filter(Boolean),
                );
                const customerText =
                    customers.size > 1
                        ? `หลายลูกค้า (${customers.size})`
                        : first.customer || "-";
                bulkTruckCustomer.textContent = `${customerText} / ${first.shipDate || "-"}`;
            }
        }

        if (bulkTruckCheckAll) {
            let eligible = visible;
            if (selected.length) {
                const seed = bulkTruckKey(selected[0]);
                eligible = visible.filter((input) =>
                    bulkTruckMatchesShipDate(input, seed),
                );
            }
            const selectedEligible = eligible.filter(
                (input) => input.checked,
            ).length;
            bulkTruckCheckAll.checked =
                eligible.length > 0 && selectedEligible === eligible.length;
            bulkTruckCheckAll.indeterminate =
                selectedEligible > 0 && selectedEligible < eligible.length;
            bulkTruckCheckAll.disabled = eligible.length === 0;
        }

        syncGroupTruckCheckAll();
    }

    // ====== เลือกทั้งกลุ่ม (D) เพื่อจัดรถ ======
    function groupTruckChecks(group) {
        return bulkTruckChecks.filter((input) => {
            if (input.disabled) return false;
            const row = input.closest("tr");
            return (
                !!row &&
                (row.dataset.group || "") === group &&
                row.style.display !== "none"
            );
        });
    }

    function setGroupBulkTruck(group, checked) {
        const groupChecks = groupTruckChecks(group);
        if (!groupChecks.length) {
            updateBulkTruckState();
            return;
        }

        if (checked) {
            const selected = selectedBulkTruckChecks();
            const seed = selected.length
                ? bulkTruckKey(selected[0])
                : bulkTruckKey(groupChecks[0]);
            let blocked = 0;
            groupChecks.forEach((input) => {
                if (bulkTruckMatchesShipDate(input, seed)) {
                    input.checked = true;
                } else {
                    blocked += 1;
                }
            });
            if (blocked > 0) {
                warn(
                    "เลือกได้เฉพาะวันส่งเดียวกัน — บางรายการในกลุ่มนี้เป็นคนละวันส่งจึงไม่ถูกเลือก",
                );
            }
        } else {
            groupChecks.forEach((input) => {
                input.checked = false;
            });
        }

        updateBulkTruckState();
    }

    function syncGroupTruckCheckAll() {
        document.querySelectorAll(".jsGroupTruckCheckAll").forEach((cb) => {
            const group = cb.dataset.group || "";
            const groupChecks = groupTruckChecks(group);
            if (!groupChecks.length) {
                cb.checked = false;
                cb.indeterminate = false;
                cb.disabled = true;
                return;
            }
            cb.disabled = false;
            const checkedCount = groupChecks.filter(
                (input) => input.checked,
            ).length;
            cb.checked = checkedCount === groupChecks.length;
            cb.indeterminate =
                checkedCount > 0 && checkedCount < groupChecks.length;
        });
    }

    function setBulkTruckChecked(input, checked) {
        if (!input || input.disabled) return false;

        if (checked) {
            const selected = selectedBulkTruckChecks().filter(
                (item) => item !== input,
            );
            if (selected.length) {
                const seed = bulkTruckKey(selected[0]);
                if (!bulkTruckMatchesShipDate(input, seed)) {
                    warn("จัดรถพร้อมกันได้เฉพาะวันส่งเดียวกัน");
                    input.checked = false;
                    updateBulkTruckState();
                    return false;
                }
            }
        }

        input.checked = checked;
        updateBulkTruckState();
        return true;
    }

    function selectVisibleBulkTruckSameShipDate() {
        const selected = selectedBulkTruckChecks();
        const visible = visibleBulkTruckChecks();
        if (!visible.length) {
            warn("ไม่พบรายการที่เลือกได้ในตาราง");
            return;
        }

        const seed = bulkTruckKey(selected[0] || visible[0]);
        visible.forEach((input) => {
            if (bulkTruckMatchesShipDate(input, seed)) {
                input.checked = true;
            }
        });
        updateBulkTruckState();
    }

    function openBulkTruckSummary() {
        const selected = selectedBulkTruckChecks();
        if (!selected.length) return;

        const first = selected[0];
        const base = ROUTES.logisticsSummary || "";
        if (!base) return;

        const ordIds = selected
            .map((input) =>
                String(input.dataset.ordId || input.value || "").trim(),
            )
            .filter(Boolean);
        const shipDates = new Set(
            selected
                .map((input) => String(input.dataset.shipDate || "").trim())
                .filter(Boolean),
        );
        if (shipDates.size !== 1) {
            warn("จัดรถพร้อมกันได้เฉพาะวันส่งเดียวกัน");
            return;
        }
        const url = new URL(base, window.location.origin);

        url.searchParams.set(
            "ship_date",
            Array.from(shipDates)[0] || first.dataset.shipDate || "",
        );
        url.searchParams.set("selected", `ord-${ordIds[0] || ""}`);
        url.searchParams.set("ord_ids", ordIds.join(","));

        window.location.href = url.toString();
    }

    function openBulkTruckUnassign() {
        const selected = selectedBulkTruckUnassignChecks();
        if (!selected.length) {
            warn("กรุณาเลือกรายการที่มีรถแล้ว");
            return;
        }

        document.dispatchEvent(
            new CustomEvent("dp:open-unassign-truck", {
                detail: {
                    returnUrl: window.location.href,
                    items: selected.map((input) => ({
                        ordId: String(input.dataset.ordId || input.value || ""),
                        so: String(input.dataset.so || ""),
                        mfg: String(input.dataset.mfg || ""),
                        plate: String(input.dataset.plate || ""),
                    })),
                },
            }),
        );
    }

    function validateBeforeSubmit() {
        if (isSpecialDispatchMode()) {
            const dispatchType = String(
                tmSpecialDispatchType?.value || "",
            ).trim();
            if (!dispatchType) {
                warn("กรุณาเลือกประเภทช่องทางพิเศษ");
                return false;
            }
            setSpecialDispatchAction(state.ordId);
            return true;
        }

        const ordIds = selectedOrdIds();

        if (!ordIds.length) {
            warn("กรุณาเลือก MFG อย่างน้อย 1 รายการ");
            return false;
        }

        const staffSelections = [
            [tmDriverStaffId, "คนขับ"],
            ...tmHelperStaffIds.map((selectEl, index) => [
                selectEl,
                `เด็กรถ ${index + 1}`,
            ]),
        ];
        const staffSeen = new Map();
        for (const [selectEl, label] of staffSelections) {
            const staffId = String(selectEl?.value || "").trim();
            if (!staffId) continue;

            if (staffSeen.has(staffId)) {
                warn(
                    `${label} ซ้ำกับ ${staffSeen.get(staffId)} กรุณาเลือกพนักงานคนละคน`,
                );
                if (selectEl && typeof selectEl.focus === "function") {
                    selectEl.focus();
                }
                return false;
            }

            staffSeen.set(staffId, label);
        }

        const checked = modalEl.querySelector(
            'input[name="truck_pick_mode"]:checked',
        );
        const mode = checked ? String(checked.value || "") : "";

        if (mode === "MASTER") {
            if (!tmTruckId || !tmTruckId.value) {
                warn("กรุณาเลือกรถในระบบ");
                return false;
            }
            clearManualHiddenFields();
        } else if (mode === "MANUAL_TEMP") {
            const plate = String(tmManualPlateHidden?.value || "").trim();
            if (!plate) {
                warn("ไม่พบข้อมูลรถนอกที่เลือก");
                return false;
            }
        } else if (mode === "MANUAL") {
            const plate = String(tmManualPlate?.value || "").trim();
            if (!plate) {
                warn("กรุณากรอกทะเบียนรถนอก");
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
                tmManualMaxLoadHidden.value = tmManualMaxLoad?.value || "";
            }
            if (tmManualLengthHidden) {
                tmManualLengthHidden.value = tmManualLength.value || "";
            }
            if (tmManualRemarkHidden) {
                tmManualRemarkHidden.value = tmManualRemark.value || "";
            }
        } else {
            warn("กรุณาเลือกประเภทรถ");
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

        const bulkBtn = e.target.closest("#bulkTruckOpenBtn");
        if (bulkBtn) {
            openBulkTruckSummary();
            return;
        }

        const bulkUnassignBtn = e.target.closest("#bulkTruckUnassignOpenBtn");
        if (bulkUnassignBtn) {
            openBulkTruckUnassign();
            return;
        }
    });

    bulkTruckChecks.forEach((input) => {
        input.addEventListener("change", () => {
            setBulkTruckChecked(input, input.checked);
        });
    });

    if (bulkTruckCheckAll) {
        bulkTruckCheckAll.addEventListener("change", () => {
            const visible = visibleBulkTruckChecks();
            const selected = selectedBulkTruckChecks();
            const seed = selected.length
                ? bulkTruckKey(selected[0])
                : bulkTruckKey(visible[0] || {});
            const checked = bulkTruckCheckAll.checked;

            visible.forEach((input) => {
                if (!checked) {
                    input.checked = false;
                    return;
                }
                input.checked = seed.shipDate
                    ? bulkTruckMatchesShipDate(input, seed)
                    : false;
            });

            updateBulkTruckState();
        });
    }

    if (bulkTruckClearBtn) {
        bulkTruckClearBtn.addEventListener("click", () => {
            bulkTruckChecks.forEach((input) => {
                input.checked = false;
            });
            updateBulkTruckState();
        });
    }

    if (bulkTruckSameCustomerBtn) {
        bulkTruckSameCustomerBtn.addEventListener("click", () => {
            selectVisibleBulkTruckSameShipDate();
        });
    }

    document.querySelectorAll(".jsGroupTruckCheckAll").forEach((cb) => {
        cb.addEventListener("change", () => {
            setGroupBulkTruck(cb.dataset.group || "", cb.checked);
        });
    });

    // เมื่อกรอง/ค้นหาในตาราง (รายการถูกซ่อน/แสดง) ให้ checkbox "เลือกทั้งกลุ่ม" อัปเดตตาม
    document.addEventListener("dp:inquiry-filtered", () => {
        syncGroupTruckCheckAll();
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

        if (
            target === tmDispatchTruckMode ||
            target === tmDispatchSpecialMode
        ) {
            setDispatchMode(
                target === tmDispatchSpecialMode ? "SPECIAL" : "TRUCK",
            );
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

        if (target.classList.contains("tmAssignWeightKg")) {
            updateTruckRemainPreview();
            return;
        }

        if (target === tmTripNo) {
            clearTruckSelection();
            clearManualHiddenFields();
            if (state.shipDate) {
                loadTruckCapacity(state.shipDate);
            }
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

    if (tmTripMinus) {
        tmTripMinus.addEventListener("click", () => stepTrip(-1));
    }

    if (tmTripPlus) {
        tmTripPlus.addEventListener("click", () => stepTrip(1));
    }

    simplifyHelperInputs();
    keepOnlyTopManualModeOption();
    updateBulkTruckState();
})();

(function bulkPostponeModal() {
    const CONFIG = window.DP_INQUIRY || {};
    const ROUTES = CONFIG.routes || {};
    const checkAll = document.getElementById("bulkPostponeCheckAll");
    const checks = Array.from(
        document.querySelectorAll(".jsBulkPostponeCheck"),
    );
    const openBtn = document.getElementById("bulkPostponeOpenBtn");
    const clearBtn = document.getElementById("bulkPostponeClearBtn");
    const countEl = document.getElementById("bulkPostponeCount");
    const soCountEl = document.getElementById("bulkPostponeSoCount");
    const modalEl = document.getElementById("bulkPostponeModal");
    const form = document.getElementById("bulkPostponeForm");
    const idsEl = document.getElementById("bulkPostponeIds");
    const previewEl = document.getElementById("bulkPostponePreview");
    const modalCountEl = document.getElementById("bulkPostponeModalCount");
    const modalSoCountEl = document.getElementById("bulkPostponeModalSoCount");
    const shipDateEl = document.getElementById("bulkPostponeShipDate");
    const windowTimeEl = document.getElementById("bulkPostponeWindowTime");
    const reasonEl = document.getElementById("bulkPostponeReason");

    if (!checks.length || !openBtn || !modalEl || !form) return;
    if (ROUTES.bulkPostpone) form.action = ROUTES.bulkPostpone;

    const esc = (value) =>
        String(value ?? "").replace(
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

    function rowVisible(input) {
        const row = input.closest("tr.data-row");
        return row && row.style.display !== "none";
    }

    function selectedChecks() {
        return checks.filter((input) => input.checked && !input.disabled);
    }

    function visibleEnabledChecks() {
        return checks.filter((input) => !input.disabled && rowVisible(input));
    }

    function soCount(items) {
        return new Set(
            items
                .map((input) => String(input.dataset.so || "").trim())
                .filter(Boolean),
        ).size;
    }

    function formatThaiDate(value) {
        if (!value) return "-";
        const parts = String(value).split("-");
        if (parts.length !== 3) return value;
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    function syncState() {
        const selected = selectedChecks();
        const visible = visibleEnabledChecks();
        const count = selected.length;

        if (countEl) countEl.textContent = count.toLocaleString();
        if (soCountEl)
            soCountEl.textContent = soCount(selected).toLocaleString();
        openBtn.disabled = count === 0;
        if (clearBtn) clearBtn.disabled = count === 0;

        if (checkAll) {
            const visibleSelected = visible.filter(
                (input) => input.checked,
            ).length;
            checkAll.checked =
                visible.length > 0 && visibleSelected === visible.length;
            checkAll.indeterminate =
                visibleSelected > 0 && visibleSelected < visible.length;
            checkAll.disabled = visible.length === 0;
        }
    }

    function renderPreview() {
        const selected = selectedChecks();
        const newDateText = formatThaiDate(shipDateEl?.value || "");

        if (idsEl) {
            idsEl.innerHTML = selected
                .map(
                    (input) =>
                        `<input type="hidden" name="ord_ids[]" value="${esc(input.value)}">`,
                )
                .join("");
        }

        if (modalCountEl)
            modalCountEl.textContent = selected.length.toLocaleString();
        if (modalSoCountEl)
            modalSoCountEl.textContent = soCount(selected).toLocaleString();

        if (previewEl) {
            previewEl.innerHTML = selected
                .map((input) => {
                    const part = [
                        input.dataset.part || "",
                        input.dataset.partDesc || "",
                    ]
                        .filter(Boolean)
                        .join(" ");
                    return `
                        <tr>
                            <td>${esc(input.dataset.so || "-")}</td>
                            <td>${esc(part || "-")}</td>
                            <td>${esc(input.dataset.shipLabel || "-")}</td>
                            <td>${esc(newDateText)}</td>
                        </tr>
                    `;
                })
                .join("");
        }
    }

    checks.forEach((input) => {
        input.addEventListener("click", (event) => event.stopPropagation());
        input.addEventListener("input", syncState);
        input.addEventListener("change", syncState);
    });

    if (checkAll) {
        checkAll.addEventListener("click", (event) => {
            event.stopPropagation();
        });
        checkAll.addEventListener("change", (event) => {
            event.stopPropagation();
            const checked = checkAll.checked;
            visibleEnabledChecks().forEach((input) => {
                input.checked = checked;
            });
            syncState();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener("click", () => {
            checks.forEach((input) => {
                input.checked = false;
            });
            syncState();
        });
    }

    openBtn.addEventListener("click", () => {
        const selected = selectedChecks();
        if (!selected.length) return;

        const firstDate = selected[0]?.dataset.shipDate || "";
        const firstTime = selected[0]?.dataset.windowTime || "08:00";
        if (shipDateEl && !shipDateEl.value) shipDateEl.value = firstDate;
        if (windowTimeEl && !windowTimeEl.value)
            windowTimeEl.value = firstTime || "08:00";
        if (reasonEl) reasonEl.value = "";

        renderPreview();
        if (window.bootstrap?.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else {
            modalEl.classList.add("show");
            modalEl.style.display = "block";
            modalEl.removeAttribute("aria-hidden");
        }
    });

    shipDateEl?.addEventListener("change", renderPreview);
    form.addEventListener("submit", () => {
        renderPreview();
    });

    document.addEventListener("input", (event) => {
        if (event.target?.classList?.contains("dp-col-filter")) {
            window.setTimeout(syncState, 0);
        }
    });
    document.addEventListener("dp:inquiry-filtered", syncState);

    syncState();
})();

(function postponeModal() {
    const byId = (id) => document.getElementById(id);
    const CONFIG = window.DP_INQUIRY || {};
    const ROUTES = CONFIG.routes || {};

    const modalEl = byId("postponeModal");
    const form = byId("postponeForm");
    const infoEl = byId("postponeInfo");
    const shipDateEl = byId("postponeShipDate");
    const windowTimeEl = byId("postponeWindowTime");
    const reasonEl = byId("postponeReason");

    if (!modalEl || !form) return;
    if (typeof bootstrap === "undefined" || !bootstrap.Modal) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    function setAction(ordId) {
        const base = ROUTES.postpone || "";
        form.action = String(base).replace("__ID__", String(ordId || 0));
    }

    function buildInfo(btn) {
        const parts = [
            `ord_id: ${btn.dataset.ordId || "-"}`,
            `SO: ${btn.dataset.so || "-"}`,
            `Part: ${btn.dataset.part || "-"}`,
            `Ship: ${btn.dataset.shipDate || "-"}`,
        ];
        return parts.join(" | ");
    }

    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;

        const btn = event.target.closest(".jsPostponeBtn");
        if (!btn || btn.hasAttribute("disabled")) return;

        setAction(btn.dataset.ordId || "");
        if (infoEl) infoEl.textContent = buildInfo(btn);

        const currentShipDate = btn.dataset.shipDate || "";
        if (shipDateEl) shipDateEl.value = currentShipDate;
        if (windowTimeEl)
            windowTimeEl.value = btn.dataset.windowTime || "08:00";
        if (reasonEl) reasonEl.value = "";

        modal.show();
    });
})();

(function duplicateModal() {
    const byId = (id) => document.getElementById(id);
    const CONFIG = window.DP_INQUIRY || {};
    const ROUTES = CONFIG.routes || {};

    const modalEl = byId("duplicateModal");
    const form = byId("duplicateForm");
    const infoEl = byId("duplicateInfo");
    const shipDateEl = byId("duplicateShipDate");
    const windowTimeEl = byId("duplicateWindowTime");
    const mfgEl = byId("duplicateMfg");
    const qtyEl = byId("duplicateQty");
    const qtyWrapEl = byId("duplicateQtyWrap");
    const sellByLineEl = byId("duplicateSellByLine");
    const lineQtyEl = byId("duplicateLineQty");
    const lineQtyWrapEl = byId("duplicateLineQtyWrap");
    const lineQtyLabelEl = byId("duplicateLineQtyLabel");
    const sellByLineChoiceWrapEl = byId("duplicateSellByLineChoiceWrap");
    const partsIdEl = byId("duplicatePartsId");
    const soNumberEl = byId("duplicateSoNumber");
    const customerIdEl = byId("duplicateCustomerId");
    const mfgSuggestEl = byId("duplicateMfgSuggest");
    const addressEl = byId("duplicateAddress");
    const telEl = byId("duplicateTel");
    const remarkEl = byId("duplicateRemark");
    const revisionEl = byId("duplicateRevisionNumber");
    const docsOtherEl = byId("duplicateAttachDocsOther");
    const continueEl = byId("duplicateContinueSamePlan");
    const modeSoEl = byId("duplicateModeSo");
    const modeAcidEl = byId("duplicateModeAcid");
    const modeSpecialEl = byId("duplicateModeSpecial");
    const soDisplayEl = byId("duplicateSoDisplay");
    const customerDisplayEl = byId("duplicateCustomerDisplay");
    const salesDisplayEl = byId("duplicateSalesDisplay");
    const partDisplayEl = byId("duplicatePartDisplay");
    const partDescDisplayEl = byId("duplicatePartDescDisplay");

    if (!modalEl || !form) return;
    if (typeof bootstrap === "undefined" || !bootstrap.Modal) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    let mfgDebounce = null;
    let mfgItems = [];
    const piecePartNumbers = Array.isArray(CONFIG.piecePartNumbers)
        ? CONFIG.piecePartNumbers.map((value) => String(value).trim().toUpperCase())
        : [];

    function isPiecePart(partNumber) {
        return piecePartNumbers.includes(String(partNumber || "").trim().toUpperCase());
    }

    function syncPieceSale(partNumber) {
        const forcedPieceUser = !sellByLineChoiceWrapEl && sellByLineEl?.value === "1";
        const pieceSale = forcedPieceUser || isPiecePart(partNumber);
        sellByLineChoiceWrapEl?.classList.toggle("d-none", pieceSale);
        if (lineQtyLabelEl)
            lineQtyLabelEl.textContent = pieceSale ? "จำนวนชิ้น" : "Qty ระบุเส้น";
        return pieceSale;
    }

    function esc(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function setAction(ordId) {
        const base = ROUTES.duplicate || "";
        form.action = String(base).replace("__ID__", String(ordId || 0));
    }

    function buildInfo(btn) {
        const parts = [
            `ord_id: ${btn.dataset.ordId || "-"}`,
            `SO: ${btn.dataset.so || "-"}`,
            `Part: ${btn.dataset.part || "-"}`,
            `Ship: ${btn.dataset.shipDate || "-"}`,
        ];
        return parts.join(" | ");
    }

    function setCheckedDocs(rawCodes) {
        const selected = String(rawCodes || "")
            .split(",")
            .map((value) => value.trim())
            .filter(Boolean);
        const selectedSet = new Set(selected);

        modalEl.querySelectorAll(".duplicateDocCheck").forEach((input) => {
            input.checked = selectedSet.has(input.value);
        });
    }

    function isSellByLine() {
        const checked = modalEl.querySelector(
            'input[name="duplicate_sell_by_line"]:checked',
        );
        if (checked) return checked.value === "1";
        return sellByLineEl ? sellByLineEl.value === "1" : false;
    }

    function setSellByLine(value) {
        const radios = modalEl.querySelectorAll(
            'input[name="duplicate_sell_by_line"]',
        );
        const normalized =
            radios.length === 0 && sellByLineEl?.value === "1"
                ? "1"
                : String(value || "0") === "1"
                  ? "1"
                  : "0";
        if (radios.length) {
            radios.forEach((radio) => {
                radio.checked = radio.value === normalized;
            });
        }
        if (sellByLineEl) sellByLineEl.value = normalized;
        if (lineQtyWrapEl)
            lineQtyWrapEl.classList.toggle("d-none", normalized !== "1");
        if (qtyWrapEl) qtyWrapEl.classList.toggle("d-none", normalized === "1");
    }

    function closeMfgSuggest() {
        if (!mfgSuggestEl) return;
        mfgSuggestEl.classList.add("d-none");
        mfgSuggestEl.innerHTML = "";
        mfgItems = [];
    }

    function renderMfgSuggest(items) {
        if (!mfgSuggestEl) return;
        if (!Array.isArray(items) || !items.length) {
            closeMfgSuggest();
            return;
        }

        mfgItems = items;
        mfgSuggestEl.innerHTML = items
            .map(
                (item, index) => `
                    <button type="button" class="duplicate-mfg-suggest-item" data-index="${index}">
                        <span class="duplicate-mfg-suggest-title">${esc(item.mfg_no || item.workordernumber || "")}</span>
                        <span class="duplicate-mfg-suggest-sub">SO: ${esc(item.ordnumber || "-")} | Qty: ${esc(item.qty ?? "")}</span>
                    </button>
                `,
            )
            .join("");
        mfgSuggestEl.classList.remove("d-none");
    }

    function applyMfg(item) {
        if (!item) return;

        const mfgNo = String(item.mfg_no || item.workordernumber || "").trim();
        const mfgQty = Number(item.qty || 0);
        if (mfgEl) mfgEl.value = mfgNo;

        if (isSellByLine()) {
            if (lineQtyEl && mfgQty > 0)
                lineQtyEl.value = String(Math.round(mfgQty));
            if (qtyEl) qtyEl.value = "0";
        } else if (qtyEl && mfgQty > 0) {
            qtyEl.value = String(mfgQty);
        }

        closeMfgSuggest();
    }

    async function lookupMfg() {
        const q = String(mfgEl?.value || "").trim();
        const base = ROUTES.mfgLookup || "";
        if (!base || q.length < 2) {
            closeMfgSuggest();
            return;
        }

        const params = new URLSearchParams({
            q,
            ordnumber: String(soNumberEl?.value || "").trim(),
            parts_id: String(partsIdEl?.value || "").trim(),
            customer_id: String(customerIdEl?.value || "").trim(),
        });

        const res = await fetch(`${base}?${params.toString()}`, {
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error("MFG lookup failed");
        const data = await res.json();
        renderMfgSuggest(data.items || []);
    }

    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;

        const mfgItem = event.target.closest(".duplicate-mfg-suggest-item");
        if (mfgItem) {
            const index = Number(mfgItem.dataset.index || -1);
            if (index >= 0) applyMfg(mfgItems[index]);
            return;
        }

        const btn = event.target.closest(".jsDuplicateBtn");
        if (!btn || btn.hasAttribute("disabled")) return;

        setAction(btn.dataset.ordId || "");
        if (infoEl) infoEl.textContent = buildInfo(btn);
        if (partsIdEl) partsIdEl.value = btn.dataset.partsId || "";
        if (soNumberEl) soNumberEl.value = btn.dataset.so || "";
        if (customerIdEl) customerIdEl.value = btn.dataset.customerId || "";
        const deliveryType = String(
            btn.dataset.deliveryType || "SO",
        ).toUpperCase();
        if (modeSoEl)
            modeSoEl.checked =
                deliveryType !== "ACID" && deliveryType !== "SPECIAL";
        if (modeAcidEl) modeAcidEl.checked = deliveryType === "ACID";
        if (modeSpecialEl) modeSpecialEl.checked = deliveryType === "SPECIAL";
        if (soDisplayEl) soDisplayEl.value = btn.dataset.so || "";
        if (customerDisplayEl)
            customerDisplayEl.value = btn.dataset.customerName || "";
        if (salesDisplayEl) salesDisplayEl.value = btn.dataset.salesName || "";
        if (partDisplayEl) partDisplayEl.value = btn.dataset.part || "";
        if (partDescDisplayEl)
            partDescDisplayEl.value = btn.dataset.partDesc || "";
        if (shipDateEl) shipDateEl.value = btn.dataset.shipDate || "";
        if (windowTimeEl)
            windowTimeEl.value = btn.dataset.windowTime || "08:00";
        if (mfgEl) mfgEl.value = btn.dataset.mfg || "";
        if (qtyEl) qtyEl.value = btn.dataset.qty || "";
        const pieceSale = syncPieceSale(btn.dataset.part || "");
        setSellByLine(pieceSale || btn.dataset.sellByLine === "1" ? "1" : "0");
        if (lineQtyEl) lineQtyEl.value = btn.dataset.lineQty || "";
        if (addressEl) addressEl.value = btn.dataset.address || "";
        if (telEl) telEl.value = btn.dataset.tel || "";
        if (remarkEl) remarkEl.value = btn.dataset.remark || "";
        if (revisionEl) revisionEl.value = btn.dataset.revision || "0";
        if (docsOtherEl) docsOtherEl.value = btn.dataset.attachDocsOther || "";
        if (continueEl) continueEl.checked = false;
        setCheckedDocs(btn.dataset.attachDocs || "");
        closeMfgSuggest();

        modal.show();
    });

    function applyContinuePayload(payload) {
        if (!payload || !payload.ord_id) return;

        const btn = Array.from(
            document.querySelectorAll(".jsDuplicateBtn"),
        ).find(
            (item) =>
                String(item.dataset.ordId || "") === String(payload.ord_id),
        );
        if (!btn) return;

        btn.dispatchEvent(
            new MouseEvent("click", { bubbles: true, cancelable: true }),
        );

        if (shipDateEl)
            shipDateEl.value = payload.ship_date || btn.dataset.shipDate || "";
        if (windowTimeEl)
            windowTimeEl.value =
                payload.window_time || btn.dataset.windowTime || "08:00";
        setSellByLine(String(payload.sell_by_line || "0") === "1" ? "1" : "0");
        if (mfgEl) mfgEl.value = "";
        if (qtyEl) qtyEl.value = "";
        if (lineQtyEl) lineQtyEl.value = "";
        if (addressEl) addressEl.value = payload.address || "";
        if (telEl) telEl.value = payload.tel || "";
        if (remarkEl) remarkEl.value = payload.remark || "";
        if (revisionEl) revisionEl.value = payload.revision_number ?? "0";
        if (docsOtherEl) docsOtherEl.value = payload.attach_docs_other || "";
        if (continueEl) continueEl.checked = true;
        setCheckedDocs(payload.attach_docs || "");

        window.setTimeout(() => {
            mfgEl?.focus();
        }, 150);
    }

    if (mfgEl) {
        mfgEl.addEventListener("input", () => {
            window.clearTimeout(mfgDebounce);
            mfgDebounce = window.setTimeout(
                () => lookupMfg().catch(closeMfgSuggest),
                200,
            );
        });
        mfgEl.addEventListener("blur", () =>
            window.setTimeout(closeMfgSuggest, 200),
        );
    }

    modalEl
        .querySelectorAll('input[name="duplicate_sell_by_line"]')
        .forEach((radio) => {
            radio.addEventListener("change", () => {
                setSellByLine(radio.value);
                if (radio.value !== "1" && lineQtyEl) lineQtyEl.value = "";
            });
        });

    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;
        if (!mfgSuggestEl || mfgSuggestEl.classList.contains("d-none")) return;
        if (
            mfgSuggestEl.contains(event.target) ||
            mfgEl?.contains(event.target)
        )
            return;
        closeMfgSuggest();
    });

    if (CONFIG.duplicateContinue) {
        window.setTimeout(
            () => applyContinuePayload(CONFIG.duplicateContinue),
            100,
        );
    }
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

(function editLockedNotice() {
    // งานที่ถูกจัดรถหรือกำหนดช่องทางพิเศษแล้ว: ต้องให้ logistics ยกเลิกก่อนแก้ไข
    document.addEventListener("click", (e) => {
        if (!(e.target instanceof Element)) return;

        const btn = e.target.closest(".jsEditLockedBtn");
        if (!btn) return;

        e.preventDefault();

        const shipDate = btn.dataset.shipDate || "";
        const soNo = (btn.dataset.so || "").trim();
        const soText = soNo ? `Sales Order : ${soNo}` : "งานนี้";
        const dateText = shipDate ? `วันส่ง : ${shipDate}` : "";
        const html =
            `${soText} มีการบันทึกรายการจัดส่งแล้ว<br>` +
            `${dateText ? dateText + "<br>" : ""}` +
            "กรุณาติดต่อ <b>Logistics</b> เพื่อยกเลิกการจัดส่งก่อนแก้ไข";

        if (window.Swal && typeof window.Swal.fire === "function") {
            window.Swal.fire({
                icon: "warning",
                title: "ไม่สามารถแก้ไขได้",
                html: html,
                confirmButtonText: "รับทราบ",
                heightAuto: false,
                scrollbarPadding: false,
            });
        } else {
            alert(html.replace(/<[^>]+>/g, " "));
        }
    });
})();

(function unassignTruckModal() {
    const byId = (id) => document.getElementById(id);
    const CONFIG = window.DP_INQUIRY || {};
    const ROUTES = CONFIG.routes || {};

    const modalEl = byId("unassignTruckModal");
    const form = byId("unassignTruckForm");
    const infoEl = byId("unassignTruckInfo");
    const remarkEl = byId("remarkUnassign");
    const returnUrlEl = byId("unassignReturnUrl");
    const ordIdsEl = byId("unassignTruckOrdIds");

    if (!modalEl || !form) return;
    if (typeof bootstrap === "undefined" || !bootstrap.Modal) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    function setAction(ordId, items) {
        if (ordIdsEl) ordIdsEl.innerHTML = "";
        const cleanItems = (items || []).filter((item) => item && item.ordId);

        if (cleanItems.length > 1) {
            form.action = String(ROUTES.truckUnassignBulk || "");
            cleanItems.forEach((item) => {
                const hidden = document.createElement("input");
                hidden.type = "hidden";
                hidden.name = "ord_ids[]";
                hidden.value = item.ordId;
                ordIdsEl?.appendChild(hidden);
            });
            return;
        }

        const base = ROUTES.truckUnassign || "";
        form.action = String(base).replace("__ID__", String(ordId || 0));
    }

    function buildItemsInfo(items) {
        const cleanItems = (items || []).filter((item) => item && item.ordId);
        if (cleanItems.length === 1) {
            return (
                [
                    cleanItems[0].so ? "SO: " + cleanItems[0].so : "",
                    cleanItems[0].mfg ? "MFG: " + cleanItems[0].mfg : "",
                    cleanItems[0].plate ? "ทะเบียน: " + cleanItems[0].plate : "",
                ]
                    .filter(Boolean)
                    .join(" | ") || "-"
            );
        }

        const preview = cleanItems
            .slice(0, 4)
            .map((item) => item.mfg || item.so || "ord_id=" + item.ordId)
            .join(", ");

        return `${cleanItems.length} รายการ${preview ? " | " + preview : ""}`;
    }

    function openUnassignModal(items, returnUrl) {
        const cleanItems = (items || []).filter((item) => item && item.ordId);
        if (!cleanItems.length) return;

        setAction(cleanItems[0].ordId, cleanItems);
        if (returnUrlEl) returnUrlEl.value = returnUrl || window.location.href;
        if (infoEl) infoEl.textContent = buildItemsInfo(cleanItems);
        if (remarkEl) remarkEl.value = "";
        modal.show();
    }

    document.addEventListener("click", (e) => {
        if (!(e.target instanceof Element)) return;

        const btn = e.target.closest(".jsUnassignTruckBtn");
        if (!btn || btn.hasAttribute("disabled")) return;

        openUnassignModal(
            [
                {
                    ordId: String(btn.dataset.ordId || ""),
                    so: String(btn.dataset.so || ""),
                    mfg: String(btn.dataset.mfg || ""),
                    plate: String(btn.dataset.plate || ""),
                },
            ],
            btn.dataset.returnUrl || window.location.href,
        );
    });

    document.addEventListener("dp:open-unassign-truck", (event) => {
        openUnassignModal(
            event.detail?.items || [],
            event.detail?.returnUrl || window.location.href,
        );
    });

    modalEl.addEventListener("hidden.bs.modal", () => {
        if (infoEl) infoEl.textContent = "-";
        if (remarkEl) remarkEl.value = "";
        if (ordIdsEl) ordIdsEl.innerHTML = "";
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
        return String(ROUTES.history || "").replace(
            "__ID__",
            encodeURIComponent(String(ordId || "")),
        );
    }

    function historyDbUrl(ordId) {
        return String(ROUTES.historyDb || "").replace(
            "__ID__",
            encodeURIComponent(String(ordId || "")),
        );
    }

    function setButtons(mode) {
        state.mode = mode;

        if (currentBtn) {
            currentBtn.classList.toggle("btn-primary", mode === "current");
            currentBtn.classList.toggle(
                "btn-outline-primary",
                mode !== "current",
            );
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
        return (
            row.revise_by_name ||
            row.revise_user_name ||
            row.created_by_name ||
            row.revise_by ||
            "-"
        );
    }

    function renderDiffs(diffs) {
        if (!Array.isArray(diffs) || diffs.length === 0) {
            return '<span class="text-muted">No field changes</span>';
        }

        return `<div class="vstack gap-1">${diffs
            .map(
                (d) => `
            <div class="border rounded p-2 bg-light">
                <div class="fw-semibold">${esc(d.label || d.field || "-")}</div>
                <div class="small">
                    <span class="text-danger">${esc(d.from || "-")}</span>
                    <span class="text-muted mx-1">-&gt;</span>
                    <span class="text-success">${esc(d.to || "-")}</span>
                </div>
            </div>
        `,
            )
            .join("")}</div>`;
    }

    function renderTop() {
        const payload = state.payload || {};
        const versions = Array.isArray(payload.versions)
            ? payload.versions
            : [];
        const diffKey = state.mode === "step" ? "diff_step" : "diff_current";

        if (metaEl) {
            metaEl.textContent = `ord_id: ${payload.ord_id || state.ordId || "-"} | current rev: ${payload.current_revision ?? "-"}`;
        }
        if (colTitleEl) {
            colTitleEl.textContent =
                state.mode === "step"
                    ? "Changed Fields (step by step)"
                    : "Changed Fields (compare with current)";
        }

        if (versions.length === 0) {
            tbodyEl.innerHTML =
                '<tr><td colspan="4" class="text-center text-muted py-3">No history versions found</td></tr>';
            return;
        }

        tbodyEl.innerHTML = versions
            .map(
                (row) => `
            <tr>
                <td class="text-center">${esc(row.revision_number ?? "-")}</td>
                <td>${formatPeriod(row)}</td>
                <td>${esc(actorName(row))}</td>
                <td>${renderDiffs(row[diffKey])}</td>
            </tr>
        `,
            )
            .join("");
    }

    function renderDbRows(rows) {
        const list = Array.isArray(rows) ? rows : [];

        if (!dbTheadEl || !dbTbodyEl) return;

        if (list.length === 0) {
            dbTheadEl.innerHTML = "";
            dbTbodyEl.innerHTML =
                '<tr><td class="text-center text-muted py-3">No database history rows found</td></tr>';
            return;
        }

        const columns = Object.keys(list[0]);
        dbTheadEl.innerHTML = columns.map((c) => `<th>${esc(c)}</th>`).join("");
        dbTbodyEl.innerHTML = list
            .map(
                (row) => `
            <tr>${columns.map((c) => `<td>${esc(row[c] ?? "")}</td>`).join("")}</tr>
        `,
            )
            .join("");
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
            renderError(
                err && err.message ? err.message : "Unable to load history",
            );
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
            dbTbodyEl.innerHTML =
                '<tr><td class="text-center text-muted py-3">Loading...</td></tr>';
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
