<script setup>
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import { Bell, CheckCheck, Sparkles, Clock, Info, Inbox } from "@lucide/vue";
import { notifications, markRead, markAllRead } from "@/stores/notifications";
import { relativeTime } from "@/lib/utils";

const router = useRouter();
const open = ref(false);

const unread = computed(() => notifications.unreadCount);

const iconFor = (type) => (type === "lead" ? Sparkles : type === "reminder" ? Clock : Info);
const toneFor = (level) =>
  ({
    success: "bg-success-bg text-success",
    warning: "bg-warning-bg text-warning",
    info: "bg-info-bg text-info",
  })[level] ?? "bg-neutral-bg text-text-tertiary";

async function openItem(n) {
  await markRead(n.id);
  open.value = false;
  if (n.url) router.push(n.url);
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      @click="open = !open"
      :aria-label="unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'"
      class="relative flex h-9 w-9 items-center justify-center rounded-full text-text-secondary transition-colors hover:bg-black/5 hover:text-text-primary"
    >
      <Bell class="h-5 w-5" :class="unread > 0 && 'animate-[wiggle_1.2s_ease-in-out]'" />
      <span
        v-if="unread > 0"
        class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white ring-2 ring-white"
      >
        {{ unread > 9 ? "9+" : unread }}
      </span>
    </button>

    <template v-if="open">
      <div class="fixed inset-0 z-30" @click="open = false" />
      <div
        class="absolute right-0 z-40 mt-2 w-[360px] max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-card border border-border bg-white shadow-popover"
      >
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
          <p class="text-sm font-semibold text-text-primary">Notifications</p>
          <button
            v-if="unread > 0"
            type="button"
            @click="markAllRead"
            class="flex items-center gap-1 text-xs font-medium text-primary hover:underline"
          >
            <CheckCheck class="h-3.5 w-3.5" /> Mark all read
          </button>
        </div>

        <div class="max-h-[min(420px,70vh)] overflow-y-auto">
          <button
            v-for="n in notifications.items"
            :key="n.id"
            type="button"
            @click="openItem(n)"
            :class="[
              'flex w-full items-start gap-3 border-b border-border/60 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-neutral-bg/60',
              !n.read && 'bg-primary/[0.04]',
            ]"
          >
            <span
              class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
              :class="toneFor(n.level)"
            >
              <component :is="iconFor(n.type)" class="h-4 w-4" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-1.5">
                <span
                  class="truncate text-sm"
                  :class="n.read ? 'font-medium text-text-secondary' : 'font-semibold text-text-primary'"
                >
                  {{ n.title }}
                </span>
                <span v-if="!n.read" class="ml-auto h-2 w-2 shrink-0 rounded-full bg-primary" />
              </span>
              <span v-if="n.body" class="mt-0.5 line-clamp-2 block text-xs text-text-secondary">
                {{ n.body }}
              </span>
              <span class="mt-1 block text-[11px] text-text-tertiary">
                {{ relativeTime(n.created_at) }}
              </span>
            </span>
          </button>

          <div
            v-if="notifications.items.length === 0"
            class="flex flex-col items-center gap-2 px-4 py-10 text-center"
          >
            <Inbox class="h-7 w-7 text-text-tertiary" />
            <p class="text-sm font-medium text-text-secondary">You're all caught up</p>
            <p class="text-xs text-text-tertiary">Fresh leads and reminders will show up here.</p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
@keyframes wiggle {
  0%, 100% { transform: rotate(0); }
  20%, 60% { transform: rotate(-9deg); }
  40%, 80% { transform: rotate(9deg); }
}
</style>
