import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const KEY = 'ikoma.son-alertes';
const REMINDER_MS = 60_000;

/**
 * Alerte sonore et vibrante du superviseur (audit global C1). Aucun canal externe : le son ne joue que dans l'application ouverte.
 * Les navigateurs interdisent un son sans geste de l'utilisateur : le superviseur l'active une fois par un bouton, et son choix est gardé.
 * Un nouveau cas sonne ; tant qu'une alerte critique reste ouverte, un rappel sonne chaque minute.
 */
export function useAlertSound(open: () => number, critical: () => number) {
  const enabled = ref(false);
  let context: AudioContext | null = null;
  let reminder: number | undefined;

  const read = (): boolean => { try { return window.localStorage.getItem(KEY) === '1'; } catch { return false; } };
  const write = (on: boolean) => { try { window.localStorage.setItem(KEY, on ? '1' : '0'); } catch { /* le choix vaut pour cette session */ } };

  function beep(times: number, hz: number) {
    try {
      context ??= new AudioContext();
      void context.resume();
      for (let i = 0; i < times; i++) {
        const osc = context.createOscillator(); const gain = context.createGain();
        const start = context.currentTime + i * 0.35;
        osc.frequency.value = hz; gain.gain.setValueAtTime(0.0001, start); gain.gain.exponentialRampToValueAtTime(0.4, start + 0.02); gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.25);
        osc.connect(gain); gain.connect(context.destination); osc.start(start); osc.stop(start + 0.3);
      }
    } catch { /* pas de son possible ici : la vibration et l'écran restent */ }
    try { navigator.vibrate?.(times > 1 ? [250, 120, 250, 120, 250] : [250]); } catch { /* sans vibration */ }
  }

  function toggle() {
    enabled.value = !enabled.value; write(enabled.value);
    if (enabled.value) beep(1, 660); // confirme l'activation : c'est aussi le geste qui autorise le son
  }

  watch([open, critical], ([count, crit], [before, critBefore]) => {
    if (!enabled.value || count <= before) return;
    if (crit > critBefore) beep(3, 1000); else beep(1, 740);
  });
  onMounted(() => {
    enabled.value = read();
    reminder = window.setInterval(() => { if (enabled.value && critical() > 0 && !document.hidden) beep(2, 1000); }, REMINDER_MS);
  });
  onBeforeUnmount(() => { window.clearInterval(reminder); void context?.close().catch(() => undefined); });

  return { enabled, toggle };
}
