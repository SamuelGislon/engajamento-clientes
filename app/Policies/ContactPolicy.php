<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleIn(app(TenantContext::class)->id()) !== null;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->viewAny($user)
            && Contact::query()->visibleTo($user)->whereKey($contact->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->view($user, $contact);
    }
}
