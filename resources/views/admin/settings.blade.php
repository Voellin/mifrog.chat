@extends('admin.layout')

@section('title', '米蛙管理后台 - 系统配置')

@push('head')
<style>
    /* ── Disable hover on all pro-card in settings pages ── */
    .pro-card:hover {
        transform: none !important;
        box-shadow: var(--pro-shadow-xs) !important;
        border-color: rgba(17, 35, 45, 0.09) !important;
    }

    /* ── Channel tab: channel cards ── */
    .channel-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .channel-card-inner {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }
    .channel-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 18px;
        color: #fff;
        flex-shrink: 0;
    }
    .channel-icon-feishu { background: linear-gradient(135deg, #3370ff, #2b5fd9); }
    .channel-icon-dingtalk { background: linear-gradient(135deg, #0089ff, #0066cc); }
    .channel-card-info h4 { margin: 0; font-size: 15px; font-weight: 600; color: var(--pro-text); }
    .channel-card-info p { margin: 2px 0 0; font-size: 12px; color: #8a9ba8; }
    .channel-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 500;
        margin-left: auto;
    }
    .channel-badge-active { background: #e6f7ef; color: #0a8a4a; }
    .channel-badge-soon { background: #f0f0f0; color: #999; }
    .coming-soon-body {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
        color: #bbb;
        text-align: center;
    }
    .coming-soon-body svg { margin-bottom: 12px; opacity: 0.4; }
    .coming-soon-body p { margin: 0; font-size: 13px; }

    /* ── Model tab: redesigned model console ── */
    .model-config-page {

        max-width: 1184px;
        margin: 0 auto;
        color: var(--pro-text);
    }
    .model-config-page * {
        letter-spacing: 0;
    }
    .model-page-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 18px;
        margin-bottom: 16px;
    }
    .model-page-head h1 {
        margin: 0;
        font-size: 22px;
        line-height: 1.25;
        color: var(--pro-text);
    }
    .model-page-head p {
        margin: 7px 0 0;
        color: var(--pro-text-secondary);
        font-size: 13px;
        line-height: 1.6;
    }
    .model-tabs-wrap {
        background: var(--pro-surface);
        border: 1px solid var(--pro-border);
        border-radius: 12px;
        box-shadow: 0 1px 1px rgba(20, 20, 15, 0.04), 0 1px 2px rgba(20, 20, 15, 0.04);
        overflow: hidden;
    }
    .model-tabs {
        display: flex;
        align-items: stretch;
        gap: 0;
        border-bottom: 1px solid var(--pro-border);
        overflow-x: auto;
    }
    .model-tab {
        border: 0;
        border-radius: 0;
        background: transparent;
        color: var(--pro-text-secondary);
        padding: 13px 18px;
        font-size: 13px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border-bottom: 2px solid transparent;
        white-space: nowrap;
    }
    .model-tab:hover {
        background: var(--pro-surface-soft);
        color: var(--pro-text);
    }
    .model-tab.active {
        color: var(--pro-primary-hover);
        border-bottom-color: var(--pro-primary);
        background: var(--pro-surface);
    }
    .model-tab .count {
        font-size: 11px;
        color: var(--pro-text-secondary);
        background: var(--pro-surface-soft);
        border-radius: 999px;
        padding: 1px 7px;
        font-variant-numeric: tabular-nums;
    }
    .model-tab.active .count {
        color: var(--pro-primary-hover);
        background: var(--pro-primary-soft);
    }
    .model-tabs-body {
        padding: 22px 24px;
    }
    .model-panel[hidden] {
        display: none !important;
    }
    .model-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 18px;
    }
    .model-toolbar .left {
        color: var(--pro-text-secondary);
        font-size: 13px;
        line-height: 1.7;
    }
    .model-toolbar .left strong {
        color: var(--pro-text);
        font-weight: 700;
    }
    .mc-btn {
        border: 1px solid var(--pro-border-strong);
        background: var(--pro-surface);
        color: var(--pro-text);
        border-radius: 8px;
        padding: 7px 13px;
        font-size: 13px;
        font-weight: 600;
        line-height: 1.2;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        white-space: nowrap;
        cursor: pointer;
    }
    .mc-btn:hover {
        background: var(--pro-surface-soft);
        border-color: var(--pro-text-secondary);
        color: var(--pro-text);
    }
    .mc-btn-primary {
        color: #fff;
        border-color: var(--pro-primary);
        background: var(--pro-primary);
    }
    .mc-btn-primary:hover {
        color: #fff;
        border-color: var(--pro-primary-hover);
        background: var(--pro-primary-hover);
    }
    .mc-btn-danger {
        color: var(--pro-error);
    }
    .mc-btn-sm {
        padding: 5px 10px;
        font-size: 12px;
    }
    .mc-section-title {
        margin: 18px 0 10px;
        color: var(--pro-text-secondary);
        font-size: 12px;
        font-weight: 700;
    }
    .vendor-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(292px, 1fr));
        gap: 12px;
    }
    .vendor-card {
        min-width: 0;
        min-height: 150px;
        background: var(--pro-surface);
        border: 1px solid var(--pro-border);
        border-radius: 12px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        box-shadow: 0 1px 1px rgba(20, 20, 15, 0.03);
    }
    .vendor-card.unconfigured {
        background: var(--pro-surface-soft);
        border-style: dashed;
    }
    .vc-head {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .vc-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        font-weight: 800;
        font-size: 13px;
        color: #075985;
        background: #e0f2fe;
    }
    .vc-icon.doubao { color: #1d4ed8; background: #dbeafe; }
    .vc-icon.qwen { color: #c05621; background: #ffedd5; }
    .vc-icon.deepseek { color: #1d4ed8; background: #dbeafe; }
    .vc-icon.claude { color: #9a3412; background: #ffedd5; }
    .vc-icon.kimi { color: #7e22ce; background: #f3e8ff; }
    .vc-icon.glm { color: #0369a1; background: #e0f2fe; }
    .vc-icon.openai { color: #166534; background: #dcfce7; }
    .vc-title {
        min-width: 0;
        flex: 1;
    }
    .vc-name {
        margin: 0;
        color: var(--pro-text);
        font-size: 14px;
        font-weight: 800;
        line-height: 1.35;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .vc-sub {
        margin-top: 2px;
        color: var(--pro-text-secondary);
        font-size: 12px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    /* R8b: 状态徽章更醒目 — 圆点 + 加深背景 + 加粗字体 + 弱阴影 */
    .vc-status {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 4px 10px 4px 8px;
        font-size: 11.5px;
        font-weight: 700;
        white-space: nowrap;
        color: var(--pro-text-secondary);
        border: 1px solid var(--pro-border);
        background: var(--pro-surface-soft);
        box-shadow: 0 1px 2px rgba(20,20,15,0.06);
    }
    .vc-status::before {
        content: "";
        display: inline-block;
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: currentColor;
        flex-shrink: 0;
    }
    .vc-status.ok {
        color: #047857;
        background: #d1fae5;
        border-color: #6ee7b7;
    }
    .vc-status.err {
        color: #b91c1c;
        background: #fee2e2;
        border-color: #f87171;
    }
    .vc-status.warn {
        color: #b45309;
        background: #fef3c7;
        border-color: #fcd34d;
    }
    .vc-status.unknown {
        color: var(--pro-text-secondary);
        background: var(--pro-surface-soft);
        border-color: var(--pro-border);
    }
    .vc-meta {
        display: grid;
        gap: 4px;
        padding: 8px 10px;
        border-radius: 8px;
        background: var(--pro-surface-soft);
        color: var(--pro-text-secondary);
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 11.5px;
        line-height: 1.45;
        word-break: break-all;
    }
    .vc-models {
        display: grid;
        gap: 6px;
    }
    .vc-model {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 8px;
        padding: 7px 8px;
        border-radius: 7px;
        background: var(--pro-surface-soft);
        font-size: 12px;
        min-width: 0;
    }
    .vc-model .cap {
        color: var(--pro-primary-hover);
        background: var(--pro-primary-soft);
        border-radius: 4px;
        padding: 1px 6px;
        font-weight: 700;
        white-space: nowrap;
    }
    .vc-model .name {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 11.5px;
        color: #101827;
    }
    .vc-model .star {
        color: var(--pro-primary-hover);
        border: 1px solid var(--pro-primary-soft);
        border-radius: 4px;
        background: var(--pro-primary-soft);
        padding: 1px 6px;
        font-size: 10.5px;
        white-space: nowrap;
    }
    .vc-empty {
        padding: 10px;
        border-radius: 8px;
        color: var(--pro-text-secondary);
        background: var(--pro-surface-soft);
        font-size: 12px;
    }
    .vc-foot {
        margin-top: auto;
        padding-top: 12px;
        border-top: 1px solid var(--pro-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        color: var(--pro-text-secondary);
        font-size: 12px;
    }
    .vc-foot strong {
        color: var(--pro-text);
    }
    .vc-actions {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .model-editor {
        margin-top: 18px;
        border: 1px solid var(--pro-border);
        border-radius: 12px;
        background: var(--pro-surface);
        overflow: hidden;
    }
    .model-editor > summary {
        list-style: none;
        cursor: pointer;
        padding: 14px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        border-bottom: 1px solid transparent;
        font-weight: 800;
    }
    .model-editor > summary::-webkit-details-marker {
        display: none;
    }
    .model-editor[open] > summary {
        border-bottom-color: var(--pro-border);
    }
    .model-editor > summary span {
        color: var(--pro-text-secondary);
        font-size: 12px;
        font-weight: 500;
    }
    .model-editor-body {
        margin-top: 18px;
        padding: 16px;
        border: 1px solid var(--pro-border);
        border-radius: 12px;
        background: var(--pro-surface);
        overflow: hidden;
    }

    /* R4: drawer + active-models bar + stepper + vendor-pick + model-pick === */
    /* [hidden] needs !important because .mc-btn sets display:inline-flex */
    .model-drawer [hidden], .md-foot [hidden] { display: none !important; }

    .active-models-bar {
        background: linear-gradient(135deg, var(--pro-primary-soft) 0%, #ffffff 65%);
        border: 1px solid var(--pro-border);
        border-left: 4px solid var(--pro-primary);
        border-radius: 12px;
        padding: 22px 26px;
        margin-bottom: 16px;
        box-shadow: 0 4px 14px rgba(15, 157, 111, 0.10), 0 1px 2px rgba(20, 20, 15, 0.04);
    }
    .active-models-head {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
    }
    .active-models-head .am-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: var(--pro-primary);
        box-shadow: 0 0 0 4px var(--pro-primary-soft);
        flex-shrink: 0;
    }
    .active-models-head h3 {
        margin: 0;
        font-size: 17px;
        font-weight: 800;
        color: var(--pro-text);
        letter-spacing: -0.01em;
    }
    .active-models-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .am-slot label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--pro-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 6px;
    }
    .am-slot label .req {
        color: var(--pro-error);
        margin-left: 2px;
    }
    .am-slot select {
        width: 100%;
        height: 38px;
        border: 1px solid var(--pro-border-strong);
        border-radius: 8px;
        padding: 0 12px;
        font-size: 13px;
        background: var(--pro-surface);
        color: var(--pro-text);
    }
    .am-slot .am-help {
        font-size: 11px;
        color: var(--pro-text-secondary);
        margin-top: 6px;
        line-height: 1.5;
    }
    .am-slot .am-status {
        font-size: 11px;
        color: var(--pro-primary-hover);
        margin-top: 4px;
        opacity: 0;
        transition: opacity 0.2s;
    }
    .am-slot .am-status.show {
        opacity: 1;
    }

    .model-drawer-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(20, 20, 15, 0.4);
        z-index: 1090;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s;
    }
    .model-drawer-backdrop.open {
        opacity: 1;
        pointer-events: auto;
    }
    .model-drawer {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 720px;
        max-width: 92vw;
        background: var(--pro-surface);
        z-index: 1100;
        box-shadow: 0 8px 16px rgba(20, 20, 15, 0.06), 0 24px 48px rgba(20, 20, 15, 0.12);
        display: flex;
        flex-direction: column;
        transform: translateX(100%);
        transition: transform 0.22s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .model-drawer.open {
        transform: translateX(0);
    }
    .md-head {
        padding: 16px 24px;
        border-bottom: 1px solid var(--pro-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .md-head h2 {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
    }
    .md-head .md-sub {
        font-size: 12px;
        color: var(--pro-text-secondary);
        margin-top: 3px;
    }
    .md-close {
        width: 32px;
        height: 32px;
        border: 0;
        background: transparent;
        font-size: 22px;
        line-height: 1;
        color: var(--pro-text-secondary);
        cursor: pointer;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
    }
    .md-close:hover {
        background: var(--pro-surface-soft);
        color: var(--pro-text);
    }
    .md-body {
        flex: 1;
        overflow: auto;
        padding: 20px 24px;
    }
    .md-foot {
        padding: 14px 24px;
        border-top: 1px solid var(--pro-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        background: var(--pro-surface-soft);
    }
    .md-foot .md-foot-msg {
        font-size: 12px;
        color: var(--pro-text-secondary);
    }
    .md-foot .md-foot-actions {
        display: flex;
        gap: 8px;
    }

    .stepper {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--pro-border);
    }
    .step {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12.5px;
        color: var(--pro-text-secondary);
    }
    .step .num {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: var(--pro-surface-soft);
        border: 1px solid var(--pro-border);
        color: var(--pro-text-secondary);
        font-size: 11.5px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .step.done .num {
        background: var(--pro-primary-soft);
        border-color: var(--pro-primary-soft);
        color: var(--pro-primary-hover);
    }
    .step.done .num::before {
        content: '✓';
    }
    .step.done .num span {
        display: none;
    }
    .step.active .num {
        background: var(--pro-primary);
        border-color: var(--pro-primary);
        color: white;
    }
    .step.active {
        color: var(--pro-text);
        font-weight: 600;
    }
    .step.done {
        color: var(--pro-text-secondary);
    }
    .step-bar {
        flex: 1;
        height: 1px;
        background: var(--pro-border);
    }
    .step-bar.done {
        background: var(--pro-primary);
    }

    .vendor-pick {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .vendor-pick .vp {
        border: 1.5px solid var(--pro-border);
        background: var(--pro-surface);
        border-radius: 10px;
        padding: 14px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 12px;
        text-align: left;
        font-family: inherit;
        transition: all 0.12s;
    }
    .vendor-pick .vp:hover {
        border-color: var(--pro-primary);
        background: var(--pro-primary-soft);
    }
    .vendor-pick .vp.active {
        border-color: var(--pro-primary);
        background: var(--pro-primary-soft);
        box-shadow: 0 0 0 3px var(--pro-primary-soft);
    }
    .vendor-pick .vp-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        flex-shrink: 0;
    }
    .vendor-pick .vp-icon.doubao { color: #1d4ed8; background: #dbeafe; }
    .vendor-pick .vp-icon.qwen { color: #c05621; background: #ffedd5; }
    .vendor-pick .vp-icon.deepseek { color: #1d4ed8; background: #dbeafe; }
    .vendor-pick .vp-icon.claude { color: #9a3412; background: #ffedd5; }
    .vendor-pick .vp-icon.kimi { color: #7e22ce; background: #f3e8ff; }
    .vendor-pick .vp-icon.glm { color: #0369a1; background: #e0f2fe; }
    .vendor-pick .vp-icon.openai { color: #166534; background: #dcfce7; }
    .vendor-pick .vp-icon.custom { color: #6b7280; background: #f3f4f6; }
    .vendor-pick .vp-name {
        font-size: 13px;
        font-weight: 700;
    }
    .vendor-pick .vp-sub {
        font-size: 11px;
        color: var(--pro-text-secondary);
        margin-top: 2px;
    }

    .model-pick {
        border: 1px solid var(--pro-border);
        border-radius: 8px;
        max-height: 320px;
        overflow: auto;
        margin-bottom: 12px;
    }
    .model-pick-row {
        padding: 10px 14px;
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 10px;
        align-items: center;
        cursor: pointer;
        border-bottom: 1px solid var(--pro-border);
        background: var(--pro-surface);
        transition: background 0.12s;
    }
    .model-pick-row:last-child { border-bottom: none; }
    .model-pick-row:hover { background: var(--pro-surface-soft); }
    .model-pick-row.selected { background: var(--pro-primary-soft); }
    .model-pick-row .check {
        width: 18px;
        height: 18px;
        border: 1.5px solid var(--pro-border-strong);
        border-radius: 4px;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .model-pick-row.selected .check {
        background: var(--pro-primary);
        border-color: var(--pro-primary);
        color: white;
    }
    .model-pick-row .pname {
        font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        font-size: 12px;
        color: var(--pro-text);
    }
    .model-pick-row .plabel {
        font-size: 11px;
        color: var(--pro-text-secondary);
        margin-top: 1px;
    }
    .model-pick-row .pcaps {
        display: inline-flex;
        gap: 4px;
    }
    .pcaps .pcap {
        font-size: 10.5px;
        padding: 1px 6px;
        border-radius: 4px;
        font-weight: 600;
    }
    .pcaps .pcap.text { background: var(--pro-primary-soft); color: var(--pro-primary-hover); }
    .pcaps .pcap.vision { background: #f3e8ff; color: #6b21a8; }

    .md-add-custom {
        padding: 14px 16px;
        background: var(--pro-surface-soft);
        font-size: 13px;
        display: flex;
        gap: 10px;
        align-items: center;
        border-top: 1px dashed var(--pro-border-strong);
    }
    .md-add-custom input {
        flex: 1;
        height: 36px;
        border: 1px solid var(--pro-border-strong);
        border-radius: 7px;
        padding: 0 12px;
        font-family: ui-monospace, monospace;
        font-size: 13px;
        background: var(--pro-surface);
    }
    .md-add-custom input:focus {
        outline: none;
        border-color: var(--pro-primary);
        box-shadow: 0 0 0 3px var(--pro-primary-soft);
    }
    .md-add-custom select {
        flex: 0 0 110px;
        width: 110px;
        height: 36px !important;
        border: 1px solid var(--pro-border-strong);
        border-radius: 7px;
        padding: 0 10px;
        font-size: 13px;
        background: var(--pro-surface);
    }
    .md-add-custom button {
        flex: 0 0 auto;
        height: 36px;
    }

    .md-section-title {
        font-size: 11.5px;
        font-weight: 700;
        color: var(--pro-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin: 18px 0 10px;
    }
    .md-section-title:first-child { margin-top: 0; }

    .md-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }
    .md-field { margin-bottom: 14px; }
    .md-field label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--pro-text-secondary);
        margin-bottom: 6px;
    }
    .md-field input:not([type="checkbox"]):not([type="radio"]),
    .md-field select {
        width: 100%;
        height: 36px;
        border: 1px solid var(--pro-border-strong);
        border-radius: 7px;
        padding: 0 10px;
        font-size: 13px;
        background: var(--pro-surface);
    }
    .md-field input:not([type="checkbox"]):not([type="radio"]):focus,
    .md-field select:focus {
        outline: none;
        border-color: var(--pro-primary);
        box-shadow: 0 0 0 3px var(--pro-primary-soft);
    }
    .md-field input[type="checkbox"] {
        width: 16px;
        height: 16px;
        margin: 0;
        flex-shrink: 0;
    }
    .md-field .md-hint {
        font-size: 11px;
        color: var(--pro-text-secondary);
        margin-top: 4px;
    }
    .md-mounted-list {
        background: var(--pro-surface-soft);
        border: 1px solid var(--pro-border);
        border-radius: 7px;
        padding: 10px 12px;
        font-size: 12.5px;
    }
    .md-mounted-list .mml-row {
        display: flex;
        gap: 8px;
        align-items: center;
        padding: 4px 0;
        font-family: ui-monospace, monospace;
    }
    /* === R4 end === */

    .model-defaults-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }
    .settings-section-title {
        margin: 18px 0 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--pro-border);
        color: var(--pro-text-secondary);
        font-size: 12px;
        font-weight: 700;
    }
    .mc-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--pro-border);
        border-radius: 10px;
    }
    .mc-table-wrap table {
        min-width: 760px;
        width: 100%;
        border-collapse: collapse;
    }
    .mc-table-wrap th,
    .mc-table-wrap td {
        padding: 10px;
        border-bottom: 1px solid var(--pro-border);
        text-align: left;
        vertical-align: middle;
    }
    .mc-table-wrap th {
        color: var(--pro-text-secondary);
        background: var(--pro-surface-soft);
        font-size: 12px;
        font-weight: 700;
    }
    .mc-table-wrap tr:last-child td {
        border-bottom: 0;
    }
    .mc-inline-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 12px;
    }
    .mc-token-hero {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 24px;
        align-items: center;
        border: 1px solid var(--pro-border);
        border-radius: 12px;
        background: var(--pro-surface);
        padding: 24px 26px;
        margin-bottom: 16px;
    }
    .mc-token-label {
        color: var(--pro-text-secondary);
        font-size: 12px;
        margin-bottom: 8px;
    }
    .mc-token-value {
        display: flex;
        align-items: baseline;
        gap: 10px;
        font-size: 36px;
        line-height: 1.1;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }
    .mc-token-value .unit {
        color: var(--pro-text-secondary);
        font-size: 14px;
        font-weight: 500;
    }
    .mc-token-meter {
        margin-top: 14px;
        height: 6px;
        border-radius: 999px;
        background: var(--pro-surface-soft);
        overflow: hidden;
    }
    .mc-token-meter-fill {
        width: 0;
        height: 100%;
        border-radius: inherit;
        background: var(--pro-primary);
        transition: width 0.25s ease, background 0.25s ease;
    }
    .mc-token-meter-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin-top: 7px;
        color: var(--pro-text-secondary);
        font-size: 12px;
        font-variant-numeric: tabular-nums;
    }
    .mc-token-meter-row strong {
        color: var(--pro-text-secondary);
    }
    .mc-token-default-row {
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px dashed var(--pro-border);
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 8px;
        font-size: 12.5px;
        color: var(--pro-text-secondary);
    }
    .mc-token-default-row .mc-token-default-label {
        font-weight: 700;
        color: var(--pro-text);
    }
    .mc-token-default-row strong {
        font-family: ui-monospace, monospace;
        font-size: 14px;
        color: var(--pro-text);
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }
    .mc-token-default-row .mc-token-default-unit {
        color: var(--pro-text-secondary);
        font-size: 12px;
    }
    .mc-token-default-row .mc-token-default-hint {
        flex-basis: 100%;
        color: var(--pro-text-secondary);
        font-size: 11.5px;
        line-height: 1.5;
        margin-top: 2px;
    }
    .mc-token-desc {
        margin-top: 8px;
        color: var(--pro-text-secondary);
        font-size: 12.5px;
        line-height: 1.6;
    }
    .mc-token-aside {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        min-width: 116px;
    }
    .mc-token-donut-ring {
        --quota-pct: 0;
        width: 94px;
        height: 94px;
        border-radius: 50%;
        background: conic-gradient(var(--pro-primary) calc(var(--quota-pct) * 1%), var(--pro-surface-soft) 0);
        display: grid;
        place-items: center;
        position: relative;
    }
    .mc-token-donut-ring::after {
        content: "";
        position: absolute;
        inset: 9px;
        border-radius: 50%;
        background: var(--pro-surface);
    }
    .mc-token-donut-ring > div {
        position: relative;
        z-index: 1;
        text-align: center;
    }
    .mc-token-donut-ring strong {
        display: block;
        font-size: 18px;
        font-variant-numeric: tabular-nums;
    }
    .mc-token-donut-ring span {
        color: var(--pro-text-secondary);
        font-size: 11px;
    }
    .mc-quota-editor {
        margin-bottom: 16px;
        padding: 14px 16px;
        border: 1px solid var(--pro-border);
        border-radius: 12px;
        background: var(--pro-surface-soft);
    }
    .mc-quota-editor[hidden] {
        display: none !important;
    }
    .mc-quota-editor-grid,
    .mc-alloc-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(180px, 260px)) auto auto minmax(120px, 1fr);
        gap: 12px;
        align-items: end;
    }
    .mc-quota-status {
        min-height: 34px;
        display: inline-flex;
        align-items: center;
        color: var(--pro-text-secondary);
        font-size: 12px;
    }
    .mc-cap-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 16px;
        padding: 14px 18px;
        border: 1px solid var(--pro-border);
        border-radius: 12px;
        background: var(--pro-surface);
    }
    .mc-cap-row h3,
    .mc-alloc-head h3 {
        margin: 0;
        color: var(--pro-text);
        font-size: 14px;
    }
    .mc-cap-row p,
    .mc-alloc-head p {
        margin: 3px 0 0;
        color: var(--pro-text-secondary);
        font-size: 12px;
        line-height: 1.55;
    }
    .mc-cap-display {
        display: inline-flex;
        align-items: baseline;
        justify-content: flex-end;
        gap: 6px;
        min-width: 164px;
        padding: 8px 12px;
        border: 1px solid var(--pro-border);
        border-radius: 8px;
        background: var(--pro-surface-soft);
        color: var(--pro-text-secondary);
        font-size: 12px;
        white-space: nowrap;
    }
    .mc-cap-display strong {
        color: var(--pro-text);
        font-size: 14px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-variant-numeric: tabular-nums;
    }
    .mc-alloc-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 16px;
        margin-bottom: 12px;
    }
    .mc-alloc-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 12px;
    }
    .alloc-card {
        min-width: 0;
        border: 1px solid var(--pro-border);
        border-radius: 12px;
        background: var(--pro-surface);
        padding: 14px 16px;
    }
    .alloc-card .ac-head {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .alloc-card .ac-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        color: var(--pro-primary-hover);
        background: var(--pro-primary-soft);
        font-weight: 800;
        font-size: 13px;
    }
    .alloc-card .ac-icon.dept {
        color: #075985;
        background: #e0f2fe;
    }
    .alloc-card .ac-title {
        min-width: 0;
        flex: 1;
    }
    .alloc-card .ac-name {
        color: var(--pro-text);
        font-weight: 800;
        font-size: 13.5px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .alloc-card .ac-meta {
        margin-top: 1px;
        color: var(--pro-text-secondary);
        font-size: 12px;
    }
    .alloc-card .ac-actions {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .alloc-card .ac-pbar {
        margin-top: 18px;
        height: 6px;
        border-radius: 999px;
        background: var(--pro-surface-soft);
        overflow: hidden;
    }
    .alloc-card .ac-pbar-fill {
        width: 0;
        height: 100%;
        border-radius: inherit;
        background: var(--pro-primary);
    }
    .alloc-card .ac-pbar-fill.warn {
        background: var(--pro-warning);
    }
    .alloc-card .ac-pbar-fill.danger {
        background: var(--pro-error);
    }
    .alloc-card .ac-pinfo {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 12px;
        margin-top: 8px;
        color: var(--pro-text-secondary);
        font-size: 12px;
        font-variant-numeric: tabular-nums;
    }
    .alloc-card .ac-pinfo strong {
        color: var(--pro-text);
    }
    .alloc-card.add-card {
        min-height: 136px;
        border-style: dashed;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: var(--pro-text-secondary);
        cursor: pointer;
        background: transparent;
    }
    .alloc-card.add-card:hover {
        color: var(--pro-primary-hover);
        border-color: var(--pro-primary);
        background: var(--pro-primary-soft);
    }
    .mc-empty-state {
        border: 1px dashed var(--pro-border-strong);
        border-radius: 12px;
        padding: 24px;
        color: var(--pro-text-secondary);
        background: var(--pro-surface-soft);
        text-align: center;
    }
    @media (max-width: 980px) {
        .model-page-head,
        .model-toolbar,
        .mc-token-hero,
        .mc-cap-row,
        .mc-alloc-head {
            grid-template-columns: 1fr;
            flex-direction: column;
            align-items: stretch;
        }
        .mc-token-aside {
            align-items: flex-start;
        }
        .model-defaults-grid,
        .mc-quota-editor-grid,
        .mc-alloc-form-grid {
            grid-template-columns: 1fr;
        }
    }


    /* ── Enterprise tab: two-column layout ── */
    .enterprise-grid {
        display: grid;
        grid-template-columns: 1fr 320px;
        gap: 20px;
        align-items: start;
    }
    .preview-card {
        background: #f8fcfa;
        border: 1px solid #e8ede8;
        border-radius: 10px;
        padding: 20px;
        position: sticky;
        top: 20px;
    }
    .preview-card-label {
        font-size: 12px;
        font-weight: 600;
        color: #8a9ba8;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 16px;
    }
    .preview-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        background: #fff;
        border-radius: 8px;
        border: 1px solid #e8e8e8;
    }
    .preview-brand img {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        object-fit: cover;
    }
    .preview-brand-fallback {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: var(--pro-primary, #1a7f5a);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 16px;
        flex-shrink: 0;
    }
    .preview-brand-title { font-size: 14px; font-weight: 600; color: var(--pro-text); }
    .preview-brand-sub { font-size: 11px; color: #8a9ba8; margin-top: 1px; }

    /* R9: editable card — display/edit 双视图 */
    .pro-card[data-editable-card] {
        position: relative;
    }
    .pro-card[data-editable-card] .card-edit-actions {
        position: absolute;
        top: 18px;
        right: 22px;
        display: flex;
        gap: 8px;
        z-index: 1;
    }
    .pro-card[data-editable-card] .field-display {
        font-size: 14px;
        color: var(--pro-text);
        padding: 9px 0;
        line-height: 1.4;
        word-break: break-all;
    }
    .pro-card[data-editable-card] .field-display.empty {
        color: var(--pro-text-secondary);
        font-style: italic;
    }
    /* default: display 模式 */
    .pro-card[data-editable-card] .field-edit { display: none; }
    .pro-card[data-editable-card] .edit-only-show { display: none; }
    .pro-card[data-editable-card] .display-only-show { display: inline-flex; }
    /* editing 模式 */
    .pro-card[data-editable-card].editing .field-display { display: none; }
    .pro-card[data-editable-card].editing .field-edit { display: block; }
    .pro-card[data-editable-card].editing .field-edit-row { display: flex; gap: 10px; align-items: stretch; }
    .pro-card[data-editable-card].editing .edit-only-show { display: inline-flex; }
    .pro-card[data-editable-card].editing .display-only-show { display: none; }
    /* 简单的小尺寸按钮变体 */
    .pro-btn-sm { padding: 6px 12px; font-size: 12.5px; }

    /* R9-fix: editable 卡片右上角"编辑"按钮更醒目 */
    .pro-card[data-editable-card] .card-edit-actions [data-edit-toggle] {
        color: var(--pro-primary-hover);
        background: var(--pro-primary-soft);
        border: 1px solid var(--pro-primary-soft);
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: background 0.15s, border-color 0.15s, transform 0.15s;
    }
    .pro-card[data-editable-card] .card-edit-actions [data-edit-toggle]:hover {
        background: #b9f0d6;
        border-color: var(--pro-primary);
        color: var(--pro-primary-hover);
    }
    .pro-card[data-editable-card].editing .card-edit-actions [data-edit-toggle] {
        display: none !important;
    }
    .pro-card[data-editable-card] .card-edit-actions [data-edit-toggle]::before {
        content: "";
        width: 13px;
        height: 13px;
        background-color: currentColor;
        -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'/%3E%3Cpath d='m18.5 2.5 3 3L12 15l-4 1 1-4z'/%3E%3C/svg%3E") center/contain no-repeat;
        mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'/%3E%3Cpath d='m18.5 2.5 3 3L12 15l-4 1 1-4z'/%3E%3C/svg%3E") center/contain no-repeat;
        flex-shrink: 0;
    }


    /* ── Styled file upload button ── */
    .file-upload-wrap {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .file-upload-wrap input[type="file"] {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0,0,0,0);
        white-space: nowrap;
        border: 0;
    }
    .file-upload-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 16px;
        border: 1px solid #d0d7de;
        border-radius: 6px;
        background: #fff;
        color: var(--pro-text);
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .file-upload-btn:hover {
        background: #f6f8fa;
        border-color: #b0bec5;
    }
    .file-upload-btn svg { flex-shrink: 0; }
    .file-upload-name {
        font-size: 13px;
        color: #8a9ba8;
    }
</style>
@endpush

@section('content')
    @php
        $activeTab = in_array(($activeTab ?? 'channel'), ['channel', 'model', 'enterprise'], true) ? $activeTab : 'channel';
        $oldCapabilities = old('model_capability', array_map(fn($m) => $m['capability'] ?? 'other', $models));
        $oldModelIds = old('model_id', array_map(fn($m) => $m['model_id'] ?? '', $models));
        $oldModelLabels = old('model_label', array_map(fn($m) => $m['label'] ?? '', $models));
        $rowCount = max(count($oldCapabilities), count($oldModelIds), count($oldModelLabels), 1);
        $testResult = session('test_result');
        $isCurrentTabResult = is_array($testResult) && (($testResult['section'] ?? '') === $activeTab);
    @endphp

    @if($isCurrentTabResult)
        <div class="pro-alert pro-alert-success">
            <div><strong>{{ $testResult['title'] ?? '测试结果' }}</strong></div>
            <div style="margin-top:4px;">{{ $testResult['content'] ?? '-' }}</div>
        </div>
    @endif

    @if($activeTab === 'channel')
        {{-- ══════════ 渠道配置 ══════════ --}}
        <div class="channel-grid">
            {{-- ── 飞书 ── --}}
            <div class="pro-card">
                <div class="channel-card-inner">
                    <div class="channel-icon channel-icon-feishu">F</div>
                    <div class="channel-card-info">
                        <h4>飞书</h4>
                        <p>Webhook 事件订阅 &amp; 消息推送</p>
                    </div>
                    <span class="channel-badge channel-badge-active">已接入</span>
                </div>

                <form method="post" action="/admin/settings" class="pro-grid">
                    @csrf
                    <input type="hidden" name="section" value="channel">

                    <div class="pro-row pro-row-2">
                        <div class="pro-field">
                            <label>App ID</label>
                            <input type="text" name="feishu_app_id" value="{{ old('feishu_app_id', $feishu['app_id']) }}" placeholder="cli_xxx">
                        </div>
                        <div class="pro-field">
                            <label>App Secret</label>
                            <input type="password" name="feishu_app_secret" value="{{ old('feishu_app_secret', $feishu['app_secret']) }}" placeholder="请输入飞书应用密钥">
                        </div>
                        <div class="pro-field" style="grid-column:1 / -1;">
                            <label>Encrypt Key（事件加密）</label>
                            <input type="password" name="feishu_encrypt_key" value="{{ old('feishu_encrypt_key', $feishu['encrypt_key']) }}" placeholder="请输入 Encrypt Key">
                            <div class="pro-help">当飞书回调包含 <code>encrypt</code> 字段时必须配置。</div>
                        </div>
                        <div class="pro-field" style="grid-column:1 / -1;">
                            <label>Verification Token（回调验签）</label>
                            <input type="password" name="feishu_verification_token" value="{{ old('feishu_verification_token', $feishu['verification_token']) }}" placeholder="请输入 Verification Token">
                            <div class="pro-help">用于校验 Webhook 请求来源，建议生产环境必填。</div>
                        </div>
                    </div>

                    <div class="pro-inline-actions">
                        @adminCan('settings.channel.update')
                            <button type="submit" class="pro-btn pro-btn-primary">保存渠道配置</button>
                        @endadminCan
                    </div>
                </form>

                <form method="post" action="/admin/settings/test/channel" style="margin-top:10px;">
                    @csrf
                    @adminCan('settings.channel.test')
                    <button type="submit" class="pro-btn">测试飞书连接</button>
                    @endadminCan
                </form>
            </div>

            {{-- ── 钉钉（占位） ── --}}
            <div class="pro-card">
                <div class="channel-card-inner">
                    <div class="channel-icon channel-icon-dingtalk">D</div>
                    <div class="channel-card-info">
                        <h4>钉钉</h4>
                        <p>DingTalk 机器人 &amp; 事件回调</p>
                    </div>
                    <span class="channel-badge channel-badge-soon">即将支持</span>
                </div>
                <div class="coming-soon-body">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <p>钉钉渠道接入正在开发中，敬请期待。</p>
                </div>
            </div>
        </div>

    @elseif($activeTab === 'model')
        {{-- ══════════ 模型配置 ══════════ --}}
        @php
            // R7 (多 vendor): 数据全部来自 controller 的 $activeProviders
            $configuredConnectionCount = count($activeProviders ?? []);
            $modelCount = collect($activeProviders ?? [])->sum(fn ($vp) => count($vp['models'] ?? []));
            $defaultModelIds = array_values(array_filter([
                trim((string) ($defaults['text'] ?? '')),
                trim((string) ($defaults['vision'] ?? '')),
            ]));
        @endphp

        <div class="model-config-page">
            <div class="model-page-head">
                <div>
                    <h1>模型配置</h1>
                    <p>连接 LLM 服务、给模型起别名、把每月 token 配额按部门或个人分下去。</p>
                </div>
            </div>

            <div class="model-tabs-wrap">
                <div class="model-tabs" role="tablist" aria-label="模型配置">
                    <button type="button" class="model-tab active" data-model-tab="access" role="tab" aria-selected="true">
                        模型接入 <span class="count">{{ $configuredConnectionCount }} 个连接 / {{ $modelCount }} 个模型</span>
                    </button>
                    <button type="button" class="model-tab" data-model-tab="quota" role="tab" aria-selected="false">
                        Token 分配 <span class="count"><span id="quota-tab-count">-</span> 项独立配额</span>
                    </button>
                </div>

                <div class="model-tabs-body">
                    <section class="model-panel" data-model-panel="access">
                        {{-- R4 (丁方案 + A1 + B3): 当前生效区。主模型必填、视觉覆盖可选；onChange 立即提交 --}}
                        @php
                            $currentMain = $defaults['text'] ?? '';
                            $currentVision = $defaults['vision'] ?? '';
                            // R8: $allOptions 含 vendor_key + 该 vendor 的 last_test_status，前端用来 disable 不可用 model
                            $__vendorStatusByKey = [];
                            foreach (($activeProviders ?? []) as $__vp) {
                                $__vendorStatusByKey[$__vp['vendor_key']] = $__vp['last_test_status'] ?? '';
                            }
                            $allOptions = collect($allMountedOptions ?? [])
                                ->map(fn ($it) => [
                                    'model_id' => $it['model_id'],
                                    'vendor_key' => $it['vendor_key'],
                                    'vendor_status' => $__vendorStatusByKey[$it['vendor_key']] ?? '',
                                ])
                                ->unique('model_id')
                                ->values()
                                ->all();
                        @endphp
                        <section class="active-models-bar" aria-label="当前生效模型">
                            <div class="active-models-head">
                                <span class="am-dot" aria-hidden="true"></span>
                                <h3>当前生效模型</h3>
                            </div>
                            <div class="active-models-grid">
                                <div class="am-slot">
                                    <label>主模型 <span class="req">*</span></label>
                                    <form method="post" action="/admin/settings" id="active-main-form" style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="section" value="active_models">
                                        <input type="hidden" name="active_vision_model_id" value="{{ $currentVision }}">
                                        <select name="active_main_model_id" id="active-main-select" data-active-slot="main">
                                            @forelse($allOptions as $opt)
                                                @php
                                                    $isFailed = $opt['vendor_status'] === 'failed';
                                                    $isSelected = $opt['model_id'] === $currentMain;
                                                @endphp
                                                <option value="{{ $opt['model_id'] }}"
                                                    {{ $isSelected ? 'selected' : '' }}
                                                    {{ $isFailed && !$isSelected ? 'disabled' : '' }}>
                                                    {{ $opt['model_id'] }}@if($isFailed)（供应商不可用）@endif
                                                </option>
                                            @empty
                                                <option value="">（暂无已挂载模型）</option>
                                            @endforelse
                                        </select>
                                    </form>
                                    <div class="am-help">所有 LLM 调用默认走它（聊天、记忆、技能、工具调用）</div>
                                    <div class="am-status" id="am-main-status">已保存</div>
                                </div>
                                <div class="am-slot">
                                    <label>视觉模型（可选）</label>
                                    <form method="post" action="/admin/settings" id="active-vision-form" style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="section" value="active_models">
                                        <input type="hidden" name="active_main_model_id" value="{{ $currentMain }}">
                                        <select name="active_vision_model_id" id="active-vision-select" data-active-slot="vision">
                                            <option value="" {{ $currentVision === '' ? 'selected' : '' }}>不覆盖（用主模型读图）</option>
                                            @foreach($allOptions as $opt)
                                                @php
                                                    $isFailed = $opt['vendor_status'] === 'failed';
                                                    $isSelected = $opt['model_id'] === $currentVision;
                                                @endphp
                                                <option value="{{ $opt['model_id'] }}"
                                                    {{ $isSelected ? 'selected' : '' }}
                                                    {{ $isFailed && !$isSelected ? 'disabled' : '' }}>
                                                    {{ $opt['model_id'] }}@if($isFailed)（供应商不可用）@endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </form>
                                    <div class="am-help">仅当主模型不支持视觉，或希望用专门视觉模型读图时配置</div>
                                    <div class="am-status" id="am-vision-status">已保存</div>
                                </div>
                            </div>
                        </section>

                        <div class="model-toolbar">
                            <div class="left">每个供应商一张卡片。卡片里展示 <strong>已挂载的模型</strong>、连接状态和默认设置。</div>
                            @adminCan('settings.model.update')
                                <button type="button" class="mc-btn mc-btn-primary" data-open-add-vendor>+ 新增模型</button>
                            @endadminCan
                        </div>

                        <div class="mc-section-title">已配置 · {{ $configuredConnectionCount }}</div>
                        <div class="vendor-grid">
                            @forelse($activeProviders as $vp)
                                @php
                                    // R8: 状态徽章按 last_test_status 显示（事实），不再用"假设"
                                    $vpStatus = $vp['last_test_status'] ?? '';
                                    if ($vpStatus === 'ok') {
                                        $vpLabel = '已连通'; $vpClass = 'ok';
                                    } elseif ($vpStatus === 'failed') {
                                        $vpLabel = '连接失败'; $vpClass = 'err';
                                    } elseif (! $vp['api_key_configured'] || count($vp['models']) === 0) {
                                        $vpLabel = '待完善'; $vpClass = 'warn';
                                    } else {
                                        $vpLabel = '未测试'; $vpClass = 'unknown';
                                    }
                                @endphp
                                <article class="vendor-card" data-vendor-card="{{ $vp['vendor_key'] }}">
                                    <div class="vc-head">
                                        <div class="vc-icon {{ $vp['icon_class'] }}">{{ $vp['icon'] }}</div>
                                        <div class="vc-title">
                                            <h3 class="vc-name" title="{{ $vp['name'] }}">{{ $vp['name'] }}</h3>
                                            <div class="vc-sub">{{ $vp['sub'] }}</div>
                                        </div>
                                        <span class="vc-status {{ $vpClass }}" data-vc-status
                                              title="{{ $vpStatus === 'failed' ? $vp['last_test_message'] : ($vp['last_test_at_human'] ? '上次测试 '.$vp['last_test_at_human'] : '从未测试过') }}">
                                            {{ $vpLabel }}@if($vp['last_test_at_human'] && $vpStatus !== '') · {{ $vp['last_test_at_human'] }}@endif
                                        </span>
                                    </div>

                                    <div class="vc-meta">
                                        <div>{{ $vp['host'] }}</div>
                                        <div>{{ $vp['api_key_configured'] ? 'Key 已保存' : 'Key 未配置' }}</div>
                                    </div>

                                    <div class="vc-models">
                                        @forelse($vp['models'] as $model)
                                            <div class="vc-model">
                                                <span class="cap">{{ $model['capability'] }}</span>
                                                <span class="name" title="{{ $model['model_id'] }}">{{ $model['model_id'] }}</span>
                                                @if(in_array($model['model_id'], $defaultModelIds, true))
                                                    <span class="star">默认</span>
                                                @endif
                                            </div>
                                        @empty
                                            <div class="vc-empty">尚未挂载模型 ID。</div>
                                        @endforelse
                                    </div>

                                    <div class="vc-foot">
                                        <span>已挂载 <strong>{{ count($vp['models']) }}</strong> 个模型</span>
                                        <div class="vc-actions">
                                            @adminCan('settings.model.test')
                                                <button type="button" class="mc-btn mc-btn-sm" data-test-vendor="{{ $vp['vendor_key'] }}">测试连接</button>
                                            @endadminCan
                                            @adminCan('settings.model.update')
                                                <button type="button" class="mc-btn mc-btn-sm" data-edit-vendor="{{ $vp['vendor_key'] }}">编辑</button>
                                            @endadminCan
                                        </div>
                                    </div>
                                </article>
                            @empty
                                <div class="mc-empty-state">尚未配置模型网关。点击「新增模型」或下方供应商卡片开始接入。</div>
                            @endforelse
                        </div>

                        @php
                            $__configuredVendorKeys = collect($activeProviders ?? [])->pluck('vendor_key')->all();
                        @endphp
                        @if(count(array_diff(['doubao','qwen','deepseek','claude','kimi','glm','openai'], $__configuredVendorKeys)) > 0)
                        <div class="mc-section-title">未配置 · 点击即开始接入</div>
                        <div class="vendor-grid">
                            @if(!in_array('doubao', $__configuredVendorKeys, true))
                            <article class="vendor-card unconfigured">
                                <div class="vc-head">
                                    <div class="vc-icon doubao">豆</div>
                                    <div class="vc-title">
                                        <h3 class="vc-name">字节跳动 / 豆包</h3>
                                        <div class="vc-sub">Doubao · Ark · Volcengine</div>
                                    </div>
                                    <span class="vc-status">未配置</span>
                                </div>
                                <div class="vc-foot">
                                    <span>OpenAI 兼容网关 · 首选 doubao-seed</span>
                                    <button type="button" class="mc-btn mc-btn-sm" data-model-preset="doubao">+ 接入</button>
                                </div>
                            </article>
                            @endif
                            @if(!in_array('qwen', $__configuredVendorKeys, true))
                            <article class="vendor-card unconfigured">
                                <div class="vc-head">
                                    <div class="vc-icon qwen">通</div>
                                    <div class="vc-title">
                                        <h3 class="vc-name">阿里云 / 通义千问</h3>
                                        <div class="vc-sub">DashScope · Qwen</div>
                                    </div>
                                    <span class="vc-status">未配置</span>
                                </div>
                                <div class="vc-foot">
                                    <span>包含多模态 · 首选 qwen-max</span>
                                    <button type="button" class="mc-btn mc-btn-sm" data-model-preset="qwen">+ 接入</button>
                                </div>
                            </article>
                            @endif
                            @if(!in_array('deepseek', $__configuredVendorKeys, true))
                            <article class="vendor-card unconfigured">
                                <div class="vc-head">
                                    <div class="vc-icon deepseek">D</div>
                                    <div class="vc-title">
                                        <h3 class="vc-name">深度求索 / DeepSeek</h3>
                                        <div class="vc-sub">deepseek-chat · deepseek-reasoner</div>
                                    </div>
                                    <span class="vc-status">未配置</span>
                                </div>
                                <div class="vc-foot">
                                    <span>包含 2 个模型 · 首选 deepseek-chat</span>
                                    <button type="button" class="mc-btn mc-btn-sm" data-model-preset="deepseek">+ 接入</button>
                                </div>
                            </article>
                            @endif
                            @if(!in_array('claude', $__configuredVendorKeys, true))
                            <article class="vendor-card unconfigured">
                                <div class="vc-head">
                                    <div class="vc-icon claude">A</div>
                                    <div class="vc-title">
                                        <h3 class="vc-name">Anthropic / Claude</h3>
                                        <div class="vc-sub">claude-haiku · sonnet · opus</div>
                                    </div>
                                    <span class="vc-status">未配置</span>
                                </div>
                                <div class="vc-foot">
                                    <span>通过兼容网关接入 · 首选 claude-haiku</span>
                                    <button type="button" class="mc-btn mc-btn-sm" data-model-preset="claude">+ 接入</button>
                                </div>
                            </article>
                            @endif
                            @if(!in_array('kimi', $__configuredVendorKeys, true))
                            <article class="vendor-card unconfigured">
                                <div class="vc-head">
                                    <div class="vc-icon kimi">K</div>
                                    <div class="vc-title">
                                        <h3 class="vc-name">月之暗面 / Kimi</h3>
                                        <div class="vc-sub">Moonshot AI</div>
                                    </div>
                                    <span class="vc-status">未配置</span>
                                </div>
                                <div class="vc-foot">
                                    <span>包含 3 个模型 · 首选 moonshot-v1-128k</span>
                                    <button type="button" class="mc-btn mc-btn-sm" data-model-preset="kimi">+ 接入</button>
                                </div>
                            </article>
                            @endif
                            @if(!in_array('glm', $__configuredVendorKeys, true))
                            <article class="vendor-card unconfigured">
                                <div class="vc-head">
                                    <div class="vc-icon glm">G</div>
                                    <div class="vc-title">
                                        <h3 class="vc-name">智谱 / GLM</h3>
                                        <div class="vc-sub">BigModel / ZhipuAI</div>
                                    </div>
                                    <span class="vc-status">未配置</span>
                                </div>
                                <div class="vc-foot">
                                    <span>包含 3 个模型 · 首选 glm-4.6</span>
                                    <button type="button" class="mc-btn mc-btn-sm" data-model-preset="glm">+ 接入</button>
                                </div>
                            </article>
                            @endif
                            @if(!in_array('openai', $__configuredVendorKeys, true))
                            <article class="vendor-card unconfigured">
                                <div class="vc-head">
                                    <div class="vc-icon openai">O</div>
                                    <div class="vc-title">
                                        <h3 class="vc-name">OpenAI</h3>
                                        <div class="vc-sub">gpt-4o · gpt-4o-mini</div>
                                    </div>
                                    <span class="vc-status">未配置</span>
                                </div>
                                <div class="vc-foot">
                                    <span>包含 2 个多模态模型</span>
                                    <button type="button" class="mc-btn mc-btn-sm" data-model-preset="openai">+ 接入</button>
                                </div>
                            </article>
                            @endif
                        </div>
                        @endif

                        {{-- R4: 旧 inline form 已被抽屉(.model-drawer)取代 --}}

                    </section>

                    <section class="model-panel" data-model-panel="quota" hidden>
                        <div id="quota-app">
                            <div class="mc-token-hero">
                                <div>
                                    <div class="mc-token-label">本月 TOKEN 总池</div>
                                    <div class="mc-token-value"><span id="quota-pool-value">-</span><span class="unit">tokens</span></div>
                                    <div class="mc-token-meter"><div class="mc-token-meter-fill" id="pool-bar"></div></div>
                                    <div class="mc-token-meter-row">
                                        <span>已用 <strong id="pool-used">-</strong></span>
                                        <span>剩余 <strong id="pool-remaining">-</strong></span>
                                    </div>
                                    <div class="mc-token-desc">未单独分配的用户共享此池。剩余不足 10% 时，每日 7:00 飞书机器人通知。</div>
                                    {{-- R5-fix-A: 把"每人默认月上限"快速展示并入 hero --}}
                                    <div class="mc-token-default-row">
                                        <span class="mc-token-default-label">每人默认月上限</span>
                                        <strong id="quota-default-display">-</strong>
                                        <span class="mc-token-default-unit">tokens / 人 / 月</span>
                                        <span class="mc-token-default-hint">没有独立配额的用户共用此上限，与「总池」独立叠加生效。0 = 不限。</span>
                                    </div>
                                </div>
                                <div class="mc-token-aside">
                                    <div class="mc-token-donut-ring" id="quota-donut">
                                        <div>
                                            <strong id="quota-pool-pct">0%</strong>
                                            <span>已用</span>
                                        </div>
                                    </div>
                                    @adminCan('settings.quota.manage')
                                        <button type="button" class="mc-btn mc-btn-sm" data-quota-edit>编辑总池</button>
                                    @endadminCan
                                </div>

                                {{-- R5-fix: editor 嵌进 hero 卡片内部，跨整行；同时管总池 + 每人默认 --}}
                                <div class="mc-quota-editor" id="quota-editor" hidden style="grid-column: 1 / -1;">
                                    <div class="mc-quota-editor-grid">
                                        <div class="pro-field">
                                            <label>每月 Token 总量</label>
                                            <input type="number" id="quota-pool-limit" min="0" step="10000" placeholder="例如 1000000">
                                        </div>
                                        <div class="pro-field">
                                            <label>每人默认月上限</label>
                                            <input type="number" id="quota-default-limit" min="0" step="10000" placeholder="0 表示不设默认">
                                        </div>
                                        <button type="button" class="mc-btn mc-btn-primary" id="save-quota-btn" @adminCan('settings.quota.manage') @else disabled title="no permission" @endadminCan>保存</button>
                                        <div class="mc-quota-status">
                                            <span id="quota-status"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mc-alloc-head">
                                <div>
                                    <h3>独立配额 · <span id="quota-alloc-count">0</span></h3>
                                    <p>为部门或重度用户单独划一块。鼠标悬停查看操作。</p>
                                </div>
                                <button type="button" class="mc-btn mc-btn-primary mc-btn-sm" id="add-alloc-btn" @adminCan('settings.quota.manage') @else disabled title="no permission" @endadminCan>+ 新增分配</button>
                            </div>

                            {{-- R5: 旧 inline alloc 表单已被抽屉(.alloc-drawer) 取代 --}}
                            <div class="mc-alloc-grid" id="alloc-rows">
                                <div class="mc-empty-state">加载中…</div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    @else
        @php
            $entName = trim((string) ($enterprise['name'] ?? ''));
            $entLogoUrl = trim((string) ($enterprise['logo_url'] ?? ''));
            $previewName = $entName !== '' ? $entName : '米蛙后台';
            $previewLogo = $entLogoUrl;
            $hasError = isset($errors) && $errors->any();
        @endphp

        {{-- R9: 企业配置 — 默认 readonly，点编辑才进入编辑状态 --}}
        <form method="post" action="/admin/settings" class="pro-grid" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="section" value="enterprise">

            <div class="pro-card" data-editable-card="enterprise" {{ $hasError ? 'data-start-editing="1"' : '' }}>
                <h3 class="pro-card-title">企业配置</h3>
                <div class="pro-card-subtitle">维护企业名称与 Logo，保存后同步到侧栏品牌区</div>

                @adminCan('settings.enterprise.update')
                <div class="card-edit-actions">
                    <button type="button" class="pro-btn pro-btn-sm display-only-show" data-edit-toggle>编辑</button>
                    <button type="button" class="pro-btn pro-btn-sm edit-only-show" data-edit-cancel>取消</button>
                    <button type="submit" class="pro-btn pro-btn-sm pro-btn-primary edit-only-show">保存</button>
                </div>
                @endadminCan

                <div class="enterprise-grid">
                    {{-- 左：字段（display + edit 双视图） --}}
                    <div>
                        <div class="pro-field">
                            <label>企业名称</label>
                            <div class="field-display {{ $entName === '' ? 'empty' : '' }}">{{ $entName !== '' ? $entName : '（未配置）' }}</div>
                            <input class="field-edit" type="text" name="enterprise_name" maxlength="36" value="{{ old('enterprise_name', $entName) }}" placeholder="例如：米蛙科技（上海）有限公司">
                        </div>

                        <div class="pro-field" style="margin-top:16px;">
                            <label>企业 Logo 地址（URL）</label>
                            <div class="field-display {{ $entLogoUrl === '' ? 'empty' : '' }}">{{ $entLogoUrl !== '' ? $entLogoUrl : '（未配置）' }}</div>
                            <input class="field-edit" type="text" name="enterprise_logo_url" maxlength="1024" value="{{ old('enterprise_logo_url', $entLogoUrl) }}" placeholder="例如：https://example.com/logo.png">
                            <div class="pro-help edit-only-show">可填写外链，也可上传本地图像文件。</div>
                        </div>

                        {{-- C2: file upload 仅编辑模式可见 --}}
                        <div class="pro-field edit-only-show" style="margin-top:16px;">
                            <label>上传本地 Logo（推荐）</label>
                            <div class="file-upload-wrap">
                                <input type="file" name="enterprise_logo_file" id="logo-file-input" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml">
                                <label for="logo-file-input" class="file-upload-btn">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                    选择文件
                                </label>
                                <span class="file-upload-name" id="logo-file-name">未选择任何文件</span>
                            </div>
                            <div class="pro-help">支持 PNG/JPG/WEBP/SVG，最大 4MB。上传后自动覆盖当前 Logo。</div>
                        </div>
                    </div>

                    {{-- 右：侧栏品牌区预览（始终显示） --}}
                    <div class="preview-card">
                        <div class="preview-card-label">侧栏品牌区预览</div>
                        <div class="preview-brand">
                            @if($previewLogo !== '')
                                <img src="{{ $previewLogo }}" alt="logo" id="preview-logo-img">
                            @else
                                <div class="preview-brand-fallback" id="preview-logo-fallback">M</div>
                            @endif
                            <div>
                                <div class="preview-brand-title" id="preview-brand-name">{{ $previewName }}</div>
                                <div class="preview-brand-sub">Mifrog Admin Console</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        {{-- R9: 对话上下文设置 — 同样默认 readonly --}}
        <form method="post" action="/admin/settings" class="pro-grid" style="margin-top:18px;">
            @csrf
            <input type="hidden" name="section" value="memory_context">

            <div class="pro-card" data-editable-card="memory_context">
                <h3 class="pro-card-title">对话上下文设置</h3>
                <div class="pro-card-subtitle">机器人回答时只参考最近 N 小时内的对话，跨天未完结的老话题不会再被捡起来。</div>

                @adminCan('settings.enterprise.update')
                <div class="card-edit-actions">
                    <button type="button" class="pro-btn pro-btn-sm display-only-show" data-edit-toggle>编辑</button>
                    <button type="button" class="pro-btn pro-btn-sm edit-only-show" data-edit-cancel>取消</button>
                    <button type="submit" class="pro-btn pro-btn-sm pro-btn-primary edit-only-show">保存</button>
                </div>
                @endadminCan

                <div class="pro-field" style="max-width:360px;">
                    <label>对话上下文时长（小时）</label>
                    <div class="field-display">{{ $memoryHistoryWindowHours ?? 24 }} 小时</div>
                    <input class="field-edit" type="number" id="memory_history_window_hours" name="memory_history_window_hours" min="1" max="720" value="{{ old('memory_history_window_hours', $memoryHistoryWindowHours ?? 24) }}">
                    <div class="pro-help edit-only-show">默认 24。范围 1–720（最长 30 天）。</div>
                </div>
            </div>
        </form>

        {{-- 日报/周报发送时间 — 第三个独立 form，沿用 readonly→编辑 切换 --}}
        <form method="post" action="/admin/settings" class="pro-grid" style="margin-top:18px;">
            @csrf
            <input type="hidden" name="section" value="summary_schedule">

            <div class="pro-card" data-editable-card="summary_schedule">
                <h3 class="pro-card-title">日报 / 周报发送时间</h3>
                <div class="pro-card-subtitle">日报每天发送 1 次、周报每周发送 1 次。crontab 每分钟巡检；只在你设定的 H:M 触发。</div>

                @adminCan('settings.enterprise.update')
                <div class="card-edit-actions">
                    <button type="button" class="pro-btn pro-btn-sm display-only-show" data-edit-toggle>编辑</button>
                    <button type="button" class="pro-btn pro-btn-sm edit-only-show" data-edit-cancel>取消</button>
                    <button type="submit" class="pro-btn pro-btn-sm pro-btn-primary edit-only-show">保存</button>
                </div>
                @endadminCan

                <div style="display:flex;flex-wrap:wrap;gap:24px;">
                    <div class="pro-field" style="flex:1 1 220px;max-width:240px;">
                        <label>日报发送时间</label>
                        <div class="field-display">每天 {{ $summarySchedule['daily_at'] ?? '07:00' }}</div>
                        <input class="field-edit" type="time" name="summary_daily_at" value="{{ old('summary_daily_at', $summarySchedule['daily_at'] ?? '07:00') }}" required>
                    </div>

                    <div class="pro-field" style="flex:1 1 320px;max-width:340px;">
                        <label>周报发送时间</label>
                        @php
                            $__dow = (int) ($summarySchedule['weekly_dow'] ?? 1);
                            $__dowLabels = [1=>'周一', 2=>'周二', 3=>'周三', 4=>'周四', 5=>'周五', 6=>'周六', 7=>'周日'];
                        @endphp
                        <div class="field-display">{{ $__dowLabels[$__dow] ?? '周一' }} {{ $summarySchedule['weekly_at'] ?? '07:30' }}</div>
                        <div class="field-edit field-edit-row">
                            <select name="summary_weekly_dow" style="flex:0 0 120px;">
                                @foreach ($__dowLabels as $__d => $__lbl)
                                    <option value="{{ $__d }}" {{ (int) old('summary_weekly_dow', $__dow) === $__d ? 'selected' : '' }}>{{ $__lbl }}</option>
                                @endforeach
                            </select>
                            <input type="time" name="summary_weekly_at" value="{{ old('summary_weekly_at', $summarySchedule['weekly_at'] ?? '07:30') }}" required style="flex:1;">
                        </div>
                    </div>
                </div>
            </div>
        </form>

        {{-- R9: 编辑/取消 切换 + 取消时 form.reset() 还原 input 初值 --}}
        <script>
        (function () {
            document.querySelectorAll('[data-edit-toggle]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const card = btn.closest('[data-editable-card]');
                    if (card) card.classList.add('editing');
                });
            });
            document.querySelectorAll('[data-edit-cancel]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const card = btn.closest('[data-editable-card]');
                    if (!card) return;
                    card.classList.remove('editing');
                    const form = card.closest('form');
                    if (form) form.reset();
                });
            });
            // 校验失败时（hasError）controller redirect 回来 → 自动 editing 模式让用户看到错误
            document.querySelectorAll('[data-start-editing="1"]').forEach((card) => card.classList.add('editing'));
        })();
        </script>
    @endif
@endsection


{{-- R4 (丁方案): Add Model 抽屉 — 3 步 stepper, 智能起点 --}}
@adminCan('settings.model.update')
<div class="model-drawer-backdrop" id="md-backdrop"></div>
<aside class="model-drawer" id="md-root" role="dialog" aria-modal="true" aria-labelledby="md-title" hidden>
    <div class="md-head">
        <div>
            <h2 id="md-title">新增模型</h2>
            <div class="md-sub" id="md-sub">选择供应商 → 挑选模型 → 填连接</div>
        </div>
        <button type="button" class="md-close" data-md-close aria-label="关闭">×</button>
    </div>

    <div class="md-body">
        <div class="stepper" id="md-stepper">
            <div class="step active" data-step-tab="1"><span class="num"><span>1</span></span> 选供应商</div>
            <div class="step-bar"></div>
            <div class="step" data-step-tab="2"><span class="num"><span>2</span></span> 挑选模型</div>
            <div class="step-bar"></div>
            <div class="step" data-step-tab="3"><span class="num"><span>3</span></span> 填连接信息</div>
        </div>

        {{-- Step 1: 选供应商 --}}
        <section class="md-step" data-step="1">
            <div class="md-section-title">选择一个供应商接入</div>
            <div class="vendor-pick" id="md-vendor-pick">
                <button type="button" class="vp" data-vp="doubao">
                    <span class="vp-icon doubao">豆</span>
                    <span><div class="vp-name">字节跳动 / 豆包</div><div class="vp-sub">Doubao · Ark · Volcengine</div></span>
                </button>
                <button type="button" class="vp" data-vp="qwen">
                    <span class="vp-icon qwen">通</span>
                    <span><div class="vp-name">阿里云 / 通义千问</div><div class="vp-sub">DashScope · Qwen</div></span>
                </button>
                <button type="button" class="vp" data-vp="deepseek">
                    <span class="vp-icon deepseek">D</span>
                    <span><div class="vp-name">深度求索 / DeepSeek</div><div class="vp-sub">deepseek-chat · deepseek-reasoner</div></span>
                </button>
                <button type="button" class="vp" data-vp="claude">
                    <span class="vp-icon claude">A</span>
                    <span><div class="vp-name">Anthropic / Claude</div><div class="vp-sub">claude-haiku · sonnet · opus</div></span>
                </button>
                <button type="button" class="vp" data-vp="kimi">
                    <span class="vp-icon kimi">K</span>
                    <span><div class="vp-name">月之暗面 / Kimi</div><div class="vp-sub">Moonshot AI</div></span>
                </button>
                <button type="button" class="vp" data-vp="glm">
                    <span class="vp-icon glm">G</span>
                    <span><div class="vp-name">智谱 / GLM</div><div class="vp-sub">BigModel / ZhipuAI</div></span>
                </button>
                <button type="button" class="vp" data-vp="openai">
                    <span class="vp-icon openai">O</span>
                    <span><div class="vp-name">OpenAI</div><div class="vp-sub">gpt-4o · gpt-4o-mini</div></span>
                </button>
                <button type="button" class="vp" data-vp="custom">
                    <span class="vp-icon custom">⚙</span>
                    <span><div class="vp-name">自定义 / OpenAI 兼容</div><div class="vp-sub">手动填 base_url 和 model_id</div></span>
                </button>
            </div>
        </section>

        {{-- Step 2: 选模型（按 vendor 预设） --}}
        <section class="md-step" data-step="2" hidden>
            <div class="md-section-title">从预设列表勾选要挂载的模型 <span style="font-weight:400;text-transform:none;color:var(--pro-text-secondary)">（可加任意自定义 model_id）</span></div>
            <div class="model-pick" id="md-model-pick"></div>
            <div class="md-add-custom">
                <input type="text" id="md-custom-model-id" placeholder="自定义 model_id（如 my-deployed-model-v2）">
                <select id="md-custom-model-cap">
                    <option value="text">text</option>
                    <option value="vision">vision</option>
                    <option value="other">other</option>
                </select>
                <button type="button" class="mc-btn mc-btn-sm" id="md-add-custom-btn">+ 添加模型</button>
            </div>
        </section>

        {{-- Step 3: 填连接 --}}
        <section class="md-step" data-step="3" hidden>
            <form method="post" action="/admin/settings" id="md-save-form" class="md-step3-form">
                @csrf
                <input type="hidden" name="section" value="model">
                <input type="hidden" name="vendor_key" id="md-vendor-key" value="">
                {{-- model_capability[] / model_id[] / model_label[] / model_capabilities[][] 由 JS 动态注入 --}}
                <div id="md-mounted-fields"></div>

                <div class="md-section-title">连接信息</div>
                <div class="md-form-grid">
                    <div class="md-field" style="grid-column:1 / -1;">
                        <label>Base URL <span class="req" style="color:var(--pro-error)">*</span></label>
                        <input type="text" name="model_base_url" id="md-base-url" required>
                        <div class="md-hint">OpenAI 兼容网关；通常以 /v1 结尾</div>
                    </div>
                    <div class="md-field" style="grid-column:1 / -1;">
                        <label>API Key</label>
                        <input type="password" name="model_api_key" id="md-api-key" placeholder="留空表示沿用现有；填新值则覆盖">
                    </div>
                    <div class="md-field" style="grid-column:1 / -1;">
                        <button type="button" class="mc-btn" id="md-test-conn-btn">测试连接</button>
                        <span id="md-test-conn-status" style="margin-left:10px;font-size:12px;color:var(--pro-text-secondary);"></span>
                        <div class="md-hint">用当前填的 Base URL + API Key + 已挂载第一个 text 模型发一次极小请求探活，不会写入</div>
                    </div>
                </div>

                <div class="md-section-title">已挂载模型</div>
                <div class="md-mounted-list" id="md-mounted-summary"></div>
            </form>
        </section>
    </div>

    <div class="md-foot">
        <span class="md-foot-msg" id="md-foot-msg"></span>
        <div class="md-foot-actions">
            <button type="button" class="mc-btn" id="md-back-btn" hidden>上一步</button>
            <button type="button" class="mc-btn" data-md-close>取消</button>
            <button type="button" class="mc-btn mc-btn-primary" id="md-next-btn">下一步</button>
            <button type="submit" form="md-save-form" class="mc-btn mc-btn-primary" id="md-save-btn" hidden>保存</button>
        </div>
    </div>
</aside>
@endadminCan

{{-- R5: 新增/编辑配额抽屉 --}}
@adminCan('settings.quota.manage')
<div class="model-drawer-backdrop" id="qa-backdrop"></div>
<aside class="model-drawer" id="qa-root" role="dialog" aria-modal="true" aria-labelledby="qa-title" hidden>
    <div class="md-head">
        <div>
            <h2 id="qa-title">新增配额</h2>
            <div class="md-sub" id="qa-sub">为部门或重度个人单独划一块 token 池</div>
        </div>
        <button type="button" class="md-close" data-qa-close aria-label="关闭">×</button>
    </div>

    <div class="md-body">
        <div class="md-section-title">分配对象</div>
        <div class="md-form-grid">
            <div class="md-field">
                <label>类型 <span style="color:var(--pro-error)">*</span></label>
                <select id="qa-type">
                    <option value="department">部门</option>
                    <option value="user">个人</option>
                </select>
                <div class="md-hint" id="qa-type-hint">编辑现有配额时类型不可改（要换类型请先删除旧配额）</div>
            </div>
            <div class="md-field">
                <label id="qa-target-label">目标部门 <span style="color:var(--pro-error)">*</span></label>
                <select id="qa-target"></select>
            </div>
        </div>

        <div class="md-section-title">配额</div>
        <div class="md-form-grid">
            <div class="md-field" style="grid-column:1 / -1;">
                <label>Token 月配额 <span style="color:var(--pro-error)">*</span></label>
                <input type="number" id="qa-limit" min="1" step="10000" placeholder="例如 200000">
                <div class="md-hint">该对象在自然月内累计可消耗的 Token 总量；超出则机器人停止响应直到下月或调高配额</div>
            </div>
        </div>

        <div class="md-section-title" id="qa-current-title" hidden>当前使用</div>
        <div class="md-mounted-list" id="qa-current" hidden></div>
    </div>

    <div class="md-foot">
        <span class="md-foot-msg" id="qa-foot-msg"></span>
        <div class="md-foot-actions">
            <button type="button" class="mc-btn" data-qa-close>取消</button>
            <button type="button" class="mc-btn mc-btn-primary" id="qa-save-btn">保存</button>
        </div>
    </div>
</aside>
@endadminCan

@push('scripts')
<script>
    /* R4 (丁方案): Model drawer JS (替代旧 inline form 逻辑) */

    // 已配置 vendor 的预填数据（用于抽屉 step3 编辑模式）— R7 多 vendor 支持
    @php
        $__existingProvidersData = [];
        foreach ($activeProviders ?? [] as $vp) {
            $__existingProvidersData[$vp['vendor_key']] = [
                'vendor_key' => $vp['vendor_key'],
                'name' => $vp['name'],
                'base_url' => $vp['base_url'],
                'models' => $vp['models'],
                'api_key_configured' => $vp['api_key_configured'],
            ];
        }
    @endphp
    const existingProviders = @json($__existingProvidersData);

    // Vendor 预设（含 capabilities，丁方案）
    const modelPresets = {
        doubao: {
            name: '字节跳动 / 豆包',
            baseUrl: 'https://ark.cn-beijing.volces.com/api/v3',
            models: [
                ['text', 'doubao-seed-2-0-code-preview-260215', 'Doubao Seed 2.0 Code', ['text']],
                ['vision', 'doubao-seedream-5-0-260128', 'Doubao Seedream 5.0', ['vision']],
            ],
        },
        qwen: {
            name: '阿里云 / 通义千问',
            baseUrl: 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            models: [
                ['text', 'qwen-max', 'Qwen Max', ['text']],
                ['text', 'qwen-plus', 'Qwen Plus', ['text']],
                ['vision', 'qwen-vl-max', 'Qwen VL Max', ['text', 'vision']],
            ],
        },
        deepseek: {
            name: '深度求索 / DeepSeek',
            baseUrl: 'https://api.deepseek.com/v1',
            models: [
                ['text', 'deepseek-chat', 'DeepSeek Chat', ['text']],
                ['text', 'deepseek-reasoner', 'DeepSeek Reasoner', ['text']],
            ],
        },
        claude: {
            name: 'Anthropic / Claude',
            baseUrl: 'https://api.anthropic.com/v1',
            models: [
                ['text', 'claude-haiku-4-5', 'Claude Haiku 4.5', ['text', 'vision']],
                ['text', 'claude-sonnet-4-5', 'Claude Sonnet 4.5', ['text', 'vision']],
                ['text', 'claude-opus-4-1', 'Claude Opus 4.1', ['text', 'vision']],
            ],
        },
        kimi: {
            name: '月之暗面 / Kimi',
            baseUrl: 'https://api.moonshot.cn/v1',
            models: [
                ['text', 'moonshot-v1-8k', 'Kimi 8K', ['text']],
                ['text', 'moonshot-v1-32k', 'Kimi 32K', ['text']],
                ['text', 'moonshot-v1-128k', 'Kimi 128K', ['text']],
            ],
        },
        glm: {
            name: '智谱 / GLM',
            baseUrl: 'https://open.bigmodel.cn/api/paas/v4',
            models: [
                ['text', 'glm-4.6', 'GLM 4.6', ['text']],
                ['text', 'glm-4-air', 'GLM 4 Air', ['text']],
                ['vision', 'glm-4v-plus', 'GLM 4V Plus', ['vision']],
            ],
        },
        openai: {
            name: 'OpenAI',
            baseUrl: 'https://api.openai.com/v1',
            models: [
                ['text', 'gpt-4o', 'GPT-4o', ['text', 'vision']],
                ['text', 'gpt-4o-mini', 'GPT-4o Mini', ['text', 'vision']],
            ],
        },
        custom: {
            name: '自定义 / OpenAI 兼容',
            baseUrl: '',
            models: [],
        },
    };

    // === Drawer state ===
    const drawer = document.getElementById('md-root');
    const backdrop = document.getElementById('md-backdrop');
    let dState = {
        step: 1,
        vendorKey: '',
        selectedModels: [], // [[capability, model_id, label, capabilities[]], ...]
    };

    function openDrawer(initStep, presetVendorKey, editMode) {
        if (!drawer) return;
        dState = {
            step: initStep || 1,
            vendorKey: presetVendorKey || '',
            selectedModels: [],
        };

        // 编辑模式：从 existingProviders 反向预填
        if (editMode && presetVendorKey && existingProviders[presetVendorKey]) {
            const prov = existingProviders[presetVendorKey];
            const presetModels = (modelPresets[presetVendorKey] || {}).models || [];
            // 已挂载的模型勾选上
            (prov.models || []).forEach((m) => {
                const caps = Array.isArray(m.capabilities) && m.capabilities.length
                    ? m.capabilities
                    : [String(m.capability || 'text')];
                dState.selectedModels.push([caps[0] || 'text', String(m.model_id || ''), String(m.label || ''), caps]);
            });
        } else if (presetVendorKey && modelPresets[presetVendorKey]) {
            // step2 智能起点：来自未配置卡片，把预设模型默认勾选上
            (modelPresets[presetVendorKey].models || []).forEach((m) => {
                dState.selectedModels.push([m[0], m[1], m[2], m[3] || [m[0]]]);
            });
        }

        // R6-test fix: 抽屉每次打开都清空"测试连接"状态（防残留）
        const _ts = document.getElementById('md-test-conn-status');
        const _tb = document.getElementById('md-test-conn-btn');
        if (_ts) { _ts.textContent = ''; _ts.style.color = ''; }
        if (_tb) { _tb.disabled = false; }

        renderDrawer();
        drawer.hidden = false;
        backdrop.classList.add('open');
        requestAnimationFrame(() => drawer.classList.add('open'));
    }

    function closeDrawer() {
        if (!drawer) return;
        drawer.classList.remove('open');
        backdrop.classList.remove('open');
        setTimeout(() => { drawer.hidden = true; }, 250);
    }

    function renderDrawer() {
        // Stepper
        document.querySelectorAll('#md-stepper .step').forEach((el) => {
            const s = parseInt(el.dataset.stepTab, 10);
            el.classList.toggle('active', s === dState.step);
            el.classList.toggle('done', s < dState.step);
        });
        document.querySelectorAll('#md-stepper .step-bar').forEach((bar, idx) => {
            bar.classList.toggle('done', idx < dState.step - 1);
        });
        // Step panels
        document.querySelectorAll('.md-step').forEach((el) => {
            el.hidden = parseInt(el.dataset.step, 10) !== dState.step;
        });
        // Foot buttons
        document.getElementById('md-back-btn').hidden = dState.step === 1;
        document.getElementById('md-next-btn').hidden = dState.step === 3;
        document.getElementById('md-save-btn').hidden = dState.step !== 3;

        // Step1: highlight active vendor
        document.querySelectorAll('#md-vendor-pick .vp').forEach((el) => {
            el.classList.toggle('active', el.dataset.vp === dState.vendorKey);
        });

        if (dState.step === 2) renderStep2();
        if (dState.step === 3) renderStep3();
    }

    function renderStep2() {
        const list = document.getElementById('md-model-pick');
        const preset = modelPresets[dState.vendorKey] || { models: [] };
        const selectedIds = new Set(dState.selectedModels.map((m) => m[1]));

        // 合并 preset 模型 + 已勾选但不在 preset 里的（自定义）
        const all = [];
        preset.models.forEach((m) => all.push(m));
        dState.selectedModels.forEach((s) => {
            if (!preset.models.some((m) => m[1] === s[1])) {
                all.push(s);
            }
        });

        list.innerHTML = '';
        all.forEach((m) => {
            const id = m[1];
            const label = m[2] || '';
            const caps = m[3] || [m[0]];
            const selected = selectedIds.has(id);
            const row = document.createElement('div');
            row.className = 'model-pick-row' + (selected ? ' selected' : '');
            row.innerHTML = `
                <div class="check">${selected ? '✓' : ''}</div>
                <div>
                    <div class="pname">${escapeHtml(id)}</div>
                    ${label ? `<div class="plabel">${escapeHtml(label)}</div>` : ''}
                </div>
                <div class="pcaps">
                    ${caps.map((c) => `<span class="pcap ${c}">${c}</span>`).join('')}
                </div>
            `;
            row.addEventListener('click', () => toggleSelectedModel(m[0], id, label, caps));
            list.appendChild(row);
        });
        if (all.length === 0) {
            list.innerHTML = '<div style="padding:14px;color:var(--pro-text-secondary);font-size:12.5px;">该供应商暂无内置预设，使用下方"自定义 model_id"手动添加。</div>';
        }
    }

    function toggleSelectedModel(capability, modelId, label, capabilities) {
        const idx = dState.selectedModels.findIndex((s) => s[1] === modelId);
        if (idx >= 0) {
            dState.selectedModels.splice(idx, 1);
        } else {
            dState.selectedModels.push([capability, modelId, label, capabilities || [capability]]);
        }
        renderStep2();
    }

    function renderStep3() {
        const preset = modelPresets[dState.vendorKey] || {};
        const existing = existingProviders[dState.vendorKey];
        const baseUrlInput = document.getElementById('md-base-url');
        if (existing && existing.base_url) {
            baseUrlInput.value = existing.base_url;
        } else if (preset.baseUrl) {
            baseUrlInput.value = preset.baseUrl;
        }
        // vendor_key
        document.getElementById('md-vendor-key').value = dState.vendorKey;

        // Mounted summary + hidden fields
        const summary = document.getElementById('md-mounted-summary');
        const fields = document.getElementById('md-mounted-fields');
        summary.innerHTML = '';
        fields.innerHTML = '';
        if (dState.selectedModels.length === 0) {
            summary.innerHTML = '<span style="color:var(--pro-text-secondary)">尚未勾选任何模型，请回到 step 2 添加。</span>';
        }
        dState.selectedModels.forEach((m, i) => {
            // summary row
            const r = document.createElement('div');
            r.className = 'mml-row';
            r.innerHTML = `<span class="pcap ${m[0]}" style="font-size:10.5px;padding:1px 6px;border-radius:4px;font-weight:600;background:var(--pro-primary-soft);color:var(--pro-primary-hover);">${m[0]}</span> ${escapeHtml(m[1])}${m[2] ? ' — '+escapeHtml(m[2]) : ''}`;
            summary.appendChild(r);
            // hidden form fields (兼容 R3 controller)
            ['model_capability', 'model_id', 'model_label'].forEach((name, idx) => {
                const v = idx === 0 ? m[0] : (idx === 1 ? m[1] : (m[2] || ''));
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = name + '[]';
                inp.value = v;
                fields.appendChild(inp);
            });
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // === Wire up triggers ===
    document.querySelectorAll('[data-open-add-vendor]').forEach((btn) => {
        btn.addEventListener('click', () => openDrawer(1));
    });
    document.querySelectorAll('[data-edit-vendor]').forEach((btn) => {
        btn.addEventListener('click', () => openDrawer(3, btn.dataset.editVendor, true));
    });
    document.querySelectorAll('[data-model-preset]').forEach((btn) => {
        btn.addEventListener('click', () => openDrawer(2, btn.dataset.modelPreset));
    });
    document.querySelectorAll('[data-md-close]').forEach((el) => el.addEventListener('click', closeDrawer));
    if (backdrop) backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && drawer && !drawer.hidden) closeDrawer();
    });

    document.querySelectorAll('#md-vendor-pick .vp').forEach((el) => {
        el.addEventListener('click', () => {
            dState.vendorKey = el.dataset.vp;
            dState.selectedModels = [];
            (modelPresets[dState.vendorKey]?.models || []).forEach((m) => {
                dState.selectedModels.push([m[0], m[1], m[2], m[3] || [m[0]]]);
            });
            dState.step = 2;
            renderDrawer();
        });
    });

    document.getElementById('md-back-btn').addEventListener('click', () => {
        if (dState.step > 1) { dState.step--; renderDrawer(); }
    });
    document.getElementById('md-next-btn').addEventListener('click', () => {
        if (dState.step === 1 && !dState.vendorKey) {
            document.getElementById('md-foot-msg').textContent = '请先选一个供应商';
            return;
        }
        if (dState.step === 2 && dState.selectedModels.length === 0) {
            document.getElementById('md-foot-msg').textContent = '请至少勾选一个模型';
            return;
        }
        document.getElementById('md-foot-msg').textContent = '';
        if (dState.step < 3) { dState.step++; renderDrawer(); }
    });

    // Step2: 添加自定义模型
    document.getElementById('md-add-custom-btn').addEventListener('click', () => {
        const id = document.getElementById('md-custom-model-id').value.trim();
        const cap = document.getElementById('md-custom-model-cap').value;
        if (!id) return;
        if (!dState.selectedModels.some((m) => m[1] === id)) {
            dState.selectedModels.push([cap, id, '', [cap]]);
            renderStep2();
            document.getElementById('md-custom-model-id').value = '';
        }
    });

    // === A1 + B3: 当前生效区 onChange 立即提交 ===
    function submitActiveSlot(slot) {
        const form = slot === 'main' ? document.getElementById('active-main-form') : document.getElementById('active-vision-form');
        const status = slot === 'main' ? document.getElementById('am-main-status') : document.getElementById('am-vision-status');
        if (!form) return;
        const fd = new FormData(form);
        fetch('/admin/settings', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        }).then((r) => r.json()).then((j) => {
            if (j && j.ok) {
                status.classList.add('show');
                setTimeout(() => status.classList.remove('show'), 1500);
                // Cross-update hidden field on the other form so下次切换时不丢另一个值
                const otherSlot = slot === 'main' ? 'vision' : 'main';
                const otherForm = otherSlot === 'main' ? document.getElementById('active-main-form') : document.getElementById('active-vision-form');
                if (otherForm) {
                    const hidden = otherForm.querySelector(`input[name="active_${slot}_model_id"]`);
                    if (hidden) hidden.value = slot === 'main' ? j.active_main : j.active_vision;
                }
            } else {
                status.textContent = '保存失败';
                status.classList.add('show');
            }
        }).catch(() => {
            status.textContent = '网络错误';
            status.classList.add('show');
        });
    }
    const mainSel = document.getElementById('active-main-select');
    const visionSel = document.getElementById('active-vision-select');
    if (mainSel) mainSel.addEventListener('change', () => submitActiveSlot('main'));
    if (visionSel) visionSel.addEventListener('change', () => submitActiveSlot('vision'));

    document.querySelectorAll('[data-model-tab]').forEach((tab) => {
        tab.addEventListener('click', function() {
            const name = this.dataset.modelTab;
            document.querySelectorAll('[data-model-tab]').forEach((item) => {
                const active = item.dataset.modelTab === name;
                item.classList.toggle('active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            document.querySelectorAll('[data-model-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.modelPanel !== name;
            });
            try {
                if (name === 'quota') {
                    history.replaceState(null, '', '#quota');
                } else if (location.hash === '#quota') {
                    history.replaceState(null, '', location.pathname + location.search);
                }
            } catch (e) {}
        });
    });

    if (location.hash === '#quota') {
        const quotaTab = document.querySelector('[data-model-tab="quota"]');
        if (quotaTab) quotaTab.click();
    }

    document.querySelectorAll('[data-model-tab]').forEach((tab) => {
        tab.addEventListener('click', function() {
            const name = this.dataset.modelTab;
            document.querySelectorAll('[data-model-tab]').forEach((item) => {
                const active = item.dataset.modelTab === name;
                item.classList.toggle('active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            document.querySelectorAll('[data-model-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.modelPanel !== name;
            });
            try {
                if (name === 'quota') {
                    history.replaceState(null, '', '#quota');
                } else if (location.hash === '#quota') {
                    history.replaceState(null, '', location.pathname + location.search);
                }
            } catch (e) {}
        });
    });

    if (location.hash === '#quota') {
        const quotaTab = document.querySelector('[data-model-tab="quota"]');
        if (quotaTab) quotaTab.click();
    }

    /* ── Enterprise file upload display ── */
    var logoInput = document.getElementById('logo-file-input');
    if (logoInput) {
        logoInput.addEventListener('change', function() {
            var nameEl = document.getElementById('logo-file-name');
            if (this.files && this.files.length > 0) {
                nameEl.textContent = this.files[0].name;
                nameEl.style.color = 'var(--pro-text)';
            } else {
                nameEl.textContent = '未选择任何文件';
                nameEl.style.color = '#8a9ba8';
            }
        });
    }
</script>

{{-- ── Token 分配 JS ── --}}
<script>
(function() {
    if (!document.getElementById('quota-app')) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                 || document.querySelector('input[name="_token"]')?.value || '';
    const canManageQuota = @json(request()->attributes->get('admin_user')?->hasAdminPermission('settings.quota.manage') ?? false);

    let quotaState = { departments: [], users: [], allocations: [] };

    function fmt(n) { return Number(n).toLocaleString(); }

    async function loadQuota() {
        try {
            const resp = await fetch('/admin/settings/quota', { headers: { 'Accept': 'application/json' } });
            const data = await resp.json();
            quotaState = data;

            // Pool
            const poolLimit = Number(data.pool.token_limit || 0);
            const used = Number(data.pool.used || 0);
            const defaultLimit = Number(data.default_monthly_limit || 0);
            const remaining = poolLimit > 0 ? Math.max(0, poolLimit - used) : 0;
            const pct = poolLimit > 0 ? Math.min(100, (used / poolLimit) * 100) : 0;
            const pctText = pct.toFixed(1) + '%';
            const barColor = pct > 90 ? '#b91c1c' : pct > 70 ? '#b45309' : '#059669';

            document.getElementById('quota-pool-limit').value = poolLimit || '';
            document.getElementById('quota-default-limit').value = defaultLimit;
            document.getElementById('quota-pool-value').textContent = poolLimit > 0 ? fmt(poolLimit) : '未设置';
            document.getElementById('pool-used').textContent = fmt(used);
            document.getElementById('pool-remaining').textContent = poolLimit > 0 ? fmt(remaining) : '不限';
            document.getElementById('quota-pool-pct').textContent = pctText;
            document.getElementById('quota-donut').style.setProperty('--quota-pct', pct.toFixed(1));
            document.getElementById('pool-bar').style.width = pct.toFixed(1) + '%';
            document.getElementById('pool-bar').style.background = barColor;
            document.getElementById('quota-default-display').textContent = defaultLimit > 0 ? fmt(defaultLimit) : '不限';

            // Allocations table
            renderAllocations(data.allocations);

            // Populate target dropdown
            populateTargets();
        } catch (e) {
            console.error('loadQuota failed', e);
        }
    }

    function renderAllocations(allocs) {
        const wrap = document.getElementById('alloc-rows');
        const countEl = document.getElementById('quota-alloc-count');
        const tabCountEl = document.getElementById('quota-tab-count');
        const count = Array.isArray(allocs) ? allocs.length : 0;
        if (countEl) countEl.textContent = String(count);
        if (tabCountEl) tabCountEl.textContent = String(count);
        if (!wrap) return;

        if (!allocs || allocs.length === 0) {
            wrap.innerHTML = '<div class="mc-empty-state">暂无独立配额，所有用户共享总池。</div>';
            return;
        }

        wrap.innerHTML = allocs.map(a => {
            const pctRaw = a.token_limit > 0 ? (a.used / a.token_limit) * 100 : 0;
            const pct = pctRaw.toFixed(1);
            const level = pctRaw > 90 ? 'danger' : pctRaw > 70 ? 'warn' : '';
            const typeLabel = a.type === 'department' ? '部门' : '个人';
            const iconClass = a.type === 'department' ? 'dept' : 'user';
            const initial = escHtml(String(a.target_name || typeLabel).slice(0, 1));
            const actions = canManageQuota
                ? '<div class="ac-actions"><button class="mc-btn mc-btn-sm" type="button" onclick="editAlloc(' + a.id + ')">编辑</button> <button class="mc-btn mc-btn-danger mc-btn-sm" type="button" onclick="deleteAlloc(' + a.id + ')">删除</button></div>'
                : '<div class="ac-actions"><span style="color:var(--pro-text-secondary);font-size:12px;">无权限</span></div>';
            return '<article class="alloc-card">' +
                '<div class="ac-head">' +
                    '<div class="ac-icon ' + iconClass + '">' + initial + '</div>' +
                    '<div class="ac-title"><div class="ac-name" title="' + escHtml(a.target_name) + '">' + escHtml(a.target_name) + '</div><div class="ac-meta">' + typeLabel + '配额 · monthly</div></div>' +
                    actions +
                '</div>' +
                '<div class="ac-pbar"><div class="ac-pbar-fill ' + level + '" style="width:' + Math.min(100, pctRaw).toFixed(1) + '%"></div></div>' +
                '<div class="ac-pinfo"><span><strong>' + fmt(a.used) + '</strong> / ' + fmt(a.token_limit) + '</span><span>' + pct + '%' + (level === 'warn' ? ' · 偏高' : level === 'danger' ? ' · 危险' : '') + '</span></div>' +
            '</article>';
        }).join('');
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function populateTargets() {
        const type = document.getElementById('alloc-type').value;
        const sel = document.getElementById('alloc-target');
        sel.innerHTML = '';
        const items = type === 'department' ? quotaState.departments : quotaState.users;
        (items || []).forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.name;
            sel.appendChild(opt);
        });
    }

    // R5-fix-A: 一个"保存"按钮 → 并行调两个端点（pool + default），都成功才收起 editor
    document.getElementById('save-quota-btn').addEventListener('click', async () => {
        const poolLimit = parseInt(document.getElementById('quota-pool-limit').value) || 0;
        const defaultLimit = parseInt(document.getElementById('quota-default-limit').value) || 0;
        const status = document.getElementById('quota-status');
        const btn = document.getElementById('save-quota-btn');
        status.textContent = '保存中…';
        status.style.color = '#888';
        btn.disabled = true;
        const opts = (body) => ({
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(body),
        });
        try {
            const [r1, r2] = await Promise.all([
                fetch('/admin/settings/quota/pool', opts({ token_limit: poolLimit, period: 'monthly' })),
                fetch('/admin/settings/quota/default', opts({ token_limit: defaultLimit })),
            ]);
            const [d1, d2] = await Promise.all([r1.json(), r2.json()]);
            const ok = (d1.status === 'ok' || d1.message) && (d2.status === 'ok' || d2.message);
            if (ok) {
                status.textContent = '已保存（总池 + 每人默认）';
                status.style.color = '#52c41a';
                setTimeout(() => { status.textContent = ''; }, 3000);
                loadQuota();
                const editor = document.getElementById('quota-editor');
                if (editor) editor.hidden = true;
            } else {
                status.textContent = (d1.message || '总池保存失败') + ' / ' + (d2.message || '每人默认保存失败');
                status.style.color = '#ff4d4f';
            }
        } catch (e) {
            status.textContent = '保存失败：' + (e.message || '请求异常');
            status.style.color = '#ff4d4f';
        } finally {
            btn.disabled = false;
        }
    });

    // Toggle quota editors  (R5-fix: editor 嵌在 hero 内, 展开后滚到 hero)
    document.querySelectorAll('[data-quota-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            const editor = document.getElementById('quota-editor');
            if (!editor) return;
            const willOpen = editor.hidden;
            editor.hidden = !editor.hidden;
            if (willOpen) {
                const hero = document.querySelector('.mc-token-hero');
                if (hero) hero.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                setTimeout(() => document.getElementById('quota-pool-limit')?.focus(), 240);
            }
        });
    });

    /* R6-test: 抽屉 step 3 测试连接按钮 */
    const mdTestBtn = document.getElementById('md-test-conn-btn');
    const mdTestStatus = document.getElementById('md-test-conn-status');
    if (mdTestBtn) {
        mdTestBtn.addEventListener('click', async () => {
            const baseUrl = (document.getElementById('md-base-url').value || '').trim();
            const apiKey = (document.getElementById('md-api-key').value || '').trim();
            const vendorKey = (document.getElementById('md-vendor-key').value || '').trim();
            // A1: 已挂载第一个 text 能力模型
            const firstText = (dState.selectedModels || []).find(m => {
                const caps = Array.isArray(m[3]) ? m[3] : [m[0]];
                return caps.indexOf('text') >= 0;
            });
            const modelId = firstText ? firstText[1] : (dState.selectedModels[0] ? dState.selectedModels[0][1] : '');
            if (!baseUrl || !modelId) {
                mdTestStatus.textContent = baseUrl ? '✗ 未挂载任何模型，无法测试' : '✗ 请先填 Base URL';
                mdTestStatus.style.color = 'var(--pro-error)';
                return;
            }
            mdTestStatus.textContent = '测试中…';
            mdTestStatus.style.color = 'var(--pro-text-secondary)';
            mdTestBtn.disabled = true;
            try {
                const fd = new FormData();
                fd.set('base_url', baseUrl);
                fd.set('api_key', apiKey);
                fd.set('model_id', modelId);
                fd.set('vendor_key', vendorKey);
                fd.set('_token', csrf);
                const resp = await fetch('/admin/settings/test/model-connection', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                });
                const data = await resp.json();
                if (data.ok) {
                    const usage = (data.tokens_in || data.tokens_out)
                        ? ` (model: ${data.model}, ${data.tokens_in}+${data.tokens_out} tokens)`
                        : '';
                    mdTestStatus.textContent = '✓ ' + (data.message || '连接正常') + usage;
                    mdTestStatus.style.color = 'var(--pro-primary-hover)';
                } else {
                    mdTestStatus.textContent = '✗ ' + (data.message || '失败');
                    mdTestStatus.style.color = 'var(--pro-error)';
                }
            } catch (e) {
                mdTestStatus.textContent = '✗ 请求失败：' + (e.message || 'unknown');
                mdTestStatus.style.color = 'var(--pro-error)';
            } finally {
                mdTestBtn.disabled = false;
            }
        });
    }

    /* R8: 已配置 vendor 卡片"测试连接"按钮 */
    document.querySelectorAll('[data-test-vendor]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const vk = btn.getAttribute('data-test-vendor');
            const card = btn.closest('[data-vendor-card]');
            const statusEl = card?.querySelector('[data-vc-status]');
            const orig = btn.textContent;
            btn.textContent = '测试中…';
            btn.disabled = true;
            try {
                // 取该 vendor 已配置的 base_url 和第一个 text 模型 — 全从 existingProviders 拿
                const ep = existingProviders[vk];
                if (!ep) { throw new Error('vendor 数据未找到'); }
                const firstText = (ep.models || []).find(m => {
                    const caps = Array.isArray(m.capabilities) ? m.capabilities : [m.capability || 'text'];
                    return caps.indexOf('text') >= 0;
                }) || ep.models[0];
                if (!firstText) { throw new Error('该 vendor 未挂载任何模型'); }

                const fd = new FormData();
                fd.set('base_url', ep.base_url);
                fd.set('api_key', ''); // 留空 → 后端 fallback 到现有 key
                fd.set('model_id', firstText.model_id);
                fd.set('vendor_key', vk);
                fd.set('_token', csrf);
                const resp = await fetch('/admin/settings/test/model-connection', {
                    method: 'POST', body: fd, credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                });
                const data = await resp.json();
                if (statusEl) {
                    if (data.ok) {
                        statusEl.textContent = '已连通 · 刚刚';
                        statusEl.className = 'vc-status ok';
                        statusEl.title = '上次测试 刚刚 (' + (data.tokens_in||0) + '+' + (data.tokens_out||0) + ' tokens)';
                    } else {
                        statusEl.textContent = '连接失败 · 刚刚';
                        statusEl.className = 'vc-status err';
                        statusEl.title = data.message || '失败';
                    }
                }
            } catch (e) {
                if (statusEl) {
                    statusEl.textContent = '连接失败 · 刚刚';
                    statusEl.className = 'vc-status err';
                    statusEl.title = e.message || '失败';
                }
            } finally {
                btn.textContent = orig;
                btn.disabled = false;
            }
        });
    });

    /* R5: AllocDrawer (替代旧 inline alloc-form) */
    const qaRoot = document.getElementById('qa-root');
    const qaBackdrop = document.getElementById('qa-backdrop');
    const qaTypeSel = document.getElementById('qa-type');
    const qaTargetSel = document.getElementById('qa-target');
    const qaLimitInp = document.getElementById('qa-limit');
    const qaFootMsg = document.getElementById('qa-foot-msg');
    const qaTitle = document.getElementById('qa-title');
    const qaSub = document.getElementById('qa-sub');
    const qaCurrentTitle = document.getElementById('qa-current-title');
    const qaCurrentBox = document.getElementById('qa-current');
    let qaState = { editing: null }; // { id, type, target_id, target_name, used } when editing

    function qaPopulateTargets(preserveValue) {
        if (!qaTargetSel || !qaTypeSel) return;
        const type = qaTypeSel.value;
        const items = type === 'department' ? quotaState.departments : quotaState.users;
        qaTargetSel.innerHTML = '';
        (items || []).forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.name;
            qaTargetSel.appendChild(opt);
        });
        if (preserveValue !== undefined && preserveValue !== null) {
            qaTargetSel.value = String(preserveValue);
        }
        // R5-fix: 根据"类型"动态改 label 文字 + placeholder
        const lbl = document.getElementById('qa-target-label');
        if (lbl) {
            const noun = type === 'department' ? '部门' : '用户';
            lbl.innerHTML = '目标' + noun + ' <span style="color:var(--pro-error)">*</span>';
        }
    }

    function openAllocDrawer(editingAlloc) {
        if (!qaRoot) return;
        qaState.editing = editingAlloc || null;

        if (qaState.editing) {
            qaTitle.textContent = '编辑配额';
            qaSub.textContent = '调整此对象的月度 Token 配额';
            qaTypeSel.value = qaState.editing.type;
            qaTypeSel.disabled = true;
            qaPopulateTargets(qaState.editing.target_id);
            qaTargetSel.disabled = true;
            qaLimitInp.value = qaState.editing.token_limit;
            qaCurrentTitle.hidden = false;
            qaCurrentBox.hidden = false;
            const used = Number(qaState.editing.used || 0);
            const limit = Number(qaState.editing.token_limit || 0);
            const pct = limit > 0 ? ((used / limit) * 100).toFixed(1) : '0.0';
            qaCurrentBox.innerHTML = `<div class="mml-row">本月已用 <strong style="margin-left:6px;">${fmt(used)}</strong> / ${fmt(limit)} (${pct}%)</div>`;
        } else {
            qaTitle.textContent = '新增配额';
            qaSub.textContent = '为部门或重度个人单独划一块 token 池';
            qaTypeSel.disabled = false;
            qaTargetSel.disabled = false;
            qaTypeSel.value = 'department';
            qaPopulateTargets();
            qaLimitInp.value = '';
            qaCurrentTitle.hidden = true;
            qaCurrentBox.hidden = true;
        }
        qaFootMsg.textContent = '';
        qaRoot.hidden = false;
        qaBackdrop.classList.add('open');
        requestAnimationFrame(() => qaRoot.classList.add('open'));
    }

    function closeAllocDrawer() {
        if (!qaRoot) return;
        qaRoot.classList.remove('open');
        qaBackdrop.classList.remove('open');
        setTimeout(() => { qaRoot.hidden = true; qaState.editing = null; }, 250);
    }

    if (qaTypeSel) qaTypeSel.addEventListener('change', () => qaPopulateTargets());
    document.querySelectorAll('[data-qa-close]').forEach((el) => el.addEventListener('click', closeAllocDrawer));
    if (qaBackdrop) qaBackdrop.addEventListener('click', closeAllocDrawer);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && qaRoot && !qaRoot.hidden) closeAllocDrawer();
    });

    document.getElementById('add-alloc-btn').addEventListener('click', () => openAllocDrawer(null));

    // 新"编辑配额"按钮 (R5)
    window.editAlloc = function(id) {
        const a = (quotaState.allocations || []).find(x => x.id === id);
        if (a) openAllocDrawer(a);
    };

    document.getElementById('qa-save-btn').addEventListener('click', async () => {
        const type = qaTypeSel.value;
        const targetId = parseInt(qaTargetSel.value);
        const limit = parseInt(qaLimitInp.value) || 0;
        if (!targetId || limit <= 0) {
            qaFootMsg.textContent = '请选择目标并填写有效配额';
            qaFootMsg.style.color = 'var(--pro-error)';
            return;
        }
        qaFootMsg.textContent = '保存中…';
        qaFootMsg.style.color = 'var(--pro-text-secondary)';
        try {
            const resp = await fetch('/admin/settings/quota/allocate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ type, target_id: targetId, token_limit: limit }),
            });
            const data = await resp.json();
            if (data.status === 'ok') {
                qaFootMsg.textContent = '已保存';
                qaFootMsg.style.color = 'var(--pro-primary-hover)';
                setTimeout(closeAllocDrawer, 600);
                loadQuota();
            } else {
                qaFootMsg.textContent = data.message || '保存失败';
                qaFootMsg.style.color = 'var(--pro-error)';
            }
        } catch (e) {
            qaFootMsg.textContent = '请求失败';
            qaFootMsg.style.color = 'var(--pro-error)';
        }
    });

    // Delete allocation
    window.deleteAlloc = async function(id) {
        if (!confirm('确认移除此配额分配？移除后该对象将回归共享总池。')) return;
        try {
            const resp = await fetch('/admin/settings/quota/allocate/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ id: id }),
            });
            const data = await resp.json();
            if (data.status === 'ok') {
                loadQuota();
            } else {
                alert(data.message || '操作失败');
            }
        } catch (e) {
            alert('请求失败');
        }
    };

    // Initial load
    loadQuota();
})();
</script>
@endpush
