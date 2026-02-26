{@if $logged_in}Welcome, {$username}!{@else}Please log in.{/if}
Role: {@if $role="admin"}Administrator{@else}Member ({$role}){/if}