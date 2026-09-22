@props(['subject'])

{{-- A user with no linked employees row has no position in the org tree, so
EmployeeScope is deliberately empty rather than "everyone" — filters and stat
cards would be meaningless over zero rows, so pages replace them entirely with
this explanation instead of a bare "no results" table. --}}
<x-card>
    <x-empty-state
        icon="user-x"
        title="Your account isn't linked to an employee record"
        :description="$subject.' can\'t be scoped to you until an admin links this login to an employee profile. Contact an admin to get this set up.'"
    />
</x-card>
