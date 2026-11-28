<?php

namespace App\Support;

use App\Models\SlipAssignment;
use App\Models\SlipSubmission;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class NotificationInbox
{
    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function unreadFor(User $user, int $limit = 50): Collection
    {
        if ($user->role !== 'guidance_counselor') {
            return $user->unreadNotifications()
                ->limit($limit)
                ->get();
        }

        return $this->guidanceUnreadNotifications($user)->take($limit);
    }

    public function unreadCount(User $user): int
    {
        if ($user->role !== 'guidance_counselor') {
            return $user->unreadNotifications()->count();
        }

        return $this->guidanceUnreadNotifications($user)->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function guidanceUnreadNotifications(User $user): Collection
    {
        $notifications = $user->unreadNotifications;
        $cifAssignmentIds = SlipAssignment::query()
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $cifSubmissionIds = SlipSubmission::query()
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $notifications->reject(static function ($notification) use ($cifAssignmentIds, $cifSubmissionIds): bool {
            $data = $notification->data ?? [];
            $activityId = $data['activity_id'] ?? null;

            if (! is_numeric($activityId)) {
                return false;
            }

            return (
                ($data['activity_type'] ?? null) === 'slip'
                && in_array((int) $activityId, $cifAssignmentIds, true)
            ) || (
                ($data['activity_type'] ?? null) === 'slip-submitted'
                && in_array((int) $activityId, $cifSubmissionIds, true)
            );
        });
    }
}
