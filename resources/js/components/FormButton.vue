<script setup lang="ts">withDefaults(defineProps<{ busy?: boolean }>(), { busy: false });</script>
<template><button type="submit" :disabled="busy" class="min-h-14 rounded-xl bg-ikoma-blue px-6 py-3 font-semibold text-white disabled:opacity-60"><slot /></button></template>
