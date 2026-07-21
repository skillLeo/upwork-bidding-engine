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

// Show a notification cross-platform. On mobile Chrome the `new Notification()`
// constructor throws ("Illegal constructor"), so we prefer the service worker's
// showNotification() (the only path that works on mobile) and fall back to the
// constructor on desktop browsers without an active SW.
async function show(title, { body, tag, url }) {
  const options = { body, tag, icon: "/favicon.svg", data: { url } };
  try {
    const reg = await navigator.serviceWorker?.ready;
    if (reg?.showNotification) {
      await reg.showNotification(title, options);
      return true;
    }
  } catch {
    /* fall through to the constructor */
  }
  try {
    const notification = new Notification(title, options);
    notification.onclick = () => {
      window.focus();
      if (url) window.location.href = url;
    };
    return true;
  } catch {
    return false;
  }
}

function fire(lead) {
  // Once real Web Push is active, the SERVER pushes the OS notification (even
  // with the browser closed), so the client poll must not also fire one -
  // that would double-notify.
  if (pushActive) return;
  show(`Fresh ${lead.score}/10 lead`, {
    body: lead.title,
    tag: `lead-${lead.id}`,
    url: `/leads/${lead.id}`,
  });
}

// --- Real Web Push subscription (reaches a locked / closed-browser phone) ---
let pushActive = false;

function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
  return output;
}

// Subscribe THIS device to server push. Idempotent - the server dedupes by
// endpoint, and an existing browser subscription is reused. Requires the SW,
// the Push API, granted permission, and a configured VAPID key server-side.
export async function subscribeToPush() {
  try {
    if (!("serviceWorker" in navigator) || !("PushManager" in window)) return false;
    if (typeof Notification === "undefined" || Notification.permission !== "granted") return false;

    const key = await apiClient.get("/push/vapid-key");
    if (!key.data.data.configured || !key.data.data.public_key) return false;

    const reg = await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(key.data.data.public_key),
      });
    }

    const json = sub.toJSON();
    await apiClient.post("/push/subscribe", {
      endpoint: json.endpoint,
      keys: json.keys,
      content_encoding: "aes128gcm",
    });
    pushActive = true;
    return true;
  } catch {
    return false;
  }
}

export async function enableLeadAlerts() {
  if (!leadAlerts.supported) return false;

  const permission = Notification.permission === "granted" ? "granted" : await Notification.requestPermission();

  if (permission !== "granted") return false;

  leadAlerts.enabled = true;
  localStorage.setItem(STORAGE_KEY, "1");
  startPolling();
  subscribeToPush(); // best-effort: also register for lock-screen pushes
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

  // Tapping test is a good moment to also register for real (lock-screen) push.
  subscribeToPush();

  const shown = await show("SkillLeo test alert ✅", {
    body: "If you can see this on your phone, real-time lead alerts work on this device.",
    tag: "skillleo-test",
    url: "/leads",
  });

  return shown ? { ok: true } : { ok: false, reason: "unsupported" };
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
    subscribeToPush(); // keep this device's push subscription fresh
  }
}
