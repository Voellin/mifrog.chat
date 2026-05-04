@extends('admin.layout')

@section('title', '米蛙管理后台 - 新建数字分身')
@section('header-title', '新建数字分身')
@section('header-subtitle', '为离职/转岗员工启动数字分身，承载其工作记忆与协作风格')
@section('page-title', '新建数字分身')
@section('page-desc', '先选源员工与时长，再上传同意材料；创建后处于「待激活」状态，激活时会一次性抽样历史数据')

@section('header-actions')
    <a class="pro-btn" href="{{ route('admin.doppelgangers.index') }}">返回列表</a>
@endsection

@push('head')
<style>
.dop-create-wrap { max-width: 880px; }

/* Top consent reminder */
.dop-consent-banner {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 12px 14px;
    background: #fff8ec;
    border: 1px solid #f6e0a6;
    color: #6b4d05;
    border-radius: var(--pro-radius-sm, 8px);
    font-size: 13px;
    line-height: 1.55;
    margin-bottom: 18px;
}
.dop-consent-banner .ico {
    flex: 0 0 22px;
    width: 22px; height: 22px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 999px;
    background: #f3c969; color: #fff;
    font-weight: 700; font-size: 13px;
}
.dop-consent-banner strong { font-weight: 600; color: #4d3700; }

/* Section header */
.dop-section {
    margin-bottom: 20px;
}
.dop-section + .dop-section {
    padding-top: 18px;
    border-top: 1px solid rgba(17, 35, 45, 0.06);
}
.dop-section-title {
    font-size: 13px;
    font-weight: 600;
    color: #4d6470;
    margin: 0 0 4px;
}
.dop-section-sub {
    font-size: 12px;
    color: var(--pro-text-secondary, #6b7a83);
    margin-bottom: 12px;
}

/* Source user pinned chip when ?source_user_id=X */
.dop-source-pinned {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: #f4f6f8;
    border: 1px solid #e4e7eb;
    border-radius: var(--pro-radius-sm, 8px);
}
.dop-source-pinned .avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: var(--pro-primary, #0f9d6f);
    color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: 14px;
}
.dop-source-pinned .meta { flex: 1; }
.dop-source-pinned .name { font-weight: 600; color: #11232d; font-size: 14px; }
.dop-source-pinned .sub { color: #6b7a83; font-size: 12px; }

/* Custom file picker */
.dop-file-picker {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border: 1px dashed #c7d0d6;
    border-radius: var(--pro-radius-sm, 8px);
    background: #fafbfc;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.dop-file-picker:hover { border-color: var(--pro-primary, #0f9d6f); background: #f1fbf6; }
.dop-file-picker input[type="file"] {
    position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none;
}
.dop-file-picker .pick-btn {
    flex: 0 0 auto;
    padding: 4px 12px;
    background: var(--pro-surface, #fff);
    border: 1px solid var(--pro-border, #e4e7eb);
    border-radius: 6px;
    font-size: 12px;
    color: var(--pro-text, #11232d);
    pointer-events: none;
}
.dop-file-picker .file-name {
    flex: 1;
    font-size: 13px;
    color: #6b7a83;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.dop-file-picker .file-name.has-file { color: #11232d; }

/* Expire hint chip */
.dop-expire-hint {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
    padding: 3px 9px;
    background: #e8f5ee;
    color: #0a5a3e;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
}
.dop-expire-hint .arrow { color: #0f9d6f; }

/* Sticky footer (matches skill_create) */
.dop-sticky-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 22px;
    padding: 14px 0 2px;
    border-top: 1px solid rgba(17, 35, 45, 0.06);
}
.dop-sticky-foot .hint {
    font-size: 12px;
    color: var(--pro-text-secondary, #6b7a83);
}
.dop-sticky-foot .actions {
    display: flex;
    gap: 10px;
}

/* Source picker — search + scroll list */
.dop-picker {
    position: relative;
}
.dop-picker-search {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px;
    border: 1px solid var(--pro-border, #e4e7eb);
    border-radius: var(--pro-radius-sm, 8px);
    background: var(--pro-surface, #fff);
    margin-bottom: 8px;
}
.dop-picker-search .ico { color: #94a3b8; font-size: 14px; }
.dop-picker-search input {
    flex: 1; border: 0; outline: 0; font-size: 13px; background: transparent;
}
.dop-picker-list {
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid var(--pro-border, #e4e7eb);
    border-radius: var(--pro-radius-sm, 8px);
    background: var(--pro-surface, #fff);
}
.dop-picker-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    border-bottom: 1px solid #f1f3f5;
}
.dop-picker-item:last-child { border-bottom: 0; }
.dop-picker-item:hover { background: #f4f6f8; }
.dop-picker-item.is-selected { background: #e8f5ee; }
.dop-picker-item .avatar {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: #d6e4eb; color: #11232d;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600;
}
.dop-picker-item .name { flex: 1; color: #11232d; }
.dop-picker-item .id { font-size: 11px; color: #94a3b8; }
.dop-picker-empty { padding: 22px; text-align: center; color: #94a3b8; font-size: 12px; }
</style>
@endpush

@section('content')
    <div class="pro-card no-card-hover dop-create-wrap">
        <h3 class="pro-card-title">新建数字分身</h3>
        <div class="pro-card-subtitle">数字分身基于源员工的飞书历史记录构建，仅在书面同意期内可被授权调阅。</div>

        <div class="dop-consent-banner">
            <span class="ico">!</span>
            <div>
                <strong>合规提醒</strong> · 启用前请确认已取得员工本人书面同意；分身到期后会自动停用，可续期。所有调用都会记录审计日志。
            </div>
        </div>

        <form method="post" action="{{ route('admin.doppelgangers.store') }}" enctype="multipart/form-data" class="pro-grid" id="dop-create-form">
            @csrf

            {{-- ── Section 1: Source employee ── --}}
            <div class="dop-section">
                <div class="dop-section-title">源员工</div>
                <div class="dop-section-sub">仅能为已停用员工创建分身。如需为在职员工建分身，请先在「用户管理」停用。</div>

                @if($candidate)
                    <input type="hidden" name="source_user_id" value="{{ $candidate->id }}">
                    <div class="dop-source-pinned">
                        <span class="avatar">{{ mb_substr($candidate->name, 0, 1) }}</span>
                        <div class="meta">
                            <div class="name">{{ $candidate->name }}</div>
                            <div class="sub">用户 #{{ $candidate->id }}@if($candidate->department_id) · 部门 #{{ $candidate->department_id }}@endif</div>
                        </div>
                        <a href="{{ route('admin.doppelgangers.create') }}" class="pro-btn pro-btn-sm">更换</a>
                    </div>
                @else
                    @if(count($candidates) === 0)
                        <div class="pro-help" style="padding:14px;background:#fafbfc;border:1px dashed #e4e7eb;border-radius:8px;color:#6b7a83;">
                            当前没有「已停用且未建过分身」的员工。先到「用户管理」停用一个员工，再回来这里。
                        </div>
                    @else
                        <input type="hidden" name="source_user_id" id="dop-source-id" value="{{ old('source_user_id') }}" required>
                        <div class="dop-picker">
                            <div class="dop-picker-search">
                                <span class="ico">⌕</span>
                                <input type="text" id="dop-source-search" placeholder="搜索员工姓名 …" autocomplete="off">
                            </div>
                            <div class="dop-picker-list" id="dop-source-list">
                                @foreach($candidates as $u)
                                    <div class="dop-picker-item" data-id="{{ $u->id }}" data-name="{{ $u->name }}">
                                        <span class="avatar">{{ mb_substr($u->name, 0, 1) }}</span>
                                        <span class="name">{{ $u->name }}</span>
                                        <span class="id">#{{ $u->id }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="pro-help" style="margin-top:6px;">点击列表中的员工即可选中；最多显示 200 人，先搜后选更快。</div>
                        </div>
                    @endif
                @endif
            </div>

            {{-- ── Section 2: Display name ── --}}
            <div class="dop-section">
                <div class="dop-section-title">分身展示名</div>
                <div class="dop-section-sub">出现在调阅入口和对话标题里。留空则用「员工姓名 + 的数字分身」。</div>
                <div class="pro-field">
                    <input type="text" name="display_name" value="{{ old('display_name') }}" placeholder="例如：张三的数字分身" maxlength="191">
                </div>
            </div>

            {{-- ── Section 3: Consent (date + PDF, side by side) ── --}}
            <div class="dop-section">
                <div class="dop-section-title">书面同意材料</div>
                <div class="dop-section-sub">日期为员工签字当天；PDF 为可选附件，便于日后审计与法务追溯。</div>
                <div class="pro-row pro-row-2">
                    <div class="pro-field">
                        <label>同意书签字日期 *</label>
                        <input type="date" name="consent_signed_at" value="{{ old('consent_signed_at') }}" required max="{{ now()->toDateString() }}">
                        <div class="pro-help">不能晚于今天。</div>
                    </div>
                    <div class="pro-field">
                        <label>同意书 PDF（可选）</label>
                        <label class="dop-file-picker" for="dop-consent-doc" id="dop-file-picker">
                            <input type="file" name="consent_doc" accept="application/pdf" id="dop-consent-doc">
                            <span class="pick-btn">选择文件</span>
                            <span class="file-name" id="dop-file-name">未选择文件（最大 10 MB）</span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- ── Section 4: Duration ── --}}
            <div class="dop-section">
                <div class="dop-section-title">使用时长</div>
                <div class="dop-section-sub">到期后分身自动停用，可由管理员续期。</div>
                <div class="pro-row pro-row-2">
                    <div class="pro-field">
                        <label>时长 *</label>
                        <select name="duration_months" id="dop-duration" required>
                            <option value="6" {{ old('duration_months') == 6 ? 'selected' : '' }}>6 个月</option>
                            <option value="12" {{ old('duration_months', 12) == 12 ? 'selected' : '' }}>12 个月</option>
                            <option value="24" {{ old('duration_months') == 24 ? 'selected' : '' }}>24 个月</option>
                            <option value="36" {{ old('duration_months') == 36 ? 'selected' : '' }}>36 个月</option>
                        </select>
                        <span class="dop-expire-hint" id="dop-expire-hint">
                            <span class="arrow">→</span>
                            预计到期 <strong id="dop-expire-date">—</strong>
                        </span>
                    </div>
                    <div class="pro-field">
                        {{-- spacer for layout balance --}}
                    </div>
                </div>
            </div>

            {{-- ── Sticky foot ── --}}
            <div class="dop-sticky-foot">
                <span class="hint">创建后状态为「待激活」，需要管理员手动激活以触发样本抽取。</span>
                <div class="actions">
                    <a class="pro-btn" href="{{ route('admin.doppelgangers.index') }}">取消</a>
                    <button type="submit" class="pro-btn pro-btn-primary" id="dop-submit">创建（待激活）</button>
                </div>
            </div>
        </form>
    </div>

    <script>
    (function () {
        // ── Source picker ──
        var picker = document.getElementById('dop-source-list');
        var pickerSearch = document.getElementById('dop-source-search');
        var sourceIdInput = document.getElementById('dop-source-id');

        if (picker && pickerSearch && sourceIdInput) {
            // Restore old('source_user_id') selection visually
            var preselected = sourceIdInput.value;
            if (preselected) {
                var pre = picker.querySelector('[data-id="' + preselected + '"]');
                if (pre) pre.classList.add('is-selected');
            }

            picker.addEventListener('click', function (e) {
                var item = e.target.closest('.dop-picker-item');
                if (!item) return;
                picker.querySelectorAll('.dop-picker-item.is-selected').forEach(function (n) { n.classList.remove('is-selected'); });
                item.classList.add('is-selected');
                sourceIdInput.value = item.getAttribute('data-id');
            });

            pickerSearch.addEventListener('input', function () {
                var q = (this.value || '').trim().toLowerCase();
                var any = false;
                picker.querySelectorAll('.dop-picker-item').forEach(function (item) {
                    var name = (item.getAttribute('data-name') || '').toLowerCase();
                    var match = !q || name.indexOf(q) !== -1;
                    item.style.display = match ? '' : 'none';
                    if (match) any = true;
                });
                var existingEmpty = picker.querySelector('.dop-picker-empty');
                if (!any) {
                    if (!existingEmpty) {
                        var empty = document.createElement('div');
                        empty.className = 'dop-picker-empty';
                        empty.textContent = '没有匹配的员工';
                        picker.appendChild(empty);
                    }
                } else if (existingEmpty) {
                    existingEmpty.remove();
                }
            });
        }

        // ── File picker name preview ──
        var fileInput = document.getElementById('dop-consent-doc');
        var fileNameEl = document.getElementById('dop-file-name');
        if (fileInput && fileNameEl) {
            fileInput.addEventListener('change', function () {
                var f = this.files && this.files[0];
                if (f) {
                    var sizeKb = (f.size / 1024).toFixed(0);
                    fileNameEl.textContent = f.name + ' · ' + sizeKb + ' KB';
                    fileNameEl.classList.add('has-file');
                } else {
                    fileNameEl.textContent = '未选择文件（最大 10 MB）';
                    fileNameEl.classList.remove('has-file');
                }
            });
        }

        // ── Expire date hint ──
        var durationSel = document.getElementById('dop-duration');
        var expireDateEl = document.getElementById('dop-expire-date');
        function updateExpire() {
            if (!durationSel || !expireDateEl) return;
            var months = parseInt(durationSel.value || '0', 10);
            if (!months) { expireDateEl.textContent = '—'; return; }
            var d = new Date();
            d.setMonth(d.getMonth() + months);
            var y = d.getFullYear();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            expireDateEl.textContent = y + '-' + m + '-' + day;
        }
        if (durationSel) durationSel.addEventListener('change', updateExpire);
        updateExpire();

        // ── Default consent_signed_at to today if empty ──
        var consentDate = document.querySelector('input[name="consent_signed_at"]');
        if (consentDate && !consentDate.value) {
            var t = new Date();
            consentDate.value = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
        }

        // ── Submit guard: must select a source user (when picker present) ──
        var form = document.getElementById('dop-create-form');
        if (form && sourceIdInput) {
            form.addEventListener('submit', function (e) {
                if (!sourceIdInput.value) {
                    e.preventDefault();
                    alert('请先在列表中选择一位源员工');
                    if (pickerSearch) pickerSearch.focus();
                }
            });
        }
    })();
    </script>
@endsection
