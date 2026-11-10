<script setup lang="ts">
/** Illustration décorative du site (bâtiment et palmiers) : purement visuelle, masquée aux lecteurs d'écran. */
defineProps<{ width?: number }>();
</script>
<template>
  <svg :width="width ?? 160" :height="(width ?? 160) * 0.62" viewBox="0 0 160 100" fill="none" aria-hidden="true" focusable="false">
    <ellipse cx="80" cy="92" rx="76" ry="7" fill="#fff" opacity=".12" />
    <path d="M44 88V34l36-14 36 14v54z" fill="#e8f1fb" />
    <path d="M44 34l36-14 36 14-36 10z" fill="#bcd6f0" />
    <path d="M52 44h56v44H52z" fill="#2f78c4" />
    <g fill="#9fcdf5" opacity=".85"><path d="M56 48h10v8H56zM70 48h10v8H70zM84 48h10v8H84zM98 48h8v8H98zM56 60h10v8H56zM70 60h10v8H70zM84 60h10v8H84zM98 60h8v8H98zM56 72h10v8H56zM84 72h10v8H84zM98 72h8v8H98z" /></g>
    <path d="M70 88V74h14v14z" fill="#0b3c6b" />
    <g stroke="#7a4f2b" stroke-width="3" stroke-linecap="round"><path d="M22 90V62" /><path d="M140 90V58" /></g>
    <g fill="#2e9b5a"><path d="M22 62c-10-2-16-8-18-14 8 0 16 4 18 14zM22 62c10-2 16-8 18-14-8 0-16 4-18 14zM22 62c-4-9-3-16 0-22 3 6 4 13 0 22z" /><path d="M140 58c-10-2-16-8-18-14 8 0 16 4 18 14zM140 58c10-2 16-8 18-14-8 0-16 4-18 14zM140 58c-4-9-3-16 0-22 3 6 4 13 0 22z" /></g>
  </svg>
</template>
