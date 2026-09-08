<?php

namespace App\Domain\Messages;

use App\Models\ClientContact;
use App\Models\Executor;
use App\Support\EmailAddress;

/**
 * Resolves a directory person (client contact or executor) by message sender email.
 */
class ResolveMessageSenderPerson
{
    /**
     * @return array{type: 'contact'|'executor', id: int, name: string, route: string, label: string}|null
     */
    public function resolve(?string $sender): ?array
    {
        $email = EmailAddress::extract($sender);
        if ($email === null) {
            return null;
        }

        $contact = $this->findContact($email);
        if ($contact !== null) {
            return [
                'type' => 'contact',
                'id' => $contact->id,
                'name' => $contact->fullName(),
                'route' => route('platform.client-contacts.edit', $contact),
                'label' => 'Редактировать контакт',
            ];
        }

        $executor = $this->findExecutor($email);
        if ($executor !== null) {
            return [
                'type' => 'executor',
                'id' => $executor->id,
                'name' => $executor->fullName(),
                'route' => route('platform.executors.edit', $executor),
                'label' => 'Редактировать исполнителя',
            ];
        }

        return null;
    }

    private function findContact(string $email): ?ClientContact
    {
        return ClientContact::query()
            ->get()
            ->first(function (ClientContact $contact) use ($email): bool {
                return in_array($email, EmailAddress::normalizeList($contact->emails), true);
            });
    }

    private function findExecutor(string $email): ?Executor
    {
        return Executor::query()
            ->get()
            ->first(function (Executor $executor) use ($email): bool {
                return in_array($email, EmailAddress::normalizeList($executor->emails), true);
            });
    }
}
