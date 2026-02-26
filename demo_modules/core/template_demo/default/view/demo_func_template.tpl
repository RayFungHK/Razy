<!-- TEMPLATE BLOCK: badge -->
<span style="display:inline-block;padding:4px 10px;margin:2px;border:1px solid #ccc;border-radius:12px">{$icon} {$title}</span>
<!-- END BLOCK: badge -->

{@each source=$cards as="c"}{@template:badge icon=$c.value.icon title=$c.value.title}{/each}