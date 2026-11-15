<?php
declare(strict_types=1);
namespace HMD;

final class Audit
{
    public static function log(string $action, ?string $entity = null, mixed $entityId = null, mixed $old = null, mixed $new = null, ?array $actor = null): void
    {
        $actor ??= Auth::user() ? ['type' => 'admin', 'id' => Auth::user()['user_id'], 'name' => Auth::user()['name']] : ['type' => 'system', 'id' => null, 'name' => 'System'];
        Db::insert('hm_audit_log', [
            'actor_type' => $actor['type'], 'actor_id' => $actor['id'], 'actor_name' => mb_substr((string)$actor['name'], 0, 100),
            'action' => $action, 'entity' => $entity, 'entity_id' => $entityId !== null ? (string)$entityId : null,
            'old_value' => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_value' => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'created_at' => Fmt::nowUtc(),
        ]);
    }
}
