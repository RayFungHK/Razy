<h3>{$dashboard_title}</h3>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px">
<!-- START BLOCK: card -->
<div style="padding:16px;border:1px solid #e0e0e0;border-radius:8px;background:#fafafa">
    <div style="font-size:24px;margin-bottom:4px">{$icon}</div>
    <div style="font-size:13px;color:#888;text-transform:uppercase">{$title}</div>
    <div style="font-size:28px;font-weight:bold;margin:4px 0">{$value}</div>
    <div style="font-size:12px">{@if $trend="up"}📈 <span style="color:#28a745">Trending up</span>{@else}{@if $trend="down"}📉 <span style="color:#dc3545">Trending down</span>{@else}➡️ <span style="color:#6c757d">Stable</span>{/if}{/if}</div>
</div>
<!-- END BLOCK: card -->
</div>