{{-- CSS ร่วมของหน้า WIP (Dashboard / รายการ WO) --}}
<style>
    .wip { --wip-radius: .75rem; font-variant-numeric: tabular-nums; }
    .wip .card { border: 1px solid rgba(0, 0, 0, .06); border-radius: var(--wip-radius); }
    .wip .card-header { background: transparent; border-bottom: 1px solid rgba(0, 0, 0, .06); font-weight: 600; padding: .75rem 1rem; }
    .wip .muted-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color); }

    /* toolbar */
    .wip-toolbar .btn-check + .btn { min-width: 4.5rem; }
    .wip-chip { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; padding: .2rem .6rem; border-radius: 999px; background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); text-decoration: none; }
    .wip-chip:hover { background: var(--bs-primary-border-subtle); }
    .wip-filter-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; }
    .wip-filter-grid fieldset { border: 1px solid rgba(0, 0, 0, .08); border-radius: .6rem; padding: .6rem .75rem .75rem; background: var(--bs-tertiary-bg); min-width: 0; }
    .wip-filter-grid legend + * { clear: both; }
    .wip-filter-grid legend { float: left; width: 100%; font-size: .78rem; font-weight: 600; color: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: .03em; margin-bottom: .35rem; padding: 0; }
    .wip-filter-grid legend i { color: var(--bs-primary); margin-right: .25rem; }
    .wip-filter-grid .form-label { font-size: .78rem; color: var(--bs-secondary-color); margin-bottom: .1rem; }
    .wip-filter-grid .ts-wrapper.form-select-sm { min-height: 0; height: auto; padding: 0; }
    .wip-filter-grid .ts-wrapper.form-select-sm .ts-control { min-height: 0; height: calc(1.5em + .5rem); padding: .2rem 2.25rem .2rem .5rem; font-size: .875rem; line-height: 1.5; }
    .wip-filter-grid .ts-wrapper.form-select-sm .ts-control > input { font-size: .875rem; }
    @media (max-width: 1399.98px) { .wip-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 767.98px) { .wip-filter-grid { grid-template-columns: 1fr; } }

    /* hero */
    .wip-hero-num { font-size: 2.6rem; font-weight: 700; line-height: 1; }
    .wip-stack { display: flex; height: 14px; border-radius: 7px; overflow: hidden; background: var(--bs-tertiary-bg); }
    .wip-stack > div { height: 100%; }
    .wip-legend { display: grid; grid-template-columns: auto 1fr auto; gap: .25rem .5rem; font-size: .85rem; align-items: center; }
    .wip-dot { width: .65rem; height: .65rem; border-radius: 50%; display: inline-block; }

    /* pipeline */
    .wip-flow { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
    .wip-stage { position: relative; display: block; color: inherit; text-decoration: none; border-radius: var(--wip-radius); padding: .9rem 1rem; background: var(--bs-body-bg); border: 1px solid rgba(0, 0, 0, .08); border-top: 4px solid var(--stage-color); transition: box-shadow .15s, transform .15s; height: 100%; }
    .wip-stage:hover { box-shadow: 0 .4rem 1rem rgba(0, 0, 0, .08); transform: translateY(-1px); color: inherit; }
    .wip-stage.active { box-shadow: 0 0 0 2px var(--stage-color); }
    .wip-flow .wip-stage:not(:last-child)::after { content: "\f054"; font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; right: -.62rem; top: 50%; transform: translateY(-50%); font-size: .7rem; color: var(--bs-secondary-color); background: var(--bs-body-bg); border-radius: 50%; width: 1.1rem; height: 1.1rem; display: grid; place-items: center; z-index: 1; border: 1px solid rgba(0, 0, 0, .08); }
    .wip-stage .ton { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
    .wip-stage .ton small { font-size: .85rem; font-weight: 400; color: var(--bs-secondary-color); }
    .wip-stage .title { font-weight: 600; font-size: .9rem; }
    .wip-stage .sub { font-size: .78rem; color: var(--bs-secondary-color); }
    .wip-side { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
    .wip-side .wip-stage { padding: .65rem .9rem; border-top-width: 1px; border-left: 4px solid var(--stage-color); }
    .wip-side .wip-stage .ton { font-size: 1.25rem; }
    .wip-stage.disabled { opacity: .55; pointer-events: none; }

    /* tables */
    .wip-table { font-size: .875rem; }
    .wip-table th { font-size: .75rem; font-weight: 600; color: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
    .wip-table td { vertical-align: middle; }
    .wip-table tbody tr[data-href] { cursor: pointer; }
    .wip-scroll { max-height: 440px; overflow-y: auto; }
    .wip-scroll thead th { position: sticky; top: 0; background: var(--bs-body-bg); z-index: 1; }
    .wip-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--bs-tertiary-bg); min-width: 80px; }
    .wip-age { display: inline-block; min-width: 3.4rem; text-align: center; font-weight: 600; font-size: .78rem; padding: .2rem .45rem; border-radius: 999px; }
    .wip-status { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; white-space: nowrap; }

    /* aging */
    .wip-aging { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
    @media (min-width: 768px) and (max-width: 1199.98px) { .wip-aging { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

    /* heatmap */
    .wip-heat td.p-1 { min-width: 4.2rem; }
    .wip-cell { display: block; border-radius: .4rem; padding: .3rem .25rem; font-weight: 600; font-size: .82rem; text-decoration: none; color: inherit; transition: transform .1s, box-shadow .1s; }
    a.wip-cell:hover { transform: scale(1.06); box-shadow: 0 .2rem .5rem rgba(0, 0, 0, .15); }
    .wip-cell.empty { color: var(--bs-tertiary-color, #adb5bd); font-weight: 400; }
    .wip-heat tfoot td { border-top: 2px solid var(--bs-border-color); background: var(--bs-tertiary-bg); }
    /* ตรึงคอลัมน์ Work Center ตอนเลื่อนแนวนอน (มือถือ) */
    .wip-heat th:first-child, .wip-heat td:first-child { position: sticky; left: 0; z-index: 2; background: var(--bs-body-bg); box-shadow: 1px 0 0 var(--bs-border-color); }
    .wip-heat thead th:first-child { z-index: 3; }
    .wip-heat tfoot td:first-child { background: var(--bs-tertiary-bg); }
    .wip-empty { height: 260px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem; color: var(--bs-secondary-color); text-align: center; }
    .wip-empty i { font-size: 2rem; opacity: .4; }
    .wip-aging a { text-decoration: none; color: inherit; border-radius: .6rem; padding: .6rem .75rem; border: 1px solid rgba(0, 0, 0, .08); border-left: 4px solid var(--band-color); display: block; }
    .wip-aging a.active { box-shadow: 0 0 0 2px var(--band-color); }
    .wip-aging .n { font-size: 1.4rem; font-weight: 700; line-height: 1.1; }

    @media (max-width: 991.98px) {
        .wip-flow { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .wip-flow .wip-stage:not(:last-child)::after { display: none; }
    }
    @media (max-width: 575.98px) {
        .wip-side { grid-template-columns: 1fr; }
        .wip-aging { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .wip-hero-num { font-size: 2.1rem; }
    }

    /* autocomplete (ช่องค้นหาเร็ว + Product) */
    .wip-search { position: relative; }
    .wip-search-icon { position: absolute; left: .65rem; top: 50%; transform: translateY(-50%); color: var(--bs-secondary-color); z-index: 3; font-size: .8rem; pointer-events: none; }
    .wip-search .ts-wrapper .ts-control { padding-left: 1.9rem; }
    .wip-toolbar .ts-wrapper.form-control-sm { min-height: 0; height: auto; padding: 0; }
    .wip-toolbar .ts-wrapper.form-control-sm .ts-control { min-height: calc(1.5em + .5rem); padding-top: .2rem; padding-bottom: .2rem; font-size: .875rem; }
    .wip-toolbar .ts-dropdown { font-size: .85rem; }
    .wip-toolbar .ts-dropdown .optgroup-header { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--bs-secondary-color); background: var(--bs-tertiary-bg); }
    .wip-sg { display: flex; flex-direction: column; line-height: 1.25; }
    .wip-sg-v { font-weight: 600; }
    .wip-sg-d { font-size: .75rem; color: var(--bs-secondary-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wip-toolbar .ts-dropdown .active .wip-sg-d { color: inherit; opacity: .8; }

    .wip-filter-grid.is-compact { grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: 820px; }
    @media (max-width: 767.98px) { .wip-filter-grid.is-compact { grid-template-columns: 1fr; } }

    /* tabs + summary strip (หน้ารายการ WO) */
    .wip-tabs .nav-link { font-weight: 600; color: var(--bs-secondary-color); }
    .wip-tabs .nav-link.active { color: var(--bs-primary); }
    .wip-summary-strip { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    .wip-summary-strip a { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .75rem; border-radius: 999px; border: 1px solid rgba(0, 0, 0, .1); text-decoration: none; color: inherit; font-size: .85rem; background: var(--bs-body-bg); }
    .wip-summary-strip a.active { border-color: var(--stage-color); box-shadow: 0 0 0 1px var(--stage-color); font-weight: 600; }
    .wip-summary-strip a:hover { background: var(--bs-tertiary-bg); }
</style>
