<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $user)
    {
        parent::__construct($user);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if ($type = Arr::get($filters, 'user_type')) {
            $query->where('user_type', $type);
        }

        if ($firstname = Arr::get($filters, 'firstname')) {
            $query->where('firstname', 'LIKE', "%{$firstname}%");
        }

        if ($lastname = Arr::get($filters, 'lastname')) {
            $query->where('lastname', 'LIKE', "%{$lastname}%");
        }

        if ($middlename = Arr::get($filters, 'middlename')) {
            $query->where('middlename', 'LIKE', "%{$middlename}%");
        }

        if ($fullName = Arr::get($filters, 'name')) {
            $kw = "%{$fullName}%";
            $query->whereRaw("CONCAT_WS(' ', firstname, middlename, lastname) LIKE ?", [$kw]);
        }

        if ($email = Arr::get($filters, 'email')) {
            $query->where('email', 'LIKE', "%{$email}%");
        }

        if ($position = Arr::get($filters, 'position')) {
            $query->where('position', 'LIKE', "%{$position}%");
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findByEmail(string $email)
    {
        return $this->model->where('email', $email)->first();
    }
}

