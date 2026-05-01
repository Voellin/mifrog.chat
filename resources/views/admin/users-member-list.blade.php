<h3 class="pro-card-title">
    成员列表
    @if($selectedDepartmentId > 0)
        @php
            $selectedDeptName = collect($departmentRows)->firstWhere('id', $selectedDepartmentId)['name'] ?? '';
        @endphp
        @if($selectedDeptName)
            <span class="pro-muted" style="font-size:13px; font-weight:400; margin-left:8px;">— {{ $selectedDeptName }}</span>
        @endif
    @endif
</h3>
@if($users->isEmpty())
    <div class="pro-empty">没有匹配到成员数据。</div>
@else
    <div class="pro-table-wrap">
        <table style="table-layout:fixed; width:100%; min-width:100%;">
            <colgroup>
                <col style="width:6%;">
                <col style="width:18%;">
                <col style="width:28%;">
                <col style="width:18%;">
                <col style="width:12%;">
                <col style="width:18%;">
            </colgroup>
            <thead>
            <tr>
                <th>ID</th>
                <th>姓名</th>
                <th>部门</th>
                <th>职位</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            @foreach($users as $user)
                @php
                    $extra = is_array($user->identity_extra ?? null) ? $user->identity_extra : [];
                @endphp
                <tr>
                    <td>{{ $user->id }}</td>
                    <td>{{ $user->display_name }}</td>
                    <td>{{ $user->department->name ?? '未分配' }}</td>
                    <td>{{ $user->title ?: '-' }}</td>
                    <td>
                        <span class="pro-tag {{ $user->is_active ? 'pro-tag-success' : '' }}">{{ $user->is_active ? '启用' : '停用' }}</span>
                    </td>
                    <td>
                        <a class="pro-btn pro-btn-sm" href="/admin/users/{{ $user->id }}">查看详情</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="pagination">
        {{ $users->links() }}
    </div>
@endif

