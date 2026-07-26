import { reactive } from "vue";
import { toast } from "vue-sonner";
import { apiClient } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import router from "@/router";

// In-app notification centre (the bell). Lives at the app root and polls, so
// new leads/reminders show up with no page refresh. Hostinger shared hosting
// can't hold a websocket open, so this is a light poll - the endpoint returns
// in ~40ms, so a short interval is cheap and feels real-time.
const POLL_MS = 15_000;

export const notifications = reactive({ items: [], unreadCount: 0 });

let timer = null;

// Ids already seen by this tab, so a fresh lead toasts exactly once. null
// until the first successful fetch: that first response seeds the set without
// toasting, otherwise every notification already on the board would pop the
// moment the dashboard loads.
let seenIds = null;

// Non-blocking toast for a lead that arrived while the operator is looking at
// the dashboard. Deliberately driven by the SAME notification stream the bell
// reads — which NotificationDispatcher populates — so the score threshold,
// freshness gate and mute switch are applied server-side, once, and never
// re-implemented here. `silent` rows are the dispatcher saying "show this in
// the list, but do not interrupt".
function toastNewLeads(items) {
  if (seenIds === null) {
    seenIds = new Set(items.map((n) => n.id));
    return;
  }

  // Oldest first, so a burst reads in the order it arrived.
  for (const n of [...items].reverse()) {
    if (seenIds.has(n.id)) continue;
    seenIds.add(n.id);

    if (n.type !== "lead" || n.silent) continue;

    toast(n.title, {
      description: n.body,
      duration: 10_000,
      action: {
        label: "View",
        onClick: () => router.push(n.url || `/leads/${n.lead_id}`),
      },
    });
  }
}

async function fetchNotifications() {
  if (!useAuthStore().token) {
    notifications.items = [];
    notifications.unreadCount = 0;
    seenIds = null;
    return;
  }
  try {
    const res = await apiClient.get("/notifications");
    notifications.items = res.data.data;
    notifications.unreadCount = res.data.meta.unread_count;
    toastNewLeads(res.data.data);
  } catch {
    // Best-effort — the next tick retries.
  }
}

export function refreshNotifications() {
  return fetchNotifications();
}

// Optimistic: flip local state immediately (so the UI is instant) then persist.
export async function markRead(id) {
  const item = notifications.items.find((n) => n.id === id);
  if (item && !item.read) {
    item.read = true;
    notifications.unreadCount = Math.max(0, notifications.unreadCount - 1);
    try {
      await apiClient.post(`/notifications/${id}/read`);
    } catch {
      /* keep the optimistic state; a later poll reconciles */
    }
  }
}

export async function markAllRead() {
  notifications.items.forEach((n) => (n.read = true));
  notifications.unreadCount = 0;
  try {
    await apiClient.post("/notifications/read-all");
  } catch {
    /* optimistic */
  }
}

let listenersBound = false;

export function initNotifications() {
  clearInterval(timer);
  seenIds = null;
  fetchNotifications();
  timer = setInterval(fetchNotifications, POLL_MS);

  // Mobile browsers freeze setInterval in a backgrounded tab, so the poll
  // alone can look stale after you switch away. Refetch the instant the tab is
  // visible/focused again - that's what makes the bell update on return with
  // no manual refresh.
  if (!listenersBound) {
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") fetchNotifications();
    });
    window.addEventListener("focus", fetchNotifications);
    listenersBound = true;
  }
}
