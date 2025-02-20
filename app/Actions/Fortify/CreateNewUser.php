<?php

namespace App\Actions\Fortify;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Account;
use App\Models\Location;
use Laravel\Jetstream\Jetstream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Create a newly registered user.
     *
     * @param  array  $input
     * @return \App\Models\User
     */
    public function create(array $input)
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => $this->passwordRules(),
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['required', 'accepted'] : '',
        ])->validate();

        return DB::transaction(function () use ($input) {
            return tap(User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'date_of_employment' => Carbon::today()
            ]), function (User $user) {
                $this->createAccount($user);
                $location = $this->createLocation($user);
                $this->createDefaultAbsentTypes($user, $location);
                $this->createDefaultTargetHours($user);
            });
        });
    }

    /**
     * Create the default Location.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function createAccount(User $user)
    {
        $account = Account::forceCreate([
            'owned_by' => $user->id,
            'name' => explode(' ', $user->name, 2)[0]."'s Account",
        ]);

        $user->ownedAccount()->save($account);

        $user->account()->associate($account)->save();
    }

    /**
     * Create the default Location.
     *
     * @param  \App\Models\User  $user
     * @return Location
     */
    public function createLocation(User $user)
    {
        $location = new Location([
            'owned_by' => $user->id,
            'name' => explode(' ', $user->name, 2)[0]."'s Location",
            'locale' => config('app.locale'),
            'time_zone' => config('app.timezone')
        ]);

        //associate location to user account
        $user->ownedAccount->locations()->save($location);

        //associate new user to current location
        $user->switchLocation($location);

        return $location;
    }

    protected function createDefaultAbsentTypes(User $user, Location $location)
    {
        $newAbsentTypes = $location->absentTypes()->createMany([
            [
                'title' => 'Krankheit',
                'affect_vacation_times' => false,
                'affect_evaluations' => true,
                'evaluation_calculation_setting' => 'absent_to_target'
            ],
            [
                'title' => 'Urlaub',
                'affect_vacation_times' => true,
                'affect_evaluations' => true,
                'evaluation_calculation_setting' => 'absent_to_target'
            ],
            [
                'title' => 'Überstundenabbau',
                'affect_evaluations' => false,
                'affect_vacation_times' => false
            ],
            [
                'title' => 'Wunschfrei',
                'affect_evaluations' => false,
                'affect_vacation_times' => false
            ]
        ]);

        $user->absenceTypes()->sync($newAbsentTypes);
    }

    public function createDefaultTargetHours($user)
    {
        $user->targetHours()->create([
            "start_date" =>  Carbon::today(),
            "hours_per" => "week",
            "target_hours" => 40,
            "target_limited" => false,
            "is_mon" => true,
            "mon" => 8,
            "is_tue" => true,
            "tue" => 8,
            "is_wed" => true,
            "wed" => 8,
            "is_thu" => true,
            "thu" => 8,
            "is_fri" => true,
            "fri" => 8,
            "is_sat" => false,
            "sat" => 0,
            "is_sun" => false,
            "sun" => 0
        ]);
    }
}
