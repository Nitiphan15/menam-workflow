@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const reviewModal = document.getElementById('wr-review-modal');
    const customerModal = document.getElementById('wr-customer-modal');
    if (!reviewModal || !customerModal || !window.bootstrap?.Modal) return;

    const states = new Map([[reviewModal, {dirty:false, saving:false}], [customerModal, {dirty:false, saving:false}]]);
    let currentButton = null;

    const setMessage = function (modal, message, type) {
        const box = modal.querySelector('.js-wr-modal-message');
        box.className = 'alert js-wr-modal-message alert-'+(type || 'danger');
        box.textContent = message;
        box.classList.toggle('d-none', !message);
    };
    const tags = function (container, values, sales) {
        container.replaceChildren();
        const list = values?.length ? values : [sales ? '-' : 'Stock'];
        list.forEach(function (value) {
            const tag = document.createElement('span');
            tag.className = sales ? 'wr-party-tag wr-party-tag--sales' : 'wr-party-tag';
            tag.textContent = value;
            container.appendChild(tag);
        });
    };
    const context = function (modal, item) {
        const box = modal.querySelector('.js-wr-modal-context');
        box.replaceChildren();
        [['Part', item.part_number], ['Serial / Heat', item.serial_number+(item.heat_number && item.heat_number !== item.serial_number ? ' / '+item.heat_number : '')], ['Site', item.site_code]].forEach(function (pair) {
            const group = document.createElement('span');
            const label = document.createElement('small'); label.textContent = pair[0];
            const value = document.createElement('strong'); value.textContent = pair[1] || '-';
            group.append(label, value); box.appendChild(group);
        });
    };
    const permission = function (modal, data) {
        const message = modal.querySelector('.js-wr-permission-message');
        message.textContent = data.denial_reason || '';
        message.classList.toggle('d-none', data.can_edit || !data.denial_reason);
    };
    const loading = function (modal, active) {
        modal.querySelector('.js-wr-modal-loading').classList.toggle('d-none', !active);
        modal.querySelector('.js-wr-modal-content').classList.toggle('d-none', active);
    };

    const fillReview = function (data) {
        const form = document.getElementById('wr-review-form');
        form.action = data.urls.review;
        context(reviewModal, data.item); permission(reviewModal, data);
        tags(reviewModal.querySelector('.js-wr-cpa-customers'), data.item.customers, false);
        tags(reviewModal.querySelector('.js-wr-sales'), data.item.sales, true);
        ['review_status','next_follow_up_date','review_detail','corrective_action','preventive_action','sales_remark','review_updated_at'].forEach(function (name) {
            if (form.elements[name]) form.elements[name].value = data.review[name] || '';
        });
        form.querySelectorAll('input:not([type="hidden"]),select,textarea').forEach(function (control) { control.disabled = !data.can_edit; });
        form.querySelector('.js-wr-save-review').classList.toggle('d-none', !data.can_edit);
    };

    const resetCustomerForm = function () {
        const form = document.getElementById('wr-customer-form');
        form.reset(); form.elements.supersedes_id.value = '';
        customerModal.querySelector('.js-wr-customer-form-title').textContent = 'เพิ่ม Customer';
        customerModal.querySelector('.js-wr-save-customer').innerHTML = '<i class="fas fa-floppy-disk me-1"></i>เพิ่ม Customer';
        customerModal.querySelector('.js-wr-cancel-revision').classList.add('d-none');
        states.get(customerModal).dirty = false;
    };
    const fillCustomers = function (data) {
        const form = document.getElementById('wr-customer-form');
        form.action = data.urls.customer; customerModal.dataset.modalUrl = data.urls.modal_data;
        context(customerModal, data.item); permission(customerModal, data);
        tags(customerModal.querySelector('.js-wr-cpa-customers'), data.item.customers, false);
        const list = customerModal.querySelector('.js-wr-additional-list'); list.replaceChildren();
        if (!data.additional_customers.length) {
            const empty = document.createElement('div'); empty.className = 'text-muted small mt-2'; empty.textContent = 'ยังไม่มี Customer เพิ่มเติม'; list.appendChild(empty);
        }
        data.additional_customers.forEach(function (extra) {
            const card = document.createElement('div'); card.className = 'wr-customer-revision';
            const name = document.createElement('strong'); name.textContent = extra.customer_name;
            const reason = document.createElement('div'); reason.className = 'small mt-1'; reason.textContent = 'เหตุผล: '+extra.reason;
            const explanation = document.createElement('div'); explanation.className = 'small text-muted'; explanation.textContent = extra.explanation;
            const audit = document.createElement('div'); audit.className = 'small text-muted mt-1'; audit.textContent = 'Revision '+extra.revision_no+' · '+extra.created_by_name+' · '+extra.created_at;
            card.append(name, reason, explanation, audit);
            if (data.can_edit) {
                const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'btn btn-sm btn-link px-0 mt-1'; edit.textContent = 'แก้ไขโดยสร้าง Revision ใหม่';
                edit.addEventListener('click', function () {
                    form.elements.supersedes_id.value = extra.id; form.elements.customer_name.value = extra.customer_name;
                    form.elements.reason.value = extra.reason; form.elements.explanation.value = extra.explanation;
                    customerModal.querySelector('.js-wr-customer-form-title').textContent = 'สร้าง Revision ใหม่';
                    customerModal.querySelector('.js-wr-save-customer').innerHTML = '<i class="fas fa-floppy-disk me-1"></i>บันทึก Revision ใหม่';
                    customerModal.querySelector('.js-wr-cancel-revision').classList.remove('d-none');
                    states.get(customerModal).dirty = true; form.elements.customer_name.focus();
                });
                card.appendChild(edit);
            }
            list.appendChild(card);
        });
        form.classList.toggle('d-none', !data.can_edit);
        resetCustomerForm();
        if (currentButton) currentButton.querySelector('.js-wr-customer-count').textContent = data.additional_customers.length;
    };

    const loadData = async function (modal, url, successMessage) {
        loading(modal, true); setMessage(modal, '', 'success'); states.get(modal).dirty = false;
        try {
            const response = await fetch(url, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) throw new Error(response.status === 403 ? 'คุณไม่มีสิทธิ์เปิดข้อมูลรายการนี้' : 'โหลดข้อมูลไม่สำเร็จ');
            const data = await response.json();
            modal === reviewModal ? fillReview(data) : fillCustomers(data);
            loading(modal, false);
            if (successMessage) setMessage(modal, successMessage, 'success');
        } catch (error) {
            loading(modal, false); setMessage(modal, error.message || 'โหลดข้อมูลไม่สำเร็จ', 'danger');
        }
    };
    const open = function (modal, button) {
        currentButton = button;
        bootstrap.Modal.getOrCreateInstance(modal).show();
        loadData(modal, button.dataset.modalUrl);
    };
    document.querySelectorAll('.js-wr-open-review').forEach(function (button) { button.addEventListener('click', function () { open(reviewModal, button); }); });
    document.querySelectorAll('.js-wr-open-customer').forEach(function (button) { button.addEventListener('click', function () { open(customerModal, button); }); });

    [document.getElementById('wr-review-form'), document.getElementById('wr-customer-form')].forEach(function (form) {
        form.addEventListener('input', function () { states.get(form.closest('.modal')).dirty = true; });
        form.addEventListener('change', function () { states.get(form.closest('.modal')).dirty = true; });
    });
    customerModal.querySelector('.js-wr-cancel-revision').addEventListener('click', resetCustomerForm);

    const submit = async function (form, modal, afterSave) {
        if (!form.reportValidity()) return;
        const state = states.get(modal); state.saving = true;
        const button = form.querySelector('button[type="submit"]'); button.disabled = true;
        setMessage(modal, '', 'success');
        try {
            const response = await fetch(form.action, {method:'POST',body:new FormData(form),headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const data = await response.json().catch(function () { return {}; });
            if (!response.ok) {
                const errors = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || 'บันทึกไม่สำเร็จ');
                throw new Error(errors);
            }
            state.dirty = false; await afterSave(data);
        } catch (error) { setMessage(modal, error.message || 'บันทึกไม่สำเร็จ', 'danger'); }
        finally { state.saving = false; button.disabled = false; }
    };
    document.getElementById('wr-review-form').addEventListener('submit', function (event) {
        event.preventDefault(); const form = event.currentTarget;
        submit(form, reviewModal, async function (data) {
            form.elements.review_updated_at.value = data.review_updated_at || '';
            setMessage(reviewModal, data.message, 'success');
            if (currentButton) {
                const row = currentButton.closest('tr');
                const badge = row?.querySelector('[data-review-status]');
                const followUp = row?.querySelector('[data-review-follow-up]');
                const labels = {open:'ยังไม่ได้ติดตาม',waiting_follow_up:'กำลังติดตาม รอคำตอบ',in_progress:'มีกำหนดส่งมอบแล้ว'};
                const classes = {open:'bg-light text-primary',waiting_follow_up:'bg-warning-subtle text-warning-emphasis',in_progress:'bg-success-subtle text-success-emphasis'};
                if (badge) { badge.textContent = labels[data.review_status] || data.review_status; badge.className = 'badge '+(classes[data.review_status] || 'bg-light text-dark'); }
                if (followUp) { followUp.className = 'small text-muted mt-1'; followUp.textContent = data.next_follow_up_date ? 'ตามต่อ '+data.next_follow_up_date : 'ยังไม่ได้กำหนดวันติดตาม'; }
            }
        });
    });
    document.getElementById('wr-customer-form').addEventListener('submit', function (event) {
        event.preventDefault(); const form = event.currentTarget;
        submit(form, customerModal, async function (data) { await loadData(customerModal, customerModal.dataset.modalUrl, data.message); });
    });

    [reviewModal, customerModal].forEach(function (modal) {
        modal.addEventListener('hide.bs.modal', function (event) {
            const state = states.get(modal);
            if (state.saving) { event.preventDefault(); return; }
            if (!state.dirty) return;
            event.preventDefault();
            if (window.confirm('มีข้อมูลที่ยังไม่ได้บันทึก ต้องการปิดและทิ้งการเปลี่ยนแปลงหรือไม่?')) {
                state.dirty = false; setTimeout(function () { bootstrap.Modal.getInstance(modal)?.hide(); }, 0);
            }
        });
        modal.addEventListener('hidden.bs.modal', function () { currentButton?.focus(); currentButton = null; });
    });
    window.addEventListener('beforeunload', function (event) {
        if (![...states.values()].some(function (state) { return state.dirty; })) return;
        event.preventDefault(); event.returnValue = '';
    });
});
</script>
@endpush
