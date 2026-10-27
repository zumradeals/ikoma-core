<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'; import BaseLayout from '../layouts/BaseLayout.vue'; import BaseCard from '../components/BaseCard.vue';
const page = usePage<{ auth: { name: string; permissions: string[] } }>();
</script>
<template><BaseLayout><BaseCard><h1 class="mb-4 text-display font-bold">Mon espace</h1><p>Bienvenue {{ page.props.auth.name }}. Les modules métier arriveront dans les prochains lots.</p><nav class="mt-6 flex flex-wrap gap-4"><Link v-if="page.props.auth.permissions.includes('comptes.gerer')" class="action" href="/administration/comptes">Comptes</Link><Link v-if="page.props.auth.permissions.includes('journal.consulter')" class="action" href="/administration/journal">Journal</Link><Link class="action" href="/securite">Sécurité</Link><Link as="button" method="post" href="/logout" class="action">Se déconnecter</Link></nav></BaseCard></BaseLayout></template>
