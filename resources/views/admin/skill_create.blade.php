@extends('admin.layout')

@section('title', '米蛙管理后台 - 新增技能')
@section('header-title', '新增技能')
@section('header-subtitle', '创建新的企业内部 Skill')
@section('page-title', '新增 Skill')
@section('page-desc', '先选类型，再填信息；创建后可分配到部门或成员')

@section('header-actions')
    <a href="/admin/skills" class="pro-btn">返回技能列表</a>
@endsection

@push('head')
<style>
    /* ── Phase 1: Type picker ── */
    .sk-type-intro {
        font-size: 13px;
        color: var(--pro-text-secondary);
        margin: 0 0 14px;
    }
    .sk-type-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
    }
    @media (min-width: 960px) {
        .sk-type-grid { grid-template-columns: 1fr 1fr 1fr; }
    }
    .sk-type-card {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 18px 18px 16px;
        background: var(--pro-surface);
        border: 1px solid rgba(17, 35, 45, 0.09);
        border-radius: var(--pro-radius);
        box-shadow: var(--pro-shadow-xs);
        cursor: pointer;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        text-align: left;
        position: relative;
    }
    .sk-type-card:hover,
    .sk-type-card:focus-visible {
        border-color: var(--pro-primary);
        box-shadow: var(--pro-shadow-sm);
        transform: translateY(-1px);
        outline: none;
    }
    .sk-type-card .glyph {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 10px;
        font-size: 18px;
        font-weight: 700;
        letter-spacing: -0.5px;
    }
    .sk-type-card.t-prompt .glyph { background: #ddf5eb; color: var(--pro-primary); }
    .sk-type-card.t-sandbox .glyph { background: #e0f2fe; color: #0369a1; font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size: 14px; }
    .sk-type-card.t-http_api .glyph { background: #fff0e4; color: #b95a1a; font-size: 13px; }
    .sk-type-card .title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 15px;
        font-weight: 700;
        color: var(--pro-text);
    }
    .sk-type-card .tag {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 500;
        border-radius: 999px;
        background: rgba(17, 35, 45, 0.05);
        color: var(--pro-text-secondary);
        letter-spacing: 0.2px;
    }
    .sk-type-card .desc {
        font-size: 13px;
        color: var(--pro-text-secondary);
        line-height: 1.55;
    }
    .sk-type-card .examples {
        font-size: 12px;
        color: #8a8880;
        padding-top: 6px;
        border-top: 1px dashed rgba(17, 35, 45, 0.08);
    }
    .sk-type-card .chev {
        position: absolute;
        top: 18px;
        right: 18px;
        color: #8a8880;
        transition: transform 0.15s ease, color 0.15s ease;
    }
    .sk-type-card:hover .chev { color: var(--pro-primary); transform: translateX(2px); }
    .sk-kbd-hint {
        margin-top: 14px;
        font-size: 12px;
        color: var(--pro-text-secondary);
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .sk-kbd {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        padding: 1px 6px;
        font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        font-size: 11px;
        background: var(--pro-surface);
        border: 1px solid var(--pro-border);
        border-bottom-width: 2px;
        border-radius: 4px;
        color: var(--pro-text);
    }

    /* ── Phase 2: Form ── */
    .sk-form-head {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        padding-bottom: 14px;
        margin-bottom: 16px;
        border-bottom: 1px solid rgba(17, 35, 45, 0.06);
    }
    .sk-chip-type {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px 4px 6px;
        border-radius: 999px;
        background: rgba(17, 35, 45, 0.05);
        font-size: 13px;
        font-weight: 500;
        color: var(--pro-text);
    }
    .sk-chip-type .mini {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
    }
    .sk-chip-type.t-prompt .mini { background: #ddf5eb; color: var(--pro-primary); }
    .sk-chip-type.t-sandbox .mini { background: #e0f2fe; color: #0369a1; font-family: ui-monospace, Menlo, Consolas, monospace; }
    .sk-chip-type.t-http_api .mini { background: #fff0e4; color: #b95a1a; font-size: 9px; }
    .sk-chip-type .x {
        cursor: pointer;
        color: var(--pro-text-secondary);
        padding: 0 2px;
    }
    .sk-chip-type .x:hover { color: var(--pro-error); }
    .sk-progress {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: var(--pro-text-secondary);
        margin-left: auto;
    }
    .sk-progress .step {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .sk-progress .dot {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        background: rgba(17, 35, 45, 0.08);
        color: var(--pro-text-secondary);
    }
    .sk-progress .step.done .dot { background: var(--pro-primary); color: #fff; }
    .sk-progress .step.active .dot { background: var(--pro-text); color: #fff; }
    .sk-progress .step.active { color: var(--pro-text); font-weight: 600; }
    .sk-progress .sep {
        width: 16px;
        height: 1px;
        background: rgba(17, 35, 45, 0.12);
    }

    .sk-section {
        margin-bottom: 18px;
    }
    .sk-section-title {
        font-size: 13px;
        font-weight: 600;
        color: #4d6470;
        margin: 0 0 4px;
    }
    .sk-section-sub {
        font-size: 12px;
        color: var(--pro-text-secondary);
        margin-bottom: 10px;
    }

    .sk-preview-banner {
        background: var(--pro-primary-soft);
        border: 1px solid rgba(15, 157, 111, 0.2);
        color: #0a5a3e;
        padding: 10px 14px;
        border-radius: var(--pro-radius-sm);
        font-size: 13px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .sk-preview-banner strong { font-weight: 600; }

    .sk-sticky-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 18px;
        padding: 14px 0 2px;
        border-top: 1px solid rgba(17, 35, 45, 0.06);
    }
    .sk-sticky-foot .hint {
        font-size: 12px;
        color: var(--pro-text-secondary);
    }

    /* API params repeater */
    .sk-params-row {
        display: grid;
        grid-template-columns: 1fr 1fr 2fr auto auto;
        gap: 8px;
        align-items: center;
    }
    .sk-params-row input[type="text"] {
        width: 100%;
    }
    @media (max-width: 900px) {
        .sk-params-row { grid-template-columns: 1fr; }
    }

    /* ── Assign (chip summary + popover), mirrors show page ── */
    .sd-assign-head {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
    }
    .sd-assign-reach {
        font-size: 12px;
        color: #6b7a83;
        margin-left: auto;
    }
    .sd-assign-reach strong { color: #1a3340; font-weight: 600; }
    .sd-assign-block { margin-bottom: 12px; }
    .sd-assign-block:last-child { margin-bottom: 0; }
    .sd-assign-block-label {
        font-size: 11px;
        color: #6b7a83;
        margin-bottom: 6px;
        font-weight: 500;
    }
    .sd-assign-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .sd-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 9px;
        border-radius: 999px;
        font-size: 12px;
        border: 1px solid transparent;
        line-height: 1.4;
    }
    .sd-chip-dept { background: #e0f2fe; border-color: #bae6fd; color: #0369a1; }
    .sd-chip-user { background: #f4f6f8; border-color: #e4e7eb; color: #334155; }
    .sd-chip-icon { font-size: 10px; opacity: 0.7; }
    .sd-chip-avatar {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #0f9d6f;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 600;
    }
    .sd-chip-count { margin-left: 2px; font-size: 11px; opacity: 0.7; }
    .sd-empty { color: #98a3aa; font-size: 12px; padding: 6px 0; }

    .sd-popover-backdrop {
        position: fixed;
        inset: 0;
        background: transparent;
        z-index: 1200;
        display: none;
    }
    .sd-popover-backdrop[data-open="true"] { display: block; }
    .sd-popover {
        position: absolute;
        width: 360px;
        max-height: 480px;
        background: #ffffff;
        border: 1px solid rgba(17, 35, 45, 0.12);
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(17, 35, 45, 0.12);
        z-index: 1201;
        display: none;
        flex-direction: column;
        overflow: hidden;
    }
    .sd-popover[data-open="true"] { display: flex; }
    .sd-popover-search {
        padding: 10px 12px;
        border-bottom: 1px solid rgba(17, 35, 45, 0.08);
        display: flex;
        align-items: center;
        gap: 8px;
        background: #fafbfc;
    }
    .sd-popover-search-icon { color: #94a3b8; font-size: 14px; }
    .sd-popover-search input {
        border: 0;
        outline: 0;
        flex: 1;
        font-size: 13px;
        background: transparent;
    }
    .sd-popover-list { flex: 1; overflow-y: auto; padding: 4px 0; }
    .sd-popover-section-title {
        padding: 8px 12px 4px;
        font-size: 11px;
        color: #94a3b8;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .sd-popover-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        cursor: pointer;
        font-size: 13px;
        color: #334155;
        user-select: none;
    }
    .sd-popover-item:hover { background: #f4f6f8; }
    .sd-popover-item.is-selected { background: #e8f5ee; }
    .sd-popover-check {
        width: 14px;
        height: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #0f9d6f;
        font-size: 12px;
        font-weight: 700;
    }
    .sd-popover-avatar {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #0f9d6f;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 600;
    }
    .sd-popover-item-dept .sd-popover-avatar {
        background: #e0f2fe;
        color: #0369a1;
        font-size: 11px;
    }
    .sd-popover-name { flex: 1; }
    .sd-popover-meta { font-size: 11px; color: #94a3b8; }
    .sd-popover-empty { padding: 24px; text-align: center; color: #94a3b8; font-size: 12px; }
    .sd-popover-foot {
        padding: 10px 12px;
        border-top: 1px solid rgba(17, 35, 45, 0.08);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fafbfc;
    }
    .sd-popover-counter { font-size: 11px; color: #6b7a83; }
</style>
@endpush

@section('content')
    @php
        $oldType = old('skill_type');

        $oldDeptIds = array_map('intval', (array) old('department_ids', []));
        $oldUserIds = array_map('intval', (array) old('user_ids', []));
        $initDepts = collect($departments)->filter(fn($d) => in_array((int) $d->id, $oldDeptIds, true))->values();
        $initUsers = collect($users)->filter(fn($u) => in_array((int) $u->id, $oldUserIds, true))->values();
        $popoverData = [
            'departments' => collect($departments)->map(fn($d) => [
                'id' => (int) $d->id,
                'name' => (string) $d->name,
                'count' => (int) ($d->users_count ?? 0),
            ])->values()->all(),
            'users' => collect($users)->map(fn($u) => [
                'id' => (int) $u->id,
                'name' => (string) $u->display_name,
                'dept' => $u->department_id ? (int) $u->department_id : null,
                'feishu' => (string) ($u->feishu_open_id ?? ''),
            ])->values()->all(),
            'assigned' => [
                'departments' => $oldDeptIds,
                'users' => $oldUserIds,
            ],
        ];
    @endphp

    {{-- ============ Phase 1: Type picker ============ --}}
    <div id="sk-phase-type" class="pro-card" style="{{ $oldType ? 'display:none;' : '' }}">
        <h3 class="pro-card-title">选一种技能类型</h3>
        <p class="sk-type-intro">不同类型对应不同的执行路径；选好之后下一步会按类型给你定制表单。</p>

        <div class="sk-type-grid">
            <button type="button" class="sk-type-card t-prompt" data-pick="prompt" aria-label="选择提示词技能">
                <span class="chev">›</span>
                <span class="glyph">✦</span>
                <span class="title">提示词技能 <span class="tag">Prompt</span></span>
                <span class="desc">让 LLM 扮演特定专家角色——最常用、最轻量，只写一份提示词即可。</span>
                <span class="examples">例如：会议纪要 · 周报生成 · 文档润色 · 需求拆解</span>
            </button>

            <button type="button" class="sk-type-card t-sandbox" data-pick="sandbox" aria-label="选择脚本技能">
                <span class="chev">›</span>
                <span class="glyph">&lt;/&gt;</span>
                <span class="title">脚本技能 <span class="tag">Sandbox</span></span>
                <span class="desc">在隔离沙箱中执行 bash / python3 等脚本，适合真实计算或系统调用。</span>
                <span class="examples">例如：查服务器状态 · 算数工具 · 数据处理脚本</span>
            </button>

            <button type="button" class="sk-type-card t-http_api" data-pick="http_api" aria-label="选择内部 API 技能">
                <span class="chev">›</span>
                <span class="glyph">API</span>
                <span class="title">内部 API 技能 <span class="tag">HTTP API</span></span>
                <span class="desc">调用企业内部 HTTP 接口，读取 CRM / 库存 / HR 等业务系统数据。</span>
                <span class="examples">例如：查商品库存 · 查工单状态 · 查员工信息</span>
            </button>
        </div>

        <div class="sk-kbd-hint">
            快捷键：<span class="sk-kbd">P</span> Prompt <span class="sk-kbd">S</span> Sandbox <span class="sk-kbd">A</span> API · <span class="sk-kbd">Esc</span> 返回列表
        </div>
    </div>

    {{-- ============ Phase 2: Form ============ --}}
    <div id="sk-phase-form" class="pro-card" style="{{ $oldType ? '' : 'display:none;' }}">
        <form method="post" action="/admin/skills" class="pro-grid" id="skill-create-form">
            @csrf
            <input type="hidden" name="skill_type" id="sk-skill-type" value="{{ $oldType ?? 'prompt' }}">

            {{-- Head strip: back + type chip + progress --}}
            <div class="sk-form-head">
                <button type="button" class="pro-btn pro-btn-sm" id="sk-back-to-type">‹ 换类型</button>
                <span class="sk-chip-type" id="sk-chip-type">
                    <span class="mini" id="sk-chip-mini"></span>
                    <span id="sk-chip-title"></span>
                    <span class="x" id="sk-chip-x" title="更换类型">✕</span>
                </span>
                <div class="sk-progress">
                    <span class="step done"><span class="dot">✓</span>选类型</span>
                    <span class="sep"></span>
                    <span class="step active" id="sk-step-fill"><span class="dot">2</span>填信息</span>
                    <span class="sep"></span>
                    <span class="step" id="sk-step-assign"><span class="dot">3</span>分配</span>
                </div>
            </div>

            <div class="sk-preview-banner">
                <strong>正在创建：<span id="sk-banner-title"></span></strong>
                <span id="sk-banner-desc" style="color:#4d6470;"></span>
            </div>

            {{-- ── Section: 基本信息 ── --}}
            <div class="sk-section">
                <div class="sk-section-title">基本信息</div>
                <div class="sk-section-sub">所有类型都必填；Skill Key 创建后不可改。</div>
                <div class="pro-row pro-row-2">
                    <div class="pro-field">
                        <label>技能名称</label>
                        <input type="text" name="name" required placeholder="例如：周报助手" value="{{ old('name') }}">
                    </div>
                    <div class="pro-field">
                        <label>Skill Key（命令字）</label>
                        <input type="text" name="skill_key" required placeholder="weekly_report" value="{{ old('skill_key') }}">
                        <div class="pro-help">仅字母/数字/下划线/中横线；飞书通过 <code>/skill_key</code> 调用。</div>
                    </div>
                </div>
                <div class="pro-field" style="margin-top:12px;">
                    <label>技能说明</label>
                    <input type="text" name="description" placeholder="内部说明，LLM 会据此判断是否要走这个技能" value="{{ old('description') }}">
                </div>
            </div>

            {{-- ── Section: skill.md (Prompt + Sandbox) ── --}}
            <div class="sk-section" data-skill-type-panel="prompt sandbox">
                <div class="sk-section-title" id="sk-md-title">技能定义 skill.md</div>
                <div class="sk-section-sub" id="sk-md-sub">YAML front matter 管理 name / description / required_capabilities / task_kinds / executor。</div>
                <div class="pro-field">
                    <textarea name="skill_md" style="min-height:240px; font-family:Consolas, 'Courier New', monospace; font-size:13px;">{{ old('skill_md', "---\nname: 示例技能\ndescription: 说明这个技能解决什么问题\nrequired_capabilities:\n  - tool.general_reasoning\ntask_kinds:\n  - general_task\nexecutor: llm\n---\n\n# 角色\n你是企业内部技能助手。\n\n# 输入\n- 明确目标\n- 确认可用数据\n\n# 输出\n- 先给结论\n- 再给步骤\n") }}</textarea>
                    <div class="pro-help" id="sk-md-help">沙箱类型还需在 front matter 写 <code>sandbox_interpreter</code>、<code>sandbox_script</code>、<code>sandbox_timeout</code>。</div>
                </div>
            </div>

            {{-- ── Section: HTTP API 配置 ── --}}
            <div class="sk-section" data-skill-type-panel="http_api" style="display:none;">
                <div class="sk-section-title">HTTP API 配置</div>
                <div class="sk-section-sub">调用企业内部接口，支持模板变量与鉴权。</div>

                <div class="pro-row pro-row-2">
                    <div class="pro-field">
                        <label>API URL</label>
                        <input type="text" name="api_url" placeholder="https://internal.example.com/api/query/@{{spu_id}}" value="{{ old('api_url') }}">
                        <div class="pro-help">URL 可用 <code>@{{param}}</code> 占位（会自动 URL 编码）。</div>
                    </div>
                    <div class="pro-field">
                        <label>HTTP Method</label>
                        <select name="api_method">
                            @foreach (['POST','GET','PUT','PATCH','DELETE'] as $m)
                                <option value="{{ $m }}" {{ old('api_method', 'POST') === $m ? 'selected' : '' }}>{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pro-row pro-row-2" style="margin-top:12px;">
                    <div class="pro-field">
                        <label>API Token</label>
                        <input type="text" name="api_token" placeholder="Bearer token（可留空）" value="{{ old('api_token') }}" autocomplete="off">
                        <div class="pro-help">默认以 <code>Authorization: Bearer &lt;token&gt;</code> 注入；自定义 Authorization 会覆盖。</div>
                    </div>
                    <div class="pro-field">
                        <label>超时时间（秒）</label>
                        <input type="number" name="api_timeout" min="1" max="60" value="{{ old('api_timeout', 10) }}">
                    </div>
                </div>

                <div class="pro-field" style="margin-top:12px;">
                    <label>Headers（JSON 对象，可留空）</label>
                    <textarea name="api_headers" rows="3" style="font-family:Consolas, 'Courier New', monospace; font-size:13px;" placeholder='{"X-Custom":"value"}'>{{ old('api_headers') }}</textarea>
                    <div class="pro-help">支持 <code>@{{token}}</code>、<code>@{{user_id}}</code> 模板占位。</div>
                </div>

                <div class="pro-field" style="margin-top:12px;">
                    <label>Body 模板（仅 POST/PUT/PATCH 用）</label>
                    <textarea name="api_body_template" rows="4" style="font-family:Consolas, 'Courier New', monospace; font-size:13px;" placeholder='{"spu_id":"@{{spu_id}}","warehouse":"@{{warehouse}}"}'>{{ old('api_body_template') }}</textarea>
                    <div class="pro-help">留空时会把参数自动拼成 JSON body。</div>
                </div>

                <div class="pro-field" style="margin-top:12px;">
                    <label>参数（API 入参定义）</label>
                    <div id="api-params-container" style="display:flex; flex-direction:column; gap:8px;"></div>
                    <button type="button" class="pro-btn pro-btn-sm" id="api-params-add" style="margin-top:8px;">+ 添加参数</button>
                    <div class="pro-help">"参数名"给 LLM 看（可中文）；"API 字段名"是真正发给后端的 key。</div>
                </div>

                <div class="pro-field" style="margin-top:12px;">
                    <label>响应可见字段（dot-notation，换行分隔，可留空）</label>
                    <textarea name="response_visible_fields" rows="3" style="font-family:Consolas, 'Courier New', monospace; font-size:13px;" placeholder="data.total&#10;data.items[].name">{{ old('response_visible_fields') }}</textarea>
                    <div class="pro-help">留空传整个响应体；配置后仅保留白名单字段。支持 <code>a.b[].c</code> 数组投影。</div>
                </div>
            </div>

            {{-- ── Section: 分配 ── --}}
            <div class="sk-section">
                <div class="sd-assign-head">
                    <div>
                        <div class="sk-section-title" style="margin-bottom:2px;">分配范围</div>
                        <div class="sk-section-sub" style="margin-bottom:0;">选中的部门全员、或显式选中的成员，都能触发这个 Skill。</div>
                    </div>
                    <span class="sd-assign-reach" id="sk-assign-reach">已选 <strong>{{ $initDepts->count() }}</strong> 部门 · <strong>{{ $initUsers->count() }}</strong> 用户</span>
                    <button type="button" class="pro-btn pro-btn-sm" id="sk-edit-assign">修改</button>
                </div>

                <div class="sd-assign-block">
                    <div class="sd-assign-block-label">部门 · <span id="sk-dept-count">{{ $initDepts->count() }}</span></div>
                    <div id="sk-dept-empty" class="sd-empty" style="{{ $initDepts->isEmpty() ? '' : 'display:none;' }}">未分配给任何部门</div>
                    <div class="sd-assign-chips" id="sk-dept-chips" style="{{ $initDepts->isEmpty() ? 'display:none;' : '' }}">
                        @foreach($initDepts as $d)
                            <span class="sd-chip sd-chip-dept" data-dept-id="{{ $d->id }}" title="共 {{ $d->users_count ?? 0 }} 人">
                                <span class="sd-chip-icon">▦</span>
                                {{ $d->name }}
                                <span class="sd-chip-count">{{ $d->users_count ?? 0 }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="sd-assign-block">
                    <div class="sd-assign-block-label">用户 · <span id="sk-user-count">{{ $initUsers->count() }}</span></div>
                    <div id="sk-user-empty" class="sd-empty" style="{{ $initUsers->isEmpty() ? '' : 'display:none;' }}">未直接分配给任何用户</div>
                    <div class="sd-assign-chips" id="sk-user-chips" style="{{ $initUsers->isEmpty() ? 'display:none;' : '' }}">
                        @foreach($initUsers as $u)
                            <span class="sd-chip sd-chip-user" data-user-id="{{ $u->id }}">
                                <span class="sd-chip-avatar">{{ mb_substr($u->display_name, 0, 1) }}</span>
                                {{ $u->display_name }}
                            </span>
                        @endforeach
                    </div>
                </div>

                {{-- hidden inputs: synced by popover apply --}}
                <div id="sk-assign-hidden-inputs" style="display:none;">
                    @foreach($oldDeptIds as $id)
                        <input type="hidden" name="department_ids[]" value="{{ $id }}">
                    @endforeach
                    @foreach($oldUserIds as $id)
                        <input type="hidden" name="user_ids[]" value="{{ $id }}">
                    @endforeach
                </div>

                <label class="pro-check" style="margin-top:12px;">
                    <input type="checkbox" name="is_active" value="1" checked> 创建后立即启用
                </label>
            </div>

            {{-- ── Sticky foot ── --}}
            <div class="sk-sticky-foot">
                <a href="/admin/skills" class="pro-btn">取消</a>
                <div style="display:flex; gap:10px; align-items:center;">
                    <span class="hint" id="sk-foot-hint">填完名称和 Skill Key 即可创建</span>
                    <button class="pro-btn pro-btn-primary" type="submit" id="sk-submit">创建 Skill</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var TYPES = {
                prompt:   { title: '提示词技能', tag: 'Prompt',   glyph: '✦',   desc: '让 LLM 扮演专家角色——只写一份提示词即可。' },
                sandbox:  { title: '脚本技能',   tag: 'Sandbox',  glyph: '</>', desc: '在沙箱里执行 bash / python3 脚本。' },
                http_api: { title: '内部 API 技能', tag: 'HTTP API', glyph: 'API', desc: '调用企业内部 HTTP 接口读取业务数据。' }
            };

            var phaseType = document.getElementById('sk-phase-type');
            var phaseForm = document.getElementById('sk-phase-form');
            var typeInput = document.getElementById('sk-skill-type');
            var panels = document.querySelectorAll('[data-skill-type-panel]');
            var chip = document.getElementById('sk-chip-type');
            var chipMini = document.getElementById('sk-chip-mini');
            var chipTitle = document.getElementById('sk-chip-title');
            var bannerTitle = document.getElementById('sk-banner-title');
            var bannerDesc = document.getElementById('sk-banner-desc');
            var mdTitle = document.getElementById('sk-md-title');
            var mdSub = document.getElementById('sk-md-sub');
            var mdHelp = document.getElementById('sk-md-help');

            function applyType(t) {
                if (!TYPES[t]) t = 'prompt';
                typeInput.value = t;
                var spec = TYPES[t];

                // Toggle panels
                panels.forEach(function (el) {
                    var match = el.getAttribute('data-skill-type-panel').split(/\s+/).indexOf(t) !== -1;
                    el.style.display = match ? '' : 'none';
                });

                // Chip styling
                chip.classList.remove('t-prompt', 't-sandbox', 't-http_api');
                chip.classList.add('t-' + t);
                chipMini.textContent = spec.glyph;
                chipTitle.textContent = spec.title;

                // Banner
                bannerTitle.textContent = spec.title;
                bannerDesc.textContent = '· ' + spec.desc;

                // skill.md labels (Prompt vs Sandbox)
                if (t === 'sandbox') {
                    mdTitle.textContent = '沙箱脚本定义 skill.md';
                    mdSub.textContent = 'YAML front matter 需写 executor: sandbox，以及 sandbox_interpreter / sandbox_script / sandbox_timeout。';
                    mdHelp.innerHTML = '脚本内容放在 <code>storage/app/skills/{skill_key}/scripts/</code> 下，创建后可在详情页继续添加/编辑。';
                } else if (t === 'prompt') {
                    mdTitle.textContent = 'Prompt 模板 skill.md';
                    mdSub.textContent = '核心就是这份 Prompt——LLM 会按此扮演角色。';
                    mdHelp.innerHTML = 'YAML front matter 管理 name / description / required_capabilities / task_kinds / executor。';
                }
            }

            function showPhase(which) {
                if (which === 'form') {
                    phaseType.style.display = 'none';
                    phaseForm.style.display = '';
                } else {
                    phaseType.style.display = '';
                    phaseForm.style.display = 'none';
                }
            }

            // Phase 1 → Phase 2
            document.querySelectorAll('[data-pick]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    applyType(btn.getAttribute('data-pick'));
                    showPhase('form');
                    var nameEl = document.querySelector('input[name="name"]');
                    if (nameEl) nameEl.focus();
                });
            });

            // Back buttons
            document.getElementById('sk-back-to-type').addEventListener('click', function () { showPhase('type'); });
            document.getElementById('sk-chip-x').addEventListener('click', function () { showPhase('type'); });

            // Keyboard shortcuts
            document.addEventListener('keydown', function (e) {
                // Only when phase-type is visible and not typing in input
                if (phaseType.style.display === 'none') return;
                if (e.target.matches('input, textarea, select')) return;
                var k = (e.key || '').toLowerCase();
                if (k === 'p' || k === '1') { applyType('prompt'); showPhase('form'); }
                else if (k === 's' || k === '2') { applyType('sandbox'); showPhase('form'); }
                else if (k === 'a' || k === '3') { applyType('http_api'); showPhase('form'); }
                else if (k === 'escape') { window.location.href = '/admin/skills'; }
            });

            // Progress: 填信息 → 分配 (visual only; not gating)
            var nameEl = document.querySelector('input[name="name"]');
            var keyEl = document.querySelector('input[name="skill_key"]');
            var stepAssign = document.getElementById('sk-step-assign');
            var stepFill = document.getElementById('sk-step-fill');
            var footHint = document.getElementById('sk-foot-hint');

            function updateProgress() {
                var filled = nameEl && nameEl.value.trim() && keyEl && keyEl.value.trim();
                stepAssign.classList.toggle('active', !!filled);
                footHint.textContent = filled ? '可以创建了' : '填完名称和 Skill Key 即可创建';
            }
            [nameEl, keyEl].forEach(function (el) {
                if (el) el.addEventListener('input', updateProgress);
            });

            // ──────── API params repeater ────────
            var paramsContainer = document.getElementById('api-params-container');
            var paramsAddBtn = document.getElementById('api-params-add');

            function rowTemplate(idx, preset) {
                preset = preset || {};
                var div = document.createElement('div');
                div.className = 'sk-params-row';
                div.innerHTML =
                    '<input type="text" name="api_params[' + idx + '][name]" placeholder="参数名（给 LLM 看）" value="' + (preset.name || '') + '">' +
                    '<input type="text" name="api_params[' + idx + '][api_key]" placeholder="API 字段名" value="' + (preset.api_key || '') + '">' +
                    '<input type="text" name="api_params[' + idx + '][description]" placeholder="说明" value="' + (preset.description || '') + '">' +
                    '<label class="pro-check" style="white-space:nowrap;"><input type="checkbox" name="api_params[' + idx + '][required]" value="1" ' + (preset.required ? 'checked' : '') + '> 必填</label>' +
                    '<button type="button" class="pro-btn pro-btn-sm" data-remove-row>&times;</button>';
                div.querySelector('[data-remove-row]').addEventListener('click', function () { div.remove(); });
                return div;
            }

            var oldParams = @json(old('api_params', []));
            if (Array.isArray(oldParams) && oldParams.length > 0) {
                oldParams.forEach(function (p, i) {
                    paramsContainer.appendChild(rowTemplate(i, {
                        name: p.name,
                        api_key: p.api_key,
                        description: p.description,
                        required: !!p.required
                    }));
                });
            }
            paramsAddBtn.addEventListener('click', function () {
                var n = paramsContainer.querySelectorAll('.sk-params-row').length;
                paramsContainer.appendChild(rowTemplate(n));
            });

            // Initial state
            applyType(typeInput.value || 'prompt');
            updateProgress();
        })();
    </script>

    {{-- ====== Assign Popover ====== --}}
    <div class="sd-popover-backdrop" id="sk-assign-backdrop" data-open="false"></div>
    <div class="sd-popover" id="sk-assign-popover" data-open="false" role="dialog" aria-hidden="true">
        <div class="sd-popover-search">
            <span class="sd-popover-search-icon">⌕</span>
            <input type="text" id="sk-assign-search" placeholder="搜索部门或用户">
        </div>
        <div class="sd-popover-list" id="sk-assign-list"></div>
        <div class="sd-popover-foot">
            <span class="sd-popover-counter" id="sk-assign-counter">0 部门 · 0 用户</span>
            <div style="display:flex; gap:6px;">
                <button type="button" class="pro-btn pro-btn-sm" id="sk-assign-cancel">取消</button>
                <button type="button" class="pro-btn pro-btn-sm pro-btn-primary" id="sk-assign-apply">应用</button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var DATA = @json($popoverData);
            var backdrop = document.getElementById('sk-assign-backdrop');
            var popover = document.getElementById('sk-assign-popover');
            var searchInput = document.getElementById('sk-assign-search');
            var listEl = document.getElementById('sk-assign-list');
            var counterEl = document.getElementById('sk-assign-counter');
            var hiddenEl = document.getElementById('sk-assign-hidden-inputs');
            var reachEl = document.getElementById('sk-assign-reach');

            var deptChipsEl = document.getElementById('sk-dept-chips');
            var deptEmptyEl = document.getElementById('sk-dept-empty');
            var deptCountEl = document.getElementById('sk-dept-count');
            var userChipsEl = document.getElementById('sk-user-chips');
            var userEmptyEl = document.getElementById('sk-user-empty');
            var userCountEl = document.getElementById('sk-user-count');

            var selectedDepts = new Set((DATA.assigned.departments || []).map(Number));
            var selectedUsers = new Set((DATA.assigned.users || []).map(Number));

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
                });
            }

            function render() {
                var q = (searchInput.value || '').trim().toLowerCase();
                var depts = DATA.departments.filter(function (d) { return !q || d.name.toLowerCase().indexOf(q) !== -1; });
                var users = DATA.users.filter(function (u) {
                    if (!q) return true;
                    return u.name.toLowerCase().indexOf(q) !== -1 || (u.feishu && u.feishu.toLowerCase().indexOf(q) !== -1);
                });

                var html = '';
                if (depts.length) {
                    html += '<div class="sd-popover-section-title">部门</div>';
                    depts.forEach(function (d) {
                        var checked = selectedDepts.has(d.id);
                        html += '<div class="sd-popover-item sd-popover-item-dept' + (checked ? ' is-selected' : '') + '" data-type="dept" data-id="' + d.id + '">'
                            + '<span class="sd-popover-check">' + (checked ? '✓' : '') + '</span>'
                            + '<span class="sd-popover-avatar">▦</span>'
                            + '<span class="sd-popover-name">' + escapeHtml(d.name) + '</span>'
                            + '<span class="sd-popover-meta">' + d.count + ' 人</span>'
                            + '</div>';
                    });
                }
                if (users.length) {
                    html += '<div class="sd-popover-section-title">用户</div>';
                    users.forEach(function (u) {
                        var checked = selectedUsers.has(u.id);
                        html += '<div class="sd-popover-item' + (checked ? ' is-selected' : '') + '" data-type="user" data-id="' + u.id + '">'
                            + '<span class="sd-popover-check">' + (checked ? '✓' : '') + '</span>'
                            + '<span class="sd-popover-avatar">' + escapeHtml((u.name || '?').substring(0, 1)) + '</span>'
                            + '<span class="sd-popover-name">' + escapeHtml(u.name) + '</span>'
                            + '</div>';
                    });
                }
                if (!depts.length && !users.length) {
                    html += '<div class="sd-popover-empty">无匹配结果</div>';
                }
                listEl.innerHTML = html;
                counterEl.textContent = selectedDepts.size + ' 部门 · ' + selectedUsers.size + ' 用户';
            }

            listEl.addEventListener('click', function (e) {
                var el = e.target.closest('.sd-popover-item');
                if (!el) return;
                var type = el.getAttribute('data-type');
                var id = Number(el.getAttribute('data-id'));
                if (type === 'dept') {
                    if (selectedDepts.has(id)) selectedDepts.delete(id); else selectedDepts.add(id);
                } else if (type === 'user') {
                    if (selectedUsers.has(id)) selectedUsers.delete(id); else selectedUsers.add(id);
                }
                render();
            });

            searchInput.addEventListener('input', render);

            function positionPopover(anchor) {
                var rect = anchor.getBoundingClientRect();
                var popW = 360;
                var left = Math.max(16, Math.min(window.innerWidth - popW - 16, rect.right - popW));
                var top = rect.bottom + 6 + window.scrollY;
                popover.style.top = top + 'px';
                popover.style.left = left + 'px';
            }

            function openPopover(anchor) {
                searchInput.value = '';
                render();
                positionPopover(anchor);
                popover.setAttribute('data-open', 'true');
                popover.setAttribute('aria-hidden', 'false');
                backdrop.setAttribute('data-open', 'true');
                setTimeout(function () { searchInput.focus(); }, 50);
            }
            function closePopover() {
                popover.setAttribute('data-open', 'false');
                popover.setAttribute('aria-hidden', 'true');
                backdrop.setAttribute('data-open', 'false');
            }

            function syncSummary() {
                // Hidden inputs
                var html = '';
                selectedDepts.forEach(function (id) {
                    html += '<input type="hidden" name="department_ids[]" value="' + id + '">';
                });
                selectedUsers.forEach(function (id) {
                    html += '<input type="hidden" name="user_ids[]" value="' + id + '">';
                });
                hiddenEl.innerHTML = html;

                // Dept chips
                var dHtml = '';
                DATA.departments.forEach(function (d) {
                    if (!selectedDepts.has(d.id)) return;
                    dHtml += '<span class="sd-chip sd-chip-dept" data-dept-id="' + d.id + '" title="共 ' + d.count + ' 人">'
                        + '<span class="sd-chip-icon">▦</span>'
                        + escapeHtml(d.name)
                        + '<span class="sd-chip-count">' + d.count + '</span>'
                        + '</span>';
                });
                deptChipsEl.innerHTML = dHtml;
                if (selectedDepts.size === 0) {
                    deptChipsEl.style.display = 'none';
                    deptEmptyEl.style.display = '';
                } else {
                    deptChipsEl.style.display = '';
                    deptEmptyEl.style.display = 'none';
                }
                deptCountEl.textContent = selectedDepts.size;

                // User chips
                var uHtml = '';
                DATA.users.forEach(function (u) {
                    if (!selectedUsers.has(u.id)) return;
                    var initial = (u.name || '?').substring(0, 1);
                    uHtml += '<span class="sd-chip sd-chip-user" data-user-id="' + u.id + '">'
                        + '<span class="sd-chip-avatar">' + escapeHtml(initial) + '</span>'
                        + escapeHtml(u.name)
                        + '</span>';
                });
                userChipsEl.innerHTML = uHtml;
                if (selectedUsers.size === 0) {
                    userChipsEl.style.display = 'none';
                    userEmptyEl.style.display = '';
                } else {
                    userChipsEl.style.display = '';
                    userEmptyEl.style.display = 'none';
                }
                userCountEl.textContent = selectedUsers.size;

                // Reach
                reachEl.innerHTML = '已选 <strong>' + selectedDepts.size + '</strong> 部门 · <strong>' + selectedUsers.size + '</strong> 用户';
            }

            document.getElementById('sk-edit-assign').addEventListener('click', function (e) {
                e.stopPropagation();
                openPopover(this);
            });
            backdrop.addEventListener('click', closePopover);
            document.getElementById('sk-assign-cancel').addEventListener('click', function () {
                // Revert: reread from hidden inputs
                selectedDepts = new Set();
                selectedUsers = new Set();
                hiddenEl.querySelectorAll('input[name="department_ids[]"]').forEach(function (n) { selectedDepts.add(Number(n.value)); });
                hiddenEl.querySelectorAll('input[name="user_ids[]"]').forEach(function (n) { selectedUsers.add(Number(n.value)); });
                closePopover();
            });
            document.getElementById('sk-assign-apply').addEventListener('click', function () {
                syncSummary();
                closePopover();
            });

            document.addEventListener('keydown', function (e) {
                if (popover.getAttribute('data-open') !== 'true') return;
                if (e.key === 'Escape') { closePopover(); }
            });
        })();
    </script>
@endsection
