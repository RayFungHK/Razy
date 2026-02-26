Colors: {@each source=$colors as="c"}{$c.value}, {/each}

Users:
{@each source=$users as="u"}
  - {$u.value.name} (age {$u.value.age})
{/each}