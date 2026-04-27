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
