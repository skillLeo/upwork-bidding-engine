<script setup>
import { ref, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { Activity, BarChart3, ChevronDown, LayoutGrid, LogOut, Settings as SettingsIcon, UserCircle } from "@lucide/vue";
import { useAuthStore } from "@/stores/auth";
import { useBrandingStore } from "@/stores/branding";
import { apiClient } from "@/lib/api-client";
import Avatar from "@/components/ui/Avatar.vue";
import { cn, initials } from "@/lib/utils";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const branding = useBrandingStore();
const menuOpen = ref(false);

const navItems = [
  { to: "/leads", label: "Leads", icon: LayoutGrid, adminOnly: false },
  { to: "/analytics", label: "Analytics", icon: BarChart3, adminOnly: true },
  { to: "/health", label: "Health", icon: Activity, adminOnly: true },
  { to: "/settings", label: "Settings", icon: SettingsIcon, adminOnly: true },
];

const visibleNavItems = computed(() =>
  navItems.filter((item) => !item.adminOnly || auth.isAdmin),
);

const firstName = computed(() => auth.user?.name?.split(" ")[0] ?? "Account");

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
    <div class="mx-auto flex h-16 max-w-[1128px] items-center justify-between px-4">
      <div class="flex items-center gap-3 sm:gap-4">
        <router-link
          to="/leads"
          class="flex items-center gap-2.5 text-lg font-bold tracking-tight text-text-primary"
        >
          <img
            v-if="branding.logoUrl"
            :src="branding.logoUrl"
            :alt="branding.name"
            class="h-8 w-8 rounded-md object-contain shadow-[0_0_0_1px_rgba(0,0,0,0.04)]"
          />
          <span
            v-else
            class="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-sm font-bold text-white shadow-[0_1px_2px_rgba(0,0,0,0.15)]"
          >
            {{ initials(branding.name) }}
          </span>
          <span class="hidden sm:inline">{{ branding.name }}</span>
        </router-link>

        <span class="hidden h-6 w-px bg-border sm:block" aria-hidden="true" />

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
                  'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-primary-tint text-primary'
                    : 'text-text-secondary hover:bg-black/5 hover:text-text-primary',
                )
              "
            >
              <component :is="item.icon" class="h-4 w-4" :stroke-width="isActive ? 2.25 : 1.75" />
              <span class="hidden sm:inline">{{ item.label }}</span>
            </a>
          </router-link>
        </nav>
      </div>

      <div class="relative">
        <button
          @click="menuOpen = !menuOpen"
          class="flex items-center gap-2 rounded-full py-1 pr-2.5 pl-1 transition-colors hover:bg-black/5"
          aria-label="Account menu"
        >
          <Avatar
            :name="auth.user?.name ?? '?'"
            :src="auth.user?.avatar_url"
            size="sm"
            class="shadow-[0_0_0_2px_#fff]"
          />
          <span class="hidden text-sm font-medium text-text-primary md:inline">{{ firstName }}</span>
          <ChevronDown class="h-4 w-4 text-text-tertiary" />
        </button>

        <template v-if="menuOpen">
          <div class="fixed inset-0 z-10" @click="menuOpen = false" />
          <div class="absolute right-0 z-20 mt-2 w-56 overflow-hidden rounded-card border border-border bg-white py-2 shadow-popover">
            <div class="border-b border-border px-3 py-2.5">
              <p class="truncate text-sm font-medium text-text-primary">{{ auth.user?.name }}</p>
              <p class="truncate text-xs text-text-secondary">{{ auth.user?.email }}</p>
              <span class="mt-1.5 inline-block rounded-pill bg-primary-tint px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">
                {{ auth.user?.role }}
              </span>
            </div>
            <router-link
              v-if="auth.isAdmin"
              to="/profile"
              @click="menuOpen = false"
              class="flex w-full items-center gap-2 px-3 py-2 text-sm text-text-secondary transition-colors hover:bg-black/5 hover:text-text-primary"
            >
              <UserCircle class="h-4 w-4" />
              Profile
            </router-link>
            <button
              @click="handleLogout"
              class="flex w-full items-center gap-2 px-3 py-2 text-sm text-text-secondary transition-colors hover:bg-black/5 hover:text-text-primary"
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
