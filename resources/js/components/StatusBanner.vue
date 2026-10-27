<script setup lang="ts">
defineProps<{ message: string }>();
</script>
<template><div role="status" class="flex items-center gap-3 rounded-xl border-l-4 border-ikoma-orange bg-orange-50 p-4 text-ikoma-navy"><span aria-hidden="true">ⓘ</span><p>{{ message }}</p></div></template>
