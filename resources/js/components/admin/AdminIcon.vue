<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ name: string; size?: number; stroke?: number }>(), { size: 22, stroke: 2 });
const icons: Record<string, string[]> = {
  speaker: ['M11 5L6 9H3v6h3l5 4z', 'M15 8a6 6 0 0 1 0 8', 'M18 5a10 10 0 0 1 0 14'],
  speakerOff: ['M11 5L6 9H3v6h3l5 4z', 'M16 9l6 6', 'M22 9l-6 6'],
  camera: ['M3 6h4l2-3h6l2 3h4v15H3z', 'M16 13a4 4 0 1 1-8 0 4 4 0 0 1 8 0'],
  plus: ['M12 4v16', 'M4 12h16'],
  monitor: ['M3 3h18v13H3z', 'M12 16v5', 'M8 21h8'],
  database: ['M21 5c0 2-4 3-9 3S3 7 3 5s4-3 9-3 9 1 9 3z', 'M3 5v14c0 2 4 3 9 3s9-1 9-3V5', 'M3 12c0 2 4 3 9 3s9-1 9-3'],
  home: ['M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z'],
  calendar: ['M3 5h18v17H3z', 'M8 2v6', 'M16 2v6', 'M3 10h18'],
  map: ['M1 6l7-3 8 3 7-3v15l-7 3-8-3-7 3z', 'M8 3v15', 'M16 6v15'],
  tag: ['M20 12l-8 8-9-9V3h8z', 'M7.5 7.5h.01'],
  list: ['M9 11l3 3L22 4', 'M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'],
  qr: ['M3 3h7v7H3z', 'M14 3h7v7h-7z', 'M3 14h7v7H3z', 'M14 14h3v3h-3z', 'M20 14v3', 'M14 20h3', 'M20 20h1'],
  bus: ['M3 4h18v12H3z', 'M7 20h2', 'M15 20h2', 'M3 12h18'],
  tablet: ['M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z', 'M12 18h.01'],
  users: ['M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M1 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1', 'M17 4a4 4 0 0 1 0 8', 'M23 21v-1a6 6 0 0 0-4-5.6'],
  book: ['M4 4h12a4 4 0 0 1 4 4v12H8a4 4 0 0 1-4-4z', 'M8 9h8', 'M8 13h8'],
  shield: ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
  alert: ['M12 3l10 18H2z', 'M12 10v5', 'M12 18h.01'],
  clock: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 7v5l3 2'],
  info: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 11v6', 'M12 7h.01'],
  arrow: ['M5 12h14', 'M13 6l6 6-6 6'],
  check: ['M5 12l5 5L20 7'],
  menu: ['M4 7h16', 'M4 12h16', 'M4 17h16'],
};
const paths = computed(() => icons[props.name] ?? []);
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="stroke" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path v-for="d in paths" :key="d" :d="d" />
  </svg>
</template>
