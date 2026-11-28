<?php

namespace App\Support;

use App\Models\SlipAssignment;
use App\Models\SlipSubmission;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationInbox
{
    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function unreadFor(User $user, int $limit = 50): Collection
    {
        if (! in_array($user->role, ['student', 'guidance_counselor'], true)) {
            return $user->unreadNotifications()
                ->limit($limit)
                ->get();
        }

        return $this->unreadWithoutClientInformationNotifications($user)->take($limit);
    }

    public function unreadCount(User $user): int
    {
        if (! in_array($user->role, ['student', 'guidance_counselor'], true)) {
            return $user->unreadNotifications()->count();
        }

        return $this->unreadWithoutClientInformationNotifications($user)->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function unreadWithoutClientInformationNotifications(User $user): Collection
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
            $message = implode(' ', array_filter([
                $data['title'] ?? null,
                $data['message'] ?? null,
            ], 'is_string'));

            if (Str::contains(Str::lower($message), 'client information form')) {
                return true;
            }

            if (! is_numeric($activityId)) {
                return false;
            }

            return match ($data['activity_type'] ?? null) {
                'slip' => in_array((int) $activityId, $cifAssignmentIds, true)
                    || in_array((int) $activityId, $cifSubmissionIds, true),
                'slip-submitted' => in_array((int) $activityId, $cifSubmissionIds, true),
                default => false,
            };
        });
    }
}
