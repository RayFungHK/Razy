Colors: {@each source=$colors as="c"}{$c.value->escape}, {/each}

Users:
{@each source=$users as="u"}
  - {$u.value.name->escape} (age {$u.value.age->escape})
{/each}