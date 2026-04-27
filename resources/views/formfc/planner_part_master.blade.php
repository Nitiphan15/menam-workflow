@extends('layouts.layout')
@section('title', 'Planner Part Master')
@section('page-title', 'Planner Part Master')

@section('content')
    <div class="container-fluid">
        @if (session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger py-2">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <div class="card mb-3">
            <div class="card-header"><b>Import Planner RM Part Master</b></div>
            <div class="card-body">
                <form method="post" action="{{ route('fc.planner.master.import') }}" enctype="multipart/form-data"
                    class="row g-3 align-items-end">
                    @csrf

                    <div class="col-md-3">
                        <label class="form-label">Mode</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="replace">replace</option>
                            <option value="append">append</option>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">Excel File</label>
                        <input type="file" name="file" class="form-control form-control-sm" required>
                    </div>

                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-success btn-sm">Import Excel</button>
                        <span class="small text-muted align-self-center">ใช้แค่คอลัมน์ `partnumber`, `desc`</span>
                    </div>
                </form>
            </div>
        </div>

        <form method="GET" action="{{ route('fc.planner.master') }}" class="mb-3">
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label">Filter Part</label>
                    <input type="text" name="part" value="{{ request('part') }}" class="form-control"
                        placeholder="ค้นหา RM Part / Description">
                </div>
                <div class="col-md-2">
                    <label class="form-label d-block">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">ค้นหา</button>
                    <a href="{{ route('fc.planner.master') }}" class="btn btn-secondary">ล้าง</a>
                </div>
            </div>
        </form>

        <form method="post" action="{{ route('fc.planner.master.save') }}" id="plannerPartMasterForm">
            @csrf

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div><b>Planner RM Part Master</b></div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="addPlannerRowBtn">เพิ่ม Row</button>
                        <button type="submit" class="btn btn-primary btn-sm">บันทึก Master</button>
                    </div>
                </div>

                <div class="card-body table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 70px;">#</th>
                                <th style="width: 260px;">RM Part</th>
                                <th>RM Description</th>
                                <th style="width: 110px;">Active</th>
                                <th style="width: 240px;">Remark</th>
                                <th style="width: 90px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="plannerPartMasterBody">
                            @foreach ($rows as $i => $row)
                                <tr>
                                    <td class="js-row-no">
                                        <span>{{ $i + 1 }}</span>
                                        <input type="hidden" name="rows[{{ $i }}][id]" value="{{ $row->id }}">
                                    </td>
                                    <td class="position-relative">
                                        <input type="text" name="rows[{{ $i }}][rm_partnumber]"
                                            value="{{ $row->rm_partnumber }}"
                                            class="form-control js-rm-part-input"
                                            data-last-selected="{{ strtoupper(trim((string) $row->rm_partnumber)) }}"
                                            autocomplete="off">
                                        <div class="list-group position-absolute w-100 shadow-sm d-none js-rm-suggest"
                                            style="z-index: 20; top: calc(100% + 2px); max-height: 220px; overflow-y: auto;"></div>
                                    </td>
                                    <td>
                                        <div class="form-control-plaintext py-2 js-rm-desc-text">{{ $row->rm_description }}</div>
                                        <input type="hidden" name="rows[{{ $i }}][rm_description]"
                                            value="{{ $row->rm_description }}" class="js-rm-desc-input">
                                    </td>
                                    <td class="text-center">
                                        <input type="hidden" name="rows[{{ $i }}][active]" value="0">
                                        <input type="checkbox" name="rows[{{ $i }}][active]" value="1"
                                            {{ (int) $row->active === 1 ? 'checked' : '' }}>
                                    </td>
                                    <td>
                                        <input type="text" name="rows[{{ $i }}][remark]"
                                            value="{{ $row->remark }}" class="form-control">
                                    </td>
                                    <td class="text-center text-muted">-</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>

    <template id="plannerPartMasterRowTemplate">
        <tr>
            <td class="js-row-no">
                <span></span>
            </td>
            <td class="position-relative">
                <input type="hidden" data-field="id" value="">
                <input type="text" data-field="rm_partnumber" class="form-control js-rm-part-input" autocomplete="off">
                <div class="list-group position-absolute w-100 shadow-sm d-none js-rm-suggest"
                    style="z-index: 20; top: calc(100% + 2px); max-height: 220px; overflow-y: auto;"></div>
            </td>
            <td>
                <div class="form-control-plaintext py-2 js-rm-desc-text"></div>
                <input type="hidden" data-field="rm_description" class="js-rm-desc-input">
            </td>
            <td class="text-center">
                <input type="hidden" data-field="active_hidden" value="0">
                <input type="checkbox" data-field="active" value="1" checked>
            </td>
            <td>
                <input type="text" data-field="remark" class="form-control">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm js-remove-row">ลบ</button>
            </td>
        </tr>
    </template>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const lookupUrl = @json(route('fc.planner.master.rm-lookup'));
            const tableBody = document.getElementById('plannerPartMasterBody');
            const addPlannerRowBtn = document.getElementById('addPlannerRowBtn');
            const rowTemplate = document.getElementById('plannerPartMasterRowTemplate');
            const form = document.getElementById('plannerPartMasterForm');

            const debounce = (fn, wait = 250) => {
                let timer = null;
                return (...args) => {
                    clearTimeout(timer);
                    timer = setTimeout(() => fn(...args), wait);
                };
            };

            function updateRowNames() {
                Array.from(tableBody.querySelectorAll('tr')).forEach((tr, index) => {
                    const rowNo = tr.querySelector('.js-row-no span');
                    if (rowNo) {
                        rowNo.textContent = String(index + 1);
                    }

                    const idInput = tr.querySelector('[data-field="id"], input[name*="[id]"]');
                    if (idInput) {
                        idInput.name = `rows[${index}][id]`;
                    }

                    const rmPartInput = tr.querySelector('.js-rm-part-input');
                    if (rmPartInput) {
                        rmPartInput.name = `rows[${index}][rm_partnumber]`;
                    }

                    const rmDescInput = tr.querySelector('.js-rm-desc-input');
                    if (rmDescInput) {
                        rmDescInput.name = `rows[${index}][rm_description]`;
                    }

                    const activeHidden = tr.querySelector('[data-field="active_hidden"], input[type="hidden"][name*="[active]"]');
                    if (activeHidden) {
                        activeHidden.name = `rows[${index}][active]`;
                    }

                    const activeCheckbox = tr.querySelector('[data-field="active"], input[type="checkbox"][name*="[active]"]');
                    if (activeCheckbox) {
                        activeCheckbox.name = `rows[${index}][active]`;
                    }

                    const remarkInput = tr.querySelector('[data-field="remark"], input[name*="[remark]"]');
                    if (remarkInput) {
                        remarkInput.name = `rows[${index}][remark]`;
                    }
                });
            }

            function hideSuggest(box) {
                if (box) {
                    box.classList.add('d-none');
                    box.innerHTML = '';
                }
            }

            function setRowDescription(tr, description) {
                const rmDescInput = tr.querySelector('.js-rm-desc-input');
                const rmDescText = tr.querySelector('.js-rm-desc-text');
                const safeDescription = description || '';

                if (rmDescInput) {
                    rmDescInput.value = safeDescription;
                }

                if (rmDescText) {
                    rmDescText.textContent = safeDescription;
                }
            }

            function applyPartSelection(tr, item) {
                const rmPartInput = tr.querySelector('.js-rm-part-input');
                const suggestBox = tr.querySelector('.js-rm-suggest');

                if (rmPartInput) {
                    rmPartInput.value = item.partnumber || '';
                    rmPartInput.dataset.lastSelected = (item.partnumber || '').trim().toUpperCase();
                }

                setRowDescription(tr, item.description || '');
                hideSuggest(suggestBox);
            }

            function collectDuplicateParts() {
                const map = new Map();

                tableBody.querySelectorAll('.js-rm-part-input').forEach(input => {
                    const value = (input.value || '').trim().toUpperCase();
                    if (!value) {
                        input.classList.remove('is-invalid');
                        return;
                    }

                    if (!map.has(value)) {
                        map.set(value, []);
                    }
                    map.get(value).push(input);
                });

                tableBody.querySelectorAll('.js-rm-part-input').forEach(input => input.classList.remove('is-invalid'));

                const duplicates = [];
                map.forEach((inputs, part) => {
                    if (inputs.length > 1) {
                        duplicates.push(part);
                        inputs.forEach(input => input.classList.add('is-invalid'));
                    }
                });

                return duplicates;
            }

            function bindAutocomplete(tr) {
                const rmPartInput = tr.querySelector('.js-rm-part-input');
                const suggestBox = tr.querySelector('.js-rm-suggest');

                if (!rmPartInput || !suggestBox) {
                    return;
                }

                const searchPart = debounce(async () => {
                    const keyword = rmPartInput.value.trim();

                    if (keyword.length < 1) {
                        hideSuggest(suggestBox);
                        if (keyword === '') {
                            setRowDescription(tr, '');
                        }
                        return;
                    }

                    try {
                        const response = await fetch(`${lookupUrl}?q=${encodeURIComponent(keyword)}`, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        if (!response.ok) {
                            hideSuggest(suggestBox);
                            return;
                        }

                        const items = await response.json();
                        if (!Array.isArray(items) || !items.length) {
                            hideSuggest(suggestBox);
                            return;
                        }

                        suggestBox.innerHTML = '';
                        items.forEach(item => {
                            const option = document.createElement('button');
                            option.type = 'button';
                            option.className = 'list-group-item list-group-item-action';
                            option.innerHTML =
                                `<div class="fw-semibold">${item.partnumber || ''}</div><div class="small text-muted">${item.description || ''}</div>`;
                            option.addEventListener('click', () => applyPartSelection(tr, item));
                            suggestBox.appendChild(option);
                        });
                        suggestBox.classList.remove('d-none');
                    } catch (error) {
                        hideSuggest(suggestBox);
                    }
                }, 200);

                rmPartInput.addEventListener('input', function() {
                    const currentPart = this.value.trim().toUpperCase();
                    if (currentPart !== (this.dataset.lastSelected || '')) {
                        this.dataset.lastSelected = '';
                        setRowDescription(tr, '');
                    }

                    searchPart();
                    collectDuplicateParts();
                });

                rmPartInput.addEventListener('blur', function() {
                    window.setTimeout(() => {
                        hideSuggest(suggestBox);
                    }, 180);
                });

                rmPartInput.addEventListener('focus', function() {
                    if (this.value.trim() !== '') {
                        searchPart();
                    }
                });
            }

            function bindRow(tr) {
                bindAutocomplete(tr);

                const removeBtn = tr.querySelector('.js-remove-row');
                if (removeBtn) {
                    removeBtn.addEventListener('click', function() {
                        tr.remove();
                        updateRowNames();
                        collectDuplicateParts();
                    });
                }
            }

            if (addPlannerRowBtn && rowTemplate && tableBody) {
                addPlannerRowBtn.addEventListener('click', function() {
                    const fragment = rowTemplate.content.cloneNode(true);
                    tableBody.appendChild(fragment);
                    bindRow(tableBody.lastElementChild);
                    updateRowNames();
                    tableBody.lastElementChild.scrollIntoView({
                        behavior: 'smooth',
                        block: 'end'
                    });
                    tableBody.lastElementChild.querySelector('.js-rm-part-input')?.focus();
                });
            }

            if (form) {
                form.addEventListener('submit', function(event) {
                    const duplicates = collectDuplicateParts();
                    if (duplicates.length > 0) {
                        event.preventDefault();
                        alert(`RM Part ซ้ำ: ${duplicates.join(', ')}`);
                        tableBody.querySelector('.js-rm-part-input.is-invalid')?.focus();
                    }
                });
            }

            tableBody.querySelectorAll('tr').forEach(bindRow);
            updateRowNames();

            document.addEventListener('click', function(event) {
                if (!event.target.closest('.position-relative')) {
                    document.querySelectorAll('.js-rm-suggest').forEach(hideSuggest);
                }
            });
        });
    </script>
@endpush
