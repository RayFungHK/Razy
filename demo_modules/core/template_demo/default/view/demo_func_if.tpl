{@if $logged_in}Welcome, {$username->escape}!{@else}Please log in.{/if}
Role: {@if $role="admin"}Administrator{@else}Member ({$role->escape}){/if}