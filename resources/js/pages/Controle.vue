<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import PosteGate from '../components/poste/PosteGate.vue';
const page = usePage<{ auth: { id: string; name: string } | null }>();
</script>
<template><PosteGate app="controle" :agent="page.props.auth ? { id: page.props.auth.id, name: page.props.auth.name } : null" /></template>
