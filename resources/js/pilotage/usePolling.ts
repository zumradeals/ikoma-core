import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Mise à jour automatique par rechargements partiels Inertia (aucune technologie temps réel) : seules les propriétés demandées sont recalculées.
 * Elle s'arrête quand l'onglet est caché et reprend, avec un rechargement immédiat, au retour.
 */
export function usePolling(only: string[], intervalMs = 3000) {
  let timer: number | undefined;
  let loading = false;
  function reload() {
    if (loading || document.hidden) return;
    loading = true;
    router.reload({ only, onFinish: () => { loading = false; } });
  }
  function onVisibility() { if (document.hidden) stop(); else { reload(); start(); } }
  function start() { stop(); timer = window.setInterval(reload, intervalMs); }
  function stop() { if (timer !== undefined) { window.clearInterval(timer); timer = undefined; } }
  onMounted(() => { document.addEventListener('visibilitychange', onVisibility); if (!document.hidden) start(); });
  onBeforeUnmount(() => { document.removeEventListener('visibilitychange', onVisibility); stop(); });
  return { reload };
}
