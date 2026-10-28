<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import PosteApp from '../components/poste/PosteApp.vue';
const page = usePage<{ auth: { name: string } }>();
</script>
<template><PosteApp :agent="page.props.auth.name" /></template>
