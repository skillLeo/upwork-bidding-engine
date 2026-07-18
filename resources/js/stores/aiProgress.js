import { reactive } from "vue";

// Global (module-level) state for the bottom-right AI progress toast, so
// the widget lives at the app root and keeps ticking while the user
// navigates and keeps working — regeneration is never a blocking modal.
export const aiProgress = reactive({ task: null });

let timer = null;

export function startAiTask(label, expectedMs) {
  clearInterval(timer);
  aiProgress.task = {
    label,
    expectedMs,
    startedAt: Date.now(),
    progress: 4,
    remaining: Math.ceil(expectedMs / 1000),
    done: false,
  };

  timer = setInterval(() => {
    const t = aiProgress.task;
    if (!t || t.done) return;
    const elapsed = Date.now() - t.startedAt;
    // Ease toward 94% and hold — the real completion snaps it to 100 so
    // the bar never lies about being finished.
    t.progress = Math.min(94, 4 + (elapsed / t.expectedMs) * 90);
    t.remaining = Math.max(1, Math.ceil((t.expectedMs - elapsed) / 1000));
  }, 150);
}

export function finishAiTask() {
  clearInterval(timer);
  const t = aiProgress.task;
  if (!t) return;
  t.progress = 100;
  t.done = true;
  setTimeout(() => {
    if (aiProgress.task === t) aiProgress.task = null;
  }, 900);
}

export function failAiTask() {
  clearInterval(timer);
  aiProgress.task = null;
}
