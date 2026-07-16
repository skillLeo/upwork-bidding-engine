<script setup>
import { ref, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { BarChart3, ChevronDown, LayoutGrid, LogOut, Settings as SettingsIcon, UserCircle } from "@lucide/vue";
import { useAuthStore } from "@/stores/auth";
import { apiClient } from "@/lib/api-client";
import Avatar from "@/components/ui/Avatar.vue";
import { cn } from "@/lib/utils";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const menuOpen = ref(false);

const navItems = [
  { to: "/leads", label: "Leads", icon: LayoutGrid, adminOnly: false },
  { to: "/analytics", label: "Analytics", icon: BarChart3, adminOnly: true },
  { to: "/settings", label: "Settings", icon: SettingsIcon, adminOnly: true },
];

const visibleNavItems = computed(() =>
  navItems.filter((item) => !item.adminOnly || auth.isAdmin),
);

async function handleLogout() {
  try {
    await apiClient.post("/auth/logout");
  } catch {
    // token may already be invalid — still clear local state below
  } finally {
    auth.logout();
  }
  toast.success("Logged out.");
  router.push({ name: "login" });
}
</script>

<template>
  <header class="sticky top-0 z-30 border-b border-border bg-white/95 shadow-[0_1px_2px_rgba(0,0,0,0.04)] backdrop-blur supports-[backdrop-filter]:bg-white/85">
    <div class="mx-auto flex h-14 max-w-[1128px] items-center justify-between px-4">
      <div class="flex items-center gap-2 sm:gap-6">
        <router-link
          to="/leads"
          class="flex items-center gap-2 text-lg font-bold tracking-tight text-primary"
        >
          <span class="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-sm font-bold text-white">
            SL
          </span>
          <span class="hidden sm:inline">SkillLeo</span>
        </router-link>
        <nav class="flex items-center gap-1">
          <router-link
            v-for="item in visibleNavItems"
            :key="item.to"
            :to="item.to"
            v-slot="{ isActive }"
          >
            <a
              :href="item.to"
              @click.prevent="router.push(item.to)"
              :class="
                cn(
                  'relative flex flex-col items-center gap-0.5 px-3 py-2.5 text-[11px] font-medium text-text-secondary transition-colors hover:text-text-primary',
                  isActive && 'text-text-primary',
                )
              "
            >
              <component :is="item.icon" class="h-5 w-5" :stroke-width="isActive ? 2.25 : 1.75" />
              {{ item.label }}
              <span
                v-if="isActive"
                class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-primary"
              />
            </a>
          </router-link>
        </nav>
      </div>

      <div class="relative">
        <button
          @click="menuOpen = !menuOpen"
          class="flex items-center gap-1.5 rounded-full p-1 hover:bg-black/5"
          aria-label="Account menu"
        >
          <Avatar :name="auth.user?.name ?? '?'" :src="auth.user?.avatar_url" size="sm" />
          <ChevronDown class="h-4 w-4 text-text-tertiary" />
        </button>

        <template v-if="menuOpen">
          <div class="fixed inset-0 z-10" @click="menuOpen = false" />
          <div class="absolute right-0 z-20 mt-2 w-56 rounded-card border border-border bg-white py-2 shadow-popover">
            <div class="border-b border-border px-3 py-2">
              <p class="truncate text-sm font-medium text-text-primary">{{ auth.user?.name }}</p>
              <p class="truncate text-xs text-text-secondary">{{ auth.user?.email }}</p>
              <span class="mt-1.5 inline-block rounded-pill bg-primary-tint px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">
                {{ auth.user?.role }}
              </span>
            </div>
            <router-link
              to="/profile"
              @click="menuOpen = false"
              class="flex w-full items-center gap-2 px-3 py-2 text-sm text-text-secondary hover:bg-black/5 hover:text-text-primary"
            >
              <UserCircle class="h-4 w-4" />
              Profile
            </router-link>
            <button
              @click="handleLogout"
              class="flex w-full items-center gap-2 px-3 py-2 text-sm text-text-secondary hover:bg-black/5 hover:text-text-primary"
            >
              <LogOut class="h-4 w-4" />
              Log out
            </button>
          </div>
        </template>
      </div>
    </div>
  </header>
</template>
