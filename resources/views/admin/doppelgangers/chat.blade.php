@extends('admin.layout')

@section('title', '与数字分身对话 - ' . $dop->display_name)
@section('header-title', $dop->display_name)
@section('header-subtitle', '统一对话框 · 三种模式自动切换')
@section('page-title', '与 ' . ($dop->sourceUser?->name ?? '分身') . ' 的数字分身对话')

@section('content')
<style>
.dop-chat-layout { display:grid; grid-template-columns: 280px 1fr; gap:16px; height: calc(100vh - 220px); min-height: 520px; }
.dop-side { background: #fff; border:1px solid var(--pro-border); border-radius: 10px; padding:18px; overflow-y:auto; }
.dop-side h4 { margin:0 0 8px; font-size:13px; color:#62666d; text-transform:uppercase; letter-spacing:.4px; }
.dop-side .wf-item { padding:10px 12px; background:#f5faf8; border:1px solid #e8ede8; border-radius:8px; margin-bottom:6px; cursor:pointer; transition:all .15s; font-size:13px; }
.dop-side .wf-item:hover { background:#e6f0ed; }
.dop-main { background: #fff; border:1px solid var(--pro-border); border-radius: 10px; display:flex; flex-direction:column; }
.dop-mode-bar { padding:12px 16px; border-bottom:1px solid var(--pro-border); display:flex; gap:6px; }
.dop-mode-btn { padding:6px 12px; border-radius:6px; background:transparent; border:1px solid transparent; cursor:pointer; font-size:13px; color:#62666d; }
.dop-mode-btn.active { background:rgba(94,106,210,0.1); border-color:rgba(94,106,210,0.3); color:#5e6ad2; font-weight:500; }
.dop-msgs { flex:1; padding:20px; overflow-y:auto; background:#fafbfc; }
.dop-msg { margin-bottom:16px; max-width:85%; }
.dop-msg.user { margin-left:auto; }
.dop-msg.bot { margin-right:auto; }
.dop-msg-bubble { padding:12px 16px; border-radius:12px; font-size:14px; line-height:1.6; word-break:break-word; }
.dop-msg.user .dop-msg-bubble { background:#5e6ad2; color:#fff; }
.dop-msg.bot .dop-msg-bubble { background:#fff; border:1px solid var(--pro-border); color:#1f2937; white-space:pre-wrap; }
.dop-msg.bot .sources { margin-top:8px; font-size:12px; color:#8a8f98; }
.dop-msg.bot .sources b { color:#62666d; }
.dop-meta { font-size:11px; color:#8a8f98; margin-top:4px; }
.dop-input-bar { padding:14px 16px; border-top:1px solid var(--pro-border); display:flex; gap:8px; }
.dop-input-bar textarea { flex:1; padding:10px 12px; border-radius:8px; border:1px solid var(--pro-border); resize:vertical; min-height:48px; max-height:160px; font-family:inherit; font-size:14px; }
.dop-input-bar button { padding:10px 18px; border-radius:8px; background:#5e6ad2; color:#fff; border:0; cursor:pointer; font-weight:500; }
.dop-input-bar button:disabled { opacity:.5; cursor:not-allowed; }
.dop-spinner { display:inline-block; width:14px; height:14px; border:2px solid #d0d6e0; border-top-color:#5e6ad2; border-radius:50%; animation:dop-spin .7s linear infinite; vertical-align:-2px; margin-right:6px; }
@keyframes dop-spin { to { transform: rotate(360deg); } }
.dop-hint { padding:14px; background:#fff7ed; border-radius:8px; font-size:13px; color:#9a3412; margin-bottom:12px; }
</style>

<div class="dop-chat-layout">
    <!-- 左侧：分身信息 + 工作流 -->
    <aside class="dop-side">
        <h4>分身信息</h4>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
            <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#5e6ad2,#828fff);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">
                {{ mb_substr($dop->sourceUser?->name ?? 'U', 0, 1) }}
            </div>
            <div>
                <div style="font-weight:600;">{{ $dop->display_name }}</div>
                <div style="font-size:12px;color:#8a8f98;">{{ $dop->sourceUser?->name ?? '#'.$dop->source_user_id }}</div>
            </div>
        </div>
        <div style="font-size:12px;color:#62666d;line-height:1.6;margin-bottom:18px;">
            <div>📅 到期：{{ $dop->expires_at?->format('Y-m-d') ?? '永久' }}</div>
            <div>📊 累计调用：{{ $recentInvocations->count() }} 条</div>
        </div>

        <h4>识别到的工作流（{{ $workflows->count() }}）</h4>
        @forelse($workflows as $wf)
            <div class="wf-item" onclick="loadWorkflow({{ $wf->id }})">{{ $wf->workflow_name }}</div>
        @empty
            <div style="font-size:12px;color:#8a8f98;">暂未识别到周期性工作流</div>
        @endforelse
    </aside>

    <!-- 右侧：对话 -->
    <main class="dop-main">
        <div class="dop-mode-bar">
            <button class="dop-mode-btn active" data-mode="ask">📚 知识问答</button>
            <button class="dop-mode-btn" data-mode="draft">✍️ 起草回复</button>
        </div>
        <div class="dop-msgs" id="msgs">
            <div class="dop-hint">
                ⚠️ 数字分身基于 {{ $dop->sourceUser?->name ?? '该员工' }} 的历史飞书数据生成。回答仅供参考，不代表本人当前观点；起草的回复请审核后再发送。
            </div>
        </div>
        <div class="dop-input-bar">
            <textarea id="input" placeholder="问点什么...（如『他过去怎么处理客户退款流程？』 或切到「起草回复」让分身按其语气写）"></textarea>
            <button id="send" onclick="send()">发送</button>
        </div>
    </main>
</div>

<script>
const DOP_ID = {{ $dop->id }};
const URL_ASK = "{{ route('admin.doppelgangers.ask', $dop->id) }}";
const URL_DRAFT = "{{ route('admin.doppelgangers.draft', $dop->id) }}";
const URL_WF = "/admin/doppelgangers/{{ $dop->id }}/workflow/";
const CSRF = "{{ csrf_token() }}";

let mode = 'ask';
document.querySelectorAll('.dop-mode-btn').forEach(b => {
    b.addEventListener('click', () => {
        document.querySelectorAll('.dop-mode-btn').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        mode = b.dataset.mode;
    });
});

function append(role, text, extra='') {
    const m = document.getElementById('msgs');
    const div = document.createElement('div');
    div.className = 'dop-msg ' + role;
    div.innerHTML = '<div class="dop-msg-bubble">' + escapeHtml(text) + '</div>' + extra;
    m.appendChild(div);
    m.scrollTop = m.scrollHeight;
}
function escapeHtml(s) { return s.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

function send() {
    const input = document.getElementById('input');
    const text = input.value.trim();
    if (!text) return;
    input.value = '';

    const sendBtn = document.getElementById('send');
    sendBtn.disabled = true;
    append('user', text);
    const loadingId = 'load-' + Date.now();
    document.getElementById('msgs').insertAdjacentHTML('beforeend',
        '<div class="dop-msg bot" id="' + loadingId + '"><div class="dop-msg-bubble"><span class="dop-spinner"></span>分身思考中…</div></div>');

    const url = mode === 'ask' ? URL_ASK : URL_DRAFT;
    const body = mode === 'ask' ? {query: text} : {situation: text};

    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json'},
        body: JSON.stringify(body)
    }).then(r => r.json()).then(data => {
        document.getElementById(loadingId)?.remove();
        if (!data.ok) {
            append('bot', '❌ ' + (data.error || '调用失败'));
        } else if (mode === 'ask') {
            const sources = (data.sources || []).map(s =>
                s.type === 'attachment' ? `📄 ${s.name}` : `🧠 [${s.layer}] ${s.title || s.date || ''}`
            ).join('  ·  ');
            const meta = `<div class="sources"><b>来源</b>　${sources || '（无引用源）'}</div>` +
                `<div class="dop-meta">tokens: in ${data.tokens.input} / out ${data.tokens.output}</div>`;
            append('bot', data.answer, meta);
        } else {
            const meta = `<div class="dop-meta">用了 ${data.samples_used} 条历史样本　·　tokens: in ${data.tokens.input} / out ${data.tokens.output}</div>`;
            append('bot', data.draft, meta);
        }
        sendBtn.disabled = false;
    }).catch(err => {
        document.getElementById(loadingId)?.remove();
        append('bot', '❌ 网络错误：' + err.message);
        sendBtn.disabled = false;
    });
}

function loadWorkflow(id) {
    fetch(URL_WF + id + '/preview', {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json'}
    }).then(r => r.json()).then(data => {
        if (data.ok) append('bot', data.preview.body);
    });
}

document.getElementById('input').addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); send(); }
});
</script>
@endsection
