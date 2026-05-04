<div class="admin-perm-groups">
    @foreach($permissionGroups as $groupName => $permissions)
        <div class="admin-perm-group">
            <div class="admin-perm-title">{{ $groupName }}</div>
            <div class="admin-perm-list">
                @foreach($permissions as $permission)
                    @php $key = $permission['key']; @endphp
                    <label class="admin-perm-item">
                        <input type="checkbox" name="permissions[]" value="{{ $key }}" {{ in_array($key, $selectedPermissionKeys ?? [], true) ? 'checked' : '' }}>
                        <span>
                            {{ $permission['label'] }}
                            <small>{{ $permission['description'] ?? $key }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
