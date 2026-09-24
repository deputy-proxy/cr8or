<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return (new EnterprisePolicy)->view($user, $task->enterprise);
    }

    public function create(User $user): bool
    {
        return (new EnterprisePolicy)->create($user);
    }

    public function createForEnterprise(User $user, Enterprise $enterprise): bool
    {
        return (new EnterprisePolicy)->createForOrganization($user, $enterprise->organization);
    }

    public function update(User $user, Task $task): bool
    {
        return (new EnterprisePolicy)->update($user, $task->enterprise);
    }

    public function delete(User $user, Task $task): bool
    {
        return (new EnterprisePolicy)->delete($user, $task->enterprise);
    }
}