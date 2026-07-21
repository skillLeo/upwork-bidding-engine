import { reactive } from "vue";
import { apiClient } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

// Browser-push failover for WhatsApp, scoped to "the dashboard is open in
// some tab" (the Notification API needs no service worker or VAPID keys
// for that case — those only matter for waking a fully closed browser,
// which isn't what this replaces). Module-level state, same pattern as
// stores/aiProgress.js, so it lives at the app root and keeps polling
// while the user navigates instead of restarting per page.
export const leadAlerts = reactive({ enabled: false, supported: typeof Notification !== "undefined" });

const STORAGE_KEY = "skillleo:browser-alerts-enabled";
const POLL_INTERVAL_MS = 20_000;
const MIN_SCORE = 8;
const POSTED_WITHIN_MINUTES = 180;

let timer = null;
let seenIds = null;

async function poll() {
  // Never poll while signed out - an unauthenticated background poll would
  // 401 and (before this) could contribute to a spurious logout.
  if (!useAuthStore().token) return;

  let leads;
  try {
    const res = await apiClient.get("/leads", {
      params: {
        status: "ready",
        score_min: MIN_SCORE,
        posted_within_minutes: POSTED_WITHIN_MINUTES,
        sort: "-posted_at",
        per_page: 10,
      },
    });
    leads = res.data.data;
  } catch {
    return; // Best-effort — a failed poll just waits for the next tick.
  }

  // First successful poll seeds the "already seen" set without firing —
  // otherwise every lead already on the board fires a notification the
  // instant alerts are turned on.
  if (seenIds === null) {
    seenIds = new Set(leads.map((lead) => lead.id));
    return;
  }

  for (const lead of leads) {
    if (seenIds.has(lead.id)) continue;
    seenIds.add(lead.id);
    fire(lead);
  }
}

function fire(lead) {
  const notification = new Notification(`Fresh ${lead.score}/10 lead`, {
    body: lead.title,
    tag: `lead-${lead.id}`,
    icon: "/favicon.svg",
  });
  notification.onclick = () => {
    window.focus();
    window.location.href = `/leads/${lead.id}`;
  };
}

export async function enableLeadAlerts() {
  if (!leadAlerts.supported) return false;

  const permission = Notification.permission === "granted" ? "granted" : await Notification.requestPermission();

  if (permission !== "granted") return false;

  leadAlerts.enabled = true;
  localStorage.setItem(STORAGE_KEY, "1");
  startPolling();
  return true;
}

export function disableLeadAlerts() {
  leadAlerts.enabled = false;
  localStorage.removeItem(STORAGE_KEY);
  stopPolling();
}

// Fire a notification RIGHT NOW on this device, so the user can confirm on
// their phone (open the app in Chrome, tap the button) that notifications
// actually appear here. Requests permission first if needed. Returns a reason
// the UI can explain, since a mobile browser can silently deny.
export async function sendTestNotification() {
  if (!leadAlerts.supported) return { ok: false, reason: "unsupported" };

  const permission =
    Notification.permission === "granted" ? "granted" : await Notification.requestPermission();

  if (permission !== "granted") {
    return { ok: false, reason: permission === "denied" ? "denied" : "dismissed" };
  }

  const notification = new Notification("SkillLeo test alert ✅", {
    body: "If you can see this on your phone, real-time lead alerts work on this device.",
    tag: "skillleo-test",
    icon: "/favicon.svg",
  });
  notification.onclick = () => window.focus();

  return { ok: true };
}

function startPolling() {
  stopPolling();
  seenIds = null;
  poll();
  timer = setInterval(poll, POLL_INTERVAL_MS);
}

function stopPolling() {
  clearInterval(timer);
  timer = null;
}

// Resume automatically on every page load if the user opted in before and
// permission is still granted — no re-clicking the toggle every session.
export function initLeadAlerts() {
  if (!leadAlerts.supported) return;
  if (localStorage.getItem(STORAGE_KEY) === "1" && Notification.permission === "granted") {
    leadAlerts.enabled = true;
    startPolling();
  }
}
