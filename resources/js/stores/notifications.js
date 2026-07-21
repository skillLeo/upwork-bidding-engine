import { reactive } from "vue";
import { apiClient } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

// In-app notification centre (the bell). Lives at the app root and polls, so
// new leads/reminders show up with no page refresh. Hostinger shared hosting
// can't hold a websocket open, so this is a light poll - the endpoint returns
// in ~40ms, so a short interval is cheap and feels real-time.
const POLL_MS = 15_000;

export const notifications = reactive({ items: [], unreadCount: 0 });

let timer = null;

async function fetchNotifications() {
  if (!useAuthStore().token) {
    notifications.items = [];
    notifications.unreadCount = 0;
    return;
  }
  try {
    const res = await apiClient.get("/notifications");
    notifications.items = res.data.data;
    notifications.unreadCount = res.data.meta.unread_count;
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
