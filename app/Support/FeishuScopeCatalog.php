<?php

namespace App\Support;

class FeishuScopeCatalog
{
    public const CAPABILITY_SCOPE_PREFIX = 'feishu.scope.';

    /**
     * Base scopes for user-level chat/message sensing.
     *
     * @return array<int,string>
     */
    public function requiredOauthScopes(): array
    {
        return [
            'search:message',
            'im:message:readonly',
            'im:chat:readonly',
            'im:message.group_msg:get_as_user',
            'im:message.p2p_msg:get_as_user',
            'offline_access',
        ];
    }

    /**
     * @param  array<int,string>  $scopes
     * @return array<int,string>
     */
    public function normalizeScopes(array $scopes): array
    {
        $set = [];
        foreach ($scopes as $scope) {
            $normalized = $this->normalizeScope((string) $scope);
            if ($normalized !== '') {
                $set[$normalized] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * @return array<int,string>
     */
    public function parseScopeString(string $scopeText): array
    {
        $parts = preg_split('/[\s,]+/', trim($scopeText), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $this->normalizeScopes(array_values($parts));
    }

    public function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        $scope = preg_replace('/<[^>]*>/', '', $scope) ?? $scope;
        $scope = preg_replace('/[^a-z0-9:._-]/', '', $scope) ?? $scope;
        if ($scope === '') {
            return '';
        }

        return match ($scope) {
            'docs:document:readonly',
            'docs:document.content:read' => 'docx:document:readonly',
            'docs:document:create',
            'docx:document:create',
            'docx:document.create',
            'docs:document.create',
            'drive:drive.file:create',
            'drive:drive.file.create' => 'docx:document',
            // Compatibility alias: some intent outputs use meeting scope wording.
            'meeting:meeting:create' => 'calendar:calendar.event:create',
            'im:message.group_msg' => 'im:message.group_msg:get_as_user',
            'im:message.p2p_msg' => 'im:message.p2p_msg:get_as_user',
            default => $scope,
        };
    }

    public function normalizeCapability(string $capability): string
    {
        $capability = strtolower(trim($capability));
        $capability = preg_replace('/<[^>]*>/', '', $capability) ?? $capability;
        $capability = preg_replace('/[^a-z0-9:._-]/', '', $capability) ?? $capability;
        if ($capability === '') {
            return '';
        }

        if (str_starts_with($capability, self::CAPABILITY_SCOPE_PREFIX)) {
            $scope = substr($capability, strlen(self::CAPABILITY_SCOPE_PREFIX));
            $scope = $this->normalizeScope((string) $scope);

            return $scope !== '' ? self::CAPABILITY_SCOPE_PREFIX.$scope : '';
        }

        return match ($capability) {
            'feishu.scope.calendar' => 'feishu.scope.calendar:calendar.event:create',
            'feishu.scope.meeting:meeting:create' => 'feishu.scope.calendar:calendar.event:create',
            default => $capability,
        };
    }

    /**
     * @param  array<int,string>  $capabilities
     * @return array<int,string>
     */
    public function normalizeCapabilities(array $capabilities): array
    {
        $set = [];
        foreach ($capabilities as $capability) {
            $normalized = $this->normalizeCapability((string) $capability);
            if ($normalized !== '') {
                $set[$normalized] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * @param  array<int,string>  ...$groups
     * @return array<int,string>
     */
    public function mergeCapabilities(array ...$groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            foreach ($group as $capability) {
                $merged[] = (string) $capability;
            }
        }

        return $this->normalizeCapabilities($merged);
    }

    public function scopeFromCapability(string $capability): ?string
    {
        $normalized = $this->normalizeCapability($capability);
        if (! str_starts_with($normalized, self::CAPABILITY_SCOPE_PREFIX)) {
            return null;
        }

        $scope = substr($normalized, strlen(self::CAPABILITY_SCOPE_PREFIX));

        return $scope !== '' ? $scope : null;
    }

    /**
     * @param  array<int,string>  $capabilities
     * @return array<int,string>
     */
    public function scopesFromCapabilities(array $capabilities, bool $includeBaseScopes = true): array
    {
        $set = [];
        if ($includeBaseScopes) {
            foreach ($this->requiredOauthScopes() as $scope) {
                $set[$scope] = true;
            }
        }

        foreach ($capabilities as $capability) {
            $scope = $this->scopeFromCapability((string) $capability);
            if ($scope !== null) {
                $set[$scope] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * @param  array<int,string>  $grantedScopes
     */
    public function hasScope(array $grantedScopes, string $requiredScope): bool
    {
        $required = $this->normalizeScope($requiredScope);
        if ($required === '') {
            return true;
        }

        $set = [];
        foreach ($grantedScopes as $scope) {
            $normalized = $this->normalizeScope((string) $scope);
            if ($normalized !== '') {
                $set[$normalized] = true;
                foreach ($this->impliedScopes($normalized) as $implied) {
                    $set[$implied] = true;
                }
            }
        }

        if (isset($set[$required])) {
            return true;
        }

        // Prefix match: sheets:spreadsheet is satisfied by sheets:spreadsheet:create etc.
        $prefix = $required . ':';
        foreach (array_keys($set) as $scope) {
            if (str_starts_with($scope, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function impliedScopes(string $grantedScope): array
    {
        return match ($grantedScope) {
            // New doc full permission includes readonly semantics.
            'docx:document' => ['docx:document:readonly'],
            default => [],
        };
    }
}
