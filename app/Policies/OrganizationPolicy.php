<?php

namespace App\Policies;

use App\Models\SystemAdmin;
use App\Models\Organization;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrganizationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Models\SystemAdmin|mixed  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny($user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\SystemAdmin|mixed  $user
     * @param  \App\Models\Organization  $model
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view($user, Organization $model)
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \App\Models\SystemAdmin|mixed  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create($user)
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\SystemAdmin|mixed  $user
     * @param  \App\Models\Organization  $model
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update($user, Organization $model)
    {
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\SystemAdmin|mixed  $user
     * @param  \App\Models\Organization  $model
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete($user, Organization $model)
    {
        return true;
    }
}
