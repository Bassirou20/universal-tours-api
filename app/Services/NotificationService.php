<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class NotificationService
{
    /**
     * Notifie tous les utilisateurs actifs.
     */
    public static function notifyAll(string $type, string $title, ?string $body = null, ?string $url = null, ?array $data = null): void
    {
        $userIds = User::query()
            ->when(Schema::hasColumn('users', 'actif'), fn ($q) => $q->where('actif', 1))
            ->pluck('id');

        self::createMany($userIds->all(), $type, $title, $body, $url, $data);
    }

    /**
     * Notifie uniquement les administrateurs.
     */
    public static function notifyAdmins(string $type, string $title, ?string $body = null, ?string $url = null, ?array $data = null): void
    {
        $userIds = User::query()->where('role', 'admin')->pluck('id');
        self::createMany($userIds->all(), $type, $title, $body, $url, $data);
    }

    /**
     * Notifie un utilisateur précis.
     */
    public static function notifyUser(int $userId, string $type, string $title, ?string $body = null, ?string $url = null, ?array $data = null): void
    {
        self::createMany([$userId], $type, $title, $body, $url, $data);
    }

    /**
     * Insertion bulk pour limiter les requêtes.
     */
    protected static function createMany(array $userIds, string $type, string $title, ?string $body, ?string $url, ?array $data): void
    {
        if (empty($userIds)) return;

        $now = now();
        $rows = collect($userIds)->map(fn ($uid) => [
            'user_id'    => $uid,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'url'        => $url,
            'data'       => $data ? json_encode($data) : null,
            'read_at'    => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        Notification::insert($rows);
    }
}
