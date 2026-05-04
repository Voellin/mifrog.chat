@extends('admin.layout')

@section('title', 'Mifrog 管理后台 - 记忆中心')
@section('header-title', '记忆中心')
@section('header-subtitle', '按 L1-L4 查看、维护和复核记忆')
@section('page-title', '记忆中心')
@section('page-desc', '后台只展示记忆链路 L1-L4；用户姓名、部门和职务属于基础上下文，不再作为记忆层展示。')

@push('head')
<style>
/* memory 页专属：让 .pro-content 不作为 sticky 的滚动容器，
   使 .memory-owner-card 的 sticky 能直接粘到浏览器视口（body 是真正滚动者） */
.pro-content {
    overflow-x: clip;
    overflow-y: visible;
}
.memory-center {
    color: var(--pro-text);
}
.memory-center,
.memory-center * {
    box-sizing: border-box;
}
.memory-topbar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 4px 0 8px;
}
.memory-crumb {
    color: #8a8176;
    font-size: 13px;
    white-space: nowrap;
}
.memory-crumb span {
    margin-left: 5px;
    color: #b5aa9f;
}
.memory-owner-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    background: var(--pro-layer-l3);
    color: #fff;
    font-weight: 900;
    width: 56px;
    height: 56px;
    border-radius: 14px;
    font-size: 24px;
}
.memory-btn {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    border: 1px solid var(--pro-border);
    border-radius: 8px;
    background: #fff;
    color: #312b25;
    padding: 0 12px;
    font-size: 13px;
    font-weight: 800;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
}
.memory-btn-green {
    border-color: #b8e5d0;
    background: #effbf5;
    color: #007a57;
}
.memory-btn-purple {
    border-color: #d8c6ff;
    background: #f7f2ff;
    color: #6d28d9;
}
.memory-btn-amber {
    border-color: #f2d386;
    background: #fff8df;
    color: #a16207;
}
.memory-empty {
    border: 1px dashed #d8d0c7;
    border-radius: 10px;
    background: #fff;
    padding: 18px;
    color: #7c7368;
    font-size: 13px;
}
.memory-owner-card {
    display: grid;
    grid-template-columns: 56px minmax(0, 1fr) auto;
    gap: 18px;
    align-items: center;
    margin-top: 12px;
    border: 1px solid var(--pro-border);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(14px);
    padding: 18px 22px;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.05);
    position: sticky;
    top: 0;
    z-index: 11;
}
.memory-owner-title {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.memory-owner-title h2 {
    margin: 0;
    font-size: 26px;
    line-height: 1.1;
}
.memory-status {
    display: inline-flex;
    align-items: center;
    height: 22px;
    border-radius: 999px;
    background: #dffbea;
    color: #007a57;
    padding: 0 8px;
    font-size: 12px;
    font-weight: 900;
}
.memory-status.off {
    background: #f2f2f2;
    color: #737373;
}
.memory-chip {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    border: 1px solid var(--pro-border);
    border-radius: 999px;
    background: #fff;
    color: #4d463f;
    padding: 0 9px;
    font-size: 12px;
    font-weight: 700;
}
.memory-owner-meta {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    margin-top: 10px;
    color: #6f665c;
    font-size: 12px;
}
.memory-owner-meta code {
    color: #6f665c;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 11px;
}
.memory-owner-metrics {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 18px;
}
.memory-metric-pill {
    display: inline-flex;
    gap: 10px;
    align-items: baseline;
    min-width: 110px;
    border-radius: 10px;
    padding: 10px 12px;
    cursor: pointer;
    transition: transform 140ms ease, box-shadow 140ms ease, filter 140ms ease;
}
.memory-metric-pill:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
    filter: brightness(1.04);
}
.memory-metric-pill:active {
    transform: translateY(0);
}
.memory-metric-pill b {
    color: #334155;
    font-size: 22px;
    line-height: 1;
}
.memory-metric-pill span {
    font-size: 12px;
}
.memory-metric-pill em {
    display: inline-flex;
    align-items: center;
    height: 20px;
    border-radius: 5px;
    background: rgba(255,255,255,0.65);
    padding: 0 6px;
    font-size: 11px;
    font-style: normal;
    font-weight: 900;
}
.memory-metric-l1 { background: #eef5fb; color: #28628a; }
.memory-metric-l2 { background: #fff2c5; color: #a16207; }
.memory-metric-l3 { background: #d9f8e8; color: #007a57; }
.memory-metric-l4 { background: #eadcff; color: #6d28d9; }
.memory-owner-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.memory-stack {
    position: relative;
    display: grid;
    gap: 26px;
    margin-top: 22px;
    padding-left: 56px;
}
.memory-stack::before {
    content: "";
    position: absolute;
    top: 12px;
    bottom: 10px;
    left: 18px;
    width: 2px;
    background: var(--pro-border);
}
.memory-layer-row {
    position: relative;
}
.memory-layer-pin {
    position: absolute;
    top: 4px;
    left: -56px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: 2px solid currentColor;
    border-radius: 12px;
    background: var(--pro-bg);
    font-size: 13px;
    font-weight: 950;
    z-index: 2;
}
.memory-layer-pin.l3 { color: var(--pro-layer-l3); }
.memory-layer-pin.l2 { color: var(--pro-layer-l2); }
.memory-layer-pin.l1 { color: var(--pro-layer-l1); }
.memory-layer-pin.l4 { color: var(--pro-layer-l4); }
.memory-layer-card {
    overflow: hidden;
    border: 1px solid var(--pro-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04);
}
.memory-layer-head {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 16px;
    align-items: start;
    padding: 20px 22px;
}
.memory-layer-head.l3 { background: linear-gradient(90deg, #e9fbf4 0%, #f9fffb 100%); }
.memory-layer-head.l2 { background: linear-gradient(90deg, #fff8df 0%, #fffdf5 100%); }
.memory-layer-head.l1 { background: linear-gradient(90deg, #eef7ff 0%, #fbfdff 100%); }
.memory-layer-head.l4 { background: linear-gradient(90deg, #f3edff 0%, #fffaff 100%); }
.memory-kicker {
    margin-bottom: 6px;
    color: #007a57;
    font-size: 12px;
    font-weight: 950;
}
.memory-layer-head.l2 .memory-kicker { color: #b45309; }
.memory-layer-head.l1 .memory-kicker { color: #0f7ab8; }
.memory-layer-head.l4 .memory-kicker { color: #7c3aed; }
.memory-layer-head h3 {
    margin: 0;
    color: #111827;
    font-size: 22px;
    line-height: 1.2;
}
.memory-layer-head p {
    margin: 6px 0 0;
    color: #5f5b56;
    font-size: 13px;
    line-height: 1.6;
}
.memory-layer-tools {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
}
.memory-filter-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    border-top: 1px solid var(--pro-border);
    border-bottom: 1px solid var(--pro-border);
    padding: 13px 22px;
}
.memory-filter-chip {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    min-height: 30px;
    border: 1px solid var(--pro-border);
    border-radius: 999px;
    background: #fff;
    color: #443c34;
    padding: 0 12px;
    font-size: 12px;
    font-weight: 850;
    cursor: pointer;
}
.memory-filter-chip.active {
    border-color: var(--pro-layer-l3);
    background: var(--pro-layer-l3);
    color: #fff;
}
.memory-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
}
.memory-fact-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    border-top: 0;
}
.memory-fact-card {
    min-height: 156px;
    border-right: 1px solid var(--pro-border);
    border-bottom: 1px solid var(--pro-border);
    padding: 18px 20px;
}
.memory-fact-card:nth-child(2n) {
    border-right: 0;
}
.memory-fact-head {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.memory-fact-badges {
    display: flex;
    gap: 7px;
    align-items: center;
    flex-wrap: wrap;
}
.memory-cat {
    display: inline-flex;
    gap: 5px;
    align-items: center;
    height: 22px;
    border-radius: 999px;
    padding: 0 8px;
    font-size: 12px;
    font-weight: 950;
}
.memory-cat.identity { background: #dff2ff; color: #0369a1; }
.memory-cat.preference { background: #eee2ff; color: #7c3aed; }
.memory-cat.constraint { background: #fff1c4; color: #b45309; }
.memory-cat.background { background: #d9f8e8; color: #047857; }
.memory-cat.goal { background: #ffe2ef; color: #be185d; }
.memory-cat.other { background: #f0f0f0; color: #525252; }
.memory-id {
    color: #8a8176;
    font-size: 11px;
    font-weight: 800;
}
.memory-review {
    display: inline-flex;
    align-items: center;
    height: 22px;
    border-radius: 5px;
    background: #d7fbe7;
    color: #007a57;
    padding: 0 7px;
    font-size: 11px;
    font-weight: 900;
}
.memory-review.warn {
    background: #fff0c2;
    color: #a16207;
}
.memory-fact-text {
    min-height: 42px;
    color: #111827;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.65;
}
.memory-priority-line {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
    margin: 18px 0 14px;
}
.memory-priority-track {
    height: 3px;
    border-radius: 999px;
    background: #ebe7df;
    overflow: hidden;
}
.memory-priority-bar {
    height: 100%;
    border-radius: 999px;
    background: var(--pro-layer-l3);
}
.memory-priority-label {
    color: #4e463f;
    font-size: 11px;
}
.memory-fact-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    color: #6f665c;
    font-size: 12px;
}
.memory-l2-shell,
.memory-l1-shell {
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr);
    min-height: 360px;
}
.memory-side-list {
    border-right: 1px solid var(--pro-border);
    background: #fffdf8;
    padding: 10px;
}
.memory-side-list a {
    display: block;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 12px 12px;
    color: #18181b;
    text-decoration: none;
}
.memory-side-list a.active {
    border-color: #bfead7;
    background: #f0fbf5;
    box-shadow: 0 0 0 1px rgba(0,154,109,0.06);
}
.memory-side-list strong {
    display: block;
    font-size: 14px;
    line-height: 1.2;
}
.memory-side-list small {
    display: block;
    margin-top: 5px;
    color: #7c7368;
    font-size: 12px;
}
.memory-entry-feed,
.memory-event-feed {
    max-height: 620px;
    overflow: auto;
    padding: 16px 18px;
}
.memory-entry {
    display: grid;
    gap: 8px;
    border-bottom: 1px dashed var(--pro-border);
    padding: 14px 0;
}
.memory-entry:first-child {
    padding-top: 0;
}
.memory-entry:last-child {
    border-bottom: 0;
}
.memory-entry-head {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    color: #7c7368;
    font-size: 12px;
}
.memory-entry-title {
    color: #0f0f10;
    font-weight: 900;
}
.memory-tag-row {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}
.memory-tag {
    display: inline-flex;
    align-items: center;
    min-height: 21px;
    border: 1px solid #e5ded5;
    border-radius: 5px;
    background: #fbfaf7;
    color: #4f463d;
    padding: 0 6px;
    font-size: 11px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.memory-tag.ttl {
    margin-left: auto;
    border-color: #abe7c7;
    background: #d8fae8;
    color: #007a57;
    font-family: inherit;
    font-weight: 900;
    cursor: help;
}
.memory-tag.expired {
    border-color: #ffd386;
    background: #fff4cf;
    color: #b45309;
}
.memory-entry-content {
    border-radius: 7px;
    background: #f6f5f0;
    padding: 10px 12px;
    color: #111827;
    font-size: 13px;
    line-height: 1.65;
    word-break: break-word;
}
.memory-l1-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
    border-bottom: 1px solid var(--pro-border);
    padding: 10px 14px;
    color: #6f665c;
    font-size: 12px;
}
/* 分段控件 segmented control（视图切换器） */
.memory-toggle-group {
    display: inline-flex;
    gap: 0;
    border: 1px solid var(--pro-border);
    border-radius: 999px;
    background: var(--pro-surface-soft);
    padding: 3px;
}
.memory-toggle-group a,
.memory-toggle-group button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: #6b665e;
    padding: 5px 14px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: background 140ms ease, color 140ms ease, box-shadow 140ms ease;
    line-height: 1.2;
}
.memory-toggle-group a:hover,
.memory-toggle-group button:hover {
    color: #2d2a25;
}
.memory-toggle-group a.active,
.memory-toggle-group button.active {
    background: var(--pro-layer-l3);
    color: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.memory-toggle-group a.active:hover,
.memory-toggle-group button.active:hover {
    color: #fff;
}
.memory-toggle-group button.active {
    background: #e8fbf2;
    color: #007a57;
}
.memory-event {
    position: relative;
    display: grid;
    gap: 6px;
    margin-left: 16px;
    border-left: 1px dashed #d8d0c7;
    padding: 0 0 20px 24px;
}
.memory-event:last-child {
    padding-bottom: 0;
}
.memory-event::before {
    content: "";
    position: absolute;
    left: -6px;
    top: 3px;
    width: 10px;
    height: 10px;
    border: 2px solid #fff;
    border-radius: 50%;
    background: var(--pro-layer-l1);
    box-shadow: 0 0 0 1px var(--pro-layer-l1);
}
.memory-event.recall::before { background: var(--pro-layer-l3); box-shadow: 0 0 0 1px var(--pro-layer-l3); }
.memory-event.tool::before { background: var(--pro-layer-l1); box-shadow: 0 0 0 1px var(--pro-layer-l1); }
.memory-event.error::before { background: #dc2626; box-shadow: 0 0 0 1px #dc2626; }
.memory-event-head {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    color: #6f665c;
    font-size: 12px;
}
.memory-event-type {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    border-radius: 5px;
    background: #e0f2fe;
    color: #0369a1;
    padding: 0 7px;
    font-weight: 950;
}
.memory-event.recall .memory-event-type { background: #d8fae8; color: #007a57; }
.memory-event.error .memory-event-type { background: #fee2e2; color: #b91c1c; }
.memory-event-text {
    color: #111827;
    font-size: 13px;
    line-height: 1.65;
    word-break: break-word;
}
.memory-json {
    white-space: pre-wrap;
    word-break: break-word;
    border: 1px solid #e5ded5;
    border-radius: 8px;
    background: #f8f7f2;
    padding: 12px;
    color: #3d352d;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    line-height: 1.55;
}
.memory-l4-grid {
    display: grid;
    grid-template-columns: minmax(0, 7fr) minmax(0, 13fr);
    gap: 0;
    border-top: 1px solid var(--pro-border);
}
.memory-l4-panel {
    padding: 20px 22px;
}
.memory-l4-panel:nth-child(odd) {
    border-right: 1px solid var(--pro-border);
}
.memory-l4-panel h4 {
    margin: 0 0 14px;
    color: #312b25;
    font-size: 14px;
}
.memory-bars {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    align-items: end;
    min-height: 108px;
}
.memory-bar {
    display: grid;
    gap: 7px;
    align-content: end;
    color: #7c7368;
    font-size: 11px;
    text-align: center;
}
.memory-bar-fill {
    min-height: 8px;
    border-radius: 5px 5px 0 0;
    background: linear-gradient(180deg, #a78bfa 0%, #7c3aed 100%);
}
.memory-recalled-list,
.memory-signal-list {
    display: grid;
    gap: 10px;
}
.memory-recalled-item,
.memory-signal-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    border-bottom: 1px dashed var(--pro-border);
    padding-bottom: 9px;
    color: #111827;
    font-size: 13px;
}
.memory-recalled-item:last-child,
.memory-signal-item:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}
.memory-signal-meter {
    width: 110px;
    height: 4px;
    border-radius: 999px;
    background: #eee8df;
    overflow: hidden;
}
.memory-signal-meter span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--pro-layer-l4);
}
.memory-log-table {
    width: 100%;
    border-collapse: collapse;
}
.memory-log-table th,
.memory-log-table td {
    border-bottom: 1px solid var(--pro-border);
    padding: 10px 10px;
    color: #2d2925;
    font-size: 12px;
    text-align: left;
    vertical-align: top;
}
.memory-log-table th {
    background: #f7f4ee;
    color: #7c7368;
    font-weight: 900;
}
.memory-log-table td:last-child,
.memory-log-table th:last-child {
    text-align: right;
}
.memory-is-hidden {
    display: none !important;
}
@media (max-width: 1180px) {
    .memory-topbar {
        grid-template-columns: 1fr;
    }
            .memory-owner-card {
        grid-template-columns: 56px minmax(0, 1fr);
    }
    .memory-owner-actions {
        grid-column: 1 / -1;
        justify-content: flex-start;
    }
    .memory-fact-grid,
    .memory-l4-grid {
        grid-template-columns: 1fr;
    }
    .memory-fact-card,
    .memory-l4-panel:nth-child(odd) {
        border-right: 0;
    }
}
@media (max-width: 820px) {
    .memory-center {
        margin: 0;
    }
    .memory-owner-card {
        grid-template-columns: 1fr;
    }
    .memory-stack {
        padding-left: 0;
    }
    .memory-stack::before,
    .memory-layer-pin {
        display: none;
    }
    .memory-layer-head {
        grid-template-columns: 1fr;
    }
    .memory-layer-tools {
        justify-content: flex-start;
    }
    .memory-l2-shell,
    .memory-l1-shell {
        grid-template-columns: 1fr;
    }
    .memory-side-list {
        border-right: 0;
        border-bottom: 1px solid var(--pro-border);
        max-height: 240px;
        overflow: auto;
    }
}

/* Codex layout containment fix: keep the imported content design inside the existing admin shell. */
.memory-center {
    width: 100%;
    max-width: 1180px;
    margin: 0 auto;
    overflow: visible;
}
.memory-topbar {
    position: relative;
    top: auto;
    grid-template-columns: minmax(220px, 360px) minmax(260px, 1fr);
    padding: 0 0 14px;
    margin-bottom: 16px;
}
.memory-crumb {
    display: none;
}
.memory-user-form,
.memory-top-actions,
.memory-owner-card,
.memory-owner-card > *,
.memory-stack,
.memory-layer-row,
.memory-layer-card,
.memory-layer-head,
.memory-l2-shell,
.memory-l1-shell,
.memory-l4-grid {
    min-width: 0;
    max-width: 100%;
}
.memory-owner-card {
    grid-template-columns: 56px minmax(0, 1fr);
}
.memory-owner-actions {
    grid-column: 1 / -1;
    justify-content: flex-end;
}
.memory-layer-head {
    grid-template-columns: minmax(0, 1fr);
}
.memory-layer-tools {
    justify-content: flex-start;
    min-width: 0;
    max-width: 100%;
}
.memory-layer-tools form {
    margin: 0;
}
.memory-chip,
.memory-btn {
    white-space: nowrap;
}
.memory-fact-text,
.memory-entry-content,
.memory-event-text,
.memory-recalled-item,
.memory-signal-item,
.memory-log-table td {
    overflow-wrap: anywhere;
}
@media (min-width: 1320px) {
    .memory-topbar {
        grid-template-columns: minmax(220px, 360px) minmax(360px, 1fr);
    }
    .memory-owner-card {
        grid-template-columns: 56px minmax(0, 1fr) minmax(180px, auto);
    }
    .memory-owner-actions {
        grid-column: auto;
    }
}
@media (max-width: 900px) {
    .memory-topbar {
        grid-template-columns: 1fr;
    }
    .memory-top-actions,
    .memory-owner-actions {
        justify-content: flex-start;
    }
    }


/* Keep the compact user selector text inside the pill. */


/* Final top selector: keep it single-line and readable. */


/* === 用户选择器 picker (button + popover 双列) === */
.memory-owner-info { min-width: 0; flex: 1 1 auto; position: relative; }
.memory-user-picker-trigger {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: transparent;
    border: 0;
    padding: 4px 10px;
    margin-left: -10px;
    border-radius: 10px;
    cursor: pointer;
    color: inherit;
    font: inherit;
    transition: background 120ms ease;
}
.memory-user-picker-trigger:hover {
    background: rgba(15, 94, 74, 0.08);
}
.memory-user-picker-trigger:hover .memory-picker-caret {
    color: var(--pro-layer-l3);
}
.memory-user-picker-trigger:focus {
    outline: none;
}
.memory-user-picker-trigger:focus-visible {
    outline: 2px solid var(--pro-layer-l3);
    outline-offset: 2px;
}
.memory-picker-name {
    margin: 0;
    font-size: 26px;
    font-weight: 800;
    color: var(--pro-text);
    letter-spacing: -0.4px;
    line-height: 1.15;
}
.memory-picker-caret {
    display: inline-block;
    margin-left: 2px;
    color: #94a3b8;
    font-size: 16px;
    transition: transform 160ms ease, color 160ms ease;
}
.memory-user-picker-trigger[aria-expanded="true"] .memory-picker-caret {
    transform: rotate(180deg);
    color: var(--pro-layer-l3);
}
.memory-user-picker-popover {
    position: absolute;
    top: calc(100% + 6px);
    left: 22px;
    z-index: 60;
    width: min(560px, calc(100% - 44px));
    background: #fff;
    border: 1px solid var(--pro-border);
    border-radius: 12px;
    box-shadow: 0 18px 48px rgba(15, 23, 42, 0.14);
    overflow: hidden;
}
.memory-user-picker-popover[hidden] { display: none; }
.memory-picker-cols {
    display: grid;
    grid-template-columns: minmax(180px, 220px) 1fr;
    max-height: 380px;
}
.memory-picker-dept-col,
.memory-picker-user-col {
    list-style: none;
    margin: 0;
    padding: 6px 0;
    overflow-y: auto;
}
.memory-picker-dept-col {
    background: #fbfaf6;
    border-right: 1px solid var(--pro-border);
}
.memory-picker-dept-item {
    padding: 8px 14px;
    font-size: 13px;
    color: #334155;
    cursor: default;
    line-height: 1.35;
    user-select: none;
}
.memory-picker-dept-item:hover,
.memory-picker-dept-item.active {
    background: #eef8f3;
    color: #0f5e4a;
}
.memory-picker-user-row { padding: 0; }
.memory-picker-user-item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 14px;
    color: #1f2937;
    text-decoration: none;
    font-size: 13px;
    line-height: 1.3;
}
.memory-picker-user-item:hover {
    background: #f3f4f6;
}
.memory-picker-user-item.active {
    background: #eef8f3;
    color: #0f5e4a;
    font-weight: 700;
}
.memory-user-mini-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--pro-layer-l3);
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    flex: 0 0 auto;
}
.memory-user-mini-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}
.memory-picker-empty {
    padding: 18px 14px;
    color: #94a3b8;
    font-size: 13px;
    text-align: center;
}
.memory-picker-back {
    display: none;
    width: 100%;
    border: 0;
    border-top: 1px solid var(--pro-border);
    background: #fbfaf6;
    color: #475569;
    font-size: 13px;
    padding: 10px;
    cursor: pointer;
    text-align: left;
}
.memory-picker-back:hover { background: #eef8f3; color: #0f5e4a; }

@media (max-width: 720px) {
    .memory-user-picker-popover {
        left: 12px;
        right: 12px;
        width: auto;
    }
    .memory-picker-cols {
        grid-template-columns: 1fr;
    }
    .memory-picker-user-col { display: none; }
    .memory-user-picker-popover.show-users .memory-picker-dept-col { display: none; }
    .memory-user-picker-popover.show-users .memory-picker-user-col { display: block; }
    .memory-user-picker-popover.show-users .memory-picker-back { display: block; }
}


/* === L2/L1 列表分页 + 容器限高 === */
.memory-l2-shell,
.memory-l1-shell {
    max-height: 480px;
    min-height: 280px;
    height: 480px;                /* 显式高度，让 grid 子项有 reference */
    overflow: hidden;
    grid-template-rows: minmax(0, 1fr);  /* 强制 grid row 高 = container 高 */
}
.memory-l1-shell {
    max-height: 600px;
    height: 600px;
}
.memory-l2-shell .memory-side-list,
.memory-l1-shell .memory-side-list,
.memory-l2-shell .memory-entry-feed,
.memory-l1-shell .memory-event-feed {
    overflow-y: auto;
    max-height: 100%;
}
.memory-pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 10px;
    border-top: 1px solid var(--pro-border);
    background: #fbfaf6;
    font-size: 12px;
    color: var(--pro-text-secondary);
}
.memory-pager-info {
    flex: 1 1 auto;
    text-align: center;
}
.memory-pager-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 24px;
    border: 1px solid var(--pro-border);
    border-radius: 6px;
    background: #fff;
    color: var(--pro-text);
    font-size: 14px;
    line-height: 1;
    text-decoration: none;
    transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
}
.memory-pager-btn:hover {
    background: #eef8f3;
    border-color: #8fcac1;
    color: #0f5e4a;
}
.memory-pager-btn.disabled {
    pointer-events: none;
    opacity: 0.4;
}
.memory-side-list,
.memory-entry-feed,
.memory-event-feed {
    display: flex;
    flex-direction: column;
}
.memory-side-list-wrap,
.memory-feed-wrap {
    display: flex;
    flex-direction: column;
    min-height: 0;
    height: 100%;            /* 强制继承 grid item 全高，否则 wrap 会按内容撑开 */
    overflow: hidden;        /* 防 wrap 自身溢出 shell */
}
.memory-side-list-wrap > .memory-side-list,
.memory-feed-wrap > .memory-entry-feed,
.memory-feed-wrap > .memory-event-feed {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;        /* 内部可滚动 */
}


/* 锚点跳转时给 sticky owner-card 留出空间，避免视线被遮 */
#l1, #l2, #l3, #l4 {
    scroll-margin-top: 180px;
}


/* L4 事实召回累计：fact 文本 + 召回次数 chip 一行排齐 */
.memory-signal-fact {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #1f2937;
}
.memory-signal-count {
    display: inline-flex;
    align-items: center;
    height: 22px;
    padding: 0 10px;
    border-radius: 999px;
    background: var(--pro-layer-l4-soft);
    color: var(--pro-layer-l4);
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
    flex: 0 0 auto;
}


/* 检索日志：CSS grid 版（彻底绕开 table 算法的怪异宽度行为） */
.memory-log-grid {
    display: grid;
    gap: 0;
    width: 100%;
    min-width: 0;
}
.memory-log-row {
    display: grid;
    grid-template-columns: 88px minmax(0, 1fr) 42px 42px 56px;
    align-items: center;
    padding: 8px 14px 8px 8px;     /* 右内边 14px 让 合计 不顶 panel 边 */
    border-bottom: 1px solid var(--pro-border);
    color: #2d2925;
    font-size: 12px;
    column-gap: 6px;
}
.memory-log-row > span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}
.memory-log-row.memory-log-head {
    background: #f7f4ee;
    color: #7c7368;
    font-weight: 900;
}
.memory-log-row .memory-log-time {
    color: #475569;
    font-variant-numeric: tabular-nums;
}
.memory-log-row .memory-log-query {
    cursor: default;
}
.memory-log-row .num {
    text-align: center;
    font-variant-numeric: tabular-nums;
}
.memory-log-empty {
    padding: 14px;
    color: #94a3b8;
    text-align: center;
    font-size: 12px;
}

</style>
@endpush

@section('content')
@php
    $selectedUser = $memory['user'] ?? null;
    $health = $memory['health'] ?? [];
    $todaySummary = $memory['today_summary'] ?? [];
    $sessions = collect($memory['sessions'] ?? []);
    $l2Files = collect($memory['l2_files'] ?? []);
    // === 分页源（来自 controller paginateArray，全量也保留以做 chip 计数）===
    $sessionsAll = $sessions;
    $l2FilesAll = $l2Files;
    $paginatedSessions = collect($pagination['l1_sessions']['items'] ?? []);
    $paginatedL2Files = collect($pagination['l2_files']['items'] ?? []);
    $paginatedEvents = $pagination['l1_events']['items'] ?? [];
    $paginatedL2Entries = collect($pagination['l2_entries']['items'] ?? []);
    $sessionsPg = $pagination['l1_sessions'] ?? null;
    $eventsPg = $pagination['l1_events'] ?? null;
    $l2FilesPg = $pagination['l2_files'] ?? null;
    $l2EntriesPg = $pagination['l2_entries'] ?? null;
    $l1ViewMode = $l1View ?? 'structured';
    $l3AuditRows = collect($memory['l3_audit_rows'] ?? []);
    $inactiveFacts = collect($memory['l3_recent_inactive_facts'] ?? []);
    $l4Signals = collect($memory['l4_fact_signals'] ?? []);
    $l4Logs = collect($memory['l4_logs'] ?? []);
    $recentEntries = collect($memory['recent_entries'] ?? []);
    $selectedL2Date = $memory['selected_l2_date'] ?? null;
    $selectedL2Entries = $recentEntries
        ->filter(fn ($entry) => (string) $entry->layer === 'L2' && (! $selectedL2Date || optional($entry->source_date)->format('Y-m-d') === $selectedL2Date))
        ->take(18);
    if ($selectedL2Entries->isEmpty()) {
        $selectedL2Entries = $recentEntries->where('layer', 'L2')->take(18);
    }
    $categoryLabel = function (?string $category): string {
        return [
            'identity' => '身份',
            'preference' => '偏好',
            'constraint' => '规则',
            'background' => '背景',
            'goal' => '目标',
        ][$category ?: ''] ?? ($category ?: '其他');
    };
    $categoryClass = fn (?string $category): string => in_array($category, ['identity', 'preference', 'constraint', 'background', 'goal'], true) ? $category : 'other';
    $categoryCounts = $l3AuditRows
        ->map(fn ($row) => (string) (($row['fact']->category ?? '') ?: 'other'))
        ->countBy();
    $fmtTime = function ($carbon, string $format = 'Y-m-d H:i') {
        if (! $carbon) {
            return '-';
        }
        try {
            $c = $carbon instanceof \Carbon\Carbon ? $carbon : \Carbon\Carbon::parse($carbon);
            return $c->timezone('Asia/Shanghai')->format($format);
        } catch (\Throwable $e) {
            return '-';
        }
    };
    $relTime = function ($carbon) {
        if (! $carbon) {
            return '-';
        }
        try {
            $c = $carbon instanceof \Carbon\Carbon ? $carbon : \Carbon\Carbon::parse($carbon);
            return $c->diffForHumans(['parts' => 1, 'short' => false]);
        } catch (\Throwable $e) {
            return '-';
        }
    };
    $memoryUrl = function (array $extra = [], string $anchor = '') use ($selectedUserId, $memory) {
        $query = [];
        if (! empty($selectedUserId)) {
            $query['user_id'] = $selectedUserId;
        }
        if (! empty($memory['selected_session'])) {
            $query['session'] = $memory['selected_session'];
        }
        if (! empty($memory['selected_l2_date'])) {
            $query['l2_date'] = $memory['selected_l2_date'];
        }
        foreach ($extra as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
                continue;
            }
            $query[$key] = $value;
        }
        return '/admin/memory'.($query ? '?'.http_build_query($query) : '').($anchor !== '' ? '#'.$anchor : '');
    };
    // 分页 helper：保留 user/session/l2_date 以及现有页码参数，覆盖指定页参数
    $pagerLink = function (string $pageParam, int $pageNum, string $anchor = '') use ($memoryUrl, $l1View) {
        $extra = [$pageParam => $pageNum];
        // 若不在 structured 视图下则保留 l1_view
        if ($l1View !== 'structured') {
            $extra['l1_view'] = $l1View;
        }
        // anchor 由调用方提供（'l1' 或 'l2'），配合 #lN 的 scroll-margin-top 让视线停在该层附近
        return $memoryUrl($extra, $anchor);
    };
    // 切换 session/l2_file 时重置对应分页
    $sessionLink = function (string $sessionKey) use ($memoryUrl) {
        return $memoryUrl([
            'session' => $sessionKey,
            'l1_session_page' => null,
            'l1_page' => null,
            'l1_view' => null,
        ], 'l1');
    };
    $l2FileLink = function (string $date) use ($memoryUrl) {
        return $memoryUrl([
            'l2_date' => $date,
            'l2_file_page' => null,
            'l2_page' => null,
        ], 'l2');
    };
    // raw/structured toggle：重置 l1_page 到 1
    $l1ViewLink = function (string $view) use ($memoryUrl) {
        return $memoryUrl([
            'l1_view' => $view === 'structured' ? null : $view,
            'l1_page' => 1,
        ], 'l1');
    };
    $name = trim((string) ($selectedUser->name ?? ''));
    $name = $name !== '' ? $name : ($selectedUser ? '用户 #'.$selectedUser->id : '未选择用户');
    $initial = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    $departmentName = trim((string) ($selectedUser?->department?->name ?? ''));
    $userKey = trim((string) ($selectedUser->feishu_open_id ?? ''));
    $userKey = $userKey !== '' ? $userKey : trim((string) ($selectedUser->feishu_union_id ?? ''));
    $userKey = $userKey !== '' ? $userKey : ($selectedUser ? 'user_'.$selectedUser->id : '');
    $lastActiveAt = $todaySummary['last_retrieval_at'] ?? ($selectedUser->updated_at ?? null);
    $l4DayCounts = $l4Logs
        ->groupBy(fn ($log) => $log->created_at ? $log->created_at->timezone('Asia/Shanghai')->format('m-d') : '-')
        ->map(fn ($items) => $items->count());
    $trendDays = collect(range(6, 0))->map(function ($offset) use ($l4DayCounts) {
        $date = \Carbon\Carbon::now('Asia/Shanghai')->subDays($offset);
        $key = $date->format('m-d');
        return ['label' => $key, 'count' => (int) ($l4DayCounts[$key] ?? 0)];
    });
    $maxTrend = max(1, (int) $trendDays->max('count'));
    $maxSignal = max(1, (int) $l4Signals->max('recall_count'));
    $eventLabel = function (array $event): string {
        $type = (string) ($event['event_type'] ?? $event['type'] ?? $event['name'] ?? 'event');
        return [
            'run_event' => '运行事件',
            'assistant_final' => '回复',
            'tool_start' => '工具调用',
            'tool_end' => '工具返回',
            'memory_recall' => '召回',
            'error' => '错误',
            'final' => '完成',
        ][$type] ?? $type;
    };
    $eventTone = function (array $event): string {
        $type = strtolower((string) ($event['event_type'] ?? $event['type'] ?? $event['name'] ?? ''));
        if (str_contains($type, 'recall')) {
            return 'recall';
        }
        if (str_contains($type, 'tool')) {
            return 'tool';
        }
        if (str_contains($type, 'error')) {
            return 'error';
        }
        return '';
    };
    $eventText = function (array $event): string {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $value = $event['message'] ?? $event['content'] ?? $payload['message'] ?? $payload['content'] ?? $payload['summary'] ?? '';
        return trim((string) $value);
    };
@endphp

<div class="memory-center">
    <div class="memory-topbar">
        <div class="memory-crumb">记忆中心 <span>›</span></div>
    </div>

    @if(!$memory || !$selectedUser)
        <div class="memory-empty">当前没有可展示的记忆数据。请先选择一个用户。</div>
    @else
        <section class="memory-owner-card">
            <div class="memory-owner-avatar">{{ $initial }}</div>
            <div class="memory-owner-info">
                <div class="memory-owner-title">
                    <button type="button" class="memory-user-picker-trigger" id="memory-user-picker-btn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="memory-picker-name">{{ $name }}</span>
                        <span class="memory-picker-caret" aria-hidden="true">▾</span>
                    </button>
                    <span class="memory-status {{ $selectedUser->is_active ? '' : 'off' }}">{{ $selectedUser->is_active ? '启用' : '停用' }}</span>
                    @if($departmentName !== '')
                        <span class="memory-chip">{{ $departmentName }}</span>
                    @endif
                </div>
                <div class="memory-owner-meta">
                    <span>UUID <code>{{ $userKey }}</code></span>
                    <span>最近活跃 {{ $fmtTime($lastActiveAt) }}</span>
                    <span>用户基础上下文 独立于记忆层</span>
                </div>
                <div class="memory-owner-metrics">
                    <a class="memory-metric-pill memory-metric-l1" href="#l1"><em>L1</em><b>{{ (int) ($health['session_count'] ?? 0) }}</b><span>会话</span></a>
                    <a class="memory-metric-pill memory-metric-l2" href="#l2"><em>L2</em><b>{{ (int) ($health['l2_file_count'] ?? 0) }}</b><span>事项</span></a>
                    <a class="memory-metric-pill memory-metric-l3" href="#l3"><em>L3</em><b>{{ (int) ($health['active_fact_count'] ?? 0) }}</b><span>长期事实</span></a>
                    <a class="memory-metric-pill memory-metric-l4" href="#l4"><em>L4</em><b>{{ (int) ($health['retrieval_log_count'] ?? 0) }}</b><span>召回</span></a>
                </div>
            </div>
            <div class="memory-owner-actions">
                <button class="memory-btn" type="button" data-copy-value="{{ $userKey }}">复制 UUID</button>
                <a class="memory-btn memory-btn-purple" href="#l4">召回历史</a>
            </div>

            <div class="memory-user-picker-popover" id="memory-user-picker-popover" hidden>
                <div class="memory-picker-cols">
                    <ul class="memory-picker-dept-col" role="listbox" aria-label="部门">
                        <li class="memory-picker-dept-item active" data-dept-id="0" role="option" aria-selected="true">全部部门</li>
                        @foreach($departmentRows ?? [] as $row)
                            <li class="memory-picker-dept-item" data-dept-id="{{ $row['id'] }}" role="option">{{ str_repeat('— ', (int) $row['depth']) }}{{ $row['name'] }}</li>
                        @endforeach
                    </ul>
                    <ul class="memory-picker-user-col" id="memory-picker-user-col" role="listbox" aria-label="用户">
                        @forelse($users as $user)
                            @php
                                $uName = trim((string) ($user->name ?? '')) !== '' ? $user->name : '用户 #'.$user->id;
                                $uInitial = function_exists('mb_substr') ? mb_substr($uName, 0, 1, 'UTF-8') : substr($uName, 0, 1);
                            @endphp
                            <li class="memory-picker-user-row" data-dept-id="{{ (int) ($user->department_id ?? 0) }}" role="option">
                                <a href="/admin/memory?user_id={{ $user->id }}" class="memory-picker-user-item {{ (int) $selectedUserId === (int) $user->id ? 'active' : '' }}">
                                    <span class="memory-user-mini-avatar">{{ $uInitial }}</span>
                                    <span class="memory-user-mini-name">{{ $uName }}</span>
                                </a>
                            </li>
                        @empty
                            <li class="memory-picker-empty">未找到用户</li>
                        @endforelse
                    </ul>
                </div>
                <button type="button" class="memory-picker-back" id="memory-picker-back" hidden aria-label="返回部门列表">‹ 返回部门</button>
            </div>
        </section>

        <div class="memory-stack">
            <section id="l3" class="memory-layer-row">
                <div class="memory-layer-pin l3">L3</div>
                <article class="memory-layer-card">
                    <div class="memory-layer-head l3">
                        <div>
                            <div class="memory-kicker">长期事实 · 稳定记忆</div>
                            <h3>{{ $name }} 的稳定记忆</h3>
                            <p>真正稳定、会影响后续对话的事实。这里是 Agent 的长期约束：经过规则复核、可被反复召回。</p>
                        </div>
                        <div class="memory-layer-tools">
                            @adminCan('memory.repair')
                                <form method="post" action="/admin/memory/repair" onsubmit="return confirm('将按当前规则重新整理这位用户的长期记忆，确认继续吗？');">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $selectedUserId }}">
                                    <button class="memory-btn" type="submit">重新整理</button>
                                </form>
                            @endadminCan
                            <span class="memory-chip">共 {{ $l3AuditRows->count() }} 条 · 活跃 {{ (int) ($health['active_fact_count'] ?? 0) }}</span>
                        </div>
                    </div>
                    <div class="memory-filter-row" data-filter-scope="l3">
                        <button class="memory-filter-chip active" type="button" data-category-filter="all">全部 {{ $l3AuditRows->count() }}</button>
                        @foreach($categoryCounts as $category => $count)
                            <button class="memory-filter-chip" type="button" data-category-filter="{{ $category }}">
                                <span class="memory-dot"></span>{{ $categoryLabel($category) }} {{ $count }}
                            </button>
                        @endforeach
                    </div>
                    <div class="memory-fact-grid">
                        @forelse($l3AuditRows as $row)
                            @php
                                $fact = $row['fact'];
                                $review = $row['review'] ?? [];
                                $meta = is_array($fact->meta) ? $fact->meta : [];
                                $recallCount = (int) ($meta['recall_count'] ?? 0);
                                $priority = max(0, min(100, (int) $fact->priority));
                            @endphp
                            <div class="memory-fact-card memory-search-item" data-category="{{ $fact->category }}">
                                <div class="memory-fact-head">
                                    <div class="memory-fact-badges">
                                        <span class="memory-cat {{ $categoryClass($fact->category) }}"><span class="memory-dot"></span>{{ $categoryLabel($fact->category) }}</span>
                                        <span class="memory-id">#{{ $fact->id }}</span>
                                        @if(($review['allow'] ?? false) === true)
                                            <span class="memory-review">✓ 通过</span>
                                        @else
                                            <span class="memory-review warn">{{ $review['reason'] ?? '复核中' }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="memory-fact-text">{{ $fact->fact }}</div>
                                <div class="memory-priority-line">
                                    <div class="memory-priority-track"><div class="memory-priority-bar" style="width: {{ $priority }}%;"></div></div>
                                    <span class="memory-priority-label">优先级 {{ $priority }}</span>
                                </div>
                                <div class="memory-fact-meta">
                                    <span>来源 run#{{ $fact->last_run_id ?: '-' }}</span>
                                    <span>召回 {{ $recallCount }} 次</span>
                                    <span>更新 {{ $fmtTime($fact->updated_at) }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="memory-empty">暂无 L3 长期事实。</div>
                        @endforelse
                    </div>
                </article>
            </section>

            <section id="l2" class="memory-layer-row">
                <div class="memory-layer-pin l2">L2</div>
                <article class="memory-layer-card">
                    <div class="memory-layer-head l2">
                        <div>
                            <div class="memory-kicker">近期事项 · TTL 衰减</div>
                            <h3>近期事项</h3>
                            <p>按日期沉淀的中期事项与摘要。它适合保留“最近在推进什么”，定期按 TTL 标记过期。</p>
                        </div>
                        <div class="memory-layer-tools">
                            <span class="memory-chip">已过期 {{ (int) ($health['expired_l2_count'] ?? 0) }}</span>
                            <span class="memory-chip">事项 {{ $recentEntries->where('layer', 'L2')->count() }}</span>
                            @adminCan('memory.cleanup')
                                <form method="post" action="/admin/memory/cleanup" onsubmit="return confirm('将按 TTL 规则标记过期的近期事项，确认继续吗？');">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $selectedUserId }}">
                                    <button class="memory-btn memory-btn-amber" type="submit">清理过期</button>
                                </form>
                            @endadminCan
                        </div>
                    </div>
                    <div class="memory-l2-shell">
                        <div class="memory-side-list-wrap">
                            <div class="memory-side-list">
                                @forelse($paginatedL2Files as $file)
                                    <a href="{{ $l2FileLink($file['date']) }}" class="{{ ($memory['selected_l2_date'] ?? null) === $file['date'] ? 'active' : '' }}">
                                        <strong>{{ $file['date'] }}</strong>
                                        <small>{{ number_format((int) ($file['size'] ?? 0)) }} B · {{ $file['updated_at'] ?? '-' }}</small>
                                    </a>
                                @empty
                                    <div class="memory-empty">暂无 L2 日期文件。</div>
                                @endforelse
                            </div>
                            @if($l2FilesPg && $l2FilesPg['total_pages'] > 1)
                                <div class="memory-pager">
                                    <a class="memory-pager-btn {{ $l2FilesPg['page'] <= 1 ? 'disabled' : '' }}" href="{{ $pagerLink('l2_file_page', max(1, $l2FilesPg['page'] - 1), 'l2') }}" aria-label="上一页">‹</a>
                                    <span class="memory-pager-info">{{ $l2FilesPg['page'] }} / {{ $l2FilesPg['total_pages'] }} · 共 {{ $l2FilesPg['total'] }}</span>
                                    <a class="memory-pager-btn {{ $l2FilesPg['page'] >= $l2FilesPg['total_pages'] ? 'disabled' : '' }}" href="{{ $pagerLink('l2_file_page', min($l2FilesPg['total_pages'], $l2FilesPg['page'] + 1), 'l2') }}" aria-label="下一页">›</a>
                                </div>
                            @endif
                        </div>
                        <div class="memory-feed-wrap">
                            <div class="memory-entry-feed">
                                @forelse($paginatedL2Entries as $entry)
                                @php
                                    $tags = array_values(array_filter((array) $entry->tags));
                                    $ttlTag = collect($tags)->first(fn ($tag) => str_starts_with((string) $tag, 'ttl:'));
                                    $entryContent = trim((string) ($entry->content ?: $entry->summary));
                                @endphp
                                <div class="memory-entry memory-search-item">
                                    <div class="memory-entry-head">
                                        <span>{{ $fmtTime($entry->created_at, 'Y-m-d H:i:s') }}</span>
                                        <span class="memory-entry-title">{{ $entry->title ?: 'Memory entry' }}</span>
                                        <span>run#{{ $entry->run_id ?: '-' }}</span>
                                        @if($ttlTag)
                                            @php
                                                $ttlDays = (int) (preg_match('/^ttl:(\d+)$/', $ttlTag, $mTtl) ? $mTtl[1] : 0);
                                                $ttlTitle = $entry->expired_at
                                                    ? '已过期，机器人不再主动召回'
                                                    : ($ttlDays > 0 ? $ttlDays.' 天后过期，过期后机器人不再主动召回' : '');
                                            @endphp
                                            <span class="memory-tag ttl {{ $entry->expired_at ? 'expired' : '' }}" title="{{ $ttlTitle }}">{{ strtoupper(str_replace(':', ' ', $ttlTag)) }}{{ $entry->expired_at ? ' · 已过期' : '' }}</span>
                                        @endif
                                    </div>
                                    @if($tags !== [])
                                        <div class="memory-tag-row">
                                            @foreach(array_slice($tags, 0, 10) as $tag)
                                                <span class="memory-tag">{{ $tag }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="memory-entry-content">{{ $entryContent !== '' ? $entryContent : $entry->summary }}</div>
                                </div>
                                @empty
                                    <div class="memory-empty">暂无 L2 索引条目。</div>
                                @endforelse
                            </div>
                            @if($l2EntriesPg && $l2EntriesPg['total_pages'] > 1)
                                <div class="memory-pager">
                                    <a class="memory-pager-btn {{ $l2EntriesPg['page'] <= 1 ? 'disabled' : '' }}" href="{{ $pagerLink('l2_page', max(1, $l2EntriesPg['page'] - 1), 'l2') }}" aria-label="上一页">‹</a>
                                    <span class="memory-pager-info">{{ $l2EntriesPg['page'] }} / {{ $l2EntriesPg['total_pages'] }} · 共 {{ $l2EntriesPg['total'] }}</span>
                                    <a class="memory-pager-btn {{ $l2EntriesPg['page'] >= $l2EntriesPg['total_pages'] ? 'disabled' : '' }}" href="{{ $pagerLink('l2_page', min($l2EntriesPg['total_pages'], $l2EntriesPg['page'] + 1), 'l2') }}" aria-label="下一页">›</a>
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            </section>

            <section id="l1" class="memory-layer-row">
                <div class="memory-layer-pin l1">L1</div>
                <article class="memory-layer-card">
                    <div class="memory-layer-head l1">
                        <div>
                            <div class="memory-kicker">会话流水 · Append-only 事件</div>
                            <h3>会话流水</h3>
                            <p>每次运行写入的原始事件。这里保留“发生了什么”，不判断它是否值得长期记住。</p>
                        </div>
                        <div class="memory-layer-tools">
                            <span class="memory-chip">会话 {{ $sessions->count() }}</span>
                            <span class="memory-chip">当前 {{ $memory['selected_session'] ?: '未选择' }}</span>
                        </div>
                    </div>
                    <div class="memory-l1-toolbar">
                        <div class="memory-toggle-group">
                            <a class="{{ $l1ViewMode === 'structured' ? 'active' : '' }}" href="{{ $l1ViewLink('structured') }}">结构化</a>
                            <a class="{{ $l1ViewMode === 'raw' ? 'active' : '' }}" href="{{ $l1ViewLink('raw') }}">原始 JSON</a>
                        </div>
                        <span>{{ $memory['selected_session'] ?: '-' }} · 共 {{ $eventsPg['total'] ?? 0 }} 个事件</span>
                    </div>
                    <div class="memory-l1-shell">
                        <div class="memory-side-list-wrap">
                            <div class="memory-side-list">
                                @forelse($paginatedSessions as $session)
                                    <a href="{{ $sessionLink($session['session_key']) }}" class="{{ ($memory['selected_session'] ?? null) === $session['session_key'] ? 'active' : '' }}">
                                        <strong>{{ $session['session_key'] }}</strong>
                                        <small>{{ $session['updated_at'] ?? '-' }} · {{ number_format((int) ($session['size'] ?? 0)) }} B</small>
                                    </a>
                                @empty
                                    <div class="memory-empty">暂无 L1 会话文件。</div>
                                @endforelse
                            </div>
                            @if($sessionsPg && $sessionsPg['total_pages'] > 1)
                                <div class="memory-pager">
                                    <a class="memory-pager-btn {{ $sessionsPg['page'] <= 1 ? 'disabled' : '' }}" href="{{ $pagerLink('l1_session_page', max(1, $sessionsPg['page'] - 1), 'l1') }}" aria-label="上一页">‹</a>
                                    <span class="memory-pager-info">{{ $sessionsPg['page'] }} / {{ $sessionsPg['total_pages'] }} · 共 {{ $sessionsPg['total'] }}</span>
                                    <a class="memory-pager-btn {{ $sessionsPg['page'] >= $sessionsPg['total_pages'] ? 'disabled' : '' }}" href="{{ $pagerLink('l1_session_page', min($sessionsPg['total_pages'], $sessionsPg['page'] + 1), 'l1') }}" aria-label="下一页">›</a>
                                </div>
                            @endif
                        </div>
                        <div class="memory-feed-wrap">
                            @if($l1ViewMode === 'structured')
                                <div class="memory-event-feed">
                                    @forelse($paginatedEvents as $event)
                                        @php
                                            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
                                            $text = $eventText($event);
                                            $raw = json_encode($payload ?: $event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                            $tone = $eventTone($event);
                                        @endphp
                                        <div class="memory-event {{ $tone }} memory-search-item">
                                            <div class="memory-event-head">
                                                <span class="memory-event-type">{{ $eventLabel($event) }}</span>
                                                <span>{{ $event['created_at'] ?? $event['timestamp'] ?? '-' }}</span>
                                                @if(!empty($payload['run_id']))
                                                    <span>run#{{ $payload['run_id'] }}</span>
                                                @endif
                                            </div>
                                            @if($text !== '')
                                                <div class="memory-event-text">{{ $text }}</div>
                                            @endif
                                            @if($raw && $raw !== '[]')
                                                <div class="memory-json">{{ $raw }}</div>
                                            @endif
                                        </div>
                                    @empty
                                        <div class="memory-empty">未选中会话，或这个会话暂时没有可展示事件。</div>
                                    @endforelse
                                </div>
                            @else
                                <div class="memory-event-feed">
                                    @if(! empty($paginatedEvents))
                                        <div class="memory-json">{{ json_encode($paginatedEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</div>
                                    @else
                                        <div class="memory-empty">暂无原始 JSON。</div>
                                    @endif
                                </div>
                            @endif
                            @if($eventsPg && $eventsPg['total_pages'] > 1)
                                <div class="memory-pager">
                                    <a class="memory-pager-btn {{ $eventsPg['page'] <= 1 ? 'disabled' : '' }}" href="{{ $pagerLink('l1_page', max(1, $eventsPg['page'] - 1), 'l1') }}" aria-label="上一页">‹</a>
                                    <span class="memory-pager-info">{{ $eventsPg['page'] }} / {{ $eventsPg['total_pages'] }} · 共 {{ $eventsPg['total'] }}</span>
                                    <a class="memory-pager-btn {{ $eventsPg['page'] >= $eventsPg['total_pages'] ? 'disabled' : '' }}" href="{{ $pagerLink('l1_page', min($eventsPg['total_pages'], $eventsPg['page'] + 1), 'l1') }}" aria-label="下一页">›</a>
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            </section>

            <section id="l4" class="memory-layer-row">
                <div class="memory-layer-pin l4">L4</div>
                <article class="memory-layer-card">
                    <div class="memory-layer-head l4">
                        <div>
                            <div class="memory-kicker">回想信号 · 召回反馈</div>
                            <h3>回想信号</h3>
                            <p>机器人实际把哪些记忆拉回了 prompt，以及最近是什么查询触发了召回。它用来反推“哪些记忆真的有用”。</p>
                        </div>
                        <div class="memory-layer-tools">
                            <span class="memory-chip">召回 {{ (int) ($health['retrieval_log_count'] ?? 0) }}</span>
                            <span class="memory-chip">不同查询 {{ (int) ($health['distinct_recent_queries'] ?? 0) }}</span>
                        </div>
                    </div>
                    <div class="memory-l4-grid">
                        <div class="memory-l4-panel">
                            <h4>近 7 天召回趋势</h4>
                            <div class="memory-bars">
                                @foreach($trendDays as $day)
                                    <div class="memory-bar">
                                        <div class="memory-bar-fill" style="height: {{ max(8, (int) round(($day['count'] / $maxTrend) * 72)) }}px;"></div>
                                        <span>{{ $day['label'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="memory-l4-panel">
                            <h4>近 7 天高频想起</h4>
                            <div class="memory-recalled-list">
                                @forelse(($todaySummary['top_recalled'] ?? []) as $rec)
                                    <div class="memory-recalled-item memory-search-item">
                                        <span><span class="memory-cat {{ $categoryClass($rec['category'] ?? null) }}">{{ $categoryLabel($rec['category'] ?? null) }}</span> {{ $rec['fact'] ?? '' }}</span>
                                        <strong>{{ (int) ($rec['hit_count'] ?? 0) }}×</strong>
                                    </div>
                                @empty
                                    <div class="memory-empty">最近 7 天暂无命中长期记忆的召回记录。</div>
                                @endforelse
                            </div>
                        </div>
                        <div class="memory-l4-panel">
                            <h4>事实召回累计</h4>
                            <div class="memory-signal-list">
                                @forelse($l4Signals->take(8) as $signal)
                                    <div class="memory-signal-item memory-search-item">
                                        <span class="memory-signal-fact">{{ $signal['fact']->fact }}</span>
                                        <span class="memory-signal-count">召回 {{ (int) $signal['recall_count'] }} 次</span>
                                    </div>
                                @empty
                                    <div class="memory-empty">暂无事实召回累计。</div>
                                @endforelse
                            </div>
                        </div>
                        <div class="memory-l4-panel">
                            <h4>最近检索日志</h4>
                            <div class="memory-log-grid">
                                <div class="memory-log-row memory-log-head">
                                    <span>时间</span>
                                    <span>查询</span>
                                    <span class="num">L3</span>
                                    <span class="num">L2</span>
                                    <span class="num">合计</span>
                                </div>
                                @forelse($l4Logs->take(8) as $log)
                                    @php
                                        $hitL3 = is_array($log->retrieved_l3_fact_ids) ? count($log->retrieved_l3_fact_ids) : 0;
                                        $hitL2 = is_array($log->retrieved_l2_entry_ids) ? count($log->retrieved_l2_entry_ids) : 0;
                                    @endphp
                                    <div class="memory-log-row memory-search-item">
                                        <span class="memory-log-time">{{ $fmtTime($log->created_at, 'm-d H:i') }}</span>
                                        <span class="memory-log-query" title="{{ $log->query_text }}">{{ $log->query_text }}</span>
                                        <span class="num">{{ $hitL3 }}</span>
                                        <span class="num">{{ $hitL2 }}</span>
                                        <span class="num">{{ $hitL3 + $hitL2 }}</span>
                                    </div>
                                @empty
                                    <div class="memory-log-empty">暂无检索日志。</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </article>
            </section>
        </div>
    @endif
</div>

<script>
(function () {
    document.querySelectorAll('[data-filter-scope="l3"] [data-category-filter]').forEach(function (button) {
        button.addEventListener('click', function () {
            const value = button.getAttribute('data-category-filter');
            document.querySelectorAll('[data-filter-scope="l3"] [data-category-filter]').forEach(function (btn) {
                btn.classList.toggle('active', btn === button);
            });
            document.querySelectorAll('.memory-fact-card').forEach(function (card) {
                card.classList.toggle('memory-is-hidden', value !== 'all' && card.getAttribute('data-category') !== value);
            });
        });
    });

    document.querySelectorAll('[data-copy-value]').forEach(function (button) {
        button.addEventListener('click', function () {
            const value = button.getAttribute('data-copy-value') || '';
            if (!value) return;
            navigator.clipboard?.writeText(value).then(function () {
                const old = button.textContent;
                button.textContent = '已复制';
                setTimeout(function () { button.textContent = old; }, 1200);
            });
        });
    });
})();

    /* picker (button + popover) */
    (function () {
        const trigger = document.getElementById('memory-user-picker-btn');
        const popover = document.getElementById('memory-user-picker-popover');
        if (!trigger || !popover) return;
        const userCol = document.getElementById('memory-picker-user-col');
        const deptItems = popover.querySelectorAll('.memory-picker-dept-item');
        const userRows = userCol ? userCol.querySelectorAll('.memory-picker-user-row') : [];
        const backBtn = document.getElementById('memory-picker-back');

        function openPicker() {
            popover.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }
        function closePicker() {
            popover.hidden = true;
            popover.classList.remove('show-users');
            trigger.setAttribute('aria-expanded', 'false');
        }
        function applyDeptFilter(deptId) {
            deptItems.forEach(function (item) {
                item.classList.toggle('active', item.getAttribute('data-dept-id') === deptId);
            });
            userRows.forEach(function (row) {
                const id = row.getAttribute('data-dept-id');
                row.style.display = (deptId === '0' || id === deptId) ? '' : 'none';
            });
        }

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            if (popover.hidden) openPicker();
            else closePicker();
        });
        document.addEventListener('click', function (event) {
            if (popover.hidden) return;
            if (popover.contains(event.target) || trigger.contains(event.target)) return;
            closePicker();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !popover.hidden) closePicker();
        });

        deptItems.forEach(function (item) {
            item.addEventListener('mouseenter', function () {
                applyDeptFilter(item.getAttribute('data-dept-id'));
            });
            // 移动端：点击部门切换到用户列
            item.addEventListener('click', function () {
                applyDeptFilter(item.getAttribute('data-dept-id'));
                if (window.matchMedia('(max-width: 720px)').matches) {
                    popover.classList.add('show-users');
                }
            });
        });
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                popover.classList.remove('show-users');
            });
        }
    })();

</script>
@endsection
